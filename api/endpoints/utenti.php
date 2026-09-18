<?php
declare(strict_types=1);

function utenti_elenco(): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $st = db()->query(
        'SELECT id, nome, cognome, email, ruolo, stato, tipo, data_iscrizione
           FROM utenti ORDER BY data_iscrizione DESC'
    );
    $out = array_map(function ($u) {
        $u['id'] = (int)$u['id'];
        return $u;
    }, $st->fetchAll());
    json_ok($out);
}

function utenti_dettaglio(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $st = db()->prepare(
        'SELECT id, nome, cognome, email, ruolo, stato, tipo, telefono, indirizzo, cap, citta,
                provincia, partita_iva, codice_fiscale, data_iscrizione
           FROM utenti WHERE id = ?'
    );
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);
    $u['id'] = (int)$u['id'];
    json_ok($u);
}

function utenti_aggiorna_stato(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    if ($id === utente_corrente_id()) {
        throw new AppError('OPERAZIONE_NON_CONSENTITA', 'Non puoi modificare il tuo stesso stato', 403);
    }
    $d = corpo();
    $nuovo_stato = campo($d, 'stato');
    if (!in_array($nuovo_stato, ['attivo', 'sospeso'], true)) {
        throw new AppError('STATO_NON_VALIDO', 'Stato non valido');
    }
    $st = db()->prepare('UPDATE utenti SET stato = ? WHERE id = ?');
    $st->execute([$nuovo_stato, $id]);
    if ($st->rowCount() === 0) {
        throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);
    }
    json_ok(['stato' => $nuovo_stato]);
}

function utenti_aggiorna_ruolo(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    if ($id === utente_corrente_id()) {
        throw new AppError('OPERAZIONE_NON_CONSENTITA', 'Non puoi modificare il tuo stesso ruolo', 403);
    }
    $d = corpo();
    $nuovo_ruolo = campo($d, 'ruolo');
    if (!in_array($nuovo_ruolo, ['cliente', 'fornitore', 'admin'], true)) {
        throw new AppError('RUOLO_NON_VALIDO', 'Ruolo non valido');
    }
    $st = db()->prepare('UPDATE utenti SET ruolo = ? WHERE id = ?');
    $st->execute([$nuovo_ruolo, $id]);
    if ($st->rowCount() === 0) {
        throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);
    }
    json_ok(['ruolo' => $nuovo_ruolo]);
}

function utenti_elimina(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    if ($id === utente_corrente_id()) {
        throw new AppError('OPERAZIONE_NON_CONSENTITA', 'Non puoi eliminare il tuo stesso account', 403);
    }

    // Blocco se l'utente ha storico
    $st = db()->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_utente = ?');
    $st->execute([$id]);
    $nPren = (int)$st->fetchColumn();

    $st = db()->prepare(
        'SELECT COUNT(*) FROM pagamenti pg JOIN prenotazioni p ON p.id = pg.id_prenotazione WHERE p.id_utente = ?'
    );
    $st->execute([$id]);
    $nPag = (int)$st->fetchColumn();

    if ($nPren > 0 || $nPag > 0) {
        throw new AppError('HA_STORICO', "Impossibile eliminare: l'utente ha $nPren partecipazioni e $nPag pagamenti registrati", 409);
    }

    $st = db()->prepare('DELETE FROM utenti WHERE id = ?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);
    }
    json_ok(['eliminato' => true]);
}

/**
 * Storico completo di un utente: partecipazioni, pagamenti, notifiche, proposte.
 */
function utenti_storico(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);

    $st = db()->prepare('SELECT id FROM utenti WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);

    // Partecipazioni
    $st = db()->prepare(
        'SELECT p.id, p.quantita, p.stato, p.data_prenotazione, p.data_pagamento,
                c.id AS id_colletta, c.stato AS stato_colletta,
                pr.nome AS prodotto
           FROM prenotazioni p
           JOIN collette c  ON c.id = p.id_colletta
           JOIN prodotti pr ON pr.id = c.id_prodotto
          WHERE p.id_utente = ?
          ORDER BY p.data_prenotazione DESC'
    );
    $st->execute([$id]);
    $partecipazioni = array_map(function ($p) {
        $p['id'] = (int)$p['id'];
        $p['id_colletta'] = (int)$p['id_colletta'];
        $p['quantita'] = (int)$p['quantita'];
        return $p;
    }, $st->fetchAll());

    // Pagamenti
    $st = db()->prepare(
        'SELECT pg.id, pg.tipo_pagamento, pg.importo, pg.stato, pg.data_pagamento,
                pr.nome AS prodotto, p.id_colletta
           FROM pagamenti pg
           JOIN prenotazioni p ON p.id = pg.id_prenotazione
           JOIN collette c     ON c.id = p.id_colletta
           JOIN prodotti pr    ON pr.id = c.id_prodotto
          WHERE p.id_utente = ?
          ORDER BY pg.data_pagamento DESC'
    );
    $st->execute([$id]);
    $pagamenti = array_map(function ($pg) {
        $pg['id'] = (int)$pg['id'];
        $pg['id_colletta'] = (int)$pg['id_colletta'];
        $pg['importo'] = (float)$pg['importo'];
        return $pg;
    }, $st->fetchAll());

    // Ultime notifiche
    $st = db()->prepare(
        'SELECT id, tipo, titolo, messaggio, data_creazione
           FROM notifiche WHERE id_utente = ?
          ORDER BY data_creazione DESC LIMIT 20'
    );
    $st->execute([$id]);
    $notifiche = array_map(function ($n) {
        $n['id'] = (int)$n['id'];
        return $n;
    }, $st->fetchAll());

    // Proposte inviate
    $st = db()->prepare(
        'SELECT id_proposta, nome_prodotto, stato, tot_voti, data_proposta
           FROM proposte_prodotti WHERE proponente_id = ?
          ORDER BY data_proposta DESC'
    );
    $st->execute([$id]);
    $proposte = array_map(function ($pp) {
        $pp['id_proposta'] = (int)$pp['id_proposta'];
        $pp['tot_voti'] = (int)$pp['tot_voti'];
        return $pp;
    }, $st->fetchAll());

    json_ok([
        'partecipazioni' => $partecipazioni,
        'pagamenti'      => $pagamenti,
        'notifiche'      => $notifiche,
        'proposte'       => $proposte,
    ]);
}
