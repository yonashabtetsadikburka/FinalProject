<?php
declare(strict_types=1);

/**
 * SELECT dell'avanzamento collette.
 * Usa: collette, prodotti, fornitori, sedi, prenotazioni
 */
function sql_collette(): string
{
    return
    'SELECT c.id, c.stato, c.data_limite, c.data_inizio, c.quantita_minima, c.quantita_attuale,
            c.regola_arrotondamento, c.prezzo_base, c.prezzo_corrente,
            c.percentuale_commissione, c.data_conferma,
            c.id_prodotto, c.id_aperta_da, c.id_referente, c.id_sede,
            pr.nome AS prodotto, pr.prezzo_unitario, pr.quantita_minima AS lotto_prodotto,
            pr.id_categoria, cat.nome AS categoria,
            (SELECT url FROM immagini_prodotto WHERE id_prodotto = pr.id ORDER BY principale DESC, ordine ASC LIMIT 1) AS immagine,
            (SELECT CONCAT("[", GROUP_CONCAT(JSON_OBJECT("id", id, "url", url, "principale", principale, "ordine", ordine) ORDER BY principale DESC, ordine ASC SEPARATOR ","), "]") FROM immagini_prodotto WHERE id_prodotto = pr.id) AS immagini,
            f.nome_azienda AS fornitore, f.id AS fornitore_id,
            s.nome AS punto_ritiro, s.citta,
            COALESCE(SUM(p.quantita), 0) AS quantita_totale,
            COUNT(DISTINCT p.id_utente) AS partecipanti
       FROM collette c
       JOIN prodotti  pr ON pr.id = c.id_prodotto
       JOIN fornitori f  ON f.id  = pr.id_fornitore
  LEFT JOIN categorie cat ON cat.id = pr.id_categoria
  LEFT JOIN sedi         s ON s.id_sede = c.id_sede
  LEFT JOIN prenotazioni p ON p.id_colletta = c.id';
}

/** Converte i tipi: MySQL restituisce tutto come stringa. */
function normalizza_colletta(array $r): array
{
    foreach (['id','quantita_minima','quantita_attuale','quantita_totale',
              'partecipanti','id_prodotto','id_aperta_da','id_referente',
              'id_sede','fornitore_id','lotto_prodotto','id_categoria'] as $k) {
        if (isset($r[$k])) $r[$k] = (int)$r[$k];
    }
    foreach (['prezzo_base','prezzo_corrente','percentuale_commissione',
              'prezzo_unitario'] as $k) {
        if (isset($r[$k])) $r[$k] = (float)$r[$k];
    }
    $r['soglia_raggiunta'] = $r['quantita_attuale'] >= $r['quantita_minima'];
    // Decodifica array immagini (JSON da GROUP_CONCAT); fallback a [immagine]
    $imgs = [];
    if (!empty($r['immagini']) && is_string($r['immagini'])) {
        $dec = json_decode($r['immagini'], true);
        if (is_array($dec)) {
            foreach ($dec as $im) {
                if (is_array($im) && !empty($im['url'])) {
                    $imgs[] = ['id' => (int)($im['id'] ?? 0), 'url' => (string)$im['url'], 'principale' => (int)($im['principale'] ?? 0), 'ordine' => (int)($im['ordine'] ?? 0)];
                }
            }
        }
    }
    if (empty($imgs) && !empty($r['immagine'])) {
        $imgs[] = ['url' => (string)$r['immagine'], 'principale' => 1, 'ordine' => 0];
    }
    unset($r['immagini']);
    $r['immagini'] = $imgs;
    return $r;
}

function campagne_elenco(): void
{
    richiedi_login();
    db()->exec('SET SESSION group_concat_max_len = 100000');
    $st = db()->query(sql_collette() . ' GROUP BY c.id ORDER BY c.data_limite ASC');
    $out = array_map('normalizza_colletta', $st->fetchAll());

    foreach ($out as &$c) { $c['stato'] = ricalcola_stato($c['id']); }
    json_ok($out);
}

function campagne_dettaglio(int $id): void
{
    richiedi_login();
    db()->exec('SET SESSION group_concat_max_len = 100000');
    $st = db()->prepare(sql_collette() . ' WHERE c.id = ? GROUP BY c.id');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);

    $c = normalizza_colletta($c);
    $c['stato'] = ricalcola_stato($id);

    // Rating fornitore: media + numero recensioni
    $stR = db()->prepare(
        'SELECT COUNT(*) AS tot, AVG(voto) AS media FROM recensioni_fornitore WHERE id_fornitore = ?'
    );
    $stR->execute([(int)($c['fornitore_id'] ?? 0)]);
    $rr = $stR->fetch();
    $c['fornitore_rating'] = [
        'media' => $rr && $rr['tot'] > 0 ? round((float)$rr['media'], 1) : null,
        'totale' => (int)($rr['tot'] ?? 0),
    ];

    // Rating campagna: media + numero recensioni della colletta
    $stRc = db()->prepare(
        'SELECT COUNT(*) AS tot, AVG(voto) AS media FROM recensioni_campagna WHERE id_colletta = ?'
    );
    $stRc->execute([$id]);
    $rc = $stRc->fetch();
    $c['campagna_rating'] = [
        'media' => $rc && $rc['tot'] > 0 ? round((float)$rc['media'], 1) : null,
        'totale' => (int)($rc['tot'] ?? 0),
    ];

    $st = db()->prepare(
        'SELECT p.id, p.id_utente, u.nome, u.cognome, p.quantita, p.stato, p.data_prenotazione,
                qr.stato AS stato_qr,
                co.id AS id_consegna, co.modalita AS consegna_modalita, co.importo_consegna, co.stato AS consegna_stato
           FROM prenotazioni p JOIN utenti u ON u.id = p.id_utente
      LEFT JOIN qr_codes qr ON qr.id_prenotazione = p.id
      LEFT JOIN consegne co ON co.id_prenotazione = p.id
          WHERE p.id_colletta = ? ORDER BY p.data_prenotazione ASC');
    $st->execute([$id]);
    $c['partecipazioni'] = array_map(function ($p) {
        $p['id'] = (int)$p['id'];
        $p['id_utente'] = (int)$p['id_utente'];
        $p['quantita'] = (int)$p['quantita'];
        return $p;
    }, $st->fetchAll());

    $mio = utente_corrente_id();
    $c['mia_partecipazione'] = null;
    foreach ($c['partecipazioni'] as $p) {
        if ($p['id_utente'] === $mio) {
            $c['mia_partecipazione'] = [
                'id'     => $p['id'],
                'quantita' => $p['quantita'],
                'stato'  => $p['stato'],
                'stato_qr' => $p['stato_qr'] ?? null,
                'id_consegna' => isset($p['id_consegna']) ? (int)$p['id_consegna'] : null,
                'consegna_modalita' => $p['consegna_modalita'] ?? null,
                'importo_consegna' => isset($p['importo_consegna']) ? (float)$p['importo_consegna'] : 0.0,
                'consegna_stato' => $p['consegna_stato'] ?? null,
            ];
        }
    }
    json_ok($c);
}

/**
 * Elimina una campagna. Solo admin, solo se senza pagamenti (qualsiasi stato).
 * A cascata: prenotazioni, ordini_fornitore, scaglioni, qr. Notifiche e prodotti restano.
 * DELETE /campagne/{id}
 */
function campagne_elimina(int $id): void
{
    richiedi_login();
    if (!sono_admin()) throw new AppError('NON_ADMIN', 'Accesso riservato agli amministratori', 403);

    $st = db()->prepare('SELECT id FROM collette WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);

    $st = db()->prepare(
        'SELECT COUNT(*) FROM pagamenti pg
           JOIN prenotazioni p ON p.id = pg.id_prenotazione
          WHERE p.id_colletta = ?'
    );
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        throw new AppError('HA_PAGAMENTI', 'Impossibile eliminare: esistono pagamenti registrati', 409);
    }

    db()->prepare('DELETE FROM collette WHERE id = ?')->execute([$id]);
    json_ok(['eliminata' => true]);
}

/**
 * Crea campagna (solo admin). POST /campagne.
 * Accetta multipart (con foto[]) o JSON. Due modalita:
 *  - prodotto_id: campagna su prodotto esistente
 *  - nome + id_fornitore + prezzi + moq: crea prodotto + campagna in un colpo
 */
function campagne_crea(): void
{
    $io = richiedi_login();
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $d = (stripos($ct, 'multipart/form-data') !== false) ? $_POST : corpo();
    $scadenza = trim((string)($d['scadenza'] ?? ''));

    $ts = strtotime($scadenza);
    if ($ts === false) throw new AppError('DATA_NON_VALIDA', 'Formato scadenza non valido');
    if ($ts <= time())  throw new AppError('DATA_NEL_PASSATO', 'La scadenza deve essere futura');

    $pdo = db();
    $pdo->beginTransaction();
    $fotoSalvate = [];
    try {
        $prodotto_id = isset($d['prodotto_id']) && $d['prodotto_id'] !== '' ? (int)$d['prodotto_id'] : 0;
        if ($prodotto_id < 1) {
            // Crea prodotto al volo
            $nome = trim((string)($d['nome'] ?? ''));
            if ($nome === '') throw new AppError('NOME_MANCANTE', 'Nome prodotto obbligatorio');
            if (mb_strlen($nome) > 80) throw new AppError('NOME_TROPPO_LUNGO', 'Massimo 80 caratteri');
            $fid = (int)($d['id_fornitore'] ?? 0);
            if ($fid < 1) throw new AppError('FORNITORE_MANCANTE', 'Fornitore obbligatorio');
            $st = $pdo->prepare('SELECT id FROM fornitori WHERE id = ?');
            $st->execute([$fid]);
            if (!$st->fetch()) throw new AppError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);
            $prezzoCorr = (float)($d['prezzo_corrente'] ?? 0);
            if ($prezzoCorr <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzo scontato non valido');
            $prezzoBase = isset($d['prezzo_base']) && $d['prezzo_base'] !== ''
                ? (float)$d['prezzo_base'] : $prezzoCorr;
            if ($prezzoBase <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzo di listino non valido');
            $moq = isset($d['quantita_minima']) && $d['quantita_minima'] !== '' ? (int)$d['quantita_minima'] : 1;
            if ($moq < 1) throw new AppError('MOQ_NON_VALIDO', 'Quantita minima deve essere almeno 1');
            $cat = isset($d['id_categoria']) && $d['id_categoria'] !== '' ? (int)$d['id_categoria'] : null;
            $pdo->prepare(
                'INSERT INTO prodotti (id_fornitore, id_categoria, nome, descrizione, prezzo_unitario, prezzo_base, quantita_minima, stato)
                 VALUES (?,?,?,?,?,?,?, \'attivo\')'
            )->execute([$fid, $cat, mb_substr($nome, 0, 80), trim((string)($d['descrizione'] ?? '')),
                $prezzoCorr, $prezzoBase, $moq]);
            $prodotto_id = (int)$pdo->lastInsertId();
            $p = ['quantita_minima' => $moq, 'prezzo_unitario' => $prezzoCorr, 'prezzo_base' => $prezzoBase];
        } else {
            $st = $pdo->prepare('SELECT quantita_minima, prezzo_unitario, prezzo_base FROM prodotti WHERE id = ?');
            $st->execute([$prodotto_id]);
            $p = $st->fetch();
            if (!$p) throw new AppError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);
        }

        $prezzoCorrC = isset($d['prezzo_corrente']) && $d['prezzo_corrente'] !== ''
            ? (float)$d['prezzo_corrente'] : (float)$p['prezzo_unitario'];
        $prezzoBaseC = isset($d['prezzo_base']) && $d['prezzo_base'] !== ''
            ? (float)$d['prezzo_base'] : (float)($p['prezzo_base'] ?? $prezzoCorrC);
        $moqC = isset($d['quantita_minima']) && $d['quantita_minima'] !== ''
            ? (int)$d['quantita_minima'] : (int)$p['quantita_minima'];
        if ($prezzoCorrC <= 0 || $prezzoBaseC <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzi non validi');
        if ($moqC < 1) throw new AppError('MOQ_NON_VALIDO', 'Quantita minima deve essere almeno 1');
        $comm = isset($d['percentuale_commissione']) && $d['percentuale_commissione'] !== ''
            ? (float)$d['percentuale_commissione'] : 10.0;
        if ($comm < 0 || $comm > 100) throw new AppError('COMMISSIONE_NON_VALIDA', 'Commissione tra 0 e 100');

        $st = $pdo->prepare(
            'INSERT INTO collette
               (id_prodotto, id_aperta_da, id_referente, id_sede,
                quantita_minima, data_limite, prezzo_base, prezzo_corrente, percentuale_commissione)
             VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $prodotto_id, $io, $io,
            isset($d['sede_id']) && $d['sede_id'] !== '' ? (int)$d['sede_id'] : null,
            $moqC,
            date('Y-m-d H:i:s', $ts),
            round($prezzoBaseC, 2), round($prezzoCorrC, 2), round($comm, 2),
        ]);
        $id = (int)$pdo->lastInsertId();

        // Foto opzionali (foto[] multiplo): prima come principale
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
        $ord = 0;
        foreach ($files as $i => $f) {
            $url = fornitore_salva_foto($f);
            $fotoSalvate[] = $url;
            $pdo->prepare(
                'INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,?,?)'
            )->execute([$prodotto_id, $url, $ord++, ($i === 0 ? 1 : 0)]);
        }

        $pdo->commit();
        json_ok(['id' => $id, 'id_prodotto' => $prodotto_id], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($fotoSalvate as $u) @unlink(__DIR__ . '/../' . $u);
        throw $e;
    }
}

/**
 * Elenco ordini per l'admin: campagne con almeno una partecipazione + dettaglio partecipazioni.
 */
function admin_ordini(): void
{
    richiedi_login();
    if (!sono_admin()) throw new AppError('NON_ADMIN', 'Accesso riservato agli amministratori', 403);

    $st = db()->query(
        "SELECT c.id, c.stato, c.quantita_minima, c.quantita_attuale, c.data_limite,
                pr.nome AS prodotto, f.nome_azienda AS fornitore
           FROM collette c
           JOIN prodotti pr  ON pr.id = c.id_prodotto
           JOIN fornitori f  ON f.id  = pr.id_fornitore
          WHERE (SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = c.id) > 0
          ORDER BY c.data_limite ASC"
    );
    $campagne = $st->fetchAll();

    foreach ($campagne as &$c) {
        $c = normalizza_colletta($c);
        $c['stato'] = ricalcola_stato($c['id']);

        $st2 = db()->prepare(
            'SELECT p.id, p.id_utente, u.nome, u.cognome, p.quantita, p.stato
               FROM prenotazioni p JOIN utenti u ON u.id = p.id_utente
              WHERE p.id_colletta = ? ORDER BY p.data_prenotazione ASC'
        );
        $st2->execute([$c['id']]);
        $c['partecipazioni'] = $st2->fetchAll();
    }

    json_ok($campagne);
}

/**
 * Invia ordine al fornitore.
 * Solo se tutte le prenotazioni sono 'pagata'.
 * POST /campagne/{id}/invia-fornitore
 */
function campagne_invia_fornitore(int $colletta_id): void
{
    $io = richiedi_login();
    if (!sono_admin()) throw new AppError('NON_ADMIN', 'Accesso riservato agli amministratori', 403);

    $ris = invia_ordine_fornitore_al($colletta_id, $io);
    json_ok([
        'messaggio' => 'Ordine inviato al fornitore con successo.',
        'totale_pezzi' => $ris['totale_pezzi'],
        'importo_ordine' => $ris['importo_ordine'],
    ]);
}

/**
 * Logica di invio ordine (riusabile senza sessione admin, es. webhook).
 * $id_admin null = invio automatico di sistema.
 */
function invia_ordine_fornitore_al(int $colletta_id, ?int $id_admin): array
{
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, stato, id_prodotto, quantita_attuale, prezzo_corrente FROM collette WHERE id = ? FOR UPDATE');
        $st->execute([$colletta_id]);
        $c = $st->fetch();
        if (!$c) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);

        if ($c['stato'] !== 'ordine_pronto') {
            throw new AppError('STATO_NON_VALIDO', 'La campagna deve essere in stato "ordine_pronto". Stato attuale: ' . $c['stato']);
        }

        // Verifica che TUTTE le prenotazioni siano pagate
        $st = $pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = ? AND stato != \'pagata\'');
        $st->execute([$colletta_id]);
        $nonPagati = (int)$st->fetchColumn();
        if ($nonPagati > 0) {
            throw new AppError('PAGAMENTI_INCOMPLETI', "Ci sono ancora $nonPagati partecipanti che non hanno pagato.");
        }

        // Crea o aggiorna ordine al fornitore
        $pezzi = (int)$c['quantita_attuale'];
        $importo_totale = round($pezzi * (float)$c['prezzo_corrente'], 2);

        $st = $pdo->prepare('SELECT id_fornitore FROM prodotti WHERE id = ?');
        $st->execute([(int)$c['id_prodotto']]);
        $fornitore_id = (int)$st->fetchColumn();

        // Verifica se esiste gia' un ordine per questa colletta
        $st = $pdo->prepare('SELECT id FROM ordini_fornitore WHERE id_colletta = ?');
        $st->execute([$colletta_id]);
        $ordineEsistente = $st->fetch();

        if ($ordineEsistente) {
            $st = $pdo->prepare(
                'UPDATE ordini_fornitore SET id_fornitore = ?, id_admin = ?, quantita_ordinata = ?, importo_totale = ?, stato = \'inviato\', data_ordine = NOW() WHERE id = ?'
            );
            $st->execute([$fornitore_id, $id_admin, $pezzi, $importo_totale, (int)$ordineEsistente['id']]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO ordini_fornitore (id_colletta, id_fornitore, id_admin, quantita_ordinata, importo_totale, stato, data_ordine)
                 VALUES (?,?,?,?,?,\'inviato\',NOW())'
            );
            $st->execute([$colletta_id, $fornitore_id, $id_admin, $pezzi, $importo_totale]);
        }

        // Aggiorna stato colletta
        $st = $pdo->prepare('UPDATE collette SET stato = \'ordine_fornitore\', data_agg_stato = NOW() WHERE id = ?');
        $st->execute([$colletta_id]);

        // Notifica tutti i partecipanti
        $stProdotto = $pdo->prepare('SELECT pr.nome FROM prodotti pr WHERE pr.id = ?');
        $stProdotto->execute([(int)$c['id_prodotto']]);
        $nomeProdotto = $stProdotto->fetchColumn() ?: 'un prodotto';

        $stFornitore = $pdo->prepare('SELECT nome_azienda FROM fornitori WHERE id = ?');
        $stFornitore->execute([$fornitore_id]);
        $nomeFornitore = $stFornitore->fetchColumn() ?: 'il fornitore';

        $stUtenti = $pdo->prepare('SELECT DISTINCT id_utente FROM prenotazioni WHERE id_colletta = ?');
        $stUtenti->execute([$colletta_id]);
        $utenti = $stUtenti->fetchAll(PDO::FETCH_COLUMN);

        $stNotifica = $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'ORDINE_INVIATO\', \'Ordine Inviato\', ?, \'colletta\', ?)'
        );
        foreach ($utenti as $utenteId) {
            $messaggio = "L'ordine per \"$nomeProdotto\" è stato inviato al fornitore $nomeFornitore. Riceverai una notifica quando sarà consegnato.";
            $stNotifica->execute([$utenteId, $messaggio, $colletta_id]);
        }

        if ($ownTransaction) $pdo->commit();

        return ['totale_pezzi' => $pezzi, 'importo_ordine' => $importo_totale];

    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Modifica una campagna (solo admin). POST /campagne/{id}/modifica.
 * Accetta JSON o multipart (per upload foto).
 * Campi: data_limite, quantita_minima, prezzo_corrente, prezzo_base, percentuale_commissione, foto (file).
 * Bloccato se ordine gia' partito (ordine_fornitore/consegnata).
 */
function campagne_modifica(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);

    // Accept both JSON and multipart (FormData)
    $raw = file_get_contents('php://input');
    $isMultipart = !empty($_POST);
    if ($isMultipart) {
        $d = array_map(fn($v) => trim((string)$v), $_POST);
    } elseif ($raw !== false && $raw !== '') {
        $d = json_decode($raw, true);
        if (!is_array($d)) throw new AppError('JSON_NON_VALIDO', 'JSON non valido');
    } else {
        $d = [];
    }

    $hasFoto = !empty($_FILES['foto']) && (($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT id, stato, id_prodotto, quantita_attuale FROM collette WHERE id = ? FOR UPDATE'
        );
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            $pdo->rollBack();
            throw new AppError('CAMPAGNA_NON_TROVATA', 'Campagna non trovata', 404);
        }
        if (in_array($c['stato'], ['ordine_fornitore', 'consegnata'], true)) {
            $pdo->rollBack();
            throw new AppError('CAMPAGNA_NON_MODIFICABILE', 'Campagna non modificabile: ordine gia\' partito', 409);
        }

        $campi = [];
        $par = [];

        if (isset($d['data_limite']) && $d['data_limite'] !== '') {
            $dt = trim((string)$d['data_limite']);
            $ts = strtotime($dt);
            if ($ts === false || $ts < time()) {
                $pdo->rollBack();
                throw new AppError('SCADENZA_NON_VALIDA', 'La scadenza deve essere una data futura');
            }
            $campi[] = 'data_limite = ?';
            $par[] = date('Y-m-d H:i:s', $ts);
        }

        if (isset($d['quantita_minima']) && $d['quantita_minima'] !== '') {
            $moq = (int)$d['quantita_minima'];
            if ($moq < 1) {
                $pdo->rollBack();
                throw new AppError('MOQ_NON_VALIDO', 'MOQ deve essere almeno 1');
            }
            $campi[] = 'quantita_minima = ?';
            $par[] = $moq;
        }

        if (isset($d['prezzo_corrente']) && $d['prezzo_corrente'] !== '') {
            $pc = (float)$d['prezzo_corrente'];
            if ($pc <= 0) {
                $pdo->rollBack();
                throw new AppError('PREZZO_NON_VALIDO', 'Prezzo corrente deve essere maggiore di 0');
            }
            $campi[] = 'prezzo_corrente = ?';
            $par[] = round($pc, 2);
        }

        if (isset($d['prezzo_base']) && $d['prezzo_base'] !== '') {
            $pb = (float)$d['prezzo_base'];
            if ($pb <= 0) {
                $pdo->rollBack();
                throw new AppError('PREZZO_NON_VALIDO', 'Prezzo base deve essere maggiore di 0');
            }
            $campi[] = 'prezzo_base = ?';
            $par[] = round($pb, 2);
        }

        if (isset($d['percentuale_commissione']) && $d['percentuale_commissione'] !== '') {
            $comm = (float)$d['percentuale_commissione'];
            if ($comm < 0 || $comm > 100) {
                $pdo->rollBack();
                throw new AppError('COMMISSIONE_NON_VALIDA', 'Commissione deve essere tra 0 e 100');
            }
            $campi[] = 'percentuale_commissione = ?';
            $par[] = round($comm, 2);
        }

        if (!empty($campi)) {
            $par[] = $id;
            $pdo->prepare('UPDATE collette SET ' . implode(', ', $campi) . ' WHERE id = ?')->execute($par);
        }

        // Sostituzione foto principale del prodotto collegato
        if ($hasFoto) {
            $nuova = fornitore_salva_foto($_FILES['foto']);
            try {
                $idProdotto = (int)$c['id_prodotto'];
                $stUrl = $pdo->prepare('SELECT url FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 1');
                $stUrl->execute([$idProdotto]);
                $vecchie = $stUrl->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare('UPDATE immagini_prodotto SET principale = 0 WHERE id_prodotto = ?')->execute([$idProdotto]);
                $pdo->prepare(
                    'INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,0,1)'
                )->execute([$idProdotto, $nuova]);
                foreach ($vecchie as $v) {
                    @unlink(__DIR__ . '/../' . $v);
                    $pdo->prepare('DELETE FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 0 AND url = ?')->execute([$idProdotto, $v]);
                }
            } catch (Throwable $e) {
                @unlink(__DIR__ . '/../' . $nuova);
                throw $e;
            }
        }

        // Aggiunta immagini extra (foto[] multiplo): non sostituiscono la principale
        $extraFoto = [];
        if (!empty($_FILES['foto_extra'])) {
            $fe = $_FILES['foto_extra'];
            if (is_array($fe['error'])) {
                foreach ($fe['error'] as $i => $err) {
                    if ($err !== UPLOAD_ERR_NO_FILE) {
                        $extraFoto[] = ['name' => $fe['name'][$i], 'type' => $fe['type'][$i],
                            'tmp_name' => $fe['tmp_name'][$i], 'error' => $err, 'size' => $fe['size'][$i]];
                    }
                }
            }
        }
        if (!empty($extraFoto)) {
            $idProdotto = (int)$c['id_prodotto'];
            $stMax = $pdo->prepare('SELECT COALESCE(MAX(ordine), -1) FROM immagini_prodotto WHERE id_prodotto = ?');
            $stMax->execute([$idProdotto]);
            $nextOrd = (int)$stMax->fetchColumn() + 1;
            $salvate = [];
            try {
                foreach ($extraFoto as $f) {
                    $url = fornitore_salva_foto($f);
                    $salvate[] = $url;
                    $pdo->prepare(
                        'INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,?,0)'
                    )->execute([$idProdotto, $url, $nextOrd++]);
                }
            } catch (Throwable $e) {
                foreach ($salvate as $u) @unlink(__DIR__ . '/../' . $u);
                throw $e;
            }
        }

        if (empty($campi) && !$hasFoto && empty($extraFoto)) {
            $pdo->rollBack();
            throw new AppError('NESSUN_CAMPO', 'Nessun campo da aggiornare');
        }

        $nuovoStato = ricalcola_stato($id);

        $pdo->commit();
        json_ok(['id' => $id, 'stato' => $nuovoStato]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
