<?php
declare(strict_types=1);

/**
 * ============================================================
 *  MACCHINA A STATI DELLE COLLETTE
 *  (schema di Abdu: database collette_acquisto_gruppo)
 * ============================================================
 *
 * Va richiamata dopo OGNI prenotazione, OGNI annullamento e a OGNI
 * lettura di una colletta. Niente cron: la scadenza si verifica in
 * lettura.
 *
 * Stati AUTOMATICI (li gestisce questa funzione):
 *     in_corso  -> riuscita   se quantita_attuale >= quantita_minima
 *     in_corso  -> fallita    se scaduta e soglia non raggiunta
 *     riuscita  -> in_corso   caso "al contrario": qualcuno si ritira e
 *                             si torna sotto soglia prima dell'ordine
 *
 * Stati MANUALI (decisi dall'admin, questa funzione NON li tocca):
 *     ordine_fornitore, consegnata, annullata
 *
 * VERITA' sulla quantita: e' la SOMMA delle prenotazioni ATTIVE
 * (stato prenotata/confermata). La colonna collette.quantita_attuale
 * viene mantenuta allineata qui, cosi' l'admin la legge senza ricalcolare.
 *
 * Il ricalcolo parte SEMPRE da zero, mai per incrementi: e' cio' che fa
 * funzionare il caso al contrario, quello che ci si dimentica sempre.
 */
function ricalcola_stato(int $colletta_id): string
{
    $pdo = db();
    $devo_gestire_tx = !$pdo->inTransaction();
    if ($devo_gestire_tx) $pdo->beginTransaction();

    try {
        // Blocco la riga: senza, due prenotazioni simultanee leggono lo
        // stesso totale e il controllo della soglia salta.
        $st = $pdo->prepare(
            'SELECT stato, quantita_minima, data_limite
               FROM collette WHERE id_colletta = ? FOR UPDATE');
        $st->execute([$colletta_id]);
        $c = $st->fetch();
        if (!$c) {
            if ($devo_gestire_tx) $pdo->rollBack();
            throw new AppError('COLLETTA_INESISTENTE', 'Colletta non trovata', 404);
        }

        $s = $pdo->prepare(
            "SELECT COALESCE(SUM(quantita),0) AS somma
               FROM prenotazioni
              WHERE id_colletta = ? AND stato IN ('prenotata','confermata')");
        $s->execute([$colletta_id]);
        $somma = (int)$s->fetch()['somma'];

        // quantita_attuale sempre allineata alla somma reale
        $pdo->prepare('UPDATE collette SET quantita_attuale = ? WHERE id_colletta = ?')
            ->execute([$somma, $colletta_id]);

        $stato_attuale = (string)$c['stato'];
        $manuali = ['ordine_fornitore', 'consegnata', 'annullata'];

        if (in_array($stato_attuale, $manuali, true)) {
            if ($devo_gestire_tx) $pdo->commit();
            return $stato_attuale;
        }

        $raggiunta = $somma >= (int)$c['quantita_minima'];
        $scaduta   = strtotime((string)$c['data_limite']) < time();

        if ($raggiunta)   $nuovo = 'riuscita';
        elseif ($scaduta) $nuovo = 'fallita';
        else              $nuovo = 'in_corso';

        if ($nuovo !== $stato_attuale) {
            $pdo->prepare('UPDATE collette SET stato = ?, data_agg_stato = NOW() WHERE id_colletta = ?')
                ->execute([$nuovo, $colletta_id]);
        }

        if ($devo_gestire_tx) $pdo->commit();
        return $nuovo;

    } catch (Throwable $e) {
        if ($devo_gestire_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Cambio di stato MANUALE da parte dell'admin (ordine_fornitore,
 * consegnata, annullata). Gli stati automatici passano da ricalcola_stato().
 */
function imposta_stato_manuale(int $colletta_id, string $nuovo): void
{
    $ammessi = ['ordine_fornitore', 'consegnata', 'annullata'];
    if (!in_array($nuovo, $ammessi, true)) {
        throw new AppError('STATO_NON_VALIDO',
            'Stato manuale ammesso: ' . implode(', ', $ammessi));
    }
    $st = db()->prepare(
        'UPDATE collette SET stato = ?, data_agg_stato = NOW() WHERE id_colletta = ?');
    $st->execute([$nuovo, $colletta_id]);
    if ($st->rowCount() === 0) {
        // rowCount 0 puo' voler dire "stato gia' uguale": verifico esistenza
        $e = db()->prepare('SELECT 1 FROM collette WHERE id_colletta = ?');
        $e->execute([$colletta_id]);
        if (!$e->fetch()) throw new AppError('COLLETTA_INESISTENTE', 'Colletta non trovata', 404);
    }
}
