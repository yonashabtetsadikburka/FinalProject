<?php
declare(strict_types=1);

/**
 * ============================================================
 *  PREZZI A SCAGLIONI  ("piu' siamo, meno si paga")
 * ============================================================
 *
 * Una campagna ha:
 *   prezzo_base      il prezzo di listino (quello barrato nelle pagine)
 *   prezzo_iniziale  il prezzo di gruppo di PARTENZA, valido finche' non si
 *                    raggiunge il primo scaglione
 *   scaglioni        coppie  soglia (pezzi totali) -> prezzo al pezzo
 *   prezzo_corrente  il prezzo di questo momento: si RICALCOLA da solo
 *
 * Regola: prezzo_corrente = prezzo dello scaglione piu' alto raggiunto dai pezzi
 * prenotati; se non se n'e' raggiunto nessuno, prezzo_iniziale.
 *
 * Il prezzo si ricalcola finche' la campagna e' aperta (in_corso / riuscita)
 * e si CONGELA quando l'admin conferma l'ordine (ordine_pronto): da quel
 * momento le persone stanno pagando e la cifra non deve piu' muoversi.
 *
 * Nota sui nomi: la colonna scaglioni_prezzo.soglia_partecipanti conserva il
 * nome delle migrazioni Laravel, ma contiene PEZZI, non persone.
 */

/**
 * FUNZIONE PURA: prezzo al pezzo per una data quantita' totale.
 *
 * @param array $scaglioni  [ ['soglia'=>int, 'prezzo'=>float], ... ] in qualsiasi ordine
 */
function prezzo_per_quantita(array $scaglioni, float $prezzo_iniziale, int $quantita): float
{
    $prezzo  = $prezzo_iniziale;
    $migliore = 0;   // la soglia piu' alta raggiunta finora
    foreach ($scaglioni as $s) {
        $soglia = (int)$s['soglia'];
        if ($soglia <= $quantita && $soglia > $migliore) {
            $migliore = $soglia;
            $prezzo   = (float)$s['prezzo'];
        }
    }
    // Uno scaglione non puo' mai far SALIRE il prezzo oltre quello di partenza.
    return round(min($prezzo, $prezzo_iniziale), 2);
}

/**
 * Controlla e normalizza gli scaglioni ricevuti (array oppure stringa JSON).
 * Ritorna la lista ordinata per soglia; lancia SCAGLIONI_NON_VALIDI se qualcosa non torna.
 *
 * Regole:  soglia intera >= 1 e non duplicata; prezzo > 0; a quantita' piu' alta il
 * prezzo non sale; nessun prezzo sopra quello di partenza; nessuna soglia oltre il
 * minimo della campagna (la campagna si chiude appena lo raggiunge, quindi uno
 * scaglione piu' in alto non si raggiungerebbe mai).
 * $prezzo_iniziale e $moq sono opzionali: se null, il relativo controllo si salta.
 *
 * @return array [ ['soglia'=>int, 'prezzo'=>float], ... ]
 */
function scaglioni_normalizza($grezzi, ?float $prezzo_iniziale = null, ?int $moq = null): array
{
    if ($grezzi === null || $grezzi === '' || $grezzi === []) return [];
    if (is_string($grezzi)) {
        $grezzi = json_decode($grezzi, true);
    }
    if (!is_array($grezzi)) {
        throw new AppError('SCAGLIONI_NON_VALIDI', 'Formato scaglioni non valido');
    }
    if (count($grezzi) > 10) {
        throw new AppError('SCAGLIONI_NON_VALIDI', 'Massimo 10 scaglioni');
    }

    $out = [];
    $viste = [];
    foreach ($grezzi as $s) {
        if (!is_array($s)) {
            throw new AppError('SCAGLIONI_NON_VALIDI', 'Ogni scaglione deve avere soglia e prezzo');
        }
        $soglia = filter_var($s['soglia'] ?? null, FILTER_VALIDATE_INT);
        $prezzo = filter_var($s['prezzo'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($soglia === false || $soglia < 1 || $prezzo === false || $prezzo <= 0) {
            throw new AppError('SCAGLIONI_NON_VALIDI', 'Ogni scaglione deve avere una soglia intera >= 1 e un prezzo > 0');
        }
        if (isset($viste[$soglia])) {
            throw new AppError('SCAGLIONI_NON_VALIDI', "Soglia duplicata: $soglia pezzi");
        }
        $viste[$soglia] = true;
        if ($moq !== null && $soglia > $moq) {
            throw new AppError('SCAGLIONI_NON_VALIDI',
                "La soglia di $soglia pezzi supera il minimo della campagna ($moq): la campagna si chiude appena lo raggiunge");
        }
        if ($prezzo_iniziale !== null && $prezzo > $prezzo_iniziale + 0.0001) {
            throw new AppError('SCAGLIONI_NON_VALIDI', 'Il prezzo di uno scaglione non puo\' superare il prezzo di partenza');
        }
        $out[] = ['soglia' => $soglia, 'prezzo' => round((float)$prezzo, 2)];
    }

    usort($out, fn($a, $b) => $a['soglia'] <=> $b['soglia']);
    for ($i = 1; $i < count($out); $i++) {
        if ($out[$i]['prezzo'] > $out[$i - 1]['prezzo'] + 0.0001) {
            throw new AppError('SCAGLIONI_NON_VALIDI', 'A quantita\' maggiore il prezzo non puo\' salire');
        }
    }
    return $out;
}

/** Scaglioni di una campagna, ordinati per soglia. */
function scaglioni_colletta(PDO $pdo, int $colletta_id): array
{
    $st = $pdo->prepare(
        'SELECT soglia_partecipanti AS soglia, prezzo_unitario AS prezzo
           FROM scaglioni_prezzo WHERE id_colletta = ? ORDER BY soglia_partecipanti ASC'
    );
    $st->execute([$colletta_id]);
    return array_map(fn($r) => ['soglia' => (int)$r['soglia'], 'prezzo' => (float)$r['prezzo']], $st->fetchAll());
}

/** Sostituisce tutti gli scaglioni di una campagna con quelli dati (gia' normalizzati). */
function scaglioni_sostituisci(PDO $pdo, int $colletta_id, array $scaglioni): void
{
    $pdo->prepare('DELETE FROM scaglioni_prezzo WHERE id_colletta = ?')->execute([$colletta_id]);
    $ins = $pdo->prepare(
        'INSERT INTO scaglioni_prezzo (id_colletta, soglia_partecipanti, prezzo_unitario) VALUES (?,?,?)'
    );
    foreach ($scaglioni as $s) {
        $ins->execute([$colletta_id, $s['soglia'], $s['prezzo']]);
    }
}

/**
 * Riallinea prezzo_corrente alla quantita' attuale. Da chiamare con la riga della
 * campagna GIA' bloccata (FOR UPDATE), cioe' da ricalcola_stato().
 * Se prezzo_iniziale manca (campagne create prima degli scaglioni) lo fissa ora
 * al prezzo corrente, che a quel punto non e' mai stato toccato da uno scaglione.
 *
 * @param array $c  riga di collette con: id, quantita_attuale, prezzo_iniziale, prezzo_corrente
 */
function ricalcola_prezzo(PDO $pdo, array $c): float
{
    $corrente = (float)$c['prezzo_corrente'];
    $iniziale = $c['prezzo_iniziale'] !== null ? (float)$c['prezzo_iniziale'] : $corrente;

    $nuovo = prezzo_per_quantita(scaglioni_colletta($pdo, (int)$c['id']), $iniziale, (int)$c['quantita_attuale']);

    if ($c['prezzo_iniziale'] === null || abs($nuovo - $corrente) > 0.004) {
        $pdo->prepare('UPDATE collette SET prezzo_iniziale = COALESCE(prezzo_iniziale, ?), prezzo_corrente = ? WHERE id = ?')
            ->execute([$iniziale, $nuovo, (int)$c['id']]);
    }
    return $nuovo;
}
