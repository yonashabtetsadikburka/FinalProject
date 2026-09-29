<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da riga di comando
/**
 * Test dei prezzi a scaglioni (funzioni pure: niente server, niente database).
 *     php tests/test_prezzi.php
 */
require __DIR__ . '/../api/lib/risposta.php';   // AppError
require __DIR__ . '/../api/lib/prezzo.php';

$falliti = 0; $totale = 0;
function ok(string $nome, bool $cond, string $dettaglio = ''): void
{
    global $falliti, $totale;
    $totale++;
    echo ($cond ? "  ok      " : "  FALLITO ") . $nome . (!$cond && $dettaglio !== '' ? "   -> $dettaglio" : '') . "\n";
    if (!$cond) $falliti++;
}
/** Codice d'errore se la funzione rifiuta l'input, null se lo accetta. */
function rifiuta(callable $f): ?string
{
    try { $f(); return null; } catch (AppError $e) { return $e->codice; }
}

$T = [['soglia' => 10, 'prezzo' => 69.90], ['soglia' => 20, 'prezzo' => 59.90]];   // partenza 79.90

echo "prezzo_per_quantita: esempi\n";
ok('nessuno scaglione: resta il prezzo di partenza', prezzo_per_quantita([], 79.90, 500) === 79.90);
ok('sotto il primo scaglione: prezzo di partenza', prezzo_per_quantita($T, 79.90, 9) === 79.90);
ok('esattamente sulla soglia scatta lo scaglione', prezzo_per_quantita($T, 79.90, 10) === 69.90);
ok('tra due scaglioni vale il piu\' basso raggiunto', prezzo_per_quantita($T, 79.90, 19) === 69.90);
ok('sul secondo scaglione', prezzo_per_quantita($T, 79.90, 20) === 59.90);
ok('oltre l\'ultimo scaglione resta l\'ultimo', prezzo_per_quantita($T, 79.90, 10000) === 59.90);
ok('quantita\' zero: prezzo di partenza', prezzo_per_quantita($T, 79.90, 0) === 79.90);
ok('l\'ordine degli scaglioni non conta', prezzo_per_quantita(array_reverse($T), 79.90, 25) === 59.90);
ok('uno scaglione non puo\' far salire il prezzo oltre la partenza',
   prezzo_per_quantita([['soglia' => 5, 'prezzo' => 99.0]], 79.90, 6) === 79.90);
ok('arrotonda a 2 decimali', prezzo_per_quantita([['soglia' => 1, 'prezzo' => 10.126]], 20.0, 1) === 10.13);

echo "\nprezzo_per_quantita: 200.000 casi casuali contro un'implementazione di riferimento\n";
mt_srand(2026);
$diverso = 0; $nonMonotono = 0;
for ($t = 0; $t < 200000; $t++) {
    $iniz = mt_rand(2000, 9000) / 100;
    $n = mt_rand(0, 6);
    $soglie = $n ? array_rand(array_flip(range(1, 60)), $n) : [];
    $soglie = (array)$soglie; sort($soglie);
    $prezzi = []; $p = $iniz;
    foreach ($soglie as $s) { $p = round($p - mt_rand(1, 300) / 100, 2); if ($p < 0.5) $p = 0.5; $prezzi[] = $p; }
    $scagl = []; foreach ($soglie as $i => $s) $scagl[] = ['soglia' => (int)$s, 'prezzo' => $prezzi[$i]];
    shuffle($scagl);
    $q = mt_rand(0, 70);
    // riferimento: scorre gli scaglioni dal piu' alto e prende il primo raggiunto
    usort($scagl, fn($a, $b) => $b['soglia'] <=> $a['soglia']);
    $atteso = $iniz; foreach ($scagl as $s) if ($q >= $s['soglia']) { $atteso = min($s['prezzo'], $iniz); break; }
    if (abs(prezzo_per_quantita($scagl, $iniz, $q) - round($atteso, 2)) > 0.0001) $diverso++;
    // con scaglioni validi il prezzo non puo' MAI salire aumentando la quantita'
    $prec = null;
    for ($k = 0; $k <= 70; $k += 7) {
        $v = prezzo_per_quantita($scagl, $iniz, $k);
        if ($prec !== null && $v > $prec + 0.0001) $nonMonotono++;
        $prec = $v;
    }
}
ok('nessuna differenza dal riferimento su 200.000 casi', $diverso === 0, "$diverso diversi");
ok('il prezzo non sale mai al crescere della quantita\'', $nonMonotono === 0, "$nonMonotono violazioni");

echo "\nscaglioni_normalizza: input validi\n";
ok('vuoto -> nessuno scaglione', scaglioni_normalizza(null) === [] && scaglioni_normalizza('') === [] && scaglioni_normalizza([]) === []);
$n = scaglioni_normalizza('[{"soglia":20,"prezzo":59.9},{"soglia":10,"prezzo":"69.90"}]', 79.90, 20);
ok('accetta una stringa JSON e ordina per soglia', $n === [['soglia' => 10, 'prezzo' => 69.9], ['soglia' => 20, 'prezzo' => 59.9]], json_encode($n));
ok('accetta un array (corpo JSON)', scaglioni_normalizza([['soglia' => 3, 'prezzo' => 5]], 10.0, 10) === [['soglia' => 3, 'prezzo' => 5.0]]);
ok('due scaglioni con lo stesso prezzo vanno bene', scaglioni_normalizza([['soglia' => 3, 'prezzo' => 5], ['soglia' => 6, 'prezzo' => 5]], 10.0, 10) !== []);
ok('senza prezzo iniziale e senza MOQ si saltano quei controlli', scaglioni_normalizza([['soglia' => 500, 'prezzo' => 5]]) !== []);

echo "\nscaglioni_normalizza: input RIFIUTATI (SCAGLIONI_NON_VALIDI)\n";
$K = 'SCAGLIONI_NON_VALIDI';
ok('soglie duplicate', rifiuta(fn() => scaglioni_normalizza([['soglia' => 5, 'prezzo' => 9], ['soglia' => 5, 'prezzo' => 8]])) === $K);
ok('soglia 0', rifiuta(fn() => scaglioni_normalizza([['soglia' => 0, 'prezzo' => 9]])) === $K);
ok('soglia negativa', rifiuta(fn() => scaglioni_normalizza([['soglia' => -3, 'prezzo' => 9]])) === $K);
ok('soglia con la virgola', rifiuta(fn() => scaglioni_normalizza([['soglia' => 2.5, 'prezzo' => 9]])) === $K);
ok('prezzo zero', rifiuta(fn() => scaglioni_normalizza([['soglia' => 2, 'prezzo' => 0]])) === $K);
ok('prezzo negativo', rifiuta(fn() => scaglioni_normalizza([['soglia' => 2, 'prezzo' => -1]])) === $K);
ok('prezzo non numerico', rifiuta(fn() => scaglioni_normalizza([['soglia' => 2, 'prezzo' => 'gratis']])) === $K);
ok('manca la soglia', rifiuta(fn() => scaglioni_normalizza([['prezzo' => 9]])) === $K);
ok('prezzo sopra quello di partenza', rifiuta(fn() => scaglioni_normalizza([['soglia' => 2, 'prezzo' => 12]], 10.0, 10)) === $K);
ok('soglia oltre il MOQ (non si raggiungerebbe mai)', rifiuta(fn() => scaglioni_normalizza([['soglia' => 11, 'prezzo' => 5]], 10.0, 10)) === $K);
ok('a quantita\' maggiore il prezzo sale', rifiuta(fn() => scaglioni_normalizza([['soglia' => 2, 'prezzo' => 5], ['soglia' => 4, 'prezzo' => 6]], 10.0, 10)) === $K);
ok('piu\' di 10 scaglioni', rifiuta(fn() => scaglioni_normalizza(array_map(fn($i) => ['soglia' => $i, 'prezzo' => 20 - $i], range(1, 11)))) === $K);
ok('JSON rotto', rifiuta(fn() => scaglioni_normalizza('{non json')) === $K);
ok('un numero al posto della lista', rifiuta(fn() => scaglioni_normalizza(42)) === $K);
ok('elemento che non e\' un array', rifiuta(fn() => scaglioni_normalizza(['ciao'])) === $K);

echo "\n" . ($falliti === 0 ? "TUTTI I $totale TEST SUPERATI" : "$falliti TEST FALLITI su $totale") . "\n";
exit($falliti === 0 ? 0 : 1);
