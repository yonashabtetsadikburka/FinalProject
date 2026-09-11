<?php
declare(strict_types=1);

/**
 * PRENOTAZIONI = partecipazione di un cliente a una colletta.
 * Nessun addebito al momento (l'importo si paga dopo la conferma).
 * Una sola prenotazione attiva per (colletta, utente): se esiste, si
 * aggiorna la quantita.
 */
function prenotazioni_crea(int $colletta_id): void
{
    $io       = richiedi_login();
    $quantita = campo_int(corpo(), 'quantita', 1);

    $stato = ricalcola_stato($colletta_id);
    if (in_array($stato, ['fallita', 'annullata'], true)) {
        throw new AppError('COLLETTA_CHIUSA', 'La colletta non e\' piu\' aperta');
    }
    if (in_array($stato, ['ordine_fornitore', 'consegnata'], true)) {
        throw new AppError('COLLETTA_ORDINATA', 'L\'ordine e\' gia\' partito, non si puo\' piu\' aderire');
    }

    // prezzo dal prodotto della colletta
    $st = db()->prepare(
        'SELECT p.prezzo_unitario
           FROM collette c JOIN prodotti p ON p.id_prodotto = c.id_prodotto
          WHERE c.id_colletta = ?');
    $st->execute([$colletta_id]);
    $p = $st->fetch();
    if (!$p) throw new AppError('COLLETTA_INESISTENTE', 'Colletta non trovata', 404);

    $saldo = round((float)$p['prezzo_unitario'] * $quantita, 2);

    // c'e' gia' una mia prenotazione attiva su questa colletta?
    $st = db()->prepare(
        'SELECT id_prenotazione FROM prenotazioni
          WHERE id_colletta = ? AND id_utente = ?
            AND stato IN (\'prenotata\',\'confermata\')');
    $st->execute([$colletta_id, $io]);
    $esistente = $st->fetch();

    if ($esistente) {
        db()->prepare(
            'UPDATE prenotazioni SET quantita = ?, importo_saldo = ?
              WHERE id_prenotazione = ?')
            ->execute([$quantita, $saldo, (int)$esistente['id_prenotazione']]);
        $id_pren = (int)$esistente['id_prenotazione'];
    } else {
        db()->prepare(
            'INSERT INTO prenotazioni
               (id_colletta, id_utente, quantita, importo_acconto, importo_saldo, stato)
             VALUES (?,?,?,?,?,\'prenotata\')')
            ->execute([$colletta_id, $io, $quantita, 0, $saldo]);
        $id_pren = (int)db()->lastInsertId();
    }

    json_ok([
        'id_prenotazione' => $id_pren,
        'quantita'        => $quantita,
        'importo_saldo'   => $saldo,
        'stato_colletta'  => ricalcola_stato($colletta_id),
    ], $esistente ? 200 : 201);
}

/** Le prenotazioni dell'utente loggato (dashboard, "i miei ordini"). */
function prenotazioni_mie(): void
{
    $io = richiedi_login();
    $st = db()->prepare(
        'SELECT pr.id_prenotazione, pr.id_colletta, pr.quantita,
                pr.importo_acconto, pr.importo_saldo, pr.stato,
                pr.data_prenotazione,
                c.stato AS stato_colletta, c.data_limite,
                p.nome AS prodotto, p.image_url, p.prezzo_unitario,
                fd.nome_azienda AS fornitore
           FROM prenotazioni pr
           JOIN collette c            ON c.id_colletta = pr.id_colletta
           JOIN prodotti p            ON p.id_prodotto = c.id_prodotto
           JOIN fornitori_dettagli fd ON fd.id_utente  = p.id_fornitore
          WHERE pr.id_utente = ?
          ORDER BY pr.data_prenotazione DESC');
    $st->execute([$io]);
    json_ok(array_map(fn($r) => [
        'id'                => (int)$r['id_prenotazione'],
        'id_colletta'       => (int)$r['id_colletta'],
        'quantita'          => (int)$r['quantita'],
        'importo_acconto'   => (float)$r['importo_acconto'],
        'importo_saldo'     => $r['importo_saldo'] !== null ? (float)$r['importo_saldo'] : null,
        'stato'             => $r['stato'],
        'data_prenotazione' => $r['data_prenotazione'],
        'stato_colletta'    => $r['stato_colletta'],
        'data_limite'       => $r['data_limite'],
        'prodotto'          => $r['prodotto'],
        'image_url'         => $r['image_url'],
        'prezzo_unitario'   => (float)$r['prezzo_unitario'],
        'fornitore'         => $r['fornitore'],
    ], $st->fetchAll()));
}

/** Annulla una PROPRIA prenotazione. Poi ricalcola lo stato della colletta. */
function prenotazioni_annulla(int $id): void
{
    $io = richiedi_login();

    $st = db()->prepare(
        'SELECT id_colletta, stato FROM prenotazioni
          WHERE id_prenotazione = ? AND id_utente = ?');
    $st->execute([$id, $io]);
    $pren = $st->fetch();
    if (!$pren) throw new AppError('NON_TROVATA', 'Prenotazione non trovata', 404);
    if ($pren['stato'] === 'annullata') {
        throw new AppError('GIA_ANNULLATA', 'Prenotazione gia\' annullata');
    }

    db()->prepare(
        'UPDATE prenotazioni SET stato = \'annullata\' WHERE id_prenotazione = ?')
        ->execute([$id]);

    // il ricalcolo puo' riportare la colletta ad 'in_corso' (caso al contrario)
    json_ok(['stato_colletta' => ricalcola_stato((int)$pren['id_colletta'])]);
}
