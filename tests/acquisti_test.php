<?php
declare(strict_types=1);

/** Test standalone di "I miei acquisti" (mappatura stato -> scheda, ordinamento, whitelist). Uso: php tests/acquisti_test.php */
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

$sc = [['soglia' => 10, 'prezzo' => 100.0], ['soglia' => 30, 'prezzo' => 90.0], ['soglia' => 60, 'prezzo' => 80.0]];

/** Riga tipo (come restituita dalla query): sovrascrivi i campi che servono. */
function riga(array $o = []): array
{
    global $sc;
    return array_merge([
        'id' => 56, 'id_colletta' => 7, 'quantita' => 4, 'stato' => 'prenotata', 'data_prenotazione' => '2026-10-01 10:00:00',
        'importo_acconto' => '20.00', 'quantita_minima' => 10, 'quantita_attuale' => 4, 'data_limite' => '2026-12-01 12:00:00',
        'stato_colletta' => 'in_corso', 'chiusura_data' => null, 'prezzo_base' => '120.00', 'prezzo_corrente' => '100.00',
        'percentuale_commissione' => '10.00', 'scaduta' => 0, 'secondi_a_scadenza' => 1000000, 'prodotto' => 'Cuffie',
        'fornitore' => 'Acme', 'fornitore_id' => 3, 'stato_qr' => null, 'data_ritiro' => null, 'consegna_modalita' => null,
        'consegna_stato' => null, 'data_consegna' => null, 'sede_nome' => 'Sede Centrale Perugia', 'immagine' => null,
        'totale_pagato' => '0', 'recensito_prodotto' => 0, 'recensito_fornitore' => 0, 'scaglioni' => $sc,
    ], $o);
}
function vista(array $o): string { return acquisto_classifica(riga($o))['vista']; }
function scheda(array $o): string { return acquisto_classifica(riga($o))['tab']; }

// ---- Mappatura stato -> scheda / vista ----
uguale('prenotata, minimo non raggiunto -> In corso / in_corso', [scheda([]), vista([])], ['in-corso', 'in_corso']);
uguale('prenotata con minimo raggiunto -> attivo', vista(['quantita_attuale' => 12]), 'attivo');
uguale('confermata -> attivo (In corso)', [scheda(['stato' => 'confermata', 'quantita_attuale' => 12]), vista(['stato' => 'confermata', 'quantita_attuale' => 12])], ['in-corso', 'attivo']);
uguale('gruppo scaduto con minimo raggiunto, chiusura da fare -> attivo + chiusura_in_corso',
    [vista(['stato' => 'confermata', 'quantita_attuale' => 12, 'scaduta' => 1]), acquisto_classifica(riga(['stato' => 'confermata', 'quantita_attuale' => 12, 'scaduta' => 1]))['chiusura_in_corso']],
    ['attivo', true]);
uguale('azione_richiesta -> In corso / pagamento', [scheda(['stato' => 'azione_richiesta']), vista(['stato' => 'azione_richiesta'])], ['in-corso', 'pagamento']);
uguale('pagata, colletta ordine_fornitore -> In arrivo / inviato', [scheda(['stato' => 'pagata', 'stato_colletta' => 'ordine_fornitore']), vista(['stato' => 'pagata', 'stato_colletta' => 'ordine_fornitore'])], ['in-arrivo', 'inviato']);
uguale('pagata + QR generato ma colletta NON ancora consegnata -> inviato (non pronto)',
    vista(['stato' => 'pagata', 'stato_qr' => 'generato', 'consegna_modalita' => 'ritiro_sede', 'stato_colletta' => 'ordine_pronto']), 'inviato');
uguale('pagata + QR generato + colletta consegnata -> pronto',
    [scheda(['stato' => 'pagata', 'stato_qr' => 'generato', 'consegna_modalita' => 'ritiro_sede', 'stato_colletta' => 'consegnata']),
     vista(['stato' => 'pagata', 'stato_qr' => 'generato', 'consegna_modalita' => 'ritiro_sede', 'stato_colletta' => 'consegnata'])], ['in-arrivo', 'pronto']);
uguale('QR scansionato -> Conclusi / ritirato', [scheda(['stato' => 'pagata', 'stato_qr' => 'scansionato']), vista(['stato' => 'pagata', 'stato_qr' => 'scansionato'])], ['conclusi', 'ritirato']);
uguale('spedizione consegnata -> ritirato, etichetta "Consegnato"',
    acquisto_classifica(riga(['stato' => 'pagata', 'consegna_modalita' => 'consegna_domicilio', 'consegna_stato' => 'consegnata']))['etichetta'], 'Consegnato');
uguale('spedizione in viaggio -> inviato', vista(['stato' => 'pagata', 'consegna_modalita' => 'consegna_domicilio', 'consegna_stato' => 'spedita', 'stato_colletta' => 'consegnata']), 'inviato');
uguale('rimborsata -> Annullati / rimborsato', [scheda(['stato' => 'rimborsata']), vista(['stato' => 'rimborsata'])], ['annullati', 'rimborsato']);
uguale('annullata -> Annullati / annullato', [scheda(['stato' => 'annullata']), vista(['stato' => 'annullata'])], ['annullati', 'annullato']);
uguale('gruppo fallito con prenotata (rimborso da eseguire) -> rimborsato in elaborazione',
    [scheda(['stato_colletta' => 'fallita']), acquisto_classifica(riga(['stato_colletta' => 'fallita']))['rimborso_in_elaborazione']], ['annullati', true]);
uguale('scaduta sotto il minimo -> rimborsato in elaborazione', [scheda(['scaduta' => 1, 'quantita_attuale' => 3]), vista(['scaduta' => 1, 'quantita_attuale' => 3])], ['annullati', 'rimborsato']);
uguale('rimborsata: non e\' "in elaborazione"', acquisto_classifica(riga(['stato' => 'rimborsata', 'stato_colletta' => 'fallita']))['rimborso_in_elaborazione'], false);
uguale('passi: in corso 3, inviato 5, pronto 6, rimborsato 0',
    [acquisto_classifica(riga())['passo'], acquisto_classifica(riga(['stato' => 'pagata']))['passo'],
     acquisto_classifica(riga(['stato' => 'pagata', 'stato_qr' => 'generato', 'stato_colletta' => 'consegnata']))['passo'], acquisto_classifica(riga(['stato' => 'rimborsata']))['passo']],
    [3, 5, 6, 0]);

// ---- Regola annullamento ----
verifica('annullabile solo se prenotata e minimo non raggiunto', acquisto_costruisci(riga(), 20.0)['puo_annullare'] === true);
$att = acquisto_costruisci(riga(['quantita_attuale' => 12]), 20.0);
verifica('minimo raggiunto: NON annullabile + nota', $att['puo_annullare'] === false && $att['annullo_bloccato'] === true);
verifica('confermata: NON annullabile', acquisto_costruisci(riga(['stato' => 'confermata', 'quantita_attuale' => 12]), 20.0)['puo_annullare'] === false);
verifica('gruppo scaduto: NON annullabile', acquisto_costruisci(riga(['scaduta' => 1]), 20.0)['puo_annullare'] === false);

// ---- Importi (formule di prezzi.php) ----
$a = acquisto_costruisci(riga(), 20.0);
uguale('acconto versato', $a['acconto'], 20.0);
uguale('saldo previsto (minimo non raggiunto: primo scaglione) 4 x 100 x 1,10 - 20', $a['saldo_previsto'], 420.0);
$a = acquisto_costruisci(riga(['quantita_attuale' => 35]), 20.0);
uguale('saldo previsto (scaglione 2, 90) 4 x 90 x 1,10 - 20', $a['saldo_previsto'], 376.0);
// Spedizione a domicilio: entra nel saldo previsto e nelle voci separate
$a = acquisto_costruisci(riga(['quantita_attuale' => 35, 'consegna_modalita' => 'consegna_domicilio', 'importo_consegna' => '4.90']), 20.0);
uguale('saldo previsto con spedizione 4 x 90 x 1,10 + 4,90 - 20', $a['saldo_previsto'], 380.9);
uguale('voci separate: merce, commissione, spedizione', [$a['saldo_dettaglio']['merce'], $a['saldo_dettaglio']['commissione'], $a['saldo_dettaglio']['spedizione']], [360.0, 36.0, 4.9]);
$a = acquisto_costruisci(riga(['quantita_attuale' => 35, 'consegna_modalita' => 'ritiro_sede', 'importo_consegna' => '0']), 20.0);
uguale('ritiro in sede: spedizione 0', $a['saldo_dettaglio']['spedizione'], 0.0);
$a = acquisto_costruisci(riga(['quantita_attuale' => 35]), 20.0);
uguale('data addebito = scadenza del gruppo', $a['data_addebito'], '2026-12-01 12:00:00');
uguale('obiettivo barra: verso il 3o scaglione', [$a['gruppo']['obiettivo']['tipo'], $a['gruppo']['obiettivo']['soglia']], ['prezzo', 60]);
uguale('sottotesto acconto %', $a['acconto_percent'], 20.0);
$a = acquisto_costruisci(riga(['quantita_attuale' => 60]), 20.0);
uguale('ultimo scaglione -> massimo', $a['gruppo']['obiettivo']['tipo'], 'massimo');

$c = acquisto_costruisci(riga(['stato' => 'pagata', 'quantita_attuale' => 35, 'totale_pagato' => '396.00', 'stato_colletta' => 'ordine_fornitore']), 20.0);
uguale('totale pagato da pagamenti confermati', $c['totale_pagato'], 396.0);
uguale('risparmiato = (listino 120 - prezzo applicato 90) x 4', $c['risparmiato'], 120.0);
$c = acquisto_costruisci(riga(['stato' => 'pagata', 'prezzo_base' => null, 'totale_pagato' => '396.00']), 20.0);
uguale('risparmiato assente senza listino', $c['risparmiato'], null);
$c = acquisto_costruisci(riga(['stato' => 'pagata', 'totale_pagato' => '0']), 20.0);
uguale('risparmiato assente se nulla pagato', $c['risparmiato'], null);
verifica('nessun saldo previsto sugli acquisti non aperti', !isset(acquisto_costruisci(riga(['stato' => 'pagata']), 20.0)['saldo_previsto']));

// ---- Recensione e rimborso ----
$r = acquisto_costruisci(riga(['stato' => 'pagata', 'stato_qr' => 'scansionato', 'data_ritiro' => '2026-11-02 15:30:00', 'recensito_prodotto' => 1]), 20.0);
verifica('ritirato: recensione gia\' fatta', $r['recensione']['prodotto_fatta'] === true && $r['recensione']['fornitore_fatta'] === false);
uguale('ritirato: data ritiro', $r['ritiro']['data'], '2026-11-02 15:30:00');
$rb = acquisto_costruisci(riga(['stato' => 'rimborsata', 'stato_colletta' => 'fallita', 'quantita_attuale' => 3, 'quantita_minima' => 6, 'scaglioni' => [], 'chiusura_data' => '2026-10-12 09:00:00', 'data_limite' => '2026-10-12 00:00:00']), 20.0);
uguale('rimborso: dati per la motivazione', [$rb['rimborso']['pezzi'], $rb['rimborso']['minimo'], $rb['rimborso']['scadenza'], $rb['rimborso']['importo'], $rb['rimborso']['data']],
    [3, 6, '2026-10-12 00:00:00', 20.0, '2026-10-12 09:00:00']);
$rp = acquisto_costruisci(riga(['stato_colletta' => 'fallita']), 20.0);
verifica('rimborso in elaborazione: nessuna data', $rp['rimborso']['in_elaborazione'] === true && $rp['rimborso']['data'] === null);

// ---- Conteggi, tab predefinita, whitelist, ordinamento ----
$tutte = [
    acquisto_costruisci(riga(['id' => 1]), 20.0),                                                                          // in_corso
    acquisto_costruisci(riga(['id' => 2, 'quantita_attuale' => 12, 'secondi_a_scadenza' => 500]), 20.0),                   // attivo, scade prima
    acquisto_costruisci(riga(['id' => 3, 'stato' => 'azione_richiesta']), 20.0),                                           // pagamento
    acquisto_costruisci(riga(['id' => 4, 'stato' => 'pagata', 'stato_qr' => 'generato', 'stato_colletta' => 'consegnata', 'data_prenotazione' => '2026-09-01 10:00:00']), 20.0), // pronto
    acquisto_costruisci(riga(['id' => 5, 'stato' => 'pagata', 'stato_colletta' => 'ordine_fornitore', 'data_prenotazione' => '2026-10-20 10:00:00']), 20.0),                   // inviato
    acquisto_costruisci(riga(['id' => 6, 'stato' => 'rimborsata']), 20.0),
];
$agg = acquisti_conteggi($tutte);
uguale('conteggi per scheda', $agg['conteggi'], ['in-corso' => 3, 'in-arrivo' => 2, 'conclusi' => 0, 'annullati' => 1]);
uguale('riepilogo (gruppi in corso, ritiri pronti)', $agg['riepilogo'], ['gruppi_in_corso' => 2, 'ritiri_pronti' => 1]);

uguale('tab predefinita: In corso se piena', acquisti_tab_predefinito(['in-corso' => 2, 'in-arrivo' => 5, 'conclusi' => 0, 'annullati' => 0]), 'in-corso');
uguale('tab predefinita: In arrivo se In corso e\' vuota', acquisti_tab_predefinito(['in-corso' => 0, 'in-arrivo' => 1, 'conclusi' => 3, 'annullati' => 0]), 'in-arrivo');
uguale('tab predefinita: la prima non vuota', acquisti_tab_predefinito(['in-corso' => 0, 'in-arrivo' => 0, 'conclusi' => 0, 'annullati' => 2]), 'annullati');
uguale('tab predefinita: tutto vuoto -> In corso', acquisti_tab_predefinito(['in-corso' => 0, 'in-arrivo' => 0, 'conclusi' => 0, 'annullati' => 0]), 'in-corso');

uguale('whitelist tab: valida', acquisti_tab_valido('conclusi'), 'conclusi');
uguale('whitelist tab: invalida -> null', acquisti_tab_valido('../etc/passwd'), null);
uguale('whitelist tab: array -> null', acquisti_tab_valido(['x']), null);
uguale('whitelist tab: SQL injection -> null', acquisti_tab_valido("in-corso' OR 1=1"), null);
uguale('sort predefinito In corso = scadenza', acquisti_sort_valido(null, 'in-corso'), 'scadenza');
uguale('sort predefinito altre schede = recenti', acquisti_sort_valido('boh', 'conclusi'), 'recenti');
uguale('sort valido', acquisti_sort_valido('recenti', 'in-corso'), 'recenti');

$inCorso = array_values(array_filter($tutte, fn($a) => $a['tab'] === 'in-corso'));
$ord = array_column(acquisti_ordina($inCorso, 'in-corso', 'scadenza'), 'id');
uguale('In corso, per scadenza: pagamento in cima, poi la scadenza piu\' vicina', $ord, [3, 2, 1]);
$inArrivo = array_values(array_filter($tutte, fn($a) => $a['tab'] === 'in-arrivo'));
uguale('In arrivo: ritiro pronto sempre in cima anche se piu\' vecchio', array_column(acquisti_ordina($inArrivo, 'in-arrivo', 'recenti'), 'id'), [4, 5]);

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test FALLITI\n";
exit($fail === 0 ? 0 : 1);
