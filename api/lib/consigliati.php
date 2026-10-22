<?php
declare(strict_types=1);

/**
 * Scelta deterministica dei "gruppi consigliati" (nessuna AI). Funzioni pure: i dati arrivano da una sola query.
 * Pesi e soglie sono definiti qui e solo qui.
 */

const CONS_PESO_CATEGORIA   = 3;    // stessa categoria di un gruppo gia' frequentato
const CONS_PESO_FORNITORE   = 2;    // fornitore da cui l'utente ha gia' comprato
const CONS_PESO_VICINO      = 2;    // vicino al traguardo
const CONS_PESO_SCADE       = 1;    // scade presto ma il traguardo e' ancora raggiungibile
const CONS_SOGLIA_VICINO    = 0.7;  // pezzi attuali >= 70% dell'obiettivo corrente
const CONS_ORE_SCADENZA     = 72;   // finestra "scade presto"
const CONS_MAX_MANCANTI_SCADE = 5;  // "raggiungibile": mancano al massimo N pezzi
const CONS_NUMERO           = 3;

/**
 * @param array $candidati gruppi aperti e non scaduti. Ogni elemento:
 *   id_colletta, prodotto, categoria (?string), id_fornitore, fornitore, quantita_attuale, partecipanti,
 *   data_limite (timestamp int), obiettivo (output di obiettivo_barra), + campi da mostrare (passati invariati)
 * @param array $profilo ['categorie' => [nome => true], 'fornitori' => [id => true], 'partecipati' => [id_colletta => true]]
 * @return array i primi CONS_NUMERO candidati, con 'punteggio', 'motivo', 'motivo_codice'
 */
function scegli_consigliati(array $candidati, array $profilo, int $adesso): array
{
    $punteggiati = [];
    foreach ($candidati as $c) {
        if (isset($profilo['partecipati'][$c['id_colletta']])) continue;   // gia' partecipa

        $ob = $c['obiettivo'];
        $vicino = $ob['tipo'] !== 'massimo' && $ob['pct'] >= CONS_SOGLIA_VICINO;
        $scadeA = $c['data_limite'] - $adesso;
        $scadePresto = $scadeA > 0 && $scadeA <= CONS_ORE_SCADENZA * 3600
            && $ob['tipo'] !== 'massimo' && $ob['mancano'] <= CONS_MAX_MANCANTI_SCADE;

        // Ordine = priorita' del motivo a parita' di peso
        $criteri = [];
        if ($c['categoria'] !== null && isset($profilo['categorie'][$c['categoria']])) {
            $criteri[] = ['categoria', CONS_PESO_CATEGORIA, 'Come i tuoi acquisti: ' . $c['categoria']];
        }
        if (isset($profilo['fornitori'][$c['id_fornitore']])) {
            $criteri[] = ['fornitore', CONS_PESO_FORNITORE, 'Da ' . $c['fornitore'] . ' che hai già scelto'];
        }
        if ($vicino) $criteri[] = ['vicino', CONS_PESO_VICINO, 'Quasi al traguardo'];
        if ($scadePresto) $criteri[] = ['scade', CONS_PESO_SCADE, 'Scade presto'];

        $punti = array_sum(array_column($criteri, 1));
        $c['punteggio'] = $punti;
        if ($criteri) {
            $migliore = $criteri[0];
            foreach ($criteri as $k) if ($k[1] > $migliore[1]) $migliore = $k;
            $c['motivo_codice'] = $migliore[0];
            $c['motivo'] = $migliore[2];
        } else {
            $c['motivo_codice'] = $vicino ? 'vicino' : 'popolare';
            $c['motivo'] = $vicino ? 'Quasi al traguardo' : 'Tra i più popolari';
        }
        $c['_vicino'] = $vicino;
        $punteggiati[] = $c;
    }

    // Prima chi ha punteggio (poi scadenza piu' vicina); poi il fallback: piu' popolari, piu' vicini al traguardo
    $conPunti = array_values(array_filter($punteggiati, fn($c) => $c['punteggio'] > 0));
    usort($conPunti, fn($a, $b) => [$b['punteggio'], $a['data_limite']] <=> [$a['punteggio'], $b['data_limite']]);
    $scelti = array_slice($conPunti, 0, CONS_NUMERO);

    if (count($scelti) < CONS_NUMERO) {
        $resto = array_values(array_filter($punteggiati, fn($c) => $c['punteggio'] <= 0));
        usort($resto, fn($a, $b) =>
            [$b['partecipanti'], $b['obiettivo']['pct'], $a['data_limite']] <=> [$a['partecipanti'], $a['obiettivo']['pct'], $b['data_limite']]);
        $scelti = array_merge($scelti, array_slice($resto, 0, CONS_NUMERO - count($scelti)));
    }
    return array_map(function ($c) { unset($c['_vicino']); return $c; }, $scelti);
}
