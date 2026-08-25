<?php
declare(strict_types=1);

/**
 * ============================================================
 *  RIPARTIZIONE  --  da completare (ruolo B)
 * ============================================================
 * Vedi piano sezione 7. Passi:
 *   1. ricalcola_stato() deve dare 'soglia_raggiunta', altrimenti errore.
 *   2. beginTransaction + SELECT COUNT(*) FROM assegnazioni ... FOR UPDATE
 *      -> se ce ne sono gia', GIA_RIPARTITA (idempotenza).
 *   3. Leggere partecipazioni + dati prodotto, ORDER BY created_at ASC.
 *   4. $scatole = intdiv($totale, $perScatola);   // arrotondamento per DIFETTO
 *      $disponibili = $scatole * $perScatola;
 *   5. $esito = ripartisci($righe, $disponibili);
 *   6. CONTROLLO: array_sum(latte) === $disponibili, altrimenti rollBack.
 *   7. INSERT assegnazioni con token = bin2hex(random_bytes(24)).
 *   8. UPDATE campagne SET stato='ripartita' + registra_evento() + commit.
 */
function assegnazioni_ripartisci(int $campagna_id): void
{
    $io = richiedi_login();

    $st = db()->prepare('SELECT aperta_da FROM campagne WHERE id = ?');
    $st->execute([$campagna_id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);
    if ((int)$c['aperta_da'] !== $io && !sono_admin()) {
        throw new AppError('NON_AUTORIZZATO', 'Solo chi ha aperto la campagna puo\' ripartirla', 403);
    }

    // TODO(B): implementare i passi 1-8 descritti sopra.
    throw new AppError('NON_IMPLEMENTATO', 'Ripartizione non ancora implementata', 501);
}

function assegnazioni_elenco(int $campagna_id): void
{
    richiedi_login();
    $st = db()->prepare(
        'SELECT a.utente_id, u.nome, a.latte_assegnate, a.quantita_totale,
                a.importo, a.ritirato_il
           FROM assegnazioni a JOIN utenti u ON u.id = a.utente_id
          WHERE a.campagna_id = ?
          ORDER BY u.nome');
    $st->execute([$campagna_id]);
    json_ok(array_map(function ($a) {
        $a['utente_id']       = (int)$a['utente_id'];
        $a['latte_assegnate'] = (int)$a['latte_assegnate'];
        $a['quantita_totale'] = (float)$a['quantita_totale'];
        $a['importo']         = (float)$a['importo'];
        $a['ritirato']        = $a['ritirato_il'] !== null;
        return $a;
    }, $st->fetchAll()));
}
