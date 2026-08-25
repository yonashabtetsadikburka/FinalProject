<?php
declare(strict_types=1);

/**
 * ============================================================
 *  FUNZIONE DI RIPARTIZIONE  --  la scrive il RUOLO A
 * ============================================================
 *
 * FUNZIONE PURA: riceve array, restituisce array, non tocca il database.
 * Cosi' A la puo' testare da solo con dati finti e tutta la persistenza
 * resta al ruolo B.
 *
 * @param array $richieste  [ ['utente_id'=>int, 'latte'=>int, 'created_at'=>string], ... ]
 * @param int   $latte_disponibili  gia' arrotondato a scatole intere da chi chiama
 * @return array            [ ['utente_id'=>int, 'latte'=>int], ... ]
 *
 * GARANZIE RICHIESTE:
 *   - array_sum(latte restituite) === $latte_disponibili
 *   - nessun valore negativo
 *   - deterministica: stesso input -> stesso output
 *     (spareggio: resto piu' alto, poi created_at piu' vecchio)
 *
 * ALGORITMO (metodo dei resti maggiori):
 *   totale  = somma delle latte richieste
 *   esatta  = (richiesta / totale) * latte_disponibili
 *   base    = floor(esatta)   ->  assegnate a tutti
 *   residue = latte_disponibili - somma(base)
 *   ordina per (resto DESC, created_at ASC) e da' +1 latta ai primi `residue`
 */
function ripartisci(array $richieste, int $latte_disponibili): array
{
    // Implementazione PROVVISORIA del ruolo B, da sostituire con quella di A.
    // E' corretta ma non e' il consegnabile di A: serve solo a non restare
    // bloccati se la sua versione arriva dopo.
    $totale = array_sum(array_column($richieste, 'latte'));
    if ($totale <= 0 || $latte_disponibili <= 0) return [];

    $calcolo = [];
    foreach ($richieste as $r) {
        $esatta = ($r['latte'] / $totale) * $latte_disponibili;
        $base   = (int)floor($esatta);
        $calcolo[] = [
            'utente_id'  => (int)$r['utente_id'],
            'latte'      => $base,
            'resto'      => $esatta - $base,
            'created_at' => $r['created_at'],
        ];
    }

    $residue = $latte_disponibili - array_sum(array_column($calcolo, 'latte'));

    // resto piu' alto per primo; a parita' di resto, chi ha aderito prima
    usort($calcolo, function ($a, $b) {
        if ($a['resto'] === $b['resto']) return strcmp($a['created_at'], $b['created_at']);
        return $b['resto'] <=> $a['resto'];
    });
    for ($i = 0; $i < $residue; $i++) {
        $calcolo[$i]['latte']++;
    }

    return array_map(
        fn($c) => ['utente_id' => $c['utente_id'], 'latte' => $c['latte']],
        $calcolo
    );
}
