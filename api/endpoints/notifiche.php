<?php
declare(strict_types=1);

/** Le notifiche dell'utente loggato (piu' recenti prima). */
function notifiche_mie(): void
{
    $io = richiedi_login();
    $st = db()->prepare(
        'SELECT id_notifica, tipo, titolo, messaggio, tipo_riferimento,
                id_riferimento, letta, data_creazione, data_lettura
           FROM notifiche WHERE id_utente = ?
          ORDER BY data_creazione DESC');
    $st->execute([$io]);
    json_ok(array_map(fn($r) => [
        'id'               => (int)$r['id_notifica'],
        'tipo'             => $r['tipo'],
        'titolo'           => $r['titolo'],
        'messaggio'        => $r['messaggio'],
        'tipo_riferimento' => $r['tipo_riferimento'],
        'id_riferimento'   => $r['id_riferimento'] !== null ? (int)$r['id_riferimento'] : null,
        'letta'            => (bool)$r['letta'],
        'data_creazione'   => $r['data_creazione'],
        'data_lettura'     => $r['data_lettura'],
    ], $st->fetchAll()));
}

/** Segna come letta una PROPRIA notifica. */
function notifiche_segna_letta(int $id): void
{
    $io = richiedi_login();
    $st = db()->prepare(
        'UPDATE notifiche SET letta = 1, data_lettura = NOW()
          WHERE id_notifica = ? AND id_utente = ?');
    $st->execute([$id, $io]);
    if ($st->rowCount() === 0) {
        // gia' letta o non esiste: verifico che sia mia
        $c = db()->prepare('SELECT 1 FROM notifiche WHERE id_notifica = ? AND id_utente = ?');
        $c->execute([$id, $io]);
        if (!$c->fetch()) throw new AppError('NON_TROVATA', 'Notifica non trovata', 404);
    }
    json_ok(['id' => $id, 'letta' => true]);
}
