<?php
declare(strict_types=1);

/**
 * ============================================================
 *  PRODOTTI — catalogo gestito dagli admin (creazione, modifica,
 *  eliminazione, galleria immagini) + "mi piace" dei clienti.
 *  I prodotti possono nascere anche dentro le campagne (POST /campagne).
 * ============================================================
 */

/** Lista immagini prodotto. GET /admin/prodotti/{id}/immagini (solo admin). */
function prodotti_immagini_elenco(int $id): void
{
    richiedi_admin('Solo gli admin');
    $st = db()->prepare('SELECT id, url, ordine, principale FROM immagini_prodotto WHERE id_prodotto = ? ORDER BY principale DESC, ordine ASC');
    $st->execute([$id]);
    $out = array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['ordine'] = (int)$r['ordine'];
        $r['principale'] = (int)$r['principale'];
        return $r;
    }, $st->fetchAll());
    json_ok($out);
}

/** Aggiunge una o piu immagini (foto[]). POST /admin/prodotti/{id}/immagini (solo admin). */
function prodotti_immagini_aggiungi(int $id): void
{
    richiedi_admin('Solo gli admin');
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM prodotti WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);

    $files = [];
    if (!empty($_FILES['foto'])) {
        $f = $_FILES['foto'];
        if (is_array($f['error'])) {
            foreach ($f['error'] as $i => $err) {
                if ($err !== UPLOAD_ERR_NO_FILE) {
                    $files[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i],
                        'tmp_name' => $f['tmp_name'][$i], 'error' => $err, 'size' => $f['size'][$i]];
                }
            }
        } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $files[] = $f;
        }
    }
    if (empty($files)) throw new AppError('FOTO_MANCANTE', 'Nessuna immagine da caricare');

    $stMax = $pdo->prepare('SELECT COALESCE(MAX(ordine), -1) FROM immagini_prodotto WHERE id_prodotto = ?');
    $stMax->execute([$id]);
    $nextOrd = (int)$stMax->fetchColumn() + 1;
    $stPrinc = $pdo->prepare('SELECT COUNT(*) FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 1');
    $stPrinc->execute([$id]);
    $haPrincipale = ((int)$stPrinc->fetchColumn()) > 0;

    $salvate = [];
    $inserite = [];
    try {
        foreach ($files as $f) {
            $url = fornitore_salva_foto($f);
            $salvate[] = $url;
            $princ = (!$haPrincipale && empty($inserite)) ? 1 : 0;
            $pdo->prepare(
                'INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,?,?)'
            )->execute([$id, $url, $nextOrd++, $princ]);
            $inserite[] = ['id' => (int)$pdo->lastInsertId(), 'url' => $url];
        }
    } catch (Throwable $e) {
        foreach ($salvate as $u) @unlink(__DIR__ . '/../' . $u);
        throw $e;
    }
    json_ok($inserite);
}

/** Elimina singola immagine. DELETE /admin/immagini/{id} (solo admin). */
function prodotti_immagine_elimina(int $id): void
{
    richiedi_admin('Solo gli admin');
    $pdo = db();
    $st = $pdo->prepare('SELECT id_prodotto, url, principale FROM immagini_prodotto WHERE id = ?');
    $st->execute([$id]);
    $img = $st->fetch();
    if (!$img) throw new AppError('IMMAGINE_INESISTENTE', 'Immagine non trovata', 404);

    $st = $pdo->prepare('SELECT COUNT(*) FROM immagini_prodotto WHERE id_prodotto = ?');
    $st->execute([$img['id_prodotto']]);
    if ((int)$st->fetchColumn() <= 1) {
        throw new AppError('ULTIMA_IMMAGINE', 'Impossibile eliminare l\'unica immagine del prodotto', 409);
    }

    $pdo->prepare('DELETE FROM immagini_prodotto WHERE id = ?')->execute([$id]);
    if ((int)$img['principale'] === 1) {
        // Promuovi la prima restante a principale
        $pdo->prepare(
            'UPDATE immagini_prodotto SET principale = 1 WHERE id_prodotto = ? ORDER BY ordine ASC LIMIT 1'
        )->execute([$img['id_prodotto']]);
    }
    @unlink(__DIR__ . '/../' . $img['url']);
    json_ok(['eliminata' => true]);
}


// ============================================================
//  CATALOGO ADMIN — creazione / modifica / eliminazione
//  Le richieste sono multipart/form-data (c'e' l'upload della foto).
// ============================================================

/** Legge e valida i campi del form prodotto. Con $completo=false valgono solo quelli inviati. */
function prodotto_campi_form(bool $completo): array
{
    $out = [];
    $txt = fn(string $k) => trim((string)($_POST[$k] ?? ''));

    if ($completo || array_key_exists('nome', $_POST)) {
        $nome = $txt('nome');
        if ($nome === '') throw new AppError('NOME_MANCANTE', 'Il nome del prodotto e\' obbligatorio');
        if (mb_strlen($nome) > 200) throw new AppError('NOME_TROPPO_LUNGO', 'Nome: massimo 200 caratteri');
        $out['nome'] = $nome;
    }
    if (array_key_exists('descrizione', $_POST)) {
        $out['descrizione'] = $txt('descrizione') !== '' ? $txt('descrizione') : null;
    }
    foreach (['prezzo_unitario', 'prezzo_base'] as $k) {
        if ($completo && $k === 'prezzo_unitario' || array_key_exists($k, $_POST) && $txt($k) !== '') {
            $v = filter_var($_POST[$k] ?? null, FILTER_VALIDATE_FLOAT);
            if ($v === false || $v === null || $v <= 0) {
                throw new AppError('PREZZO_NON_VALIDO', "Il campo $k deve essere un prezzo maggiore di zero");
            }
            $out[$k] = round((float)$v, 2);
        }
    }
    if ($completo || (array_key_exists('quantita_minima', $_POST) && $txt('quantita_minima') !== '')) {
        $q = filter_var($_POST['quantita_minima'] ?? 1, FILTER_VALIDATE_INT);
        if ($q === false || $q < 1) throw new AppError('MOQ_NON_VALIDO', 'La quantita\' minima deve essere un intero >= 1');
        $out['quantita_minima'] = (int)$q;
    }
    if (array_key_exists('id_categoria', $_POST)) {
        $c = $txt('id_categoria');
        if ($c === '') {
            $out['id_categoria'] = null;
        } else {
            $st = db()->prepare('SELECT id FROM categorie WHERE id = ?');
            $st->execute([(int)$c]);
            if (!$st->fetch()) throw new AppError('CATEGORIA_INESISTENTE', 'Categoria non trovata', 404);
            $out['id_categoria'] = (int)$c;
        }
    }
    return $out;
}

/** Il prezzo attuale non puo' superare il prezzo base (il "prezzo pieno" da cui si calcola lo sconto). */
function prodotto_controlla_prezzi(?float $unitario, ?float $base): void
{
    if ($unitario !== null && $base !== null && $unitario > $base) {
        throw new AppError('PREZZO_NON_VALIDO', 'Il prezzo attuale non puo\' superare il prezzo base');
    }
}

/** Foto singola dal campo "foto", oppure null se non inviata. Ritorna il path relativo salvato. */
function prodotto_foto_da_form(): ?string
{
    $f = $_FILES['foto'] ?? null;
    if (!$f || is_array($f['error']) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    return fornitore_salva_foto($f);
}

/** Crea un prodotto. POST /admin/prodotti (solo admin). */
function prodotti_admin_crea(): void
{
    richiedi_admin('Solo gli admin');
    $pdo = db();

    $c = prodotto_campi_form(true);
    $idFornitore = filter_var($_POST['id_fornitore'] ?? null, FILTER_VALIDATE_INT);
    if ($idFornitore === false || $idFornitore === null) {
        throw new AppError('FORNITORE_MANCANTE', 'Seleziona il fornitore del prodotto');
    }
    $st = $pdo->prepare('SELECT id FROM fornitori WHERE id = ?');
    $st->execute([$idFornitore]);
    if (!$st->fetch()) throw new AppError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);

    $c['prezzo_base'] = $c['prezzo_base'] ?? $c['prezzo_unitario'];
    prodotto_controlla_prezzi($c['prezzo_unitario'], $c['prezzo_base']);

    $foto = prodotto_foto_da_form();
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO prodotti (id_fornitore, id_categoria, nome, descrizione, prezzo_unitario, prezzo_base, quantita_minima)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$idFornitore, $c['id_categoria'] ?? null, $c['nome'], $c['descrizione'] ?? null,
                    $c['prezzo_unitario'], $c['prezzo_base'], $c['quantita_minima']]);
        $id = (int)$pdo->lastInsertId();
        if ($foto !== null) {
            $pdo->prepare('INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,0,1)')
                ->execute([$id, $foto]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($foto !== null) @unlink(__DIR__ . '/../' . $foto);
        throw $e;
    }
    json_ok(['id' => $id, 'nome' => $c['nome']], 201);
}

/** Modifica un prodotto (solo i campi inviati). POST /admin/prodotti/{id}/modifica (solo admin). */
function prodotti_admin_modifica(int $id): void
{
    richiedi_admin('Solo gli admin');
    $pdo = db();
    $st = $pdo->prepare('SELECT id, prezzo_unitario, prezzo_base FROM prodotti WHERE id = ?');
    $st->execute([$id]);
    $att = $st->fetch();
    if (!$att) throw new AppError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);

    $c = prodotto_campi_form(false);
    prodotto_controlla_prezzi(
        (float)($c['prezzo_unitario'] ?? $att['prezzo_unitario']),
        (float)($c['prezzo_base'] ?? ($att['prezzo_base'] ?? $att['prezzo_unitario']))
    );

    $foto = prodotto_foto_da_form();
    if (empty($c) && $foto === null) throw new AppError('NESSUNA_MODIFICA', 'Nessun campo da modificare');

    $vecchie = [];
    try {
        $pdo->beginTransaction();
        if (!empty($c)) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($c)));
            $pdo->prepare("UPDATE prodotti SET $set WHERE id = ?")->execute([...array_values($c), $id]);
        }
        if ($foto !== null) {
            // "Sostituisci immagine": la nuova diventa principale, la vecchia principale sparisce.
            $stV = $pdo->prepare('SELECT id, url FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 1');
            $stV->execute([$id]);
            $vecchie = $stV->fetchAll();
            $pdo->prepare('DELETE FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 1')->execute([$id]);
            $pdo->prepare('INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,0,1)')
                ->execute([$id, $foto]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($foto !== null) @unlink(__DIR__ . '/../' . $foto);
        throw $e;
    }
    foreach ($vecchie as $v) {
        if (!preg_match('#^https?://#i', $v['url'])) @unlink(__DIR__ . '/../' . $v['url']);
    }
    json_ok(['id' => $id, 'aggiornato' => true]);
}

/** Elimina un prodotto senza campagne. DELETE /admin/prodotti/{id} (solo admin). */
function prodotti_admin_elimina(int $id): void
{
    richiedi_admin('Solo gli admin');
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM prodotti WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);

    $st = $pdo->prepare('SELECT COUNT(*) FROM collette WHERE id_prodotto = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        throw new AppError('HA_CAMPAGNE', 'Impossibile eliminare: ci sono campagne collegate a questo prodotto', 409);
    }

    $st = $pdo->prepare('SELECT url FROM immagini_prodotto WHERE id_prodotto = ?');
    $st->execute([$id]);
    $file = $st->fetchAll(PDO::FETCH_COLUMN);

    $pdo->prepare('DELETE FROM prodotti WHERE id = ?')->execute([$id]);   // le immagini cadono in cascata
    foreach ($file as $u) {
        if (!preg_match('#^https?://#i', $u)) @unlink(__DIR__ . '/../' . $u);
    }
    json_ok(['eliminato' => true]);
}

// ============================================================
//  MI PIACE
// ============================================================

function prodotto_like_stato(int $idProdotto, int $idUtente): array
{
    $st = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM prodotti_like WHERE id_prodotto = ?) AS n,
                EXISTS(SELECT 1 FROM prodotti_like WHERE id_prodotto = ? AND id_utente = ?) AS mio'
    );
    $st->execute([$idProdotto, $idProdotto, $idUtente]);
    $r = $st->fetch();
    return ['mio_like' => (bool)$r['mio'], 'mi_piace' => (int)$r['n']];
}

/** Mette "mi piace". POST /prodotti/{id}/like (idempotente). */
function prodotti_like_aggiungi(int $id): void
{
    $io = richiedi_utente_attivo();
    $st = db()->prepare("SELECT id FROM prodotti WHERE id = ? AND stato = 'attivo'");
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);

    db()->prepare('INSERT IGNORE INTO prodotti_like (id_prodotto, id_utente) VALUES (?,?)')->execute([$id, $io]);
    json_ok(prodotto_like_stato($id, $io));
}

/** Toglie "mi piace". DELETE /prodotti/{id}/like (idempotente). */
function prodotti_like_rimuovi(int $id): void
{
    $io = richiedi_utente_attivo();
    db()->prepare('DELETE FROM prodotti_like WHERE id_prodotto = ? AND id_utente = ?')->execute([$id, $io]);
    json_ok(prodotto_like_stato($id, $io));
}
