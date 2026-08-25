<?php
declare(strict_types=1);

/**
 * ============================================================
 *  MACCHINA A STATI DELLE CAMPAGNE
 *  Questa e' la parte centrale del ruolo B. Da scrivere io.
 * ============================================================
 *
 * Va richiamata dopo OGNI adesione, OGNI ritiro e a OGNI lettura
 * di una campagna. Niente cron: la scadenza si verifica in lettura.
 *
 * Stati: aperta -> soglia_raggiunta -> ripartita
 *        aperta -> decaduta
 *
 * REGOLE (dal piano, sezione 5):
 *
 *  1. Aprire una transazione e leggere la riga campagne con FOR UPDATE.
 *     Senza il blocco, due adesioni simultanee leggono lo stesso totale
 *     e il controllo della soglia salta.
 *
 *  2. Calcolare:
 *       $totale  = SUM(partecipazioni.latte_richieste)
 *       $scatole = intdiv($totale, $prodotto['latte_per_scatola'])
 *       $scaduta = adesso > campagne.scadenza
 *
 *  3. Decidere il nuovo stato RICALCOLANDO SEMPRE DA ZERO,
 *     mai per incrementi:
 *       - se lo stato attuale e' 'ripartita'  -> resta 'ripartita' (terminale)
 *       - se scaduta   -> $scatole >= soglia ? 'soglia_raggiunta' : 'decaduta'
 *       - se non scaduta -> $scatole >= soglia ? 'soglia_raggiunta' : 'aperta'
 *
 *     Ricalcolare da zero e' cio' che fa funzionare il caso al contrario:
 *     uno si ritira, il totale scende sotto la soglia, la campagna torna
 *     'aperta'. E' il caso che si dimentica sempre.
 *
 *  4. Se il nuovo stato e' diverso dal vecchio: UPDATE campagne
 *     + INSERT in log_eventi con stato_da, stato_a e un motivo leggibile.
 *
 *  5. commit() e restituire il nuovo stato.
 */
function ricalcola_stato(int $campagna_id): string
{
    // TODO(B): implementare seguendo i 5 punti qui sopra.
    // Provvisorio: restituisce lo stato attuale senza toccarlo,
    // cosi' il resto dell'API funziona gia' mentre lo scrivo.
    $st = db()->prepare('SELECT stato FROM campagne WHERE id = ?');
    $st->execute([$campagna_id]);
    $r = $st->fetch();
    if (!$r) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);
    return $r['stato'];
}

/**
 * Registra una transizione. Usarla dentro ricalcola_stato().
 */
function registra_evento(int $campagna_id, ?string $da, string $a, string $motivo): void
{
    db()->prepare(
        'INSERT INTO log_eventi (campagna_id, stato_da, stato_a, motivo, eseguito_da)
         VALUES (?,?,?,?,?)'
    )->execute([$campagna_id, $da, $a, $motivo, utente_corrente_id()]);
}
