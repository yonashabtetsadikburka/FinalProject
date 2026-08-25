<?php
declare(strict_types=1);

function partecipazioni_aderisci(int $campagna_id): void
{
    $io    = richiedi_login();
    $latte = campo_int(corpo(), 'latte', 1);

    $stato = ricalcola_stato($campagna_id);
    if ($stato === 'decaduta')  throw new AppError('CAMPAGNA_DECADUTA', 'La campagna e\' scaduta');
    if ($stato === 'ripartita') throw new AppError('CAMPAGNA_RIPARTITA', 'La campagna e\' gia\' stata ripartita');

    try {
        $st = db()->prepare(
            'INSERT INTO partecipazioni (campagna_id, utente_id, latte_richieste)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE latte_richieste = VALUES(latte_richieste)');
        $st->execute([$campagna_id, $io, $latte]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);
        }
        throw $e;
    }

    json_ok(['stato' => ricalcola_stato($campagna_id), 'latte_richieste' => $latte]);
}

function partecipazioni_ritira(int $campagna_id): void
{
    $io    = richiedi_login();
    $stato = ricalcola_stato($campagna_id);
    if ($stato === 'ripartita') {
        throw new AppError('CAMPAGNA_RIPARTITA', 'Non si puo\' uscire da una campagna gia\' ripartita');
    }

    // Si cancella SOLO la propria adesione.
    $st = db()->prepare('DELETE FROM partecipazioni WHERE campagna_id = ? AND utente_id = ?');
    $st->execute([$campagna_id, $io]);
    if ($st->rowCount() === 0) {
        throw new AppError('NON_PARTECIPI', 'Non risulti iscritto a questa campagna', 404);
    }

    // Il ricalcolo puo' riportare la campagna ad 'aperta': e' il caso
    // al contrario, quello che si dimentica sempre.
    json_ok(['stato' => ricalcola_stato($campagna_id)]);
}
