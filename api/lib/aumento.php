<?php
declare(strict_types=1);

/**
 * "Aumenta la quantita'" di un'adesione esistente: regole pure (nessun DB, nessuna chiamata Stripe).
 * L'acconto dei pezzi aggiuntivi usa la STESSA formula dell'acconto iniziale (pagamento_prepara_acconto):
 * pezzi x prezzo del primo scaglione x acconto_percent / 100, minimo 0,50 euro.
 */

const AUMENTO_MAX_PEZZI = 99;          // tetto per adesione (come l'adesione iniziale)
const AUMENTO_ACCONTO_MINIMO = 0.50;   // euro

/** Acconto per $k pezzi aggiuntivi (arrotondato a 2 decimali). */
function acconto_aumento(int $k, float $primoPrezzo, float $accontoPercent): float
{
    return round($k * $primoPrezzo * $accontoPercent / 100, 2);
}

/** Acconto per UN pezzo, non arrotondato: serve alla UI per la stima (la UI arrotonda sul totale, come il server). */
function acconto_unitario(float $primoPrezzo, float $accontoPercent): float
{
    return $primoPrezzo * $accontoPercent / 100;
}

/** Stato corretto di una nuova adesione (o di un'adesione che aumenta) in base al gruppo. */
function stato_adesione_per_gruppo(string $statoGruppo): string
{
    // Se il minimo e' gia' raggiunto l'adesione parte 'confermata': altrimenti la chiusura non la addebiterebbe mai.
    return $statoGruppo === 'riuscita' ? 'confermata' : 'prenotata';
}

/**
 * L'aumento e' consentito? Ritorna null se si', altrimenti [codice, messaggio, http].
 * @param array $pren  stato, quantita
 * @param array $coll  stato (gia' ricalcolato), data_limite_ts (unix), chiusura_data (?string)
 */
function aumento_errore(array $pren, array $coll, int $k, int $adesso): ?array
{
    if ($k < 1) {
        return ['QUANTITA_NON_VALIDA', 'Indica quanti pezzi vuoi aggiungere', 422];
    }
    if (!in_array($pren['stato'], ['prenotata', 'confermata'], true)) {
        return ['PRENOTAZIONE_NON_AUMENTABILE', 'Questa adesione non si puo\' piu\' modificare', 409];
    }
    if (!in_array($coll['stato'], ['in_corso', 'riuscita'], true)) {
        return ['CAMPAGNA_NON_APERTA', 'Il gruppo non e\' piu\' aperto', 409];
    }
    if (!empty($coll['chiusura_data'])) {
        return ['CAMPAGNA_CHIUSA', 'Il gruppo si e\' gia\' chiuso', 409];
    }
    if ((int)$coll['data_limite_ts'] <= $adesso) {
        return ['CAMPAGNA_SCADUTA', 'Il gruppo e\' scaduto: non puoi piu\' aggiungere pezzi', 409];
    }
    if ((int)$pren['quantita'] + $k > AUMENTO_MAX_PEZZI) {
        return ['LIMITE_PEZZI', 'Puoi prenotare al massimo ' . AUMENTO_MAX_PEZZI . ' pezzi', 422];
    }
    return null;
}

/** Quanti pezzi si possono ancora aggiungere (0 = nessuno). */
function aumento_max_aggiuntivi(int $quantitaAttuale): int
{
    return max(0, AUMENTO_MAX_PEZZI - $quantitaAttuale);
}
