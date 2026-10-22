<?php
declare(strict_types=1);

/**
 * Calcoli di prezzo/saldo condivisi: funzioni pure, senza database.
 * Gli scaglioni sono [['soglia' => int pezzi, 'prezzo' => float], ...] ordinati per soglia crescente.
 */

/**
 * UNICA formula del saldo (chiusura, retry, stime, email):
 *   saldo = prezzo finale x quantita + commissione BuyPool + spedizione (solo se scelta) - acconto gia' versato.
 * La commissione e' la percentuale del gruppo sul totale merce. Ogni voce e' arrotondata al centesimo,
 * cosi' le voci mostrate al cliente sommano esattamente al saldo addebitato. Il saldo puo' essere <= 0.
 *
 * @return array{merce:float,commissione:float,spedizione:float,totale:float,acconto:float,saldo:float}
 */
function saldo_dettaglio(int $quantita, float $prezzoUnitario, float $commissionePct, float $spedizione, float $accontoVersato): array
{
    $merce = round($quantita * $prezzoUnitario, 2);
    $commissione = round($merce * $commissionePct / 100, 2);
    $spedizione = round(max(0.0, $spedizione), 2);
    $totale = round($merce + $commissione + $spedizione, 2);
    $acconto = round($accontoVersato, 2);
    return [
        'merce' => $merce, 'commissione' => $commissione, 'spedizione' => $spedizione,
        'totale' => $totale, 'acconto' => $acconto, 'saldo' => round($totale - $acconto, 2),
    ];
}

/** Saldo di chiusura (solo l'importo). Con $spedizione = 0 equivale alla formula storica. */
function saldo_chiusura(int $quantita, float $prezzoUnitario, float $commissionePct, float $accontoVersato, float $spedizione = 0.0): float
{
    return saldo_dettaglio($quantita, $prezzoUnitario, $commissionePct, $spedizione, $accontoVersato)['saldo'];
}

/** Costo della spedizione a domicilio da config (`costo_spedizione`); 0 se assente. Mai negativo. */
function costo_spedizione_config(): float
{
    $file = __DIR__ . '/../config.php';
    $cfg = file_exists($file) ? require $file : [];
    return round(max(0.0, (float)(is_array($cfg) ? ($cfg['costo_spedizione'] ?? 0) : 0)), 2);
}

/** Spedizione da addebitare per una modalita' di consegna: solo `consegna_domicilio` costa. */
function spedizione_per_modalita(?string $modalita, float $importoSalvato): float
{
    return $modalita === 'consegna_domicilio' ? round(max(0.0, $importoSalvato), 2) : 0.0;
}

/** Prezzo dello scaglione piu' alto raggiunto da $totale pezzi (default: prezzo corrente della campagna). */
function prezzo_scaglione_raggiunto(array $scaglioni, int $totale, float $prezzoCorrente): float
{
    $prezzo = $prezzoCorrente;
    foreach ($scaglioni as $s) {
        if ($totale >= $s['soglia']) $prezzo = (float)$s['prezzo'];
    }
    return $prezzo;
}

/**
 * Prezzo da usare per STIMARE il saldo di un gruppo ancora aperto:
 * minimo gia' raggiunto -> scaglione corrente; altrimenti il PRIMO scaglione
 * (quello che si applica nel momento in cui il minimo viene raggiunto).
 */
function prezzo_per_stima_saldo(array $scaglioni, int $totale, float $prezzoCorrente): float
{
    if (!$scaglioni) return $prezzoCorrente;
    if ($totale >= $scaglioni[0]['soglia']) {
        return prezzo_scaglione_raggiunto($scaglioni, $totale, $prezzoCorrente);
    }
    return (float)$scaglioni[0]['prezzo'];
}

/**
 * Obiettivo mostrato dalla barra di avanzamento: UN solo traguardo alla volta.
 * tipo: 'attivazione' (prima del primo scaglione), 'prezzo' (verso il prossimo scaglione),
 *       'massimo' (ultimo scaglione raggiunto, barra piena).
 * Senza scaglioni l'unico traguardo e' la quantita minima.
 * @return array{tipo:string,soglia:int,mancano:int,prezzo:float,pct:float}
 */
function obiettivo_barra(array $scaglioni, int $quantitaMinima, int $totale, float $prezzoCorrente): array
{
    $lista = $scaglioni ?: [['soglia' => max(1, $quantitaMinima), 'prezzo' => $prezzoCorrente]];
    $primo = $lista[0];
    if ($totale < $primo['soglia']) {
        return ['tipo' => 'attivazione', 'soglia' => (int)$primo['soglia'], 'mancano' => (int)$primo['soglia'] - $totale,
                'prezzo' => (float)$primo['prezzo'], 'pct' => $primo['soglia'] > 0 ? $totale / $primo['soglia'] : 0.0];
    }
    foreach ($lista as $s) {
        if ($totale < $s['soglia']) {
            return ['tipo' => 'prezzo', 'soglia' => (int)$s['soglia'], 'mancano' => (int)$s['soglia'] - $totale,
                    'prezzo' => (float)$s['prezzo'], 'pct' => $totale / $s['soglia']];
        }
    }
    $ultimo = $lista[count($lista) - 1];
    return ['tipo' => 'massimo', 'soglia' => (int)$ultimo['soglia'], 'mancano' => 0,
            'prezzo' => (float)$ultimo['prezzo'], 'pct' => 1.0];
}

/** Sconto massimo rispetto al listino, in %: stessa regola di scontoMax() in home.js (0 se manca il listino). */
function sconto_massimo(array $scaglioni, float $listino): int
{
    if ($listino <= 0 || !$scaglioni) return 0;
    $ultimo = (float)$scaglioni[count($scaglioni) - 1]['prezzo'];
    return $ultimo > 0 ? (int)round((1 - $ultimo / $listino) * 100) : 0;
}

/** Risparmio in % = risparmio / (speso + risparmio), intero; null se il denominatore non e' positivo. */
function percentuale_risparmio(float $risparmio, float $speso): ?int
{
    $den = $speso + $risparmio;
    return $den > 0 ? (int)round($risparmio / $den * 100) : null;
}
