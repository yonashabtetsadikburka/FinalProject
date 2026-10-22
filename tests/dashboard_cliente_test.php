<?php
declare(strict_types=1);

/** Test standalone di prezzi, stima del saldo e consigliati. Uso: php tests/dashboard_cliente_test.php */
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/stato.php';
require __DIR__ . '/../api/lib/prezzi.php';
require __DIR__ . '/../api/lib/aumento.php';
require __DIR__ . '/../api/lib/acquisti.php';
require __DIR__ . '/../api/lib/consigliati.php';
require __DIR__ . '/../api/endpoints/dashboard.php';

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

// ---- saldo_chiusura ----
uguale('saldo: 5 pz x 10 EUR, comm 10%, acconto 2 -> 53', saldo_chiusura(5, 10.0, 10.0, 2.0), 53.0);
uguale('saldo: senza commissione', saldo_chiusura(3, 20.0, 0.0, 12.0), 48.0);
uguale('saldo: acconto > totale -> negativo', saldo_chiusura(1, 1.0, 0.0, 5.0), -4.0);
uguale('saldo: arrotondamento a 2 decimali', saldo_chiusura(3, 3.333, 10.0, 0.0), 11.0);

// ---- prezzo_scaglione_raggiunto / stima ----
$sc = [['soglia' => 10, 'prezzo' => 100.0], ['soglia' => 30, 'prezzo' => 90.0], ['soglia' => 60, 'prezzo' => 80.0]];
uguale('scaglione raggiunto: nessuno -> prezzo corrente', prezzo_scaglione_raggiunto($sc, 5, 120.0), 120.0);
uguale('scaglione raggiunto: il piu\' alto', prezzo_scaglione_raggiunto($sc, 35, 120.0), 90.0);
uguale('scaglione raggiunto: soglia esatta', prezzo_scaglione_raggiunto($sc, 60, 120.0), 80.0);
uguale('stima: minimo NON raggiunto -> primo scaglione', prezzo_per_stima_saldo($sc, 5, 120.0), 100.0);
uguale('stima: minimo raggiunto -> scaglione corrente', prezzo_per_stima_saldo($sc, 35, 120.0), 90.0);
uguale('stima: senza scaglioni -> prezzo corrente', prezzo_per_stima_saldo([], 5, 120.0), 120.0);

// ---- percentuale risparmio ----
uguale('risparmio %: 14', percentuale_risparmio(50.0, 307.0), 14);
uguale('risparmio %: denominatore 0 -> null', percentuale_risparmio(0.0, 0.0), null);
uguale('risparmio %: solo risparmio -> 100', percentuale_risparmio(10.0, 0.0), 100);

// ---- sconto massimo (stessa regola di home.js) ----
uguale('sconto: ultimo scaglione 80 su listino 100 -> 20', sconto_massimo($sc, 100.0), 20);
uguale('sconto: senza listino -> 0', sconto_massimo($sc, 0.0), 0);
uguale('sconto: senza scaglioni -> 0', sconto_massimo([], 100.0), 0);

// ---- obiettivo barra: tre casi ----
$o = obiettivo_barra($sc, 10, 4, 120.0);
verifica('barra: prima del primo scaglione = attivazione', $o['tipo'] === 'attivazione' && $o['soglia'] === 10 && $o['mancano'] === 6 && abs($o['pct'] - 0.4) < 1e-9);
$o = obiettivo_barra($sc, 10, 12, 120.0);
verifica('barra: primo raggiunto = verso il secondo (prezzo)', $o['tipo'] === 'prezzo' && $o['soglia'] === 30 && $o['mancano'] === 18 && $o['prezzo'] === 90.0, json_encode($o));
$o = obiettivo_barra($sc, 10, 59, 120.0);
verifica('barra: penultimo -> verso l\'ultimo', $o['tipo'] === 'prezzo' && $o['soglia'] === 60 && $o['mancano'] === 1);
$o = obiettivo_barra($sc, 10, 60, 120.0);
verifica('barra: ultimo raggiunto = massimo, piena', $o['tipo'] === 'massimo' && $o['pct'] === 1.0 && $o['mancano'] === 0);
$o = obiettivo_barra($sc, 10, 500, 120.0);
verifica('barra: oltre l\'ultimo resta massimo', $o['tipo'] === 'massimo' && $o['mancano'] === 0);
$o = obiettivo_barra([], 8, 3, 50.0);
verifica('barra: senza scaglioni usa la quantita minima', $o['tipo'] === 'attivazione' && $o['soglia'] === 8 && $o['mancano'] === 5);
$o = obiettivo_barra([], 8, 8, 50.0);
verifica('barra: senza scaglioni, minimo raggiunto = massimo', $o['tipo'] === 'massimo');

// ---- Saldo in arrivo (stima su piu' gruppi) ----
function gruppo(int $q, float $acconto, int $attuale, array $scaglioni, string $limite, float $comm = 10.0): array
{
    return ['quantita' => $q, 'importo_acconto' => $acconto, 'quantita_attuale' => $attuale, 'prezzo_corrente' => 120.0,
            'percentuale_commissione' => $comm, 'data_limite' => $limite, 'scaglioni' => $scaglioni];
}
$r = dash_stima_saldo_in_arrivo([]);
verifica('saldo in arrivo: zero gruppi', $r['importo'] === 0.0 && $r['n_gruppi'] === 0 && $r['prossima_scadenza'] === null);

// minimo NON raggiunto: primo scaglione (100). 2 pz x 100 x 1.10 - 20 = 200
$r = dash_stima_saldo_in_arrivo([gruppo(2, 20.0, 5, $sc, '2026-12-01 12:00:00')]);
uguale('saldo in arrivo: minimo non raggiunto -> primo scaglione', $r['importo'], 200.0);

// minimo raggiunto con scaglione 2 (90): 2 x 90 x 1.10 - 20 = 178
$r = dash_stima_saldo_in_arrivo([gruppo(2, 20.0, 35, $sc, '2026-12-01 12:00:00')]);
uguale('saldo in arrivo: minimo raggiunto -> scaglione corrente', $r['importo'], 178.0);

// piu' gruppi + scadenza piu' vicina
$r = dash_stima_saldo_in_arrivo([
    gruppo(2, 20.0, 5, $sc, '2026-12-10 12:00:00'),     // 200
    gruppo(1, 10.0, 35, $sc, '2026-11-25 18:00:00'),    // 1 x 90 x 1.1 - 10 = 89
]);
verifica('saldo in arrivo: somma di piu\' gruppi', $r['importo'] === 289.0 && $r['n_gruppi'] === 2, json_encode($r));
uguale('saldo in arrivo: scadenza piu\' vicina', $r['prossima_scadenza'], '2026-11-25 18:00:00');

// un saldo negativo (acconto > totale) non riduce la somma
$r = dash_stima_saldo_in_arrivo([gruppo(1, 500.0, 5, $sc, '2026-12-01 12:00:00'), gruppo(1, 0.0, 5, $sc, '2026-12-02 12:00:00')]);
uguale('saldo in arrivo: saldo negativo conta come 0', $r['importo'], 110.0);

// ---- consigliati ----
$adesso = 1_800_000_000;
function cand(int $id, string $cat, int $forn, int $attuale, int $limiteTra, int $partecipanti, array $scaglioni = null): array
{
    global $adesso, $sc;
    $scaglioni = $scaglioni ?? $sc;
    return [
        'id_colletta' => $id, 'prodotto' => "P$id", 'categoria' => $cat, 'id_fornitore' => $forn, 'fornitore' => "Forn$forn",
        'quantita_attuale' => $attuale, 'partecipanti' => $partecipanti, 'data_limite' => $adesso + $limiteTra,
        'obiettivo' => obiettivo_barra($scaglioni, 10, $attuale, 120.0),
    ];
}
$giorni = 86400;
$profilo = ['categorie' => ['Elettronica' => true], 'fornitori' => [7 => true], 'partecipati' => [1 => true]];

$cands = [
    cand(1, 'Elettronica', 7, 3, 5 * $giorni, 4),      // gia' partecipato -> escluso
    cand(2, 'Elettronica', 8, 3, 9 * $giorni, 4),      // categoria (+3)
    cand(3, 'Casa', 7, 3, 9 * $giorni, 4),             // fornitore (+2)
    cand(4, 'Casa', 9, 8, 9 * $giorni, 4),             // vicino (80% di 10) (+2)
    cand(5, 'Casa', 9, 3, 2 * $giorni, 2),             // scade entro 72h ma mancano 7 -> NO
    cand(6, 'Sport', 9, 9, 1 * $giorni, 2),            // vicino (+2) e scade presto (+1) = 3
];
$sel = scegli_consigliati($cands, $profilo, $adesso);
$ids = array_column($sel, 'id_colletta');
verifica('consigliati: esclude i gruppi gia\' partecipati', !in_array(1, $ids, true));
uguale('consigliati: ne restituisce 3', count($sel), 3);
$per = array_column($sel, null, 'id_colletta');
uguale('consigliati: il piu\' alto e\' la categoria o il vicino+scade (3 punti, scadenza piu\' vicina prima)', $ids[0], 6);
uguale('consigliati: secondo a 3 punti', $ids[1], 2);
uguale('motivo categoria', $per[2]['motivo'], 'Come i tuoi acquisti: Elettronica');
uguale('motivo fornitore (id 3 e\' terzo con 2 punti, a pari con id 4: scadenza uguale -> ordine stabile)', $ids[2] === 3 || $ids[2] === 4, true);
uguale('motivo del 6: peso maggiore tra vicino(2) e scade(1) = vicino', $per[6]['motivo'], 'Quasi al traguardo');
uguale('punteggio del 6 = 3', $per[6]['punteggio'], 3);

// "scade presto" richiede traguardo raggiungibile
$solo = scegli_consigliati([cand(5, 'Casa', 9, 3, 2 * $giorni, 2)], ['categorie' => [], 'fornitori' => [], 'partecipati' => []], $adesso);
uguale('scade presto ma lontano dal traguardo: nessun punto', $solo[0]['punteggio'], 0);
$solo = scegli_consigliati([cand(5, 'Casa', 9, 6, 2 * $giorni, 2)], ['categorie' => [], 'fornitori' => [], 'partecipati' => []], $adesso);
uguale('scade presto con pochi pezzi mancanti: +1', $solo[0]['punteggio'], 1);
uguale('motivo "Scade presto"', $solo[0]['motivo'], 'Scade presto');

// Utente senza storia: fallback popolari
$senza = ['categorie' => [], 'fornitori' => [], 'partecipati' => []];
$cands2 = [cand(10, 'Casa', 1, 1, 20 * $giorni, 3), cand(11, 'Casa', 2, 2, 20 * $giorni, 50), cand(12, 'Sport', 3, 1, 20 * $giorni, 10), cand(13, 'Sport', 4, 1, 20 * $giorni, 1)];
$sel = scegli_consigliati($cands2, $senza, $adesso);
verifica('senza storia: ordine per popolarita\'', array_column($sel, 'id_colletta') === [11, 12, 10], json_encode(array_column($sel, 'id_colletta')));
uguale('senza storia: motivo "Tra i più popolari"', $sel[0]['motivo'], 'Tra i più popolari');

// meno di 3 candidati con punteggio: completa con il fallback
$misti = [cand(20, 'Elettronica', 5, 1, 20 * $giorni, 1), cand(21, 'Casa', 6, 1, 20 * $giorni, 30), cand(22, 'Casa', 6, 1, 20 * $giorni, 5)];
$sel = scegli_consigliati($misti, ['categorie' => ['Elettronica' => true], 'fornitori' => [], 'partecipati' => []], $adesso);
verifica('misto: prima quello con punteggio, poi i popolari', array_column($sel, 'id_colletta') === [20, 21, 22]);

uguale('nessun candidato -> lista vuota', scegli_consigliati([], $senza, $adesso), []);
uguale('tutti gia\' partecipati -> lista vuota', scegli_consigliati([cand(1, 'Casa', 1, 1, $giorni, 1)], ['categorie' => [], 'fornitori' => [], 'partecipati' => [1 => true]], $adesso), []);

// ---- Contatori della Dashboard = quelli di "I miei acquisti" (stessa classificazione) ----
$scDash = [['soglia' => 10, 'prezzo' => 100.0], ['soglia' => 30, 'prezzo' => 90.0]];
function rigaDash(array $o = []): array
{
    global $scDash;
    return array_merge([
        'id' => 56, 'id_colletta' => 7, 'quantita' => 4, 'stato' => 'prenotata', 'data_prenotazione' => '2026-10-01 10:00:00',
        'importo_acconto' => '20.00', 'quantita_minima' => 10, 'quantita_attuale' => 7, 'data_limite' => '2026-12-01 12:00:00',
        'stato_colletta' => 'in_corso', 'chiusura_data' => null, 'prezzo_base' => '120.00', 'prezzo_corrente' => '100.00',
        'percentuale_commissione' => '10.00', 'scaduta' => 0, 'secondi_a_scadenza' => 1000000, 'prodotto' => 'Lampada Solare',
        'fornitore' => 'Acme', 'fornitore_id' => 3, 'stato_qr' => null, 'data_ritiro' => null, 'consegna_modalita' => null,
        'consegna_stato' => null, 'data_consegna' => null, 'sede_nome' => 'Sede Centrale Perugia', 'sede_indirizzo' => 'Via Assisana 10',
        'sede_citta' => 'Perugia', 'immagine' => null, 'totale_pagato' => '0', 'recensito_prodotto' => 0, 'recensito_fornitore' => 0,
        'scaglioni' => $scDash, 'saldo_scadenza' => null,
    ], $o);
}
function costruisci(array $righe): array { return array_map(fn($r) => acquisto_costruisci($r, 20.0), $righe); }

// 1 adesione a gruppo aperto; chiusura_data valorizzata ma scadenza futura (caso reale dei dati: NON deve azzerare il contatore)
$a = costruisci([rigaDash(['chiusura_data' => '2026-09-29 12:40:27'])]);
uguale('1 adesione a gruppo aperto -> n gruppi in corso = 1', acquisti_conteggi($a)['riepilogo']['gruppi_in_corso'], 1);
uguale('acquisti_in_corso: 1 riga (Lampada Solare, 4 pezzi)', [count(acquisti_in_corso($a)), acquisti_in_corso($a)[0]['quantita']], [1, 4]);
uguale('nessun ritiro pronto, "Da fare" vuoto', acquisti_da_fare($a)['totale'], 0);

// gruppo attivo (minimo raggiunto, in attesa di chiusura) = ancora "in corso"
$a = costruisci([rigaDash(['stato' => 'confermata', 'quantita_attuale' => 12])]);
uguale('gruppo attivo = in corso', acquisti_conteggi($a)['riepilogo']['gruppi_in_corso'], 1);

// ordine inviato al fornitore = in arrivo, NON in corso; ritiro pronto = ritiri_pronti
$a = costruisci([
    rigaDash(['id' => 1, 'stato' => 'pagata', 'stato_colletta' => 'ordine_fornitore', 'quantita_attuale' => 12]),
    rigaDash(['id' => 2, 'id_colletta' => 8, 'stato' => 'pagata', 'stato_colletta' => 'consegnata', 'stato_qr' => 'generato', 'quantita_attuale' => 12, 'prodotto' => 'Zaino']),
    rigaDash(['id' => 3, 'id_colletta' => 9, 'stato' => 'pagata', 'stato_colletta' => 'consegnata', 'stato_qr' => 'generato', 'quantita_attuale' => 12, 'prodotto' => 'Tappetino']),
    rigaDash(['id' => 4, 'id_colletta' => 10]),
    rigaDash(['id' => 5, 'id_colletta' => 11, 'stato' => 'confermata', 'quantita_attuale' => 12]),
]);
$agg = acquisti_conteggi($a);
uguale('2 ritiri pronti e 2 gruppi in corso (inviato al fornitore escluso)', [$agg['riepilogo']['ritiri_pronti'], $agg['riepilogo']['gruppi_in_corso']], [2, 2]);
uguale('i contatori della dashboard coincidono con le schede (In corso 2, In arrivo 3)', [$agg['conteggi']['in-corso'], $agg['conteggi']['in-arrivo']], [2, 3]);
$df = acquisti_da_fare($a);
uguale('"Da fare": 2 ritiri con la sede reale', [count($df['ritiri']), $df['ritiri'][0]['sede_nome'], $df['ritiri'][0]['sede_indirizzo']], [2, 'Sede Centrale Perugia', 'Via Assisana 10']);

// saldo non riuscito -> "Da fare" con la scadenza
$a = costruisci([rigaDash(['stato' => 'azione_richiesta', 'saldo_scadenza' => '2026-10-23 14:30:00'])]);
$df = acquisti_da_fare($a);
uguale('"Da fare": saldo non riuscito con scadenza', [count($df['saldi_non_riusciti']), $df['saldi_non_riusciti'][0]['saldo_scadenza'], $df['totale']], [1, '2026-10-23 14:30:00', 1]);
uguale('un saldo non riuscito non e\' un gruppo in corso', acquisti_conteggi($a)['riepilogo']['gruppi_in_corso'], 0);

// utente senza nulla
uguale('nessuna adesione -> tutto a zero', [acquisti_conteggi([])['riepilogo'], acquisti_da_fare([])['totale'], acquisti_in_corso([])], [['gruppi_in_corso' => 0, 'ritiri_pronti' => 0], 0, []]);

// saldo in arrivo: somma dei saldi previsti delle schede, prima scadenza
$a = costruisci([rigaDash(['id' => 1]), rigaDash(['id' => 2, 'id_colletta' => 8, 'data_limite' => '2026-11-10 10:00:00'])]);
$sa = acquisti_saldo_in_arrivo(acquisti_in_corso($a));
uguale('saldo in arrivo = somma dei saldi previsti', $sa['importo'], round($a[0]['saldo_previsto'] + $a[1]['saldo_previsto'], 2));
uguale('saldo in arrivo: 2 gruppi, scadenza piu\' vicina', [$sa['n_gruppi'], $sa['prossima_scadenza']], [2, '2026-11-10 10:00:00']);


echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test FALLITI\n";
exit($fail === 0 ? 0 : 1);
