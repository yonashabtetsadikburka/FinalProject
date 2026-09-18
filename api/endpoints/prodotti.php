<?php
declare(strict_types=1);

/**
 * ============================================================
 *  PRODOTTI — gestione galleria immagini (solo admin).
 *  I prodotti nascono dentro le campagne (POST /campagne).
 * ============================================================
 */

/** Lista immagini prodotto. GET /admin/prodotti/{id}/immagini (solo admin). */
function prodotti_immagini_elenco(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
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
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
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
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
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
