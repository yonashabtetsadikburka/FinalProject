<?php
declare(strict_types=1);

/**
 * Test standalone del calcolo di acconto e saldo (funzioni pure, senza database).
 * Uso: php tests/saldo_test.php
 */
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/stato.php';
require __DIR__ . '/../api/lib/prezzi.php';
require __DIR__ . '/../api/lib/aumento.php';

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

// ---- acconto: 20% del PRIMO scaglione, senza commissione ne' spedizione, arrotondato sul totale ----
uguale('acconto: 1 pezzo x 30,99 x 20% = 6,20', acconto_aumento(1, 30.99, 20.0), 6.2);
uguale('acconto: 3 pezzi x 29,99 x 20% = 17,99 (arrotondo sul totale)', acconto_aumento(3, 29.99, 20.0), 17.99);

// ---- esempio del brief: 3 pezzi, prezzo finale 28,99, commissione 10%, acconto 17,99 ----
$senza = saldo_dettaglio(3, 28.99, 10.0, 0.0, 17.99);
uguale('merce 3 x 28,99', $senza['merce'], 86.97);
uguale('commissione 10% sul merce', $senza['commissione'], 8.7);
uguale('totale senza spedizione', $senza['totale'], 95.67);
uguale('saldo senza spedizione = 77,68', $senza['saldo'], 77.68);

$con = saldo_dettaglio(3, 28.99, 10.0, 4.90, 17.99);
uguale('spedizione nel dettaglio', $con['spedizione'], 4.9);
uguale('totale con spedizione', $con['totale'], 100.57);
uguale('saldo con spedizione = 82,58', $con['saldo'], 82.58);
uguale('le voci sommano al saldo (merce+comm+sped-acconto)', round($con['merce'] + $con['commissione'] + $con['spedizione'] - $con['acconto'], 2), $con['saldo']);

// ---- saldo_chiusura: stessa formula, spedizione opzionale ----
uguale('saldo_chiusura senza spedizione = formula storica', saldo_chiusura(3, 28.99, 10.0, 17.99), 77.68);
uguale('saldo_chiusura con spedizione', saldo_chiusura(3, 28.99, 10.0, 17.99, 4.90), 82.58);
uguale('spedizione negativa ignorata', saldo_chiusura(3, 28.99, 10.0, 17.99, -5.0), 77.68);

// ---- arrotondamenti ----
$r = saldo_dettaglio(1, 33.33, 10.0, 0.0, 6.67);
uguale('arrotondamento commissione 33,33 x 10% = 3,33', $r['commissione'], 3.33);
uguale('arrotondamento saldo 1 x 33,33', $r['saldo'], 29.99);
uguale('commissione 0% = 0', saldo_dettaglio(2, 10.0, 0.0, 0.0, 4.0)['commissione'], 0.0);

// ---- saldo <= 0: l'acconto copre tutto (nessun addebito) ----
verifica('saldo <= 0 quando l\'acconto supera il totale', saldo_dettaglio(1, 1.0, 0.0, 0.0, 5.0)['saldo'] <= 0);

// ---- spedizione per modalita' ----
uguale('ritiro in sede: spedizione 0 anche con importo salvato', spedizione_per_modalita('ritiro_sede', 4.9), 0.0);
uguale('domicilio: usa l\'importo salvato', spedizione_per_modalita('consegna_domicilio', 4.9), 4.9);
uguale('modalita mancante: 0', spedizione_per_modalita(null, 4.9), 0.0);
verifica('costo_spedizione_config mai negativo', costo_spedizione_config() >= 0.0);

// ---- aumento quantita': solo la differenza di acconto (regola invariata) ----
uguale('aumento: +2 pezzi a 30,99 x 20% = 12,40', acconto_aumento(2, 30.99, 20.0), 12.4);

// ---- rimborso: solo acconti (commissione e spedizione non sono mai state addebitate) ----
$acconto1 = acconto_aumento(3, 29.99, 20.0);
$aumento = acconto_aumento(1, 29.99, 20.0);
uguale('rimborso = somma degli acconti versati (iniziale + aumento)', round($acconto1 + $aumento, 2), 23.99);
$d = saldo_dettaglio(4, 28.99, 10.0, 4.90, round($acconto1 + $aumento, 2));
verifica('il rimborso non include commissione/spedizione', ($acconto1 + $aumento) < $d['commissione'] + $d['spedizione'] + $d['merce']);

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test falliti\n";
exit($fail === 0 ? 0 : 1);
