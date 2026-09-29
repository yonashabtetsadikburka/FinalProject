<?php
declare(strict_types=1);

/**
 * Minimo effettivo della campagna: primo scaglione se presente, altrimenti MOQ.
 */
function minimo_colletta(array $c, array $scaglioni = []): int
{
    if (!empty($scaglioni) && isset($scaglioni[0]['soglia'])) {
        return max(1, (int)$scaglioni[0]['soglia']);
    }
    return max(1, (int)($c['quantita_minima'] ?? 1));
}

/**
 * Stato campagna per la UI pubblica, SEPARATO dallo stato delle partecipazioni:
 * - 'in_corso': timer attivo, indipendentemente dal minimo (anche con confermate)
 * - 'conclusa': timer scaduto + minimo raggiunto + chiusura elaborata (o stati avanzati)
 * - 'non_riuscita': fallita/annullata, oppure scaduta senza minimo
 * - 'in_chiusura': scaduta + minimo raggiunto ma chiusura non ancora eseguita
 * Non persiste nulla: deriva da stato interno, scadenza e chiusura_data.
 */
function stato_campagna_display(array $c, array $scaglioni = []): string
{
    $stato = $c['stato'] ?? 'in_corso';
    if (in_array($stato, ['ordine_pronto', 'ordine_fornitore', 'consegnata'], true)) return 'conclusa';
    if (in_array($stato, ['fallita', 'annullata'], true)) return 'non_riuscita';
    $ts = strtotime($c['data_limite'] ?? '');
    $scaduta = $ts !== false && $ts < time();
    $raggiunto = ((int)($c['quantita_attuale'] ?? 0)) >= minimo_colletta($c, $scaglioni);
    if (!$scaduta) return 'in_corso';
    if (!$raggiunto) return 'non_riuscita';
    return !empty($c['chiusura_data']) ? 'conclusa' : 'in_chiusura';
}

/**
 * ============================================================
 *  MACCHINA A STATI DELLE CAMPAGNE
 * ============================================================
 *
 * Stati: in_corso -> riuscita -> ordine_pronto -> ordine_fornitore -> consegnata
 *        in_corso -> fallita
 *
 * REGOLE:
 *  1. Transazione con FOR UPDATE per evitare race condition
 *  2. Ricalcolare sempre da zero (non incrementale)
 *  3. Se lo stato e' terminale (consegnata, annullata), non cambiare
 */
function ricalcola_stato(int $campagna_id): string
{
    $pdo = db();

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT id, stato, quantita_attuale, quantita_minima, data_limite
               FROM collette WHERE id = ? FOR UPDATE'
        );
        $st->execute([$campagna_id]);
        $c = $st->fetch();
        if (!$c) {
            if ($ownTransaction) $pdo->rollBack();
            throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);
        }

        $statoAttuale = $c['stato'];

        // Stati terminali: non cambiare
        if (in_array($statoAttuale, ['consegnata', 'annullata'], true)) {
            if ($ownTransaction) $pdo->rollBack();
            return $statoAttuale;
        }

        $totale = (int)$c['quantita_attuale'];
        $soglia = (int)$c['quantita_minima'];
        $scaduta = strtotime($c['data_limite']) < time();

        if (in_array($statoAttuale, ['ordine_pronto', 'ordine_fornitore'], true)) {
            $nuovoStato = $statoAttuale;
        } elseif ($totale >= $soglia) {
            $nuovoStato = 'riuscita';
        } elseif ($scaduta) {
            $nuovoStato = 'fallita';
        } else {
            $nuovoStato = 'in_corso';
        }

        if ($nuovoStato !== $statoAttuale) {
            $stUp = $pdo->prepare(
                'UPDATE collette SET stato = ?, data_agg_stato = NOW() WHERE id = ?'
            );
            $stUp->execute([$nuovoStato, $campagna_id]);
        }

        if ($ownTransaction) $pdo->commit();
        return $nuovoStato;

    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
