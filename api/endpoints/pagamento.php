<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * ============================================================
 *  PAGAMENTO VIA STRIPE CHECKOUT
 * ============================================================
 *
 * Flusso:
 *   1. POST /pagamento/checkout  â†’ crea Checkout Session â†’ reindirizza
 *   2. Utente paga su Stripe (hosted)
 *   3. Stripe chiama POST /pagamento/webhook â†’ conferma nel DB
 *   4. Redirect a /pagamento/successo o /pagamento/annullato
 *
 * Il server NON tocca mai i dati della carta.
 */

/**
 * Crea una Checkout Session Stripe per una prenotazione.
 * POST body: { prenotazione_id: int }
 */
function pagamento_checkout(): void
{
    $io = richiedi_utente_attivo();
    $d = corpo();
    $prenotazione_id = campo_int($d, 'prenotazione_id');

    $st = db()->prepare(
        'SELECT p.id, p.id_colletta, p.quantita, p.stato,
                c.prezzo_corrente, c.percentuale_commissione,
                pr.nome AS prodotto_nome
           FROM prenotazioni p
           JOIN collette c ON c.id = p.id_colletta
           JOIN prodotti pr ON pr.id = c.id_prodotto
          WHERE p.id = ? AND p.id_utente = ?'
    );
    $st->execute([$prenotazione_id, $io]);
    $pren = $st->fetch();

    if (!$pren) {
        throw new AppError('PRENOTAZIONE_NON_TROVATA', 'Prenotazione non trovata', 404);
    }

    if ($pren['stato'] !== 'confermata') {
        throw new AppError('STATO_NON_VALIDO',
            'Pagamento disponibile solo dopo la conferma dell\'ordine. Stato attuale: ' . $pren['stato']
        );
    }

    $prezzo_unitario = (float)$pren['prezzo_corrente'];
    $commesso_pct   = (float)$pren['percentuale_commissione'];
    $quantita       = (int)$pren['quantita'];
    $importo_pezzi  = round($prezzo_unitario * $quantita, 2);
    $commissione    = round($importo_pezzi * $commesso_pct / 100, 2);
    $totale         = round($importo_pezzi + $commissione, 2);

    // Costo spedizione se scelta (default ritiro = 0)
    $st = db()->prepare(
        'SELECT modalita, importo_consegna FROM consegne WHERE id_prenotazione = ?'
    );
    $st->execute([$prenotazione_id]);
    $consegna = $st->fetch();
    $spedizione = ($consegna && $consegna['modalita'] === 'consegna_domicilio')
        ? round((float)$consegna['importo_consegna'], 2) : 0.0;
    $totale = round($totale + $spedizione, 2);

    $cfg = require __DIR__ . '/../config.php';
    $totale_centesimi = (int)round($totale * 100);

    $line_items = [[
        'price_data' => [
            'currency'     => 'eur',
            'product_data' => [
                'name'        => $pren['prodotto_nome'] . ' â€” ' . $quantita . ' pezzi',
                'description' => 'Acquisto collettivo campagna #' . $pren['id_colletta'],
            ],
            'unit_amount'  => (int)round(($importo_pezzi + $commissione) * 100),
        ],
        'quantity' => 1,
    ]];
    if ($spedizione > 0) {
        $line_items[] = [
            'price_data' => [
                'currency'     => 'eur',
                'product_data' => [
                    'name'        => 'Spedizione a domicilio',
                    'description' => 'Consegna all\'indirizzo del profilo',
                ],
                'unit_amount'  => (int)round($spedizione * 100),
            ],
            'quantity' => 1,
        ];
    }

    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);

    try {
        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'customer_email' => utente_email(),
            'line_items' => $line_items,
            'mode' => 'payment',
            'success_url' => $cfg['frontend_url'] . '/app.html#/pagamento/successo?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $cfg['frontend_url'] . '/app.html#/pagamento/annullato',
            'metadata' => [
                'prenotazione_id' => $prenotazione_id,
                'colletta_id'     => $pren['id_colletta'],
                'utente_id'       => $io,
            ],
        ]);

        // Salva importo_saldo sulla prenotazione
        db()->prepare(
            'UPDATE prenotazioni SET importo_saldo = ? WHERE id = ?'
        )->execute([$totale, $prenotazione_id]);

        json_ok([
            'checkout_url' => $session->url,
            'session_id'   => $session->id,
            'importo'      => $totale,
        ]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        throw new AppError('STRIPE_ERRORE', 'Errore Stripe: ' . $e->getMessage(), 500);
    }
}

/**
 * Webhook Stripe â€” idempotente via eventi_stripe.
 * POST /pagamento/webhook
 */
function pagamento_webhook(): void
{
    $cfg = require __DIR__ . '/../config.php';

    $payload = file_get_contents('php://input');
    $sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    if (empty($sig)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing signature']);
        exit;
    }

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig, $cfg['stripe_webhook_secret']);
    } catch (\UnexpectedValueException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    $pdo = db();

    // Idempotenza: controlla se l'evento e' gia' stato processato
    $st = $pdo->prepare('SELECT id, elaborato FROM eventi_stripe WHERE stripe_event_id = ?');
    $st->execute([$event->id]);
    $esistente = $st->fetch();

    if ($esistente && $esistente['elaborato']) {
        http_response_code(200);
        echo json_encode(['received' => true, 'duplicate' => true]);
        exit;
    }

    // Salva l'evento
    if ($esistente) {
        $pdo->prepare('UPDATE eventi_stripe SET elaborato = FALSE WHERE id = ?')->execute([$esistente['id']]);
        $evento_id = (int)$esistente['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO eventi_stripe (stripe_event_id, tipo_evento, payload_json) VALUES (?, ?, ?)'
        )->execute([$event->id, $event->type, $payload]);
        $evento_id = (int)$pdo->lastInsertId();
    }

    // Gestisci solo checkout.session.completed
    if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object;
        $prenotazione_id = (int)($session->metadata->prenotazione_id ?? 0);
        $colletta_id     = (int)($session->metadata->colletta_id ?? 0);
        $utente_id       = (int)($session->metadata->utente_id ?? 0);

        if ($prenotazione_id <= 0) {
            error_log("Stripe webhook: prenotazione_id mancante in metadata");
            $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]);
            http_response_code(200);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Verifica prenotazione
            $st = $pdo->prepare(
                'SELECT p.id, p.stato, p.importo_saldo, p.quantita,
                        c.percentuale_commissione
                   FROM prenotazioni p
                   JOIN collette c ON c.id = p.id_colletta
                  WHERE p.id = ? FOR UPDATE'
            );
            $st->execute([$prenotazione_id]);
            $pren = $st->fetch();

            if (!$pren || $pren['stato'] === 'rimborsata') {
                $pdo->rollBack();
                $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]);
                http_response_code(200);
                exit;
            }

            // Se gia' pagata, e' un duplicato
            if ($pren['stato'] === 'pagata') {
                $pdo->rollBack();
                $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]);
                http_response_code(200);
                exit;
            }

            $importo = (float)($pren['importo_saldo'] ?? 0);
            $commesso_pct = (float)$pren['percentuale_commissione'];
            $commissione = round($importo * $commesso_pct / 100, 2);

            // Registra pagamento
            $stripe_payment_intent_id = $session->payment_intent ?? null;
            $stripe_charge_id = null;
            $stripe_payment_method = null;

            $pdo->prepare(
                'INSERT INTO pagamenti (id_prenotazione, tipo_pagamento, importo, commissione_agenzia,
                                        stripe_payment_intent_id, stripe_charge_id, stripe_payment_method,
                                        stato_stripe, stato, data_pagamento, data_conferma)
                 VALUES (?, \'saldo\', ?, ?, ?, ?, ?, ?, \'confermato\', NOW(), NOW())'
            )->execute([
                $prenotazione_id, $importo, $commissione,
                $stripe_payment_intent_id, $stripe_charge_id, $stripe_payment_method,
                $session->payment_status ?? 'paid',
            ]);

            // Genera QR code solo per ritiro in sede (la spedizione non ha QR)
            $stCons = $pdo->prepare('SELECT modalita FROM consegne WHERE id_prenotazione = ?');
            $stCons->execute([$prenotazione_id]);
            $modalita = $stCons->fetchColumn() ?: 'ritiro_sede';
            if ($modalita !== 'consegna_domicilio') {
                $token = bin2hex(random_bytes(24));
                $pdo->prepare(
                    'INSERT INTO qr_codes (id_prenotazione, token, quantita_assegnata, stato)
                     VALUES (?, ?, ?, \'generato\')'
                )->execute([$prenotazione_id, $token, $pren['quantita']]);
            }

            // Aggiorna prenotazione: stato = 'pagata'
            $pdo->prepare(
                'UPDATE prenotazioni SET stato = \'pagata\', data_pagamento = NOW(),
                        importo_commissione = ? WHERE id = ?'
            )->execute([$commissione, $prenotazione_id]);

            // Calcola progresso pagamenti
            $stProg = $pdo->prepare(
                "SELECT COUNT(*) AS totale,
                        SUM(CASE WHEN stato = 'pagata' THEN 1 ELSE 0 END) AS pagati
                   FROM prenotazioni WHERE id_colletta = ?"
            );
            $stProg->execute([$colletta_id]);
            $prog = $stProg->fetch();
            $pagati = (int)($prog['pagati'] ?? 0);
            $totale = (int)($prog['totale'] ?? 0);
            $progresso = "$pagati/$totale pagati";

            // Collega evento al pagamento
            $payment_id = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE eventi_stripe SET id_pagamento = ? WHERE id = ?')->execute([$payment_id, $evento_id]);

            // Notifica all'utente (testo distinto per spedizione)
            $messaggioUtente = $modalita === 'consegna_domicilio'
                ? "Pagamento di EUR " . number_format($importo, 2, ',', '.') . " ricevuto con successo. Ti avviseremo quando il tuo ordine verrà spedito. ($progresso)"
                : "Pagamento di EUR " . number_format($importo, 2, ',', '.') . " ricevuto con successo. ($progresso)";
            $pdo->prepare(
                'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                 VALUES (?, \'PAGAMENTO_RIUSCITO\', \'Pagamento Ricevuto\', ?, \'prenotazione\', ?)'
            )->execute([
                $utente_id,
                $messaggioUtente,
                $prenotazione_id,
            ]);

            // Notifica agli admin (non bloccante: un errore qui non deve annullare il pagamento)
            try {
                $stAdmin = $pdo->prepare('SELECT id FROM utenti WHERE ruolo = \'admin\'');
                $stAdmin->execute();
                $adminIds = $stAdmin->fetchAll(PDO::FETCH_COLUMN);

                $stNomeUtente = $pdo->prepare('SELECT nome, cognome FROM utenti WHERE id = ?');
                $stNomeUtente->execute([$utente_id]);
                $utente = $stNomeUtente->fetch();
                $nomeUtente = $utente ? trim($utente['nome'] . ' ' . $utente['cognome']) : "Utente #$utente_id";

                $stNomeProdotto = $pdo->prepare(
                    'SELECT pr.nome FROM prenotazioni p JOIN collette c ON c.id = p.id_colletta JOIN prodotti pr ON pr.id = c.id_prodotto WHERE p.id = ?'
                );
                $stNomeProdotto->execute([$prenotazione_id]);
                $nomeProdotto = $stNomeProdotto->fetchColumn() ?: 'un prodotto';

                $stNotificaAdmin = $pdo->prepare(
                    'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                     VALUES (?, \'PAGAMENTO_RICEVUTO\', \'Pagamento Ricevuto\', ?, \'colletta\', ?)'
                );
                foreach ($adminIds as $adminId) {
                    $messaggioAdmin = "Pagamento di EUR " . number_format($importo, 2, ',', '.') . " ricevuto da $nomeUtente per $nomeProdotto. Commissione: EUR " . number_format($commissione, 2, ',', '.') . ". ($progresso)";
                    $stNotificaAdmin->execute([$adminId, $messaggioAdmin, $colletta_id]);
                }
            } catch (\Throwable $eNotify) {
                error_log("Stripe webhook: notifica admin fallita per prenotazione #$prenotazione_id: " . $eNotify->getMessage());
            }

            // Aggiorna stato colletta
            $nuovoStato = ricalcola_stato($colletta_id);

            // Invio automatico al fornitore quando tutti hanno pagato (non bloccante)
            if ($nuovoStato === 'ordine_pronto' && $pagati > 0 && $pagati === $totale) {
                try {
                    invia_ordine_fornitore_al((int)$colletta_id, null);
                    error_log("Stripe webhook: ordine colletta #$colletta_id inviato automaticamente al fornitore");
                } catch (\Throwable $eAuto) {
                    error_log("Stripe webhook: invio automatico colletta #$colletta_id fallito: " . $eAuto->getMessage());
                }
            }

            $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]);
            $pdo->commit();

            error_log("Stripe webhook: pagamento confermato per prenotazione #$prenotazione_id");

        } catch (\Throwable $e) {
            $pdo->rollBack();
            $pdo->prepare('UPDATE eventi_stripe SET elaborato = FALSE WHERE id = ?')->execute([$evento_id]);
            error_log("Stripe webhook errore: " . $e->getMessage());
            throw $e;
        }
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
}

/**
 * Verifica lo stato di un pagamento (dopo il redirect).
 * GET /pagamento/stato?session_id=cs_...
 */
function pagamento_stato(): void
{
    $io = richiedi_login();
    $session_id = $_GET['session_id'] ?? '';
    if (empty($session_id)) {
        throw new AppError('SESSION_ID_MANCANTE', 'Session ID mancante', 400);
    }

    $cfg = require __DIR__ . '/../config.php';
    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);

    try {
        $session = $stripe->checkout->sessions->retrieve($session_id);
        $prenotazione_id = (int)($session->metadata->prenotazione_id ?? 0);
        $isPaid = ($session->payment_status === 'paid');

        if ($isPaid && $prenotazione_id > 0) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Verifica se la prenotazione e' ancora 'confermata' (webhook non procesato)
                $st = $pdo->prepare('SELECT stato FROM prenotazioni WHERE id = ? FOR UPDATE');
                $st->execute([$prenotazione_id]);
                $pren = $st->fetch();

                if ($pren && $pren['stato'] === 'confermata') {
                    // Fallback: webhook mancato, aggiorna manualmente
                    $stPren = $pdo->prepare(
                        'SELECT p.id, p.id_colletta, p.id_utente, p.quantita, p.importo_saldo,
                                c.percentuale_commissione, pr.nome AS nome_prodotto
                           FROM prenotazioni p
                           JOIN collette c ON c.id = p.id_colletta
                           JOIN prodotti pr ON pr.id = c.id_prodotto
                          WHERE p.id = ?'
                    );
                    $stPren->execute([$prenotazione_id]);
                    $dettagli = $stPren->fetch();

                    if ($dettagli) {
                        $importo = (float)$dettagli['importo_saldo'];
                        $commissione_pct = (float)$dettagli['percentuale_commissione'];
                        $commissione = round($importo * $commissione_pct / 100, 2);
                        $colletta_id = (int)$dettagli['id_colletta'];
                        $utente_id = (int)$dettagli['id_utente'];

                        // 1. Registra pagamento
                        $stripe_payment_intent_id = $session->payment_intent ?? null;
                        $pdo->prepare(
                            'INSERT INTO pagamenti (id_prenotazione, tipo_pagamento, importo, commissione_agenzia,
                                                    stripe_payment_intent_id, stato_stripe, stato, data_pagamento, data_conferma)
                             VALUES (?, \'saldo\', ?, ?, ?, ?, \'confermato\', NOW(), NOW())'
                        )->execute([
                            $prenotazione_id, $importo, $commissione,
                            $stripe_payment_intent_id, $session->payment_status ?? 'paid',
                        ]);

                        // 2. Genera QR code solo per ritiro in sede
                        $stCons = $pdo->prepare('SELECT modalita FROM consegne WHERE id_prenotazione = ?');
                        $stCons->execute([$prenotazione_id]);
                        $modalita = $stCons->fetchColumn() ?: 'ritiro_sede';
                        if ($modalita !== 'consegna_domicilio') {
                            $token = bin2hex(random_bytes(24));
                            $pdo->prepare(
                                'INSERT INTO qr_codes (id_prenotazione, token, quantita_assegnata, stato)
                                 VALUES (?, ?, ?, \'generato\')'
                            )->execute([$prenotazione_id, $token, $dettagli['quantita']]);
                        }

                        // 3. Aggiorna prenotazione a 'pagata'
                        $pdo->prepare(
                            'UPDATE prenotazioni SET stato = \'pagata\', data_pagamento = NOW(),
                                    importo_commissione = ? WHERE id = ?'
                        )->execute([$commissione, $prenotazione_id]);

                        // 4. Calcola progresso pagamenti
                        $stProg = $pdo->prepare(
                            "SELECT COUNT(*) AS totale,
                                    SUM(CASE WHEN stato = 'pagata' THEN 1 ELSE 0 END) AS pagati
                               FROM prenotazioni WHERE id_colletta = ?"
                        );
                        $stProg->execute([$colletta_id]);
                        $prog = $stProg->fetch();
                        $pagati = (int)($prog['pagati'] ?? 0);
                        $totale = (int)($prog['totale'] ?? 0);
                        $progresso = "$pagati/$totale pagati";

                        // 5. Notifica all'utente (testo distinto per spedizione)
                        $messaggioUtente = $modalita === 'consegna_domicilio'
                            ? "Pagamento di EUR " . number_format($importo, 2, ',', '.') . " ricevuto con successo. Ti avviseremo quando il tuo ordine verrà spedito. ($progresso)"
                            : "Pagamento di EUR " . number_format($importo, 2, ',', '.') . " ricevuto con successo. ($progresso)";
                        $pdo->prepare(
                            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                             VALUES (?, \'PAGAMENTO_RIUSCITO\', \'Pagamento Ricevuto\', ?, \'prenotazione\', ?)'
                        )->execute([$utente_id, $messaggioUtente, $prenotazione_id]);

                        // 6. Notifica agli admin (non bloccante: un errore qui non deve annullare il pagamento)
                        try {
                            $stAdmin = $pdo->prepare('SELECT id FROM utenti WHERE ruolo = \'admin\'');
                            $stAdmin->execute();
                            $adminIds = $stAdmin->fetchAll(PDO::FETCH_COLUMN);

                            $stNomeUtente = $pdo->prepare('SELECT nome, cognome FROM utenti WHERE id = ?');
                            $stNomeUtente->execute([$utente_id]);
                            $utente = $stNomeUtente->fetch();
                            $nomeUtente = $utente ? trim($utente['nome'] . ' ' . $utente['cognome']) : "Utente #$utente_id";

                            $stNotificaAdmin = $pdo->prepare(
                                'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                                 VALUES (?, \'PAGAMENTO_RICEVUTO\', \'Pagamento Ricevuto\', ?, \'colletta\', ?)'
                            );
                            foreach ($adminIds as $adminId) {
                                $messaggioAdmin = "Pagamento di EUR " . number_format($importo, 2, ',', '.') . " ricevuto da $nomeUtente per {$dettagli['nome_prodotto']}. Commissione: EUR " . number_format($commissione, 2, ',', '.') . ". ($progresso)";
                                $stNotificaAdmin->execute([$adminId, $messaggioAdmin, $colletta_id]);
                            }
                        } catch (\Throwable $eNotify) {
                            error_log("pagamento_stato: notifica admin fallita per prenotazione #$prenotazione_id: " . $eNotify->getMessage());
                        }

                        // 7. Aggiorna stato colletta
                        $nuovoStatoFb = ricalcola_stato($colletta_id);

                        // 8. Invio automatico al fornitore quando tutti hanno pagato (non bloccante)
                        if ($nuovoStatoFb === 'ordine_pronto' && $pagati > 0 && $pagati === $totale) {
                            try {
                                invia_ordine_fornitore_al((int)$colletta_id, null);
                                error_log("pagamento_stato: ordine colletta #$colletta_id inviato automaticamente al fornitore");
                            } catch (\Throwable $eAuto) {
                                error_log("pagamento_stato: invio automatico colletta #$colletta_id fallito: " . $eAuto->getMessage());
                            }
                        }

                        error_log("pagamento_stato: fallback aggiornamento prenotazione #$prenotazione_id (webhook mancato)");
                    }
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("pagamento_stato fallback errore: " . $e->getMessage());
            }
        }

        json_ok([
            'status'       => $session->payment_status,
            'prenotazione' => $prenotazione_id,
            'importo'      => ($session->amount_total ?? 0) / 100,
        ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        throw new AppError('STRIPE_ERRORE', 'Errore nel recupero stato: ' . $e->getMessage());
    }
}
