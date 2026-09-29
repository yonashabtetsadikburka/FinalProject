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

    // Gestisci checkout.session.completed: legacy/riprova (con prenotazione_id)
    // oppure nuovo flusso acconto (metadata tipo=acconto, senza prenotazione_id)
    if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object;
        if (($session->metadata->tipo ?? null) === 'acconto') {
            $piId = is_string($session->payment_intent ?? null)
                ? $session->payment_intent
                : (string)($session->payment_intent->id ?? '');
            if ($piId === '') {
                error_log('Stripe webhook: payment_intent mancante in sessione acconto');
                $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]);
                http_response_code(200);
                exit;
            }
            $cfg2 = require __DIR__ . '/../config.php';
            $stripe2 = new \Stripe\StripeClient($cfg2['stripe_secret_key']);
            $pi = $stripe2->paymentIntents->retrieve($piId);
            webhook_acconto_crea($pi, $evento_id);
            http_response_code(200);
            echo json_encode(['received' => true]);
            exit;
        }
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

    // PaymentIntent: acconto (crea partecipazione) o saldo (finalizza pagamento)
    if ($event->type === 'payment_intent.succeeded') {
        $pi = $event->data->object;
        $tipo = $pi->metadata->tipo ?? null;
        if ($tipo === 'acconto') {
            webhook_acconto_crea($pi, $evento_id);
        } elseif ($tipo === 'saldo') {
            $pdo->beginTransaction();
            try {
                saldo_completa(
                    $pdo,
                    (int)($pi->metadata->prenotazione_id ?? 0),
                    (int)($pi->metadata->colletta_id ?? 0),
                    (int)($pi->metadata->utente_id ?? 0),
                    (string)($pi->id ?? ''),
                    round(((float)($pi->amount_received ?? $pi->amount ?? 0)) / 100, 2)
                );
                $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $pdo->prepare('UPDATE eventi_stripe SET elaborato = FALSE WHERE id = ?')->execute([$evento_id]);
                error_log('webhook saldo errore: ' . $e->getMessage());
                throw $e;
            }
        } else {
            $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]);
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

        // Self-healing per il flusso acconto: se pagata e senza partecipazione,
        // la crea subito (stesso core idempotente del webhook). Copre webhook
        // mai recapitati (es. localhost senza `stripe listen`) o in ritardo.
        if ($isPaid && ($session->metadata->tipo ?? null) === 'acconto') {
            $uidMeta = (int)($session->metadata->utente_id ?? 0);
            if ($uidMeta !== $io) {
                throw new AppError('NON_AUTORIZZATO', 'Sessione di un altro utente', 403);
            }
            $piId = is_string($session->payment_intent ?? null)
                ? $session->payment_intent
                : (string)($session->payment_intent->id ?? '');
            if ($piId !== '') {
                $st = db()->prepare('SELECT id FROM pagamenti WHERE stripe_payment_intent_id = ?');
                $st->execute([$piId]);
                if (!$st->fetch()) {
                    $evId = 'sync_' . $session->id;
                    $stE = db()->prepare('SELECT id, elaborato FROM eventi_stripe WHERE stripe_event_id = ?');
                    $stE->execute([$evId]);
                    $er = $stE->fetch();
                    if ($er) {
                        $erId = (int)$er['id'];
                        $giaFatto = (bool)$er['elaborato'];
                    } else {
                        db()->prepare(
                            'INSERT INTO eventi_stripe (stripe_event_id, tipo_evento, payload_json) VALUES (?, ?, ?)'
                        )->execute([$evId, 'checkout.session.completed', '{}']);
                        $erId = (int)db()->lastInsertId();
                        $giaFatto = false;
                    }
                    if (!$giaFatto) {
                        $pi = $stripe->paymentIntents->retrieve($piId);
                        webhook_acconto_crea($pi, $erId);
                    }
                }
            }
        }

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

/* ============================================================
 *  ACCONTO CON STRIPE PAYMENT ELEMENT + CHIUSURA SALDI
 * ============================================================
 *
 * Nuovo flusso Prenota (il server non tocca mai i dati carta):
 *   1. POST /pagamento/acconto-intent (scelta consegna completa)
 *      -> PaymentIntent acconto + setup_future_usage off_session
 *   2. Frontend: stripe.confirmPayment() con Payment Element
 *   3. Webhook payment_intent.succeeded (metadata tipo=acconto)
 *      -> crea prenotazione + consegna + QR? no (QR al saldo)
 *   4. Alla scadenza: POST /campagne/{id}/chiusura (cron con secret o admin)
 *      -> addebito off_session del saldo per ogni confermata
 *      -> pagata | azione_richiesta (+ retry via /pagamento/riprova)
 *
 * Stripe Connect: nessuna integrazione esistente nel progetto.
 * Se in futuro si configura Connect per i fornitori, aggiungere qui
 * transfer_data ['destination' => account_fornitore] e
 * application_fee_amount in creazione PaymentIntent (acconto e saldo).
 */

/** GET /pagamento/config — publishable key per Stripe.js (mai il secret). */
function pagamento_config(): void
{
    richiedi_login();
    $cfg = require __DIR__ . '/../config.php';
    json_ok(['publishable_key' => $cfg['stripe_publishable_key'] ?? null]);
}

/** Crea o riusa lo Stripe Customer dell'utente (serve per addebiti off_session). */
function pagamento_customer(int $io, string $email): string
{
    $cfg = require __DIR__ . '/../config.php';
    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);
    $st = db()->prepare('SELECT stripe_customer_id FROM utenti WHERE id = ?');
    $st->execute([$io]);
    $cid = $st->fetchColumn();
    if ($cid) return (string)$cid;
    $customer = $stripe->customers->create(['email' => $email, 'metadata' => ['utente_id' => $io]]);
    db()->prepare('UPDATE utenti SET stripe_customer_id = ? WHERE id = ?')->execute([$customer->id, $io]);
    return $customer->id;
}

/**
 * Valida scelta consegna + calcola acconto (condiviso tra intent e checkout).
 * Ritorna [colletta_id, quantita, tipo, idSede, indirizzo, acconto, nomeProdotto].
 */
function pagamento_prepara_acconto(int $io, array $d): array
{
    $colletta_id = campo_int($d, 'colletta_id');
    $quantita = max(1, min(99, campo_int($d, 'quantita', 1)));
    $delivery = $d['delivery'] ?? null;
    if (!is_array($delivery)) throw new AppError('SCELTA_MANCANTE', 'Scegli come ricevere l\'articolo');
    $tipo = $delivery['tipo'] ?? '';
    if (!in_array($tipo, ['ritiro_sede', 'consegna_domicilio'], true)) {
        throw new AppError('SCELTA_NON_VALIDA', 'Metodo di consegna non valido');
    }

    $stato = ricalcola_stato($colletta_id);
    if (!in_array($stato, ['in_corso', 'riuscita'], true)) {
        throw new AppError('CAMPAGNA_NON_APERTA', 'Campagna non aperta alle prenotazioni', 409);
    }

    $st = db()->prepare('SELECT id FROM prenotazioni WHERE id_colletta = ? AND id_utente = ?');
    $st->execute([$colletta_id, $io]);
    if ($st->fetch()) throw new AppError('GIA_PARTECIPI', 'Hai gia\' partecipato a questa campagna', 409);

    $idSede = null;
    $indirizzo = null;
    if ($tipo === 'ritiro_sede') {
        $st = db()->prepare('SELECT id_sede FROM collette WHERE id = ?');
        $st->execute([$colletta_id]);
        $sedeCampagna = $st->fetchColumn();
        if (!$sedeCampagna) throw new AppError('RITIRO_NON_DISPONIBILE', 'Ritiro in sede non disponibile per questa campagna', 409);
        $idSede = isset($delivery['id_sede']) ? (int)$delivery['id_sede'] : 0;
        if ($idSede !== (int)$sedeCampagna) throw new AppError('SEDE_NON_VALIDA', 'Punto di ritiro non valido');
    } else {
        $via = trim((string)($delivery['via'] ?? ''));
        $cap = trim((string)($delivery['cap'] ?? ''));
        $citta = trim((string)($delivery['citta'] ?? ''));
        $prov = trim((string)($delivery['provincia'] ?? ''));
        if ($via === '' || $cap === '' || $citta === '') {
            throw new AppError('INDIRIZZO_MANCANTE', 'Via, CAP e città sono obbligatori per la spedizione');
        }
        $indirizzo = $via . ', ' . $cap . ' ' . $citta . ($prov !== '' ? ' (' . strtoupper($prov) . ')' : '');
    }

    $cfg = require __DIR__ . '/../config.php';
    $accPerc = (float)($cfg['acconto_percent'] ?? 20);
    $st = db()->prepare('SELECT prezzo_unitario FROM scaglioni_prezzo WHERE id_colletta = ? ORDER BY soglia_partecipanti ASC LIMIT 1');
    $st->execute([$colletta_id]);
    $r = $st->fetch();
    if ($r) {
        $primoPrezzo = (float)$r['prezzo_unitario'];
    } else {
        $st = db()->prepare('SELECT prezzo_corrente FROM collette WHERE id = ?');
        $st->execute([$colletta_id]);
        $primoPrezzo = (float)($st->fetchColumn() ?: 0);
    }
    $acconto = round($quantita * $primoPrezzo * $accPerc / 100, 2);
    if ($acconto < 0.50) throw new AppError('ACCONTO_MINIMO', 'Acconto minimo €0,50', 422);

    $st = db()->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?');
    $st->execute([$colletta_id]);
    $nomeProdotto = $st->fetchColumn() ?: 'Prodotto';

    return [$colletta_id, $quantita, $tipo, $idSede, $indirizzo, $acconto, $nomeProdotto];
}

/**
 * Crea PaymentIntent per l'acconto di una futura partecipazione.
 * POST body: { colletta_id, quantita, delivery: { tipo, id_sede?, indirizzo? } }
 * amount = qty x prezzo primo scaglione x ACCONTO_PERCENT. Solo a scelta completa.
 * (Mantenuto come fallback; il flusso principale usa acconto-checkout.)
 */
function pagamento_acconto_intent(): void
{
    $io = richiedi_utente_attivo();
    [$colletta_id, $quantita, $tipo, $idSede, $indirizzo, $acconto] = pagamento_prepara_acconto($io, corpo());

    $cfg = require __DIR__ . '/../config.php';
    $customer = pagamento_customer($io, utente_email());
    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);
    try {
        $pi = $stripe->paymentIntents->create([
            'amount' => (int)round($acconto * 100),
            'currency' => 'eur',
            'customer' => $customer,
            'setup_future_usage' => 'off_session',
            'receipt_email' => utente_email(),
            'metadata' => [
                'tipo' => 'acconto',
                'colletta_id' => $colletta_id,
                'quantita' => $quantita,
                'utente_id' => $io,
                'delivery_type' => $tipo,
                'id_sede' => $idSede ?? '',
                'indirizzo' => mb_substr($indirizzo ?? '', 0, 400),
            ],
        ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        throw new AppError('STRIPE_ERRORE', 'Errore Stripe: ' . $e->getMessage(), 500);
    }

    json_ok(['client_secret' => $pi->client_secret, 'payment_intent' => $pi->id, 'importo' => $acconto]);
}

/**
 * Crea Checkout Session per l'acconto (flusso principale dal modale Prenota).
 * POST body: come acconto-intent. Unico line item = acconto totale, quantità 1.
 * setup_future_usage off_session sul PaymentIntent (per il saldo futuro).
 * Stripe Connect: nessuna integrazione esistente — predisposizione futura qui
 * (payment_intent_data.transfer_data / application_fee_amount).
 */
function pagamento_acconto_checkout(): void
{
    $io = richiedi_utente_attivo();
    [$colletta_id, $quantita, $tipo, $idSede, $indirizzo, $acconto, $nomeProdotto] = pagamento_prepara_acconto($io, corpo());

    $cfg = require __DIR__ . '/../config.php';
    $customer = pagamento_customer($io, utente_email());
    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);
    $base = rtrim($cfg['frontend_url'] ?? '', '/') . '/app.html#/campagne/' . $colletta_id;
    $meta = [
        'tipo' => 'acconto',
        'colletta_id' => $colletta_id,
        'quantita' => $quantita,
        'utente_id' => $io,
        'delivery_type' => $tipo,
        'id_sede' => $idSede ?? '',
        'indirizzo' => mb_substr($indirizzo ?? '', 0, 400),
    ];
    try {
        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'customer' => $customer,
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => "Acconto $nomeProdotto — $quantita " . ($quantita === 1 ? 'pezzo' : 'pezzi'),
                        'description' => 'Acconto campagna #' . $colletta_id,
                    ],
                    'unit_amount' => (int)round($acconto * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'payment_intent_data' => [
                'setup_future_usage' => 'off_session',
                'metadata' => $meta,
            ],
            'success_url' => $base . '?pagamento=in_verifica&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $base,
            'metadata' => $meta,
        ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        throw new AppError('STRIPE_ERRORE', 'Errore Stripe: ' . $e->getMessage(), 500);
    }

    json_ok(['checkout_url' => $session->url, 'session_id' => $session->id, 'importo' => $acconto]);
}

/**
 * Crea la partecipazione da un PaymentIntent acconto riuscito (solo webhook).
 * Idempotente su stripe_payment_intent_id. Rimborsa se campagna chiusa o doppia.
 */
function webhook_acconto_crea(object $pi, int $evento_id): void
{
    $cfg = require __DIR__ . '/../config.php';
    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);
    $pdo = db();
    $meta = $pi->metadata ?? new stdClass();
    $colletta_id = (int)($meta->colletta_id ?? 0);
    $quantita = max(1, (int)($meta->quantita ?? 1));
    $io = (int)($meta->utente_id ?? 0);
    $delivery = (string)($meta->delivery_type ?? '');
    $idSede = ($meta->id_sede ?? '') !== '' ? (int)$meta->id_sede : null;
    $indirizzo = trim((string)($meta->indirizzo ?? ''));
    $piId = (string)($pi->id ?? '');
    $acconto = round(((float)($pi->amount_received ?? $pi->amount ?? 0)) / 100, 2);

    $segna = function () use ($pdo, $evento_id) {
        $pdo->prepare('UPDATE eventi_stripe SET elaborato = TRUE WHERE id = ?')->execute([$evento_id]);
    };

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM pagamenti WHERE stripe_payment_intent_id = ?');
        $st->execute([$piId]);
        if ($st->fetch()) {
            $segna();
            $pdo->commit();
            return;
        }
        if ($colletta_id < 1 || $io < 1 || !in_array($delivery, ['ritiro_sede', 'consegna_domicilio'], true)) {
            error_log("webhook acconto: metadati mancanti per PI $piId");
            $segna();
            $pdo->commit();
            return;
        }

        $statoPrima = ricalcola_stato($colletta_id);
        $st = $pdo->prepare('SELECT id FROM prenotazioni WHERE id_colletta = ? AND id_utente = ?');
        $st->execute([$colletta_id, $io]);
        $gia = (bool)$st->fetch();
        if ($gia || !in_array($statoPrima, ['in_corso', 'riuscita'], true)) {
            try {
                $stripe->refunds->create(['payment_intent' => $piId]);
            } catch (Throwable $eRef) {
                error_log("webhook acconto: rimborso fallito per PI $piId: " . $eRef->getMessage());
            }
            $segna();
            $pdo->commit();
            return;
        }

        $st = $pdo->prepare(
            'INSERT INTO prenotazioni (id_colletta, id_utente, quantita, importo_acconto, stato)
             VALUES (?, ?, ?, ?, \'prenotata\')'
        );
        $st->execute([$colletta_id, $io, $quantita, $acconto]);
        $prenId = (int)$pdo->lastInsertId();

        $costoSped = 0.0;
        if ($delivery === 'consegna_domicilio') {
            $costoSped = round((float)($cfg['costo_spedizione'] ?? 0), 2);
        }
        $pdo->prepare(
            'INSERT INTO consegne (id_prenotazione, modalita, id_sede, indirizzo_consegna, importo_consegna, stato)
             VALUES (?, ?, ?, ?, ?, \'in_attesa\')'
        )->execute([$prenId, $delivery, $idSede, ($indirizzo !== '' ? $indirizzo : null), $costoSped]);

        $pdo->prepare(
            'INSERT INTO pagamenti (id_prenotazione, tipo_pagamento, importo, commissione_agenzia,
                                    stripe_payment_intent_id, stripe_payment_method, stato_stripe, stato, data_pagamento, data_conferma)
             VALUES (?, \'acconto\', ?, 0, ?, ?, ?, \'confermato\', NOW(), NOW())'
        )->execute([$prenId, $acconto, $piId, (string)($pi->payment_method ?? ''), (string)($pi->status ?? 'succeeded')]);

        $pdo->prepare('UPDATE collette SET quantita_attuale = quantita_attuale + ? WHERE id = ?')->execute([$quantita, $colletta_id]);
        $nuovoStato = ricalcola_stato($colletta_id);

        $stProd = $pdo->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?');
        $stProd->execute([$colletta_id]);
        $nomeProdotto = $stProd->fetchColumn() ?: 'una campagna';

        $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'ORDINE_CONFERMATO\', \'Prenotazione confermata\', ?, \'colletta\', ?)'
        )->execute([$io, "La tua prenotazione per \"$nomeProdotto\" ($quantita " . ($quantita === 1 ? 'pezzo' : 'pezzi') . ") è confermata. Acconto pagato: €" . number_format($acconto, 2, ',', '.') . ".", $colletta_id]);

        if ($statoPrima === 'in_corso' && $nuovoStato === 'riuscita') {
            $pdo->prepare("UPDATE prenotazioni SET stato = 'confermata' WHERE id_colletta = ? AND stato = 'prenotata'")->execute([$colletta_id]);
            $stU = $pdo->prepare('SELECT DISTINCT id_utente FROM prenotazioni WHERE id_colletta = ?');
            $stU->execute([$colletta_id]);
            $stN = $pdo->prepare(
                'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                 VALUES (?, \'MOQ_RAGGIUNTO\', \'Minimo raggiunto\', ?, \'colletta\', ?)'
            );
            $stDeadline = $pdo->prepare('SELECT data_limite FROM collette WHERE id = ?');
            $stDeadline->execute([$colletta_id]);
            $dl = $stDeadline->fetchColumn();
            $dataTxt = $dl ? date('d/m/Y', strtotime($dl)) : '';
            foreach ($stU->fetchAll() as $u) {
                $msg = "Il gruppo ha raggiunto il minimo per \"$nomeProdotto\"! La campagna resta aperta fino al $dataTxt: più persone aderiscono, più il prezzo può ancora scendere.";
                $stN->execute([(int)$u['id_utente'], $msg, $colletta_id]);
            }
            try {
                $stForn = $pdo->prepare(
                    'SELECT f.id_utente FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto
                      JOIN fornitori f ON f.id = pr.id_fornitore WHERE c.id = ?'
                );
                $stForn->execute([$colletta_id]);
                $fornUtente = $stForn->fetchColumn();
                if ($fornUtente) {
                    $stN->execute([(int)$fornUtente, "Soglia MOQ raggiunta per \"$nomeProdotto\"! Preparati a evadere l'ordine cumulativo.", $colletta_id]);
                }
            } catch (Throwable $eNotify) {
                error_log("webhook acconto: notify fornitore fallita per colletta #$colletta_id: " . $eNotify->getMessage());
            }
        }

        $segna();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare('UPDATE eventi_stripe SET elaborato = FALSE WHERE id = ?')->execute([$evento_id]);
        error_log('webhook acconto errore: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Finalizza un saldo (da webhook PI tipo=saldo o da chiusura sincrona).
 * Idempotente: se già pagata, non fa nulla. Ritorna true se ora pagata.
 */
function saldo_completa(PDO $pdo, int $prenotazione_id, int $colletta_id, int $utente_id, ?string $stripe_pi_id, float $importo): bool
{
    $st = $pdo->prepare(
        'SELECT p.id, p.stato, p.quantita, p.importo_saldo, c.percentuale_commissione
           FROM prenotazioni p JOIN collette c ON c.id = p.id_colletta
          WHERE p.id = ? FOR UPDATE'
    );
    $st->execute([$prenotazione_id]);
    $pren = $st->fetch();
    if (!$pren || $pren['stato'] === 'rimborsata') return false;
    if ($pren['stato'] === 'pagata') return true;

    $commissione = round($importo * (float)$pren['percentuale_commissione'] / 100, 2);
    $pdo->prepare(
        'INSERT INTO pagamenti (id_prenotazione, tipo_pagamento, importo, commissione_agenzia,
                                stripe_payment_intent_id, stato_stripe, stato, data_pagamento, data_conferma)
         VALUES (?, \'saldo\', ?, ?, ?, \'succeeded\', \'confermato\', NOW(), NOW())'
    )->execute([$prenotazione_id, $importo, $commissione, $stripe_pi_id]);

    $stCons = $pdo->prepare('SELECT modalita FROM consegne WHERE id_prenotazione = ?');
    $stCons->execute([$prenotazione_id]);
    $modalita = $stCons->fetchColumn() ?: 'ritiro_sede';
    if ($modalita !== 'consegna_domicilio') {
        $token = bin2hex(random_bytes(24));
        $pdo->prepare(
            'INSERT INTO qr_codes (id_prenotazione, token, quantita_assegnata, stato)
             VALUES (?, ?, ?, \'generato\')'
        )->execute([$prenotazione_id, $token, (int)$pren['quantita']]);
    }

    $pdo->prepare(
        "UPDATE prenotazioni SET stato = 'pagata', data_pagamento = NOW(), importo_commissione = ? WHERE id = ?"
    )->execute([$commissione, $prenotazione_id]);

    $stProg = $pdo->prepare(
        "SELECT COUNT(*) AS totale, SUM(CASE WHEN stato = 'pagata' THEN 1 ELSE 0 END) AS pagati
           FROM prenotazioni WHERE id_colletta = ?"
    );
    $stProg->execute([$colletta_id]);
    $prog = $stProg->fetch();
    $progresso = ((int)($prog['pagati'] ?? 0)) . '/' . ((int)($prog['totale'] ?? 0)) . ' pagati';

    $msgUtente = $modalita === 'consegna_domicilio'
        ? "Pagamento di EUR " . number_format($importo, 2, ',', '.') . " ricevuto con successo. Ti avviseremo quando il tuo ordine verrà spedito. ($progresso)"
        : "Pagamento di EUR " . number_format($importo, 2, ',', '.') . " ricevuto con successo. ($progresso)";
    $pdo->prepare(
        'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
         VALUES (?, \'PAGAMENTO_RIUSCITO\', \'Pagamento Ricevuto\', ?, \'prenotazione\', ?)'
    )->execute([$utente_id, $msgUtente, $prenotazione_id]);

    $nuovoStato = ricalcola_stato($colletta_id);
    $stTot = $pdo->prepare("SELECT COUNT(*), SUM(CASE WHEN stato = 'pagata' THEN 1 ELSE 0 END) FROM prenotazioni WHERE id_colletta = ?");
    $stTot->execute([$colletta_id]);
    [$tot2, $pag2] = array_map('intval', $stTot->fetch(PDO::FETCH_NUM));
    if ($nuovoStato === 'ordine_pronto' && $pag2 > 0 && $pag2 === $tot2) {
        try {
            invia_ordine_fornitore_al($colletta_id, null);
        } catch (Throwable $eAuto) {
            error_log("saldo_completa: invio automatico colletta #$colletta_id fallito: " . $eAuto->getMessage());
        }
    }
    return true;
}

/**
 * Riprova il pagamento del saldo per una prenotazione in azione_richiesta.
 * POST body: { prenotazione_id } — crea Checkout Session del solo saldo restante.
 */
function pagamento_riprova(): void
{
    $io = richiedi_utente_attivo();
    $d = corpo();
    $prenotazione_id = campo_int($d, 'prenotazione_id');

    $st = db()->prepare(
        'SELECT p.id, p.id_colletta, p.quantita, p.stato, p.importo_acconto,
                c.prezzo_corrente, c.percentuale_commissione, pr.nome AS prodotto_nome
           FROM prenotazioni p
           JOIN collette c ON c.id = p.id_colletta
           JOIN prodotti pr ON pr.id = c.id_prodotto
          WHERE p.id = ? AND p.id_utente = ?'
    );
    $st->execute([$prenotazione_id, $io]);
    $pren = $st->fetch();
    if (!$pren) throw new AppError('PRENOTAZIONE_NON_TROVATA', 'Prenotazione non trovata', 404);
    if ($pren['stato'] !== 'azione_richiesta') {
        throw new AppError('STATO_NON_VALIDO', 'Nessuna azione richiesta per questa prenotazione. Stato: ' . $pren['stato']);
    }

    $totale = round((float)$pren['prezzo_corrente'] * (int)$pren['quantita'] * (1 + (float)$pren['percentuale_commissione'] / 100), 2);
    $restante = round(max(0.50, $totale - (float)($pren['importo_acconto'] ?? 0)), 2);

    $cfg = require __DIR__ . '/../config.php';
    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);
    try {
        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'customer_email' => utente_email(),
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => 'Saldo ' . $pren['prodotto_nome'] . ' — ' . $pren['quantita'] . ' pezzi',
                        'description' => 'Saldo campagna #' . $pren['id_colletta'],
                    ],
                    'unit_amount' => (int)round($restante * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $cfg['frontend_url'] . '/app.html#/pagamento/successo?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cfg['frontend_url'] . '/app.html#/pagamento/annullato',
            'metadata' => ['prenotazione_id' => $prenotazione_id, 'colletta_id' => $pren['id_colletta'], 'utente_id' => $io, 'tipo' => 'saldo-retry'],
        ]);
        db()->prepare('UPDATE prenotazioni SET importo_saldo = ? WHERE id = ?')->execute([$totale, $prenotazione_id]);
        json_ok(['checkout_url' => $session->url, 'session_id' => $session->id, 'importo' => $restante]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        throw new AppError('STRIPE_ERRORE', 'Errore Stripe: ' . $e->getMessage(), 500);
    }
}
