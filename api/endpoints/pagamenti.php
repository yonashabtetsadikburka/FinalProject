<?php
declare(strict_types=1);

/**
 * Gestione pagamenti e statistiche.
 *
 * GET /wallet          - storico pagamenti utente corrente
 * GET /wallet/movimenti - movimenti (alias)
 * POST /wallet/ricarica - ricarica wallet (solo admin) — placeholder
 * GET /wallet/{id}     - pagamenti di un utente (admin)
 * GET /pagamenti/stats - statistiche commissioni (admin)
 */

/**
 * Storico pagamenti dell'utente corrente.
 */
function wallet_dettaglio(): void
{
    $io = richiedi_login();

    $st = db()->prepare(
        'SELECT pag.id, pag.tipo_pagamento, pag.importo, pag.commissione_agenzia,
                pag.stato, pag.data_pagamento,
                p.id_colletta
           FROM pagamenti pag
           JOIN prenotazioni p ON p.id = pag.id_prenotazione
          WHERE p.id_utente = ?
          ORDER BY pag.id DESC LIMIT 50'
    );
    $st->execute([$io]);
    $pagamenti = array_map(function ($p) {
        $p['id'] = (int)$p['id'];
        $p['importo'] = (float)$p['importo'];
        $p['commissione_agenzia'] = (float)$p['commissione_agenzia'];
        $p['id_colletta'] = (int)$p['id_colletta'];
        return $p;
    }, $st->fetchAll());

    json_ok(['pagamenti' => $pagamenti]);
}

/**
 * Movimenti (alias per compatibilita' frontend).
 */
function wallet_movimenti(): void
{
    wallet_dettaglio();
}

/**
 * Dettaglio pagamenti di un utente (solo admin).
 */
function wallet_dettaglio_utente(int $utente_id): void
{
    richiedi_login();
    if (!sono_admin()) {
        throw new AppError('NON_AUTORIZZATO', 'Accesso riservato agli amministratori', 403);
    }

    $st = db()->prepare(
        'SELECT pag.id, pag.tipo_pagamento, pag.importo, pag.commissione_agenzia,
                pag.stato, pag.data_pagamento,
                p.id_colletta
           FROM pagamenti pag
           JOIN prenotazioni p ON p.id = pag.id_prenotazione
          WHERE p.id_utente = ?
          ORDER BY pag.id DESC LIMIT 50'
    );
    $st->execute([$utente_id]);
    $pagamenti = array_map(function ($p) {
        $p['id'] = (int)$p['id'];
        $p['importo'] = (float)$p['importo'];
        $p['commissione_agenzia'] = (float)$p['commissione_agenzia'];
        $p['id_colletta'] = (int)$p['id_colletta'];
        return $p;
    }, $st->fetchAll());

    json_ok(['pagamenti' => $pagamenti]);
}

/**
 * Statistiche commissioni per admin dashboard.
 */
function wallet_statistiche(): void
{
    richiedi_login();
    if (!sono_admin()) {
        throw new AppError('NON_AUTORIZZATO', 'Accesso riservato agli amministratori', 403);
    }

    // Totale commissioni incassate
    $st = db()->prepare(
        'SELECT COALESCE(SUM(commissione_agenzia), 0) FROM pagamenti WHERE stato = \'confermato\''
    );
    $st->execute();
    $totale_commissioni = (float)$st->fetchColumn();

    // Commissioni mese corrente
    $st = db()->prepare(
        'SELECT COALESCE(SUM(commissione_agenzia), 0) FROM pagamenti
          WHERE stato = \'confermato\'
            AND MONTH(data_pagamento) = MONTH(CURDATE()) AND YEAR(data_pagamento) = YEAR(CURDATE())'
    );
    $st->execute();
    $commissioni_mese = (float)$st->fetchColumn();

    // Totale importi pagati
    $st = db()->prepare(
        'SELECT COALESCE(SUM(importo), 0) FROM pagamenti WHERE stato = \'confermato\''
    );
    $st->execute();
    $totale_addebiti = (float)$st->fetchColumn();

    // Ordini confermati (in corso)
    $st = db()->prepare(
        "SELECT COUNT(*) FROM collette WHERE stato = 'ordine_fornitore'"
    );
    $st->execute();
    $ordini_in_corso = (int)$st->fetchColumn();

    // Campagne completate
    $st = db()->prepare(
        "SELECT COUNT(*) FROM collette WHERE stato = 'consegnata'"
    );
    $st->execute();
    $campagne_completate = (int)$st->fetchColumn();

    json_ok([
        'totale_commissioni'   => $totale_commissioni,
        'commissioni_mese'     => $commissione_mese ?? $totale_commissioni,
        'totale_addebiti'      => $totale_addebiti,
        'ordini_in_corso'      => $ordini_in_corso,
        'campagne_completate'  => $campagne_completate,
    ]);
}

/**
 * Statistiche personali dell'utente corrente per la dashboard.
 * GET /mie/statistiche
 */
function mie_statistiche(): void
{
    $io = richiedi_login();

    // Speso finora (pagamenti confermati)
    $st = db()->prepare(
        "SELECT COALESCE(SUM(pg.importo), 0)
           FROM pagamenti pg
           JOIN prenotazioni p ON p.id = pg.id_prenotazione
          WHERE p.id_utente = ? AND pg.stato = 'confermato'"
    );
    $st->execute([$io]);
    $spesa_totale = round((float)$st->fetchColumn(), 2);

    // Risparmio stimato sulle proprie partecipazioni attive
    $st = db()->prepare(
        "SELECT COALESCE(SUM((c.prezzo_base - c.prezzo_corrente) * p.quantita), 0)
           FROM prenotazioni p
           JOIN collette c ON c.id = p.id_colletta
          WHERE p.id_utente = ?
            AND p.stato IN ('prenotata','confermata','pagata')
            AND c.stato NOT IN ('annullata','fallita')"
    );
    $st->execute([$io]);
    $risparmio = round((float)$st->fetchColumn(), 2);

    // Da pagare (confermate, con commissione)
    $st = db()->prepare(
        "SELECT COALESCE(SUM(p.quantita * c.prezzo_corrente * (1 + c.percentuale_commissione / 100)), 0)
           FROM prenotazioni p
           JOIN collette c ON c.id = p.id_colletta
          WHERE p.id_utente = ? AND p.stato = 'confermata'"
    );
    $st->execute([$io]);
    $da_pagare = round((float)$st->fetchColumn(), 2);

    // Conteggi
    $st = db()->prepare(
        "SELECT COUNT(*) FROM prenotazioni WHERE id_utente = ? AND stato IN ('prenotata','confermata','pagata')"
    );
    $st->execute([$io]);
    $n_attive = (int)$st->fetchColumn();

    $st = db()->prepare(
        "SELECT COUNT(*) FROM qr_codes qr
           JOIN prenotazioni p ON p.id = qr.id_prenotazione
          WHERE p.id_utente = ? AND qr.stato = 'generato'"
    );
    $st->execute([$io]);
    $n_ritirare = (int)$st->fetchColumn();

    $st = db()->prepare(
        "SELECT COUNT(*) FROM qr_codes qr
           JOIN prenotazioni p ON p.id = qr.id_prenotazione
          WHERE p.id_utente = ? AND qr.stato = 'scansionato'"
    );
    $st->execute([$io]);
    $n_ritirati = (int)$st->fetchColumn();

    json_ok([
        'spesa_totale'      => $spesa_totale,
        'risparmio_stimato' => $risparmio,
        'da_pagare'         => $da_pagare,
        'n_attive'          => $n_attive,
        'n_ritirare'        => $n_ritirare,
        'n_ritirati'        => $n_ritirati,
    ]);
}
