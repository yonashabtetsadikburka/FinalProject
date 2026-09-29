<?php
declare(strict_types=1);

/**
 * Partecipa a una campagna — DISABILITATO (410).
 * Le partecipazioni nascono solo dal webhook payment_intent.succeeded
 * dell'acconto (modale Prenota + Stripe Payment Element).
 */
function partecipazioni_aderisci(int $colletta_id): void
{
    throw new AppError('FLUSSO_DISABILITATO', 'Usa il pulsante Prenota nella pagina campagna', 410);
}

/**
 * Ritira la propria adesione dalla campagna.
 */
function partecipazioni_ritira(int $colletta_id): void
{
    $io = richiedi_login();
    $stato = ricalcola_stato($colletta_id);

    if ($stato === 'ordine_fornitore') {
        throw new AppError('CAMPAGNA_ORDINATA', 'Non si puo\' uscire da una campagna già ordinata');
    }
    if ($stato === 'consegnata') {
        throw new AppError('CAMPAGNA_CONSEGNATA', 'Non si puo\' uscire da una campagna consegnata');
    }

    $st = db()->prepare('SELECT id, quantita, stato, importo_acconto FROM prenotazioni WHERE id_colletta = ? AND id_utente = ?');
    $st->execute([$colletta_id, $io]);
    $p = $st->fetch();
    if (!$p) {
        throw new AppError('NON_PARTECIPI', 'Non risulti iscritto a questa campagna', 404);
    }
    if (in_array($p['stato'], ['pagata', 'rimborsata'], true)) {
        throw new AppError('PAGAMENTO_EFFETTUATO', 'Non puoi annullare: pagamento già effettuato. Contatta l\'assistenza per un rimborso.');
    }

    // Se l'acconto risulta pagato, rimborsalo su Stripe prima di cancellare
    if ((float)($p['importo_acconto'] ?? 0) > 0) {
        $stPay = db()->prepare(
            "SELECT stripe_payment_intent_id FROM pagamenti
              WHERE id_prenotazione = ? AND tipo_pagamento = 'acconto' ORDER BY id DESC LIMIT 1"
        );
        $stPay->execute([$p['id']]);
        if ($piId = $stPay->fetchColumn()) {
            try {
                $cfg = require __DIR__ . '/../config.php';
                $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);
                $stripe->refunds->create(['payment_intent' => $piId]);
            } catch (Throwable $eRef) {
                throw new AppError('RIMBORSO_FALLITO', 'Rimborso acconto fallito: ' . $eRef->getMessage(), 500);
            }
        }
    }

    $st = db()->prepare('DELETE FROM prenotazioni WHERE id = ?');
    $st->execute([$p['id']]);

    $st = db()->prepare('UPDATE collette SET quantita_attuale = GREATEST(0, quantita_attuale - ?) WHERE id = ?');
    $st->execute([(int)$p['quantita'], $colletta_id]);

    $nuovoStato = ricalcola_stato($colletta_id);

    json_ok(['stato' => $nuovoStato]);
}

/**
 * Elenco partecipazioni dell'utente corrente.
 */
function partecipazioni_mie(): void
{
    $io = richiedi_login();
    $st = db()->prepare(
        'SELECT p.id, p.id_colletta, p.quantita, p.stato, p.data_prenotazione,
                p.importo_acconto, p.importo_saldo,
                c.quantita_minima, c.quantita_attuale, c.data_limite, c.stato AS stato_colletta,
                pr.nome AS prodotto, f.nome_azienda AS fornitore, f.id AS fornitore_id,
                qr.stato AS stato_qr,
                co.id AS id_consegna, co.modalita AS consegna_modalita, co.importo_consegna, co.stato AS consegna_stato,
                s.nome AS sede_nome, s.indirizzo AS sede_indirizzo, s.citta AS sede_citta,
                s.telefono AS sede_telefono, s.orari AS sede_orari,
                (SELECT url FROM immagini_prodotto WHERE id_prodotto = c.id_prodotto ORDER BY principale DESC, ordine ASC LIMIT 1) AS immagine,
                COALESCE(p.importo_saldo, ROUND(c.prezzo_corrente * p.quantita * (1 + c.percentuale_commissione / 100) + COALESCE(co.importo_consegna, 0), 2)) AS totale
           FROM prenotazioni p
           JOIN collette c   ON c.id  = p.id_colletta
           JOIN prodotti pr  ON pr.id = c.id_prodotto
           JOIN fornitori f  ON f.id  = pr.id_fornitore
      LEFT JOIN qr_codes qr ON qr.id_prenotazione = p.id
      LEFT JOIN consegne co ON co.id_prenotazione = p.id
      LEFT JOIN sedi s ON s.id_sede = COALESCE(co.id_sede, c.id_sede)
          WHERE p.id_utente = ?
          ORDER BY p.data_prenotazione DESC'
    );
    $st->execute([$io]);
    json_ok($st->fetchAll());
}

/**
 * Notifica pigra di esito negativo: chiamata in lettura quando una campagna
 * risulta 'fallita'. Invia una sola volta (idempotente su tipo RIMBORSO).
 * Mai far fallire la lettura per errori di notifica.
 */
function notifica_esito_fallita(int $colletta_id): void
{
    try {
        $pdo = db();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM notifiche
              WHERE tipo = \'RIMBORSO\' AND tipo_riferimento = \'colletta\' AND id_riferimento = ?'
        );
        $st->execute([$colletta_id]);
        if ((int)$st->fetchColumn() > 0) return;

        $stU = $pdo->prepare('SELECT DISTINCT id_utente, quantita FROM prenotazioni WHERE id_colletta = ?');
        $stU->execute([$colletta_id]);
        $utenti = $stU->fetchAll();
        if (empty($utenti)) return;

        $stP = $pdo->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?');
        $stP->execute([$colletta_id]);
        $nomeProdotto = $stP->fetchColumn() ?: 'una campagna';
        $cfg = require __DIR__ . '/../config.php';
        $accPerc = (float)($cfg['acconto_percent'] ?? 20);
        $stS = $pdo->prepare(
            'SELECT prezzo_unitario FROM scaglioni_prezzo WHERE id_colletta = ? ORDER BY soglia_partecipanti ASC LIMIT 1'
        );
        $stS->execute([$colletta_id]);
        $r = $stS->fetch();
        $stC = $pdo->prepare('SELECT prezzo_corrente FROM collette WHERE id = ?');
        $stC->execute([$colletta_id]);
        $primoPrezzo = $r ? (float)$r['prezzo_unitario'] : (float)($stC->fetchColumn() ?: 0);

        $stN = $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'RIMBORSO\', \'Minimo non raggiunto\', ?, \'colletta\', ?)'
        );
        foreach ($utenti as $u) {
            $acconto = round((int)$u['quantita'] * $primoPrezzo * $accPerc / 100, 2);
            $messaggio = "\"$nomeProdotto\" non ha raggiunto il minimo entro la scadenza. Ti abbiamo rimborsato l'acconto di €"
                . number_format($acconto, 2, ',', '.') . ': lo vedrai sulla carta entro 5-10 giorni lavorativi.';
            $stN->execute([(int)$u['id_utente'], $messaggio, $colletta_id]);
        }
    } catch (Throwable $e) {
        error_log('notifica_esito_fallita fallita per colletta #' . $colletta_id . ': ' . $e->getMessage());
    }
}
