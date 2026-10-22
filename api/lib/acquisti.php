<?php
declare(strict_types=1);

/**
 * "I miei acquisti": unisce partecipazioni e ordini del cliente in 4 schede.
 * Funzioni PURE (nessun DB): ricevono le righe gia' lette e le classificano.
 * Le formule di prezzo/saldo NON sono qui: si riusano quelle di lib/prezzi.php e lib/stato.php.
 */

const ACQ_TABS = ['in-corso', 'in-arrivo', 'conclusi', 'annullati'];
const ACQ_SORT = ['scadenza', 'recenti'];

/** Etichetta mostrata al cliente per ogni "vista" (unico punto). */
const ACQ_ETICHETTE = [
    'in_corso'   => 'Gruppo in corso',
    'attivo'     => 'Gruppo attivo',
    'pagamento'  => 'Pagamento non riuscito',
    'inviato'    => 'Ordine inviato al fornitore',
    'pronto'     => 'Pronto per il ritiro',
    'ritirato'   => 'Ritirato',
    'rimborsato' => 'Rimborsato',
    'annullato'  => 'Annullato',
];

/** Passo del percorso a 6 puntini per ogni vista. */
const ACQ_PASSO = [
    'in_corso' => 3, 'attivo' => 3, 'pagamento' => 4, 'inviato' => 5,
    'pronto' => 6, 'ritirato' => 6, 'rimborsato' => 0, 'annullato' => 0,
];

/** Scheda predefinita dell'ordinamento. */
function acquisti_sort_predefinito(string $tab): string
{
    return $tab === 'in-corso' ? 'scadenza' : 'recenti';
}

/** Whitelist di ?tab= : valore valido o null. */
function acquisti_tab_valido($tab): ?string
{
    return is_string($tab) && in_array($tab, ACQ_TABS, true) ? $tab : null;
}

/** Whitelist di ?sort= : valore valido, altrimenti il predefinito della scheda. */
function acquisti_sort_valido($sort, string $tab): string
{
    return is_string($sort) && in_array($sort, ACQ_SORT, true) ? $sort : acquisti_sort_predefinito($tab);
}

/** Scheda da aprire quando non e' indicata: In corso; se vuota In arrivo; altrimenti la prima non vuota. */
function acquisti_tab_predefinito(array $conteggi): string
{
    foreach (ACQ_TABS as $t) {
        if (($conteggi[$t] ?? 0) > 0) return $t;
    }
    return 'in-corso';
}

/**
 * Classifica una riga: scheda + vista + passo + flag. $r contiene le colonne della query di mie_acquisti()
 * e la chiave 'scaglioni' (lista ['soglia','prezzo']). Numeri e stringhe possono arrivare da MySQL come stringhe.
 * @return array{tab:string,vista:string,etichetta:string,passo:int,concluso:bool,spedizione:bool,chiusura_in_corso:bool,rimborso_in_elaborazione:bool}
 */
function acquisto_classifica(array $r): array
{
    $stato = (string)$r['stato'];
    $scaglioni = $r['scaglioni'] ?? [];
    $minimo = minimo_colletta(['quantita_minima' => $r['quantita_minima'] ?? 1], $scaglioni);
    $raggiunto = (int)$r['quantita_attuale'] >= $minimo;
    $scaduta = (int)($r['scaduta'] ?? 0) === 1;
    $collFallita = in_array($r['stato_colletta'] ?? '', ['fallita', 'annullata'], true);
    $spedizione = ($r['consegna_modalita'] ?? null) === 'consegna_domicilio';

    $tab = 'in-corso';
    $vista = 'in_corso';
    $chiusuraInCorso = false;
    $rimborsoInElaborazione = false;

    if ($stato === 'annullata') {
        [$tab, $vista] = ['annullati', 'annullato'];
    } elseif ($stato === 'rimborsata') {
        [$tab, $vista] = ['annullati', 'rimborsato'];
    } elseif (in_array($stato, ['prenotata', 'confermata', 'azione_richiesta'], true)) {
        if ($stato !== 'azione_richiesta' && ($collFallita || ($scaduta && !$raggiunto))) {
            // Gruppo non riuscito: l'acconto viene rimborsato in automatico (a volte non ancora eseguito)
            [$tab, $vista] = ['annullati', 'rimborsato'];
            $rimborsoInElaborazione = true;
        } elseif ($stato === 'azione_richiesta') {
            [$tab, $vista] = ['in-corso', 'pagamento'];
        } else {
            $vista = $raggiunto ? 'attivo' : 'in_corso';
            $chiusuraInCorso = $scaduta;
        }
    } elseif ($stato === 'pagata') {
        $ritirato = ($r['stato_qr'] ?? null) === 'scansionato'
            || in_array($r['consegna_stato'] ?? '', ['ritirata', 'consegnata'], true);
        if ($ritirato) {
            [$tab, $vista] = ['conclusi', 'ritirato'];
        } elseif (($r['stato_qr'] ?? null) === 'generato' && !$spedizione && ($r['stato_colletta'] ?? '') === 'consegnata') {
            // Pronto solo quando il fornitore ha evaso il gruppo (non appena addebitato il saldo)
            [$tab, $vista] = ['in-arrivo', 'pronto'];
        } else {
            [$tab, $vista] = ['in-arrivo', 'inviato'];
        }
    }

    $etichetta = ACQ_ETICHETTE[$vista];
    if ($vista === 'ritirato' && $spedizione) $etichetta = 'Consegnato';

    return [
        'tab' => $tab, 'vista' => $vista, 'etichetta' => $etichetta, 'passo' => ACQ_PASSO[$vista],
        'concluso' => $vista === 'ritirato', 'spedizione' => $spedizione,
        'chiusura_in_corso' => $chiusuraInCorso, 'rimborso_in_elaborazione' => $rimborsoInElaborazione,
    ];
}

/**
 * Riga completa per la UI (numeri veri, nessun testo HTML). Riusa prezzi.php per tutti i calcoli.
 * @param float $accontoPercent percentuale di acconto da config (solo per il sottotesto "20% del prezzo di gruppo")
 */
function acquisto_costruisci(array $r, float $accontoPercent): array
{
    $c = acquisto_classifica($r);
    $scaglioni = $r['scaglioni'] ?? [];
    $qta = (int)$r['quantita'];
    $attuale = (int)$r['quantita_attuale'];
    $minimo = minimo_colletta(['quantita_minima' => $r['quantita_minima'] ?? 1], $scaglioni);
    $prezzoCorr = (float)$r['prezzo_corrente'];
    $comm = (float)$r['percentuale_commissione'];
    $acconto = (float)($r['importo_acconto'] ?? 0);
    $aperto = in_array($c['vista'], ['in_corso', 'attivo'], true);
    $pagato = round((float)($r['totale_pagato'] ?? 0), 2);

    $out = [
        'id'               => (int)$r['id'],
        'numero_ordine'    => (int)$r['id'],
        'id_colletta'      => (int)$r['id_colletta'],
        'prodotto'         => $r['prodotto'],
        'fornitore'        => $r['fornitore'],
        'fornitore_id'     => (int)$r['fornitore_id'],
        'immagine'         => $r['immagine'] ?? null,
        'quantita'         => $qta,
        'data_prenotazione' => $r['data_prenotazione'],
        'stato_db'         => $r['stato'],
        'tab'              => $c['tab'],
        'vista'            => $c['vista'],
        'etichetta'        => $c['etichetta'],
        'passo'            => $c['passo'],
        'concluso'         => $c['concluso'],
        'spedizione'       => $c['spedizione'],
        'chiusura_in_corso' => $c['chiusura_in_corso'],
        'acconto'          => round($acconto, 2),
        'acconto_percent'  => $accontoPercent,
        'puo_annullare'    => $r['stato'] === 'prenotata' && $c['vista'] === 'in_corso' && (int)($r['scaduta'] ?? 0) !== 1,
        'annullo_bloccato' => $c['vista'] === 'attivo',
        'puo_aumentare'    => false,
        'data_limite'      => $r['data_limite'],
        // Termine per aggiornare il pagamento del saldo (solo vista 'pagamento'); null se non impostato
        'saldo_scadenza'   => $c['vista'] === 'pagamento' ? ($r['saldo_scadenza'] ?? null) : null,
    ];

    if ($aperto) {
        $out['gruppo'] = [
            'quantita_attuale' => $attuale,
            'minimo'           => $minimo,
            'obiettivo'        => obiettivo_barra($scaglioni, (int)($r['quantita_minima'] ?? 1), $attuale, $prezzoCorr),
            'scade_tra_secondi' => max(0, (int)($r['secondi_a_scadenza'] ?? 0)),
        ];
        // Stima di sola lettura (stessa formula della chiusura, vedi lib/prezzi.php)
        $prezzo = prezzo_per_stima_saldo($scaglioni, $attuale, $prezzoCorr);
        $spedizione = spedizione_per_modalita($r['consegna_modalita'] ?? null, (float)($r['importo_consegna'] ?? 0));
        $dett = saldo_dettaglio($qta, $prezzo, $comm, $spedizione, $acconto);
        $out['saldo_previsto'] = max(0.0, $dett['saldo']);
        // Voci separate (prezzo, commissione, spedizione) per mostrarle al cliente; 'stimato' finche' il prezzo finale non e' noto
        $out['saldo_dettaglio'] = $dett + ['stimato' => true, 'spedizione_scelta' => ($r['consegna_modalita'] ?? null) === 'consegna_domicilio'];
        $out['data_addebito'] = $r['data_limite'];

        // "Aumenta la quantita'": acconto dei pezzi in piu' = stessa formula dell'acconto iniziale (lib/aumento.php)
        $maxAgg = aumento_max_aggiuntivi($qta);
        $primoPrezzo = $scaglioni ? (float)$scaglioni[0]['prezzo'] : $prezzoCorr;
        $out['puo_aumentare'] = $maxAgg > 0 && (int)($r['scaduta'] ?? 0) !== 1;
        $out['max_aggiuntivi'] = $maxAgg;
        $out['acconto_unitario'] = acconto_unitario($primoPrezzo, $accontoPercent);
    }

    if (in_array($c['vista'], ['inviato', 'pronto', 'ritirato'], true)) {
        $out['totale_pagato'] = $pagato;
        // Risparmio rispetto al listino con il prezzo effettivamente applicato alla chiusura; assente se non calcolabile
        $listino = (float)($r['prezzo_base'] ?? 0);
        $applicato = prezzo_scaglione_raggiunto($scaglioni, $attuale, $prezzoCorr);
        $risp = round(($listino - $applicato) * $qta, 2);
        $out['risparmiato'] = ($listino > 0 && $pagato > 0 && $risp > 0) ? $risp : null;
    }

    if ($c['vista'] === 'pronto' || $c['vista'] === 'ritirato') {
        $out['ritiro'] = [
            'sede_nome' => $r['sede_nome'] ?? null, 'sede_indirizzo' => $r['sede_indirizzo'] ?? null,
            'sede_citta' => $r['sede_citta'] ?? null, 'sede_orari' => $r['sede_orari'] ?? null,
            'data' => $r['data_ritiro'] ?? ($r['data_consegna'] ?? null),
        ];
    }

    if ($c['vista'] === 'ritirato') {
        $out['recensione'] = [
            'prodotto_fatta'   => (int)($r['recensito_prodotto'] ?? 0) === 1,
            'fornitore_fatta'  => (int)($r['recensito_fornitore'] ?? 0) === 1,
        ];
    }

    if ($c['vista'] === 'rimborsato') {
        $out['rimborso'] = [
            'importo'       => round($acconto, 2),
            'data'          => $c['rimborso_in_elaborazione'] ? null : ($r['chiusura_data'] ?? null),
            'in_elaborazione' => $c['rimborso_in_elaborazione'],
            'scadenza'      => $r['data_limite'],
            'pezzi'         => $attuale,
            'minimo'        => $minimo,
        ];
    }

    return $out;
}

/** Ordinamento della scheda. Il pagamento da completare e i ritiri pronti stanno sempre in cima. */
function acquisti_ordina(array $lista, string $tab, string $sort): array
{
    $priorita = fn($a) => in_array($a['vista'], ['pagamento', 'pronto'], true) ? 0 : 1;
    usort($lista, function ($a, $b) use ($sort, $priorita) {
        if ($priorita($a) !== $priorita($b)) return $priorita($a) <=> $priorita($b);
        if ($sort === 'scadenza') {
            $sa = $a['gruppo']['scade_tra_secondi'] ?? PHP_INT_MAX;
            $sb = $b['gruppo']['scade_tra_secondi'] ?? PHP_INT_MAX;
            if ($sa !== $sb) return $sa <=> $sb;
        }
        return [$b['data_prenotazione'], $b['id']] <=> [$a['data_prenotazione'], $a['id']];
    });
    return $lista;
}

/** Conteggi per scheda e riepilogo per la frase di testata. */
function acquisti_conteggi(array $righe): array
{
    $conteggi = array_fill_keys(ACQ_TABS, 0);
    $gruppi = 0;
    $ritiri = 0;
    foreach ($righe as $a) {
        $conteggi[$a['tab']]++;
        if (in_array($a['vista'], ['in_corso', 'attivo'], true)) $gruppi++;
        if ($a['vista'] === 'pronto') $ritiri++;
    }
    return ['conteggi' => $conteggi, 'riepilogo' => ['gruppi_in_corso' => $gruppi, 'ritiri_pronti' => $ritiri]];
}

/**
 * Tutte le adesioni dell'utente, gia' classificate (vista/scheda): unica fonte per "I miei acquisti" e per la Dashboard,
 * cosi' i contatori non possono divergere. Una query principale + una per gli scaglioni, filtrata sull'utente.
 * Richiede dash_scaglioni_per_collette() (endpoints/dashboard.php).
 */
function acquisti_dell_utente(PDO $pdo, int $io): array
{
    $st = $pdo->prepare(
        'SELECT p.id, p.id_colletta, p.quantita, p.stato, p.data_prenotazione, p.importo_acconto,
                c.quantita_minima, c.quantita_attuale, c.data_limite, c.stato AS stato_colletta, c.chiusura_data,
                c.prezzo_base, c.prezzo_corrente, c.percentuale_commissione,
                (c.data_limite < NOW()) AS scaduta,
                TIMESTAMPDIFF(SECOND, NOW(), c.data_limite) AS secondi_a_scadenza,
                pr.nome AS prodotto, f.nome_azienda AS fornitore, f.id AS fornitore_id,
                qr.stato AS stato_qr, qr.data_scansione AS data_ritiro,
                co.modalita AS consegna_modalita, co.importo_consegna, co.stato AS consegna_stato, co.data_effettiva AS data_consegna,
                s.nome AS sede_nome, s.indirizzo AS sede_indirizzo, s.citta AS sede_citta, s.orari AS sede_orari,
                (SELECT i.url FROM immagini_prodotto i WHERE i.id_prodotto = pr.id ORDER BY i.principale DESC, i.ordine ASC LIMIT 1) AS immagine,
                COALESCE((SELECT SUM(pg.importo) FROM pagamenti pg WHERE pg.id_prenotazione = p.id AND pg.stato = \'confermato\'), 0) AS totale_pagato,
                EXISTS(SELECT 1 FROM recensioni_campagna rc WHERE rc.id_colletta = c.id AND rc.id_utente = p.id_utente) AS recensito_prodotto,
                EXISTS(SELECT 1 FROM recensioni_fornitore rf WHERE rf.id_fornitore = f.id AND rf.id_utente = p.id_utente) AS recensito_fornitore
           FROM prenotazioni p
           JOIN collette c   ON c.id  = p.id_colletta
           JOIN prodotti pr  ON pr.id = c.id_prodotto
           JOIN fornitori f  ON f.id  = pr.id_fornitore
      LEFT JOIN qr_codes qr  ON qr.id_prenotazione = p.id
      LEFT JOIN consegne co  ON co.id_prenotazione = p.id
      LEFT JOIN sedi s       ON s.id_sede = COALESCE(co.id_sede, c.id_sede, (SELECT MIN(id_sede) FROM sedi))
          WHERE p.id_utente = ?
          ORDER BY p.data_prenotazione DESC, p.id DESC
          LIMIT 500'
    );
    $st->execute([$io]);
    $righe = $st->fetchAll();

    // Termine di 48h per il saldo non riuscito (colonna della migrazione 2026-10-21: se manca, nessun avviso con data)
    $scadenze = [];
    try {
        $stS = $pdo->prepare("SELECT id, saldo_scadenza FROM prenotazioni WHERE id_utente = ? AND stato = 'azione_richiesta'");
        $stS->execute([$io]);
        foreach ($stS->fetchAll() as $r) $scadenze[(int)$r['id']] = $r['saldo_scadenza'];
    } catch (Throwable $e) {
        error_log('acquisti: saldo_scadenza non disponibile: ' . $e->getMessage());
    }

    $scaglioni = dash_scaglioni_per_collette($pdo, array_unique(array_map(fn($r) => (int)$r['id_colletta'], $righe)));
    $cfg = require __DIR__ . '/../config.php';
    $accontoPercent = (float)($cfg['acconto_percent'] ?? 20);

    $acquisti = [];
    foreach ($righe as $r) {
        $r['saldo_scadenza'] = $scadenze[(int)$r['id']] ?? null;
        $r['scaglioni'] = $scaglioni[(int)$r['id_colletta']] ?? [];
        $acquisti[] = acquisto_costruisci($r, $accontoPercent);
    }
    return $acquisti;
}

/** Adesioni a gruppi in corso (viste in_corso e attivo), per scadenza crescente. */
function acquisti_in_corso(array $acquisti): array
{
    $aperti = array_values(array_filter($acquisti, fn($a) => in_array($a['vista'], ['in_corso', 'attivo'], true)));
    usort($aperti, fn($a, $b) => [$a['data_limite'], $a['id']] <=> [$b['data_limite'], $b['id']]);
    return $aperti;
}

/** Cosa richiede attenzione: ritiri pronti (con sede reale) e saldi non riusciti con il termine. */
function acquisti_da_fare(array $acquisti): array
{
    $ritiri = [];
    $saldi = [];
    foreach ($acquisti as $a) {
        if ($a['vista'] === 'pronto') {
            $r = $a['ritiro'] ?? [];
            $ritiri[] = [
                'id' => $a['id'], 'prodotto' => $a['prodotto'], 'quantita' => $a['quantita'],
                'sede_nome' => $r['sede_nome'] ?? null, 'sede_indirizzo' => $r['sede_indirizzo'] ?? null,
                'sede_citta' => $r['sede_citta'] ?? null, 'sede_orari' => $r['sede_orari'] ?? null,
            ];
        } elseif ($a['vista'] === 'pagamento') {
            $saldi[] = [
                'id' => $a['id'], 'prodotto' => $a['prodotto'], 'quantita' => $a['quantita'],
                'saldo_scadenza' => $a['saldo_scadenza'] ?? null,
            ];
        }
    }
    return ['ritiri' => $ritiri, 'saldi_non_riusciti' => $saldi, 'totale' => count($ritiri) + count($saldi)];
}

/** Saldo previsto dei gruppi in corso: stessa stima mostrata nelle schede di "I miei acquisti". */
function acquisti_saldo_in_arrivo(array $inCorso): array
{
    $totale = 0.0;
    $prossima = null;
    foreach ($inCorso as $a) {
        $totale += max(0.0, (float)($a['saldo_previsto'] ?? 0));
        if ($prossima === null || $a['data_limite'] < $prossima) $prossima = $a['data_limite'];
    }
    return ['importo' => round($totale, 2), 'n_gruppi' => count($inCorso), 'prossima_scadenza' => $prossima];
}
