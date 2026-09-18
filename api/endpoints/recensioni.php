<?php
declare(strict_types=1);

/**
 * ============================================================
 *  RECENSIONI FORNITORE — stelle (1-5) + testo.
 *  Solo chi ha acquistato (partecipazione pagata) puo recensire.
 * ============================================================
 */

/** Verifica che il fornitore esista, altrimenti 404. */
function recensioni_fornitore_ok(int $idFornitore): void
{
    $st = db()->prepare('SELECT id FROM fornitori WHERE id = ?');
    $st->execute([$idFornitore]);
    if (!$st->fetch()) throw new AppError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);
}

/** True se l'utente ha almeno una partecipazione pagata presso il fornitore. */
function recensioni_ha_acquistato(int $idFornitore, int $idUtente): bool
{
    $st = db()->prepare(
        'SELECT 1 FROM prenotazioni p
          JOIN collette c ON c.id = p.id_colletta
          JOIN prodotti pr ON pr.id = c.id_prodotto
         WHERE pr.id_fornitore = ? AND p.id_utente = ? AND p.stato = \'pagata\' LIMIT 1'
    );
    $st->execute([$idFornitore, $idUtente]);
    return (bool)$st->fetch();
}

/** GET /fornitori/{id}/recensioni — lista + media + flag puo_recensire. */
function recensioni_elenco(int $idFornitore): void
{
    $io = richiedi_login();
    recensioni_fornitore_ok($idFornitore);

    $st = db()->prepare(
        'SELECT r.id, r.voto, r.testo, r.data_creazione, u.nome AS autore
           FROM recensioni_fornitore r
           JOIN utenti u ON u.id = r.id_utente
          WHERE r.id_fornitore = ?
          ORDER BY r.data_creazione DESC'
    );
    $st->execute([$idFornitore]);
    $rows = array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['voto'] = (int)$r['voto'];
        return $r;
    }, $st->fetchAll());

    $tot = count($rows);
    $media = $tot > 0 ? round(array_sum(array_column($rows, 'voto')) / $tot, 1) : null;

    $mia = null;
    $st = db()->prepare(
        'SELECT id, voto, testo FROM recensioni_fornitore WHERE id_fornitore = ? AND id_utente = ?'
    );
    $st->execute([$idFornitore, $io]);
    if ($m = $st->fetch()) {
        $mia = ['id' => (int)$m['id'], 'voto' => (int)$m['voto'], 'testo' => $m['testo']];
    }

    json_ok([
        'media' => $media,
        'totale' => $tot,
        'mia' => $mia,
        'puo_recensire' => recensioni_ha_acquistato($idFornitore, $io),
        'recensioni' => $rows,
    ]);
}

/** POST /fornitori/{id}/recensioni — crea o aggiorna la propria recensione. */
function recensioni_salva(int $idFornitore): void
{
    $io = richiedi_utente_attivo();
    recensioni_fornitore_ok($idFornitore);
    if (!recensioni_ha_acquistato($idFornitore, $io)) {
        throw new AppError('NON_ACQUIRENTE', 'Puoi recensire solo dopo un acquisto', 403);
    }

    $d = corpo();
    $voto = (int)($d['voto'] ?? 0);
    if ($voto < 1 || $voto > 5) throw new AppError('VOTO_NON_VALIDO', 'Il voto deve essere tra 1 e 5');
    $testo = trim((string)($d['testo'] ?? ''));
    if (mb_strlen($testo) > 1000) throw new AppError('TESTO_TROPPO_LUNGO', 'Massimo 1000 caratteri');

    db()->prepare(
        'INSERT INTO recensioni_fornitore (id_fornitore, id_utente, voto, testo)
         VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE voto = VALUES(voto), testo = VALUES(testo)'
    )->execute([$idFornitore, $io, $voto, ($testo !== '' ? $testo : null)]);

    json_ok(['salvata' => true]);
}

/** DELETE /fornitori/recensioni/{id} — cancella la propria (o admin). */
function recensione_elimina(int $id): void
{
    $io = richiedi_login();
    $st = db()->prepare('SELECT id_utente FROM recensioni_fornitore WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new AppError('RECENSIONE_INESISTENTE', 'Recensione non trovata', 404);
    if ((int)$r['id_utente'] !== $io && !sono_admin()) {
        throw new AppError('NON_AUTORIZZATO', 'Puoi eliminare solo la tua recensione', 403);
    }
    db()->prepare('DELETE FROM recensioni_fornitore WHERE id = ?')->execute([$id]);
    json_ok(['eliminata' => true]);
}

/* ============================================================
 *  RECENSIONI CAMPAGNA — stelle (1-5) + testo per singola colletta.
 *  Solo chi ha ricevuto la consegna (consegnata/ritirata o QR scansionato).
 * ============================================================
 */

/** Verifica che la colletta esista, altrimenti 404. */
function recensioni_campagna_ok(int $idColletta): void
{
    $st = db()->prepare('SELECT id FROM collette WHERE id = ?');
    $st->execute([$idColletta]);
    if (!$st->fetch()) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);
}

/** True se l'utente ha pagato E ricevuto (consegna o ritiro) in questa campagna. */
function recensioni_campagna_consegnata(int $idColletta, int $idUtente): bool
{
    $st = db()->prepare(
        'SELECT 1 FROM prenotazioni p
       LEFT JOIN consegne co ON co.id_prenotazione = p.id
       LEFT JOIN qr_codes qr ON qr.id_prenotazione = p.id
         WHERE p.id_colletta = ? AND p.id_utente = ? AND p.stato = \'pagata\'
           AND (co.stato IN (\'consegnata\',\'ritirata\') OR qr.stato = \'scansionato\') LIMIT 1'
    );
    $st->execute([$idColletta, $idUtente]);
    return (bool)$st->fetch();
}

/** GET /campagne/{id}/recensioni — lista + media + flag puo_recensire. */
function recensioni_campagna_elenco(int $idColletta): void
{
    $io = richiedi_login();
    recensioni_campagna_ok($idColletta);

    $st = db()->prepare(
        'SELECT r.id, r.voto, r.testo, r.data_creazione, u.nome AS autore
           FROM recensioni_campagna r
           JOIN utenti u ON u.id = r.id_utente
          WHERE r.id_colletta = ?
          ORDER BY r.data_creazione DESC'
    );
    $st->execute([$idColletta]);
    $rows = array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['voto'] = (int)$r['voto'];
        return $r;
    }, $st->fetchAll());

    $tot = count($rows);
    $media = $tot > 0 ? round(array_sum(array_column($rows, 'voto')) / $tot, 1) : null;

    $mia = null;
    $st = db()->prepare(
        'SELECT id, voto, testo FROM recensioni_campagna WHERE id_colletta = ? AND id_utente = ?'
    );
    $st->execute([$idColletta, $io]);
    if ($m = $st->fetch()) {
        $mia = ['id' => (int)$m['id'], 'voto' => (int)$m['voto'], 'testo' => $m['testo']];
    }

    json_ok([
        'media' => $media,
        'totale' => $tot,
        'mia' => $mia,
        'puo_recensire' => recensioni_campagna_consegnata($idColletta, $io),
        'recensioni' => $rows,
    ]);
}

/** POST /campagne/{id}/recensioni — crea o aggiorna la propria recensione. */
function recensioni_campagna_salva(int $idColletta): void
{
    $io = richiedi_utente_attivo();
    recensioni_campagna_ok($idColletta);
    if (!recensioni_campagna_consegnata($idColletta, $io)) {
        throw new AppError('NON_CONSEGNATO', 'Puoi recensire solo dopo la consegna', 403);
    }

    $d = corpo();
    $voto = (int)($d['voto'] ?? 0);
    if ($voto < 1 || $voto > 5) throw new AppError('VOTO_NON_VALIDO', 'Il voto deve essere tra 1 e 5');
    $testo = trim((string)($d['testo'] ?? ''));
    if (mb_strlen($testo) > 1000) throw new AppError('TESTO_TROPPO_LUNGO', 'Massimo 1000 caratteri');

    db()->prepare(
        'INSERT INTO recensioni_campagna (id_colletta, id_utente, voto, testo)
         VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE voto = VALUES(voto), testo = VALUES(testo)'
    )->execute([$idColletta, $io, $voto, ($testo !== '' ? $testo : null)]);

    json_ok(['salvata' => true]);
}

/** DELETE /campagne/recensioni/{id} — cancella la propria (o admin). */
function recensione_campagna_elimina(int $id): void
{
    $io = richiedi_login();
    $st = db()->prepare('SELECT id_utente FROM recensioni_campagna WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new AppError('RECENSIONE_INESISTENTE', 'Recensione non trovata', 404);
    if ((int)$r['id_utente'] !== $io && !sono_admin()) {
        throw new AppError('NON_AUTORIZZATO', 'Puoi eliminare solo la tua recensione', 403);
    }
    db()->prepare('DELETE FROM recensioni_campagna WHERE id = ?')->execute([$id]);
    json_ok(['eliminata' => true]);
}
