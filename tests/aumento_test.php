<?php
declare(strict_types=1);

/** Test standalone delle regole di "Aumenta la quantita'". Uso: php tests/aumento_test.php */
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/stato.php';
require __DIR__ . '/../api/lib/prezzi.php';
require __DIR__ . '/../api/lib/aumento.php';
require __DIR__ . '/../api/lib/acquisti.php';

$fail = 0;
function verifica(string $nome, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $nome . ($ok || $extra === '' ? '' : "  ($extra)") . "\n";
    if (!$ok) $fail++;
}
function uguale(string $nome, $ottenuto, $atteso): void
{
    verifica($nome, $ottenuto === $atteso, 'ottenuto ' . var_export($ottenuto, true) . ', atteso ' . var_export($atteso, true));
}
function codice(?array $e): ?string { return $e[0] ?? null; }

$adesso = 1_800_000_000;
$pren = ['stato' => 'prenotata', 'quantita' => 4];
$coll = ['stato' => 'in_corso', 'data_limite_ts' => $adesso + 86400, 'chiusura_data' => null];

// ---- acconto ----
uguale('acconto: 3 pezzi x 100 x 20% = 60', acconto_aumento(3, 100.0, 20.0), 60.0);
uguale('acconto: arrotondamento a 2 decimali', acconto_aumento(1, 33.33, 20.0), 6.67);
uguale('acconto: come l\'acconto iniziale (4 x 100 x 20%)', acconto_aumento(4, 100.0, 20.0), 80.0);
verifica('acconto sotto il minimo di 0,50 si riconosce', acconto_aumento(1, 1.0, 20.0) < AUMENTO_ACCONTO_MINIMO);
uguale('acconto unitario non arrotondato', acconto_unitario(33.33, 20.0), 33.33 * 20.0 / 100);
uguale('totale UI = totale server (round su K x unitario)', round(3 * acconto_unitario(33.33, 20.0), 2), acconto_aumento(3, 33.33, 20.0));

// ---- stato adesione in base al gruppo ----
uguale('gruppo in corso -> prenotata', stato_adesione_per_gruppo('in_corso'), 'prenotata');
uguale('gruppo riuscita -> confermata (altrimenti la chiusura non la addebita)', stato_adesione_per_gruppo('riuscita'), 'confermata');

// ---- aumento consentito / rifiuti ----
uguale('aumento valido (prenotata, gruppo aperto)', aumento_errore($pren, $coll, 2, $adesso), null);
uguale('aumento valido (confermata, gruppo riuscita)', aumento_errore(['stato' => 'confermata', 'quantita' => 4], array_merge($coll, ['stato' => 'riuscita']), 2, $adesso), null);
uguale('0 pezzi rifiutato', codice(aumento_errore($pren, $coll, 0, $adesso)), 'QUANTITA_NON_VALIDA');
uguale('pezzi negativi rifiutati', codice(aumento_errore($pren, $coll, -3, $adesso)), 'QUANTITA_NON_VALIDA');
foreach (['pagata', 'azione_richiesta', 'rimborsata', 'annullata'] as $s) {
    uguale("adesione $s rifiutata", codice(aumento_errore(['stato' => $s, 'quantita' => 4], $coll, 1, $adesso)), 'PRENOTAZIONE_NON_AUMENTABILE');
}
foreach (['fallita', 'annullata', 'ordine_pronto', 'ordine_fornitore', 'consegnata'] as $s) {
    uguale("gruppo $s rifiutato", codice(aumento_errore($pren, array_merge($coll, ['stato' => $s]), 1, $adesso)), 'CAMPAGNA_NON_APERTA');
}
uguale('gruppo gia\' chiuso (chiusura_data) rifiutato', codice(aumento_errore($pren, array_merge($coll, ['chiusura_data' => '2026-10-01 10:00:00']), 1, $adesso)), 'CAMPAGNA_CHIUSA');
uguale('gruppo scaduto rifiutato', codice(aumento_errore($pren, array_merge($coll, ['data_limite_ts' => $adesso - 1]), 1, $adesso)), 'CAMPAGNA_SCADUTA');
uguale('scadenza esattamente adesso rifiutata', codice(aumento_errore($pren, array_merge($coll, ['data_limite_ts' => $adesso]), 1, $adesso)), 'CAMPAGNA_SCADUTA');
uguale('tetto 99: 4 + 95 = 99 ok', aumento_errore($pren, $coll, 95, $adesso), null);
uguale('tetto 99: 4 + 96 = 100 rifiutato', codice(aumento_errore($pren, $coll, 96, $adesso)), 'LIMITE_PEZZI');
uguale('max aggiuntivi', [aumento_max_aggiuntivi(4), aumento_max_aggiuntivi(99), aumento_max_aggiuntivi(120)], [95, 0, 0]);

// ---- puo_aumentare nella scheda "I miei acquisti" ----
function riga(array $o = []): array
{
    return array_merge([
        'id' => 56, 'id_colletta' => 7, 'quantita' => 4, 'stato' => 'prenotata', 'data_prenotazione' => '2026-10-01 10:00:00',
        'importo_acconto' => '20.00', 'quantita_minima' => 10, 'quantita_attuale' => 4, 'data_limite' => '2026-12-01 12:00:00',
        'stato_colletta' => 'in_corso', 'chiusura_data' => null, 'prezzo_base' => '120.00', 'prezzo_corrente' => '100.00',
        'percentuale_commissione' => '10.00', 'scaduta' => 0, 'secondi_a_scadenza' => 1000000, 'prodotto' => 'Cuffie',
        'fornitore' => 'Acme', 'fornitore_id' => 3, 'stato_qr' => null, 'data_ritiro' => null, 'consegna_modalita' => null,
        'consegna_stato' => null, 'data_consegna' => null, 'sede_nome' => 'Sede', 'immagine' => null,
        'totale_pagato' => '0', 'recensito_prodotto' => 0, 'recensito_fornitore' => 0,
        'scaglioni' => [['soglia' => 10, 'prezzo' => 100.0], ['soglia' => 30, 'prezzo' => 90.0]],
    ], $o);
}
$a = acquisto_costruisci(riga(), 20.0);
verifica('gruppo in corso: puo\' aumentare', $a['puo_aumentare'] === true && $a['max_aggiuntivi'] === 95);
uguale('acconto per pezzo = primo scaglione x %', $a['acconto_unitario'], 100.0 * 20.0 / 100);
verifica('gruppo attivo (minimo raggiunto): puo\' aumentare', acquisto_costruisci(riga(['stato' => 'confermata', 'quantita_attuale' => 12]), 20.0)['puo_aumentare'] === true);
verifica('gruppo scaduto: NON puo\' aumentare', acquisto_costruisci(riga(['scaduta' => 1]), 20.0)['puo_aumentare'] === false);
verifica('99 pezzi: NON puo\' aumentare', acquisto_costruisci(riga(['quantita' => 99]), 20.0)['puo_aumentare'] === false);
verifica('pagamento non riuscito: NON puo\' aumentare', acquisto_costruisci(riga(['stato' => 'azione_richiesta']), 20.0)['puo_aumentare'] === false);
verifica('ordine inviato: NON puo\' aumentare', acquisto_costruisci(riga(['stato' => 'pagata']), 20.0)['puo_aumentare'] === false);

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test FALLITI\n";
exit($fail === 0 ? 0 : 1);
