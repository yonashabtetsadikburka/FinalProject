<?php
declare(strict_types=1);

/**
 * ============================================================
 *  EVENTI DI PAGAMENTO / RIMBORSO / GRUPPO: email + notifica sul sito
 * ============================================================
 *
 * Un solo punto di emissione: evento_emetti().
 *  - Idempotenza: tabella email_inviate, chiave unica (evento, riferimento_tipo, riferimento_id, id_utente).
 *    Se la riga esiste gia' non succede niente (ne' email ne' notifica): webhook ripetuti e cron doppi sono innocui.
 *  - La riga di registro e la notifica nascono nella stessa transazione dell'evento di business.
 *  - L'email e' in coda (stato 'in_coda') e parte a transazione conclusa (shutdown della richiesta o
 *    email_flush() dal cron). Un errore di invio non tocca mai pagamenti/rimborsi: resta in coda e si riprova.
 *  - Nessuna eccezione esce da qui: se manca la migrazione o l'SMTP e' giu' si logga e basta.
 *
 * Gli eventi che hanno gia' una notifica nel codice esistente (adesione, aumento, minimo, saldo, saldo fallito)
 * passano $notifica = null: il registro gestisce solo l'email, la notifica resta quella gia' creata.
 */

/* Dipende da lib/mail.php e lib/prezzi.php: caricate prima da api/index.php (o dallo script CLI). */

/** Importo in formato italiano: €3.659,58 */
function formatta_euro(float $n): string
{
    return '€' . number_format($n, 2, ',', '.');
}

/** GG/MM da una data MySQL/ISO; '' se non valida. */
function formatta_ggmm(?string $data): string
{
    $t = $data ? strtotime($data) : false;
    return $t === false ? '' : date('d/m', $t);
}

/** GG/MM alle HH:MM */
function formatta_scadenza_ora(?string $data): string
{
    $t = $data ? strtotime($data) : false;
    return $t === false ? '' : date('d/m', $t) . ' alle ' . date('H:i', $t);
}

function evento_link(string $percorso): string
{
    return mail_config()['frontend_url'] . '/app.html#' . $percorso;
}

/**
 * Registra l'evento (una sola volta) e crea la notifica indicata.
 * $oggetto/$html null = nessuna email. $notifica = ['tipo','titolo','messaggio','rif_tipo','rif_id'] oppure null.
 * Ritorna true se l'evento e' nuovo.
 */
function evento_emetti(PDO $pdo, string $evento, string $rifTipo, int $rifId, int $idUtente,
                       ?string $oggetto, ?string $html, ?array $notifica = null): bool
{
    try {
        $stato = $oggetto !== null && $html !== null ? 'in_coda' : 'solo_notifica';
        $st = $pdo->prepare(
            'INSERT IGNORE INTO email_inviate (evento, riferimento_tipo, riferimento_id, id_utente, oggetto, corpo_html, stato)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$evento, $rifTipo, $rifId, $idUtente, $oggetto, $html, $stato]);
        if ($st->rowCount() !== 1) return false;
        $regId = (int)$pdo->lastInsertId();

        if ($notifica !== null) {
            $pdo->prepare(
                'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$idUtente, $notifica['tipo'], $notifica['titolo'], $notifica['messaggio'],
                        $notifica['rif_tipo'] ?? null, $notifica['rif_id'] ?? null]);
        }
        if ($stato === 'in_coda') evento_pianifica_invio($regId);
        return true;
    } catch (Throwable $e) {
        error_log("evento_emetti($evento #$rifId utente $idUtente): " . $e->getMessage());
        return false;
    }
}

/** Accoda l'id per l'invio a fine richiesta (quando le transazioni sono concluse). */
function evento_pianifica_invio(int $regId): void
{
    static $ids = [];
    static $registrato = false;
    $ids[] = $regId;
    if ($registrato) return;
    $registrato = true;
    register_shutdown_function(function () use (&$ids) {
        try {
            email_flush(db(), $ids);
        } catch (Throwable $e) {
            error_log('evento shutdown flush: ' . $e->getMessage());
        }
    });
}

/**
 * Invia le email in coda (solo quelle gia' committate). $ids null = tutte (cron).
 * Le email in coda da piu' di 72 ore vengono scartate (SMTP spento o indirizzo inutilizzabile).
 * Ritorna il numero di email inviate.
 */
function email_flush(PDO $pdo, ?array $ids = null, int $limite = 100): int
{
    $inviate = 0;
    try {
        $pdo->exec("UPDATE email_inviate SET stato = 'scartata' WHERE stato = 'in_coda' AND data_creazione < NOW() - INTERVAL 72 HOUR");
        if (!mail_attiva()) return 0;
        if ($ids !== null) {
            if (!$ids) return 0;
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT e.id, e.oggetto, e.corpo_html, u.email FROM email_inviate e JOIN utenti u ON u.id = e.id_utente
                                  WHERE e.stato = 'in_coda' AND e.id IN ($ph)");
            $st->execute(array_map('intval', $ids));
        } else {
            $st = $pdo->prepare("SELECT e.id, e.oggetto, e.corpo_html, u.email FROM email_inviate e JOIN utenti u ON u.id = e.id_utente
                                  WHERE e.stato = 'in_coda' ORDER BY e.id ASC LIMIT " . (int)$limite);
            $st->execute();
        }
        $up = $pdo->prepare("UPDATE email_inviate SET stato = 'inviata', data_invio = NOW(), tentativi = tentativi + 1 WHERE id = ? AND stato = 'in_coda'");
        $ko = $pdo->prepare('UPDATE email_inviate SET tentativi = tentativi + 1 WHERE id = ?');
        foreach ($st->fetchAll() as $r) {
            if (invia_email((string)$r['email'], (string)$r['oggetto'], (string)$r['corpo_html'])) {
                $up->execute([(int)$r['id']]);
                $inviate++;
            } else {
                error_log('email_flush: invio fallito per email_inviate #' . $r['id'] . ', si riprova');
                $ko->execute([(int)$r['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('email_flush: ' . $e->getMessage());
    }
    return $inviate;
}

/** Saldo stimato di un'adesione su un gruppo ancora aperto (stessa formula della chiusura). */
function evento_saldo_stimato(PDO $pdo, int $collettaId, int $quantita, float $acconto, float $spedizione = 0.0): float
{
    $st = $pdo->prepare('SELECT quantita_attuale, prezzo_corrente, percentuale_commissione FROM collette WHERE id = ?');
    $st->execute([$collettaId]);
    $c = $st->fetch();
    if (!$c) return 0.0;
    $st = $pdo->prepare('SELECT soglia_partecipanti AS soglia, prezzo_unitario AS prezzo FROM scaglioni_prezzo WHERE id_colletta = ? ORDER BY soglia_partecipanti ASC');
    $st->execute([$collettaId]);
    $scaglioni = array_map(fn($s) => ['soglia' => (int)$s['soglia'], 'prezzo' => (float)$s['prezzo']], $st->fetchAll());
    $prezzo = prezzo_per_stima_saldo($scaglioni, (int)$c['quantita_attuale'], (float)$c['prezzo_corrente']);
    return max(0.0, saldo_chiusura($quantita, $prezzo, (float)$c['percentuale_commissione'], $acconto, $spedizione));
}

/** Dati comuni di una prenotazione per testi email/notifica. */
function evento_dati_prenotazione(PDO $pdo, int $prenId): ?array
{
    $st = $pdo->prepare(
        'SELECT p.id, p.id_colletta, p.id_utente, p.quantita, p.importo_acconto, p.importo_saldo,
                u.nome AS utente_nome, u.email AS utente_email,
                pr.nome AS prodotto, c.data_limite, c.quantita_minima, c.quantita_attuale,
                c.prezzo_base, c.prezzo_corrente, c.percentuale_commissione,
                (SELECT soglia_partecipanti FROM scaglioni_prezzo WHERE id_colletta = c.id ORDER BY soglia_partecipanti ASC LIMIT 1) AS primo_soglia,
                (SELECT prezzo_unitario FROM scaglioni_prezzo WHERE id_colletta = c.id ORDER BY soglia_partecipanti ASC LIMIT 1) AS primo_prezzo,
                co.modalita, s.nome AS sede_nome, s.citta AS sede_citta, s.indirizzo AS sede_indirizzo
           FROM prenotazioni p
           JOIN utenti u ON u.id = p.id_utente
           JOIN collette c ON c.id = p.id_colletta
           JOIN prodotti pr ON pr.id = c.id_prodotto
      LEFT JOIN consegne co ON co.id_prenotazione = p.id
      LEFT JOIN sedi s ON s.id_sede = COALESCE(co.id_sede, c.id_sede)
          WHERE p.id = ?'
    );
    $st->execute([$prenId]);
    $r = $st->fetch();
    if (!$r) return null;
    // Colonna della migrazione 2026-10-21: letta a parte, cosi' le altre email funzionano anche senza
    $r['saldo_scadenza'] = null;
    try {
        $s2 = $pdo->prepare('SELECT saldo_scadenza FROM prenotazioni WHERE id = ?');
        $s2->execute([$prenId]);
        $r['saldo_scadenza'] = $s2->fetchColumn() ?: null;
    } catch (Throwable $e) {
        error_log('evento_dati_prenotazione: saldo_scadenza non disponibile: ' . $e->getMessage());
    }
    return $r;
}

function evento_pezzi(int $n): string
{
    return $n . ' ' . ($n === 1 ? 'pezzo' : 'pezzi');
}

function evento_minimo_di(array $d): int
{
    return max(1, (int)($d['primo_soglia'] ?? 0) ?: (int)($d['quantita_minima'] ?? 1));
}

/** Ritorna [oggetto, html] per una email evento. */
function evento_email(string $oggetto, string $titolo, array $paragrafi, array $righe, string $ctaTesto, string $ctaLink): array
{
    return [$oggetto, mail_template_evento($titolo, $paragrafi, $righe, $ctaTesto, $ctaLink)];
}

/* ----------------------------- Eventi (tutti non bloccanti) ----------------------------- */

/** 1. Adesione confermata: acconto addebitato. */
function evento_adesione(PDO $pdo, int $prenId, float $acconto): void
{
    try {
        $d = evento_dati_prenotazione($pdo, $prenId);
        if (!$d) return;
        $minimo = evento_minimo_di($d);
        $primoPrezzo = (float)($d['primo_prezzo'] ?? 0) ?: (float)$d['prezzo_corrente'];
        [$o, $h] = evento_email(
            'Adesione confermata: ' . $d['prodotto'],
            'Hai aderito al gruppo',
            ["Ciao {$d['utente_nome']}, ti sei unito al gruppo per \"{$d['prodotto']}\". Abbiamo addebitato l'acconto; il saldo viene addebitato solo se il gruppo raggiunge il minimo."],
            [
                'Acconto addebitato' => formatta_euro($acconto),
                'Quantità' => evento_pezzi((int)$d['quantita']),
                'Minimo da raggiungere' => evento_pezzi($minimo) . ' (ora ' . (int)$d['quantita_attuale'] . ')',
                'Scadenza del gruppo' => date('d/m/Y', strtotime((string)$d['data_limite'])),
            ],
            'Vai al gruppo', evento_link('/campagne/' . (int)$d['id_colletta'])
        );
        evento_emetti($pdo, 'ADESIONE', 'prenotazione', $prenId, (int)$d['id_utente'], $o, $h);
    } catch (Throwable $e) {
        error_log('evento_adesione: ' . $e->getMessage());
    }
}

/** 2. Quantità aumentata. $pagamentoId = riga pagamenti dell'acconto aggiuntivo (chiave unica per ogni aumento). */
function evento_aumento(PDO $pdo, int $prenId, int $pagamentoId, int $k, int $nuovaQta, float $importo): void
{
    try {
        $d = evento_dati_prenotazione($pdo, $prenId);
        if (!$d) return;
        [$o, $h] = evento_email(
            'Quantità aumentata: ' . $d['prodotto'],
            'Hai aumentato la quantità',
            ["Hai aggiunto " . evento_pezzi($k) . " al tuo acquisto di \"{$d['prodotto']}\". Abbiamo addebitato la differenza di acconto."],
            [
                'Acconto aggiuntivo addebitato' => formatta_euro($importo),
                'Nuova quantità' => evento_pezzi($nuovaQta),
            ],
            'Vai al gruppo', evento_link('/campagne/' . (int)$d['id_colletta'])
        );
        evento_emetti($pdo, 'AUMENTO', 'pagamento', $pagamentoId, (int)$d['id_utente'], $o, $h);
    } catch (Throwable $e) {
        error_log('evento_aumento: ' . $e->getMessage());
    }
}

/** 3. Minimo raggiunto: una email a ogni aderente (la notifica MOQ_RAGGIUNTO esiste gia'). */
function evento_minimo_raggiunto(PDO $pdo, int $collettaId): void
{
    try {
        $st = $pdo->prepare(
            'SELECT p.id, p.id_utente, u.nome, pr.nome AS prodotto, c.data_limite
               FROM prenotazioni p
               JOIN utenti u ON u.id = p.id_utente
               JOIN collette c ON c.id = p.id_colletta
               JOIN prodotti pr ON pr.id = c.id_prodotto
              WHERE p.id_colletta = ?'
        );
        $st->execute([$collettaId]);
        foreach ($st->fetchAll() as $r) {
            $chiusura = date('d/m/Y', strtotime((string)$r['data_limite']));
            [$o, $h] = evento_email(
                'Il gruppo è attivo: ' . $r['prodotto'],
                'Il gruppo ha raggiunto il minimo',
                ["Ciao {$r['nome']}, il gruppo per \"{$r['prodotto']}\" ha raggiunto il minimo ed è attivo. Da ora la tua adesione non si può più annullare.",
                 "Il gruppo resta aperto fino al $chiusura: più persone aderiscono, più il prezzo può ancora scendere. Il saldo ti verrà addebitato alla chiusura, in base al prezzo finale."],
                ['Chiusura del gruppo' => $chiusura],
                'Vai al gruppo', evento_link('/campagne/' . $collettaId)
            );
            evento_emetti($pdo, 'MINIMO_RAGGIUNTO', 'colletta', $collettaId, (int)$r['id_utente'], $o, $h);
        }
    } catch (Throwable $e) {
        error_log('evento_minimo_raggiunto: ' . $e->getMessage());
    }
}

/** 4. Saldo addebitato alla chiusura. $saldo = importo del saldo appena addebitato. */
function evento_saldo_addebitato(PDO $pdo, int $prenId, float $saldo): void
{
    try {
        $d = evento_dati_prenotazione($pdo, $prenId);
        if (!$d) return;
        $qta = (int)$d['quantita'];
        // Voci separate dall'unica formula del saldo (prezzo, commissione, spedizione); senza, solo gli importi pagati
        $dett = function_exists('saldo_prenotazione') ? saldo_prenotazione($pdo, $prenId) : null;
        $righe = [];
        if ($dett) {
            $righe['Prezzo del gruppo (' . $qta . ' × ' . formatta_euro($dett['merce'] / max(1, $qta)) . ')'] = formatta_euro($dett['merce']);
            $righe['Commissione di servizio'] = formatta_euro($dett['commissione']);
            if ($dett['spedizione'] > 0) $righe['Spedizione a domicilio'] = formatta_euro($dett['spedizione']);
            $righe['Acconto già versato'] = '−' . formatta_euro($dett['acconto']);
        }
        if ($saldo > 0) $righe['Saldo addebitato'] = formatta_euro($saldo);
        $totale = $dett ? $dett['totale'] : round((float)$d['importo_acconto'] + $saldo, 2);
        $righe['Totale pagato'] = formatta_euro($totale);
        $risparmio = $dett
            ? max(0.0, round(((float)$d['prezzo_base'] - $dett['merce'] / max(1, $qta)) * $qta, 2))
            : max(0.0, round(((float)$d['prezzo_base'] - (float)$d['prezzo_corrente']) * $qta, 2));
        if ($risparmio > 0) $righe['Risparmio rispetto al listino'] = formatta_euro($risparmio);
        [$o, $h] = evento_email(
            'Saldo addebitato: ' . $d['prodotto'],
            'Il gruppo si è chiuso: saldo addebitato',
            ["Ciao {$d['utente_nome']}, il gruppo per \"{$d['prodotto']}\" si è chiuso e abbiamo addebitato il saldo. Il fornitore preparerà il tuo ordine."],
            $righe,
            'Vai a I miei acquisti', evento_link('/acquisti?tab=in-corso')
        );
        evento_emetti($pdo, 'SALDO_ADDEBITATO', 'prenotazione', $prenId, (int)$d['id_utente'], $o, $h);
    } catch (Throwable $e) {
        error_log('evento_saldo_addebitato: ' . $e->getMessage());
    }
}

/** 5. Saldo non riuscito: link per aggiornare il metodo di pagamento entro il termine. */
function evento_saldo_fallito(PDO $pdo, int $prenId, float $saldo): void
{
    try {
        $d = evento_dati_prenotazione($pdo, $prenId);
        if (!$d) return;
        $termine = formatta_scadenza_ora($d['saldo_scadenza'] ?? null);
        $paragrafi = ["Ciao {$d['utente_nome']}, non siamo riusciti ad addebitare il saldo di " . formatta_euro($saldo) . " per \"{$d['prodotto']}\" (carta rifiutata o scaduta)."];
        $paragrafi[] = $termine !== ''
            ? "Aggiorna il metodo di pagamento entro il $termine (48 ore): se il pagamento non va a buon fine, l'adesione viene annullata e l'acconto ti viene rimborsato."
            : "Aggiorna il metodo di pagamento il prima possibile.";
        [$o, $h] = evento_email(
            'Pagamento del saldo non riuscito: ' . $d['prodotto'],
            'Pagamento del saldo non riuscito',
            $paragrafi,
            ['Saldo da pagare' => formatta_euro($saldo)],
            'Aggiorna pagamento', evento_link('/acquisti?tab=in-corso')
        );
        evento_emetti($pdo, 'SALDO_FALLITO', 'prenotazione', $prenId, (int)$d['id_utente'], $o, $h);
    } catch (Throwable $e) {
        error_log('evento_saldo_fallito: ' . $e->getMessage());
    }
}

/** 5b. Promemoria 24 ore prima della scadenza del termine. */
function evento_saldo_promemoria(PDO $pdo, int $prenId): void
{
    try {
        $d = evento_dati_prenotazione($pdo, $prenId);
        if (!$d) return;
        $termine = formatta_scadenza_ora($d['saldo_scadenza'] ?? null);
        [$o, $h] = evento_email(
            'Ultime ore per pagare il saldo: ' . $d['prodotto'],
            'Il termine per il saldo scade domani',
            ["Ciao {$d['utente_nome']}, il pagamento del saldo per \"{$d['prodotto']}\" non è ancora andato a buon fine. Hai tempo fino al $termine per aggiornare il metodo di pagamento; poi l'adesione viene annullata e l'acconto rimborsato."],
            [],
            'Aggiorna pagamento', evento_link('/acquisti?tab=in-corso')
        );
        evento_emetti($pdo, 'SALDO_PROMEMORIA', 'prenotazione', $prenId, (int)$d['id_utente'], $o, $h);
    } catch (Throwable $e) {
        error_log('evento_saldo_promemoria: ' . $e->getMessage());
    }
}

/**
 * 6. Rimborso automatico. $motivo: 'minimo' (non raggiunto alla scadenza), 'annullato' (gruppo annullato).
 * Crea anche la notifica RIMBORSO.
 */
function evento_rimborso(PDO $pdo, int $prenId, float $importo, string $motivo): void
{
    try {
        $d = evento_dati_prenotazione($pdo, $prenId);
        if (!$d) return;
        $scad = formatta_ggmm((string)$d['data_limite']);
        $perche = $motivo === 'annullato'
            ? 'il gruppo è stato annullato'
            : "il gruppo non ha raggiunto il minimo entro la scadenza ($scad)";
        [$o, $h] = evento_email(
            'Acconto rimborsato: ' . $d['prodotto'],
            'Acconto rimborsato',
            ["Ciao {$d['utente_nome']}, $perche. Ti abbiamo rimborsato l'acconto per \"{$d['prodotto']}\"."],
            ['Importo rimborsato' => formatta_euro($importo), 'Tempi indicativi' => '5–10 giorni lavorativi a seconda della banca'],
            'Vai a I miei acquisti', evento_link('/acquisti?tab=annullati')
        );
        $msg = formatta_euro($importo) . " per {$d['prodotto']}: " . ($motivo === 'annullato'
            ? 'il gruppo è stato annullato.'
            : 'il gruppo non ha raggiunto il minimo entro la scadenza.') . ' Il rimborso arriva in 5–10 giorni lavorativi.';
        evento_emetti($pdo, 'RIMBORSO', 'prenotazione', $prenId, (int)$d['id_utente'], $o, $h,
            ['tipo' => 'RIMBORSO', 'titolo' => 'Acconto rimborsato', 'messaggio' => $msg, 'rif_tipo' => 'prenotazione', 'rif_id' => $prenId]);
    } catch (Throwable $e) {
        error_log('evento_rimborso: ' . $e->getMessage());
    }
}

/** 6b. Saldo non pagato entro il termine: adesione annullata e acconto rimborsato. */
function evento_adesione_annullata_saldo(PDO $pdo, int $prenId, float $importo): void
{
    try {
        $d = evento_dati_prenotazione($pdo, $prenId);
        if (!$d) return;
        [$o, $h] = evento_email(
            'Adesione annullata: pagamento del saldo non riuscito',
            'Adesione annullata: pagamento del saldo non riuscito',
            ["Ciao {$d['utente_nome']}, il saldo per \"{$d['prodotto']}\" non è stato pagato entro il termine, quindi abbiamo annullato la tua adesione e rimborsato l'acconto."],
            ['Importo rimborsato' => formatta_euro($importo), 'Tempi indicativi' => '5–10 giorni lavorativi a seconda della banca'],
            'Vai a I miei acquisti', evento_link('/acquisti?tab=annullati')
        );
        evento_emetti($pdo, 'ADESIONE_ANNULLATA_SALDO', 'prenotazione', $prenId, (int)$d['id_utente'], $o, $h,
            ['tipo' => 'RIMBORSO', 'titolo' => 'Acconto rimborsato',
             'messaggio' => formatta_euro($importo) . " per {$d['prodotto']}: il saldo non è stato pagato entro il termine e l'adesione è stata annullata. Il rimborso arriva in 5–10 giorni lavorativi.",
             'rif_tipo' => 'prenotazione', 'rif_id' => $prenId]);
    } catch (Throwable $e) {
        error_log('evento_adesione_annullata_saldo: ' . $e->getMessage());
    }
}

/** 7. Adesione annullata dal cliente (prima del minimo). $d = dati letti PRIMA della cancellazione. */
function evento_adesione_annullata_cliente(PDO $pdo, array $d, float $rimborso): void
{
    try {
        [$o, $h] = evento_email(
            'Adesione annullata: ' . $d['prodotto'],
            'Hai annullato la tua adesione',
            ["Ciao {$d['utente_nome']}, la tua adesione al gruppo per \"{$d['prodotto']}\" è stata annullata."],
            ['Acconto rimborsato' => formatta_euro($rimborso), 'Tempi indicativi' => '5–10 giorni lavorativi a seconda della banca'],
            'Vedi i gruppi aperti', evento_link('/')
        );
        evento_emetti($pdo, 'ADESIONE_ANNULLATA_CLIENTE', 'prenotazione', (int)$d['id'], (int)$d['id_utente'], $o, $h,
            ['tipo' => 'RIMBORSO', 'titolo' => 'Acconto rimborsato',
             'messaggio' => formatta_euro($rimborso) . " per {$d['prodotto']}: hai annullato l'adesione. Il rimborso arriva in 5–10 giorni lavorativi.",
             'rif_tipo' => 'colletta', 'rif_id' => (int)$d['id_colletta']]);
    } catch (Throwable $e) {
        error_log('evento_adesione_annullata_cliente: ' . $e->getMessage());
    }
}

/** 8. Ordine pronto per il ritiro (solo ritiro in sede). La notifica MERCE_PRONTA esiste gia'. */
function evento_ritiro_pronto(PDO $pdo, int $collettaId): void
{
    try {
        $st = $pdo->prepare(
            'SELECT p.id FROM prenotazioni p LEFT JOIN consegne co ON co.id_prenotazione = p.id
              WHERE p.id_colletta = ? AND p.stato = \'pagata\' AND (co.modalita IS NULL OR co.modalita = \'ritiro_sede\')'
        );
        $st->execute([$collettaId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $prenId) {
            $d = evento_dati_prenotazione($pdo, (int)$prenId);
            if (!$d) continue;
            $sede = trim(($d['sede_nome'] ?? '') . (($d['sede_citta'] ?? '') !== '' ? ' di ' . $d['sede_citta'] : ''));
            $righe = ['Sede di ritiro' => $sede !== '' ? $sede : 'Punto di ritiro indicato nel tuo acquisto'];
            if (!empty($d['sede_indirizzo'])) $righe['Indirizzo'] = (string)$d['sede_indirizzo'];
            [$o, $h] = evento_email(
                'Pronto per il ritiro: ' . $d['prodotto'],
                'Il tuo ordine è pronto per il ritiro',
                ["Ciao {$d['utente_nome']}, \"{$d['prodotto']}\" ti aspetta in sede. Mostra il codice QR dalla pagina I miei acquisti per ritirarlo."],
                $righe,
                'Mostra il codice QR', evento_link('/acquisti?tab=in-arrivo')
            );
            evento_emetti($pdo, 'RITIRO_PRONTO', 'prenotazione', (int)$prenId, (int)$d['id_utente'], $o, $h);
        }
    } catch (Throwable $e) {
        error_log('evento_ritiro_pronto: ' . $e->getMessage());
    }
}

/** 9. Ordine inoltrato al fornitore (la notifica ORDINE_INVIATO esiste gia'). */
function evento_ordine_inoltrato(PDO $pdo, int $collettaId, string $nomeProdotto, string $nomeFornitore): void
{
    try {
        $st = $pdo->prepare('SELECT p.id_utente, u.nome FROM prenotazioni p JOIN utenti u ON u.id = p.id_utente WHERE p.id_colletta = ?');
        $st->execute([$collettaId]);
        foreach ($st->fetchAll() as $r) {
            [$o, $h] = evento_email(
                'Ordine inoltrato al fornitore: ' . $nomeProdotto,
                'Il tuo ordine è stato inoltrato al fornitore',
                ["Ciao {$r['nome']}, l'ordine del gruppo per \"$nomeProdotto\" è stato inoltrato a $nomeFornitore. Ti avviseremo quando sarà pronto."],
                [],
                'Vai a I miei acquisti', evento_link('/acquisti?tab=in-arrivo')
            );
            evento_emetti($pdo, 'ORDINE_INOLTRATO', 'colletta', $collettaId, (int)$r['id_utente'], $o, $h);
        }
    } catch (Throwable $e) {
        error_log('evento_ordine_inoltrato: ' . $e->getMessage());
    }
}

/**
 * Notifica GRUPPO "Il prezzo è sceso" agli aderenti quando un aumento di pezzi fa scattare un nuovo scaglione
 * (dal secondo in poi: il primo e' il minimo raggiunto). Solo notifica, nessuna email. Idempotente per scaglione.
 */
function evento_prezzo_sceso(PDO $pdo, int $collettaId, int $qtaPrima, int $qtaDopo): void
{
    try {
        if ($qtaDopo <= $qtaPrima) return;
        $st = $pdo->prepare('SELECT soglia_partecipanti AS soglia, prezzo_unitario AS prezzo FROM scaglioni_prezzo WHERE id_colletta = ? ORDER BY soglia_partecipanti ASC');
        $st->execute([$collettaId]);
        $scaglioni = $st->fetchAll();
        $idx = function (int $q) use ($scaglioni): int {
            $i = -1;
            foreach ($scaglioni as $k => $s) if ($q >= (int)$s['soglia']) $i = $k;
            return $i;
        };
        $prima = $idx($qtaPrima);
        $dopo = $idx($qtaDopo);
        if ($dopo < 1 || $dopo <= $prima) return;
        $prezzo = (float)$scaglioni[$dopo]['prezzo'];

        $stP = $pdo->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?');
        $stP->execute([$collettaId]);
        $nome = (string)($stP->fetchColumn() ?: 'il prodotto');
        $stU = $pdo->prepare('SELECT DISTINCT id_utente FROM prenotazioni WHERE id_colletta = ?');
        $stU->execute([$collettaId]);
        $msg = "$nome ora costa " . formatta_euro($prezzo) . ": il gruppo ha raggiunto $qtaDopo pezzi.";
        foreach ($stU->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            evento_emetti($pdo, 'PREZZO_SCESO:' . $dopo, 'colletta', $collettaId, (int)$uid, null, null,
                ['tipo' => 'NUOVO_SCAGLIONE', 'titolo' => 'Il prezzo è sceso', 'messaggio' => $msg, 'rif_tipo' => 'colletta', 'rif_id' => $collettaId]);
        }
    } catch (Throwable $e) {
        error_log('evento_prezzo_sceso: ' . $e->getMessage());
    }
}

/* ----------------------------- Saldo non riuscito: fasi del termine (funzione pura) ----------------------------- */

const SALDO_TERMINE_ORE = 48;
const SALDO_PROMEMORIA_ORE = 24;

/**
 * Fase di un saldo non riuscito rispetto al termine: 'attesa' (piu' di 24h), 'promemoria' (ultime 24h) o 'scaduto'.
 * $scadenzaTs null = nessun termine impostato (adesione precedente alla migrazione): 'attesa'.
 */
function saldo_fase(?int $scadenzaTs, int $adesso): string
{
    if ($scadenzaTs === null) return 'attesa';
    if ($adesso >= $scadenzaTs) return 'scaduto';
    if ($scadenzaTs - $adesso <= SALDO_PROMEMORIA_ORE * 3600) return 'promemoria';
    return 'attesa';
}
