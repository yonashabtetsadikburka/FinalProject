<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da riga di comando
/**
 * Test della ripartizione dei pezzi tra i partecipanti (metodo dei resti maggiori).
 * Funzione pura: niente server, niente database.
 *     php tests/test_ripartizione.php
 */
require __DIR__ . '/../api/lib/ripartizione.php';

$falliti = 0; $totale = 0;
function ok(string $nome, bool $cond, string $dettaglio = ''): void
{
    global $falliti, $totale;
    $totale++;
    echo ($cond ? "  ok      " : "  FALLITO ") . $nome . (!$cond && $dettaglio !== '' ? "   -> $dettaglio" : '') . "\n";
    if (!$cond) $falliti++;
}
function richieste(array $q): array
{
    $out = [];
    foreach (array_values($q) as $i => $qt) {
        $out[] = ['id_prenotazione' => $i + 1, 'utente_id' => 100 + $i, 'quantita' => $qt,
                  'created_at' => sprintf('2026-09-%02d 10:00:00', $i + 1)];
    }
    return $out;
}
function per_id(array $res): array { return array_column($res, 'quantita', 'id_prenotazione'); }

echo "esempi\n";
$r = ripartisci(richieste([5, 4, 3, 3]), 15);
ok('pezzi disponibili = pezzi richiesti: ognuno riceve quanto ha prenotato', per_id($r) === [1 => 5, 2 => 4, 3 => 3, 4 => 3], json_encode(per_id($r)));
$r = ripartisci(richieste([1, 1, 1]), 2);
ok('2 pezzi a 3 persone: 2 ricevono 1, uno resta a 0, la somma torna', array_sum(per_id($r)) === 2 && count(array_filter(per_id($r))) === 2);
ok('a parita\' di resto vince chi ha prenotato prima', per_id($r) === [1 => 1, 2 => 1, 3 => 0], json_encode(per_id($r)));
$r = ripartisci(richieste([7]), 3);
ok('una sola persona riceve tutto', per_id($r) === [1 => 3]);
ok('nessuna richiesta -> nessuna assegnazione', ripartisci([], 10) === []);
ok('zero pezzi -> nessuna assegnazione', ripartisci(richieste([2, 3]), 0) === []);
$a = ripartisci(richieste([5, 9, 2, 7]), 13); $b = ripartisci(richieste([5, 9, 2, 7]), 13);
ok('deterministica: stesso input, stesso output', $a === $b);
ok('restituisce id_prenotazione, utente_id e quantita\' per ognuno', array_keys($a[0]) === ['id_prenotazione', 'utente_id', 'quantita']);

echo "\n500.000 casi casuali (proprieta' che devono valere sempre)\n";
mt_srand(7);
$somma = 0; $neg = 0; $diverso = 0; $troppo = 0; $n = 0;
for ($t = 0; $t < 500000; $t++) {
    $k = mt_rand(1, $t % 2 ? 25 : 8);
    $max = $t % 3 ? 40 : 900;
    $q = []; for ($i = 0; $i < $k; $i++) $q[] = mt_rand(1, $max);
    $tot = array_sum($q);
    $pezzi = $t % 4 ? $tot : mt_rand(1, $tot * 2);       // spesso == richiesti (il caso reale), a volte diversi
    $res = per_id(ripartisci(richieste($q), $pezzi));
    $n++;
    if (array_sum($res) !== $pezzi) $somma++;
    foreach ($res as $id => $v) {
        if ($v < 0) $neg++;
        $esatta = $q[$id - 1] / $tot * $pezzi;
        if ($v > (int)ceil($esatta) + 0 || $v < (int)floor($esatta)) $troppo++;   // mai piu' di 1 pezzo dal valore esatto
    }
    if ($pezzi === $tot) foreach ($q as $i => $qt) if (($res[$i + 1] ?? -1) !== $qt) { $diverso++; break; }
}
ok("la somma assegnata e' sempre uguale ai pezzi disponibili ($n casi)", $somma === 0, "$somma violazioni");
ok('nessuna quantita\' negativa', $neg === 0);
ok('ognuno riceve floor o ceil della sua quota esatta (mai piu\' di 1 pezzo di scarto)', $troppo === 0, "$troppo violazioni");
ok('con pezzi = richiesti, nessuno riceve una quantita\' diversa da quella prenotata', $diverso === 0, "$diverso casi");

echo "\n" . ($falliti === 0 ? "TUTTI I $totale TEST SUPERATI" : "$falliti TEST FALLITI su $totale") . "\n";
exit($falliti === 0 ? 0 : 1);
