<?php
declare(strict_types=1);

/**
 * SELECT dell'avanzamento collette.
 * Usa: collette, prodotti, fornitori, sedi, prenotazioni
 */
function sql_collette(): string
{
    return
    'SELECT c.id, c.stato, c.data_limite, c.data_inizio, c.quantita_minima, c.quantita_attuale,
            c.regola_arrotondamento, c.prezzo_base, c.prezzo_corrente,
            c.percentuale_commissione, c.data_conferma, c.chiusura_data,
            c.id_prodotto, c.id_aperta_da, c.id_referente, c.id_sede,
            pr.nome AS prodotto, pr.descrizione AS descrizione_prodotto, pr.prezzo_unitario, pr.quantita_minima AS lotto_prodotto,
            pr.id_categoria, cat.nome AS categoria,
            (SELECT url FROM immagini_prodotto WHERE id_prodotto = pr.id ORDER BY principale DESC, ordine ASC LIMIT 1) AS immagine,
            (SELECT CONCAT("[", GROUP_CONCAT(JSON_OBJECT("id", id, "url", url, "principale", principale, "ordine", ordine) ORDER BY principale DESC, ordine ASC SEPARATOR ","), "]") FROM immagini_prodotto WHERE id_prodotto = pr.id) AS immagini,
            f.nome_azienda AS fornitore, f.id AS fornitore_id,
            s.nome AS punto_ritiro, s.citta,
            COALESCE(SUM(p.quantita), 0) AS quantita_totale,
            COUNT(DISTINCT p.id_utente) AS partecipanti
       FROM collette c
       JOIN prodotti  pr ON pr.id = c.id_prodotto
       JOIN fornitori f  ON f.id  = pr.id_fornitore
  LEFT JOIN categorie cat ON cat.id = pr.id_categoria
  LEFT JOIN sedi         s ON s.id_sede = c.id_sede
  LEFT JOIN prenotazioni p ON p.id_colletta = c.id';
}

/** Converte i tipi: MySQL restituisce tutto come stringa. */
function normalizza_colletta(array $r): array
{
    foreach (['id','quantita_minima','quantita_attuale','quantita_totale',
              'partecipanti','id_prodotto','id_aperta_da','id_referente',
              'id_sede','fornitore_id','lotto_prodotto','id_categoria'] as $k) {
        if (isset($r[$k])) $r[$k] = (int)$r[$k];
    }
    foreach (['prezzo_base','prezzo_corrente','percentuale_commissione',
              'prezzo_unitario'] as $k) {
        if (isset($r[$k])) $r[$k] = (float)$r[$k];
    }
    $r['soglia_raggiunta'] = $r['quantita_attuale'] >= $r['quantita_minima'];
    // Decodifica array immagini (JSON da GROUP_CONCAT); fallback a [immagine]
    $imgs = [];
    if (!empty($r['immagini']) && is_string($r['immagini'])) {
        $dec = json_decode($r['immagini'], true);
        if (is_array($dec)) {
            foreach ($dec as $im) {
                if (is_array($im) && !empty($im['url'])) {
                    $imgs[] = ['id' => (int)($im['id'] ?? 0), 'url' => (string)$im['url'], 'principale' => (int)($im['principale'] ?? 0), 'ordine' => (int)($im['ordine'] ?? 0)];
                }
            }
        }
    }
    if (empty($imgs) && !empty($r['immagine'])) {
        $imgs[] = ['url' => (string)$r['immagine'], 'principale' => 1, 'ordine' => 0];
    }
    unset($r['immagini']);
    $r['immagini'] = $imgs;
    return $r;
}

function campagne_elenco(): void
{
    // Pubblico: la lista non contiene dati personali, visibile anche agli ospiti.
    db()->exec('SET SESSION group_concat_max_len = 100000');
    $st = db()->query(sql_collette() . ' GROUP BY c.id ORDER BY c.data_limite ASC');
    $out = array_map('normalizza_colletta', $st->fetchAll());

    foreach ($out as &$c) { $c['stato'] = ricalcola_stato($c['id']); }
    unset($c);
    foreach ($out as $c) {
        if ($c['stato'] === 'fallita') notifica_esito_fallita((int)$c['id']);
    }

    // Scaglioni + rating fornitore in 2 query aggregate (per card lista)
    $ids = array_map(fn($c) => (int)$c['id'], $out);
    $fids = array_values(array_unique(array_map(fn($c) => (int)($c['fornitore_id'] ?? 0), $out)));
    $scaglioni = [];
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stS = db()->prepare(
            "SELECT id_colletta, soglia_partecipanti AS soglia, prezzo_unitario AS prezzo
               FROM scaglioni_prezzo WHERE id_colletta IN ($ph) ORDER BY id_colletta, soglia_partecipanti ASC"
        );
        $stS->execute($ids);
        foreach ($stS->fetchAll() as $s) {
            $scaglioni[(int)$s['id_colletta']][] = ['soglia' => (int)$s['soglia'], 'prezzo' => (float)$s['prezzo']];
        }
    }
    $rating = [];
    if (!empty($fids)) {
        $ph = implode(',', array_fill(0, count($fids), '?'));
        $stR = db()->prepare(
            "SELECT id_fornitore, COUNT(*) AS tot, AVG(voto) AS media
               FROM recensioni_fornitore WHERE id_fornitore IN ($ph) GROUP BY id_fornitore"
        );
        $stR->execute($fids);
        foreach ($stR->fetchAll() as $r) {
            $tot = (int)$r['tot'];
            $rating[(int)$r['id_fornitore']] = [
                'media' => $tot > 0 ? round((float)$r['media'], 1) : null,
                'totale' => $tot,
            ];
        }
    }
    foreach ($out as &$c) {
        $c['scaglioni'] = $scaglioni[$c['id']] ?? [];
        $c['fornitore_rating'] = $rating[(int)($c['fornitore_id'] ?? 0)] ?? ['media' => null, 'totale' => 0];
        $c['stato_campagna'] = stato_campagna_display($c, $c['scaglioni']);
    }
    unset($c);
    json_ok($out);
}

function campagne_dettaglio(int $id): void
{
    // Pubblico per gli ospiti: senza login si omette la lista partecipanti.
    $io = utente_corrente_id();
    db()->exec('SET SESSION group_concat_max_len = 100000');
    $st = db()->prepare(sql_collette() . ' WHERE c.id = ? GROUP BY c.id');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);

    $c = normalizza_colletta($c);
    $c['stato'] = ricalcola_stato($id);
    if ($c['stato'] === 'fallita') notifica_esito_fallita($id);

    // Scaglioni prezzo della campagna (soglia -> prezzo), per il box vetrina
    $stS = db()->prepare(
        'SELECT soglia_partecipanti AS soglia, prezzo_unitario AS prezzo
           FROM scaglioni_prezzo WHERE id_colletta = ? ORDER BY soglia_partecipanti ASC'
    );
    $stS->execute([$id]);
    $c['scaglioni'] = array_map(function ($s) {
        return ['soglia' => (int)$s['soglia'], 'prezzo' => (float)$s['prezzo']];
    }, $stS->fetchAll());
    $c['stato_campagna'] = stato_campagna_display($c, $c['scaglioni']);

    // Rating fornitore: media + numero recensioni
    $stR = db()->prepare(
        'SELECT COUNT(*) AS tot, AVG(voto) AS media FROM recensioni_fornitore WHERE id_fornitore = ?'
    );
    $stR->execute([(int)($c['fornitore_id'] ?? 0)]);
    $rr = $stR->fetch();
    $c['fornitore_rating'] = [
        'media' => $rr && $rr['tot'] > 0 ? round((float)$rr['media'], 1) : null,
        'totale' => (int)($rr['tot'] ?? 0),
    ];

    // Info fornitore per la vetrina (evita chiamate extra dal frontend)
    $stF = db()->prepare(
        'SELECT nome_azienda AS nome, data_partnership,
                (SELECT COUNT(DISTINCT co.id) FROM prodotti pr2
                   JOIN collette co ON co.id_prodotto = pr2.id
                  WHERE pr2.id_fornitore = f.id) AS num_campagne
           FROM fornitori f WHERE f.id = ?'
    );
    $stF->execute([(int)($c['fornitore_id'] ?? 0)]);
    $fr = $stF->fetch();
    $c['fornitore_info'] = $fr ? [
        'nome' => (string)$fr['nome'],
        'data_partnership' => $fr['data_partnership'],
        'num_campagne' => (int)$fr['num_campagne'],
    ] : null;

    // Rating campagna: media + numero recensioni della colletta
    $stRc = db()->prepare(
        'SELECT COUNT(*) AS tot, AVG(voto) AS media FROM recensioni_campagna WHERE id_colletta = ?'
    );
    $stRc->execute([$id]);
    $rc = $stRc->fetch();
    $c['campagna_rating'] = [
        'media' => $rc && $rc['tot'] > 0 ? round((float)$rc['media'], 1) : null,
        'totale' => (int)($rc['tot'] ?? 0),
    ];

    $st = db()->prepare(
        'SELECT p.id, p.id_utente, u.nome, u.cognome, p.quantita, p.stato, p.data_prenotazione,
                qr.stato AS stato_qr,
                co.id AS id_consegna, co.modalita AS consegna_modalita, co.importo_consegna, co.stato AS consegna_stato
           FROM prenotazioni p JOIN utenti u ON u.id = p.id_utente
      LEFT JOIN qr_codes qr ON qr.id_prenotazione = p.id
      LEFT JOIN consegne co ON co.id_prenotazione = p.id
          WHERE p.id_colletta = ? ORDER BY p.data_prenotazione ASC');
    $st->execute([$id]);
    $c['partecipazioni'] = array_map(function ($p) {
        $p['id'] = (int)$p['id'];
        $p['id_utente'] = (int)$p['id_utente'];
        $p['quantita'] = (int)$p['quantita'];
        return $p;
    }, $st->fetchAll());

    $mio = utente_corrente_id();
    $c['mia_partecipazione'] = null;
    if ($mio === null) {
        // Ospite: niente lista partecipanti (privacy), solo conteggi.
        $c['partecipazioni'] = [];
        json_ok($c);
        return;
    }
    foreach ($c['partecipazioni'] as $p) {
        if ($p['id_utente'] === $mio) {
            $c['mia_partecipazione'] = [
                'id'     => $p['id'],
                'quantita' => $p['quantita'],
                'stato'  => $p['stato'],
                'stato_qr' => $p['stato_qr'] ?? null,
                'id_consegna' => isset($p['id_consegna']) ? (int)$p['id_consegna'] : null,
                'consegna_modalita' => $p['consegna_modalita'] ?? null,
                'importo_consegna' => isset($p['importo_consegna']) ? (float)$p['importo_consegna'] : 0.0,
                'consegna_stato' => $p['consegna_stato'] ?? null,
            ];
        }
    }
    json_ok($c);
}

/**
 * Chiusura campagna alla scadenza: addebita off_session il saldo alle confermate.
 * POST /campagne/{id}/chiusura — admin in sessione OPPURE cron con ?secret= (config chiusura_secret).
 * Body opzionale: { "id_colletta": N } per chiudere una singola campagna (bypass id route).
 * Idempotente per colletta (colonna chiusura_data).
 *
 * Cron esempio (ogni ora):
 *   curl -s -X POST "http://localhost:8888/buypool/api/campagne/chiusura?secret=..." 
 */
function campagne_chiusura(int $idRoute = 0): void
{
    $cfg = require __DIR__ . '/../config.php';
    $secret = (string)($cfg['chiusura_secret'] ?? '');
    $dato = $_GET['secret'] ?? '';
    if (!sono_admin()) {
        if ($secret === '' || !hash_equals($secret, (string)$dato)) {
            throw new AppError('NON_AUTORIZZATO', 'Solo admin o cron autorizzato', 403);
        }
    }
    $d = corpo();
    $solo = isset($d['id_colletta']) ? (int)$d['id_colletta'] : $idRoute;

    $pdo = db();
    if ($solo > 0) {
        $st = $pdo->prepare('SELECT id, data_limite FROM collette WHERE id = ?');
        $st->execute([$solo]);
        $una = $st->fetch();
        if (!$una) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);
        if (strtotime($una['data_limite'] ?? '') > time()) {
            throw new AppError('CHIUSURA_ANTICIPATA', 'Chiusura possibile solo dopo la scadenza', 409);
        }
        $ids = [$solo];
    } else {
        $st = $pdo->query(
            "SELECT id FROM collette
              WHERE data_limite <= NOW() AND chiusura_data IS NULL
                AND stato NOT IN ('consegnata','annullata','ordine_pronto','ordine_fornitore')"
        );
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    $riepilogo = ['campagne' => 0, 'pagate' => 0, 'azione_richiesta' => 0, 'rimborsate' => 0, 'saltate' => 0, 'gia_chiusa' => false];
    foreach ($ids as $cid) {
        $r = chiusura_colletta((int)$cid);
        $riepilogo['campagne']++;
        $riepilogo['pagate'] += $r['pagate'];
        $riepilogo['azione_richiesta'] += $r['azione_richiesta'];
        $riepilogo['rimborsate'] += $r['rimborsate'];
        $riepilogo['saltate'] += $r['saltate'];
        if (!empty($r['gia_chiusa'])) $riepilogo['gia_chiusa'] = true;
    }
    json_ok($riepilogo);
}

/**
 * Chiude una singola colletta: per ogni prenotazione 'confermata' addebita il
 * saldo via Stripe off_session (PM salvato con l'acconto). Fallimento ->
 * stato 'azione_richiesta' + notifica PAGAMENTO_FALLITO (retry via /pagamento/riprova).
 */
function chiusura_colletta(int $cid): array
{
    $pdo = db();
    $res = ['pagate' => 0, 'azione_richiesta' => 0, 'rimborsate' => 0, 'saltate' => 0, 'gia_chiusa' => false];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, stato, chiusura_data, data_limite, quantita_attuale FROM collette WHERE id = ? FOR UPDATE');
        $st->execute([$cid]);
        $c = $st->fetch();
        if (!$c) {
            $pdo->rollBack();
            $res['saltate']++;
            return $res;
        }
        if ($c['chiusura_data'] !== null) {
            $pdo->rollBack();
            $res['saltate']++;
            $res['gia_chiusa'] = true;
            return $res;
        }
        $stato = ricalcola_stato($cid);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("chiusura colletta #$cid errore lettura: " . $e->getMessage());
        $res['saltate']++;
        return $res;
    }

    if (in_array($stato, ['fallita', 'annullata'], true)) {
        $res['rimborsate'] = chiusura_rimborsi($cid);
        $pdo->prepare('UPDATE collette SET chiusura_data = NOW() WHERE id = ? AND chiusura_data IS NULL')->execute([$cid]);
        return $res;
    }
    if (!in_array($stato, ['in_corso', 'riuscita'], true)) {
        $res['saltate']++;
        return $res;
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT id, quantita_attuale, quantita_minima, data_limite, prezzo_corrente, percentuale_commissione
               FROM collette WHERE id = ? FOR UPDATE'
        );
        $st->execute([$cid]);
        $c = $st->fetch();
        $st = $pdo->prepare(
            "SELECT p.id, p.id_utente, p.quantita, p.stato, p.importo_acconto, pr.nome AS prodotto
               FROM prenotazioni p
               JOIN collette c ON c.id = p.id_colletta
               JOIN prodotti pr ON pr.id = c.id_prodotto
              WHERE p.id_colletta = ? AND p.stato = 'confermata' FOR UPDATE"
        );
        $st->execute([$cid]);
        $confermate = $st->fetchAll();
        $st = $pdo->prepare(
            'SELECT soglia_partecipanti AS soglia, prezzo_unitario AS prezzo
               FROM scaglioni_prezzo WHERE id_colletta = ? ORDER BY soglia_partecipanti ASC'
        );
        $st->execute([$cid]);
        $scaglioni = array_map(
            fn($s) => ['soglia' => (int)$s['soglia'], 'prezzo' => (float)$s['prezzo']],
            $st->fetchAll()
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("chiusura colletta #$cid errore lettura: " . $e->getMessage());
        $res['saltate']++;
        return $res;
    }

    $totale = (int)$c['quantita_attuale'];
    $minimo = !empty($scaglioni) ? (int)$scaglioni[0]['soglia'] : (int)$c['quantita_minima'];
    if ($totale < $minimo) {
        $res['rimborsate'] = chiusura_rimborsi($cid);
        $pdo->prepare('UPDATE collette SET chiusura_data = NOW() WHERE id = ? AND chiusura_data IS NULL')->execute([$cid]);
        return $res;
    }

    // Scaglione più alto effettivamente raggiunto (non il primo)
    $prezzoFinale = (float)$c['prezzo_corrente'];
    foreach ($scaglioni as $s) {
        if ($totale >= $s['soglia']) $prezzoFinale = (float)$s['prezzo'];
    }
    $comm = (float)$c['percentuale_commissione'];

    $cfg = require __DIR__ . '/../config.php';
    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);
    $prodotto = '';
    foreach ($confermate as $p) {
        $pid = (int)$p['id'];
        $uid = (int)$p['id_utente'];
        $prodotto = (string)($p['prodotto'] ?? $prodotto ?: 'un prodotto');
        // Saldo = qty × prezzo finale × (1+commissione) − acconto già versato
        $saldo = round((int)$p['quantita'] * $prezzoFinale * (1 + $comm / 100) - (float)($p['importo_acconto'] ?? 0), 2);
        if ($saldo <= 0) {
            $pdo->beginTransaction();
            try {
                saldo_completa($pdo, $pid, $cid, $uid, null, 0.0);
                $pdo->commit();
                $res['pagate']++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("chiusura colletta #$cid prenotazione #$pid saldo-zero fallita: " . $e->getMessage());
                $res['saltate']++;
            }
            continue;
        }
        $stPm = $pdo->prepare(
            "SELECT stripe_payment_method, stripe_customer_id FROM (
                SELECT pg.stripe_payment_method AS stripe_payment_method, u.stripe_customer_id AS stripe_customer_id
                  FROM pagamenti pg JOIN prenotazioni p2 ON p2.id = pg.id_prenotazione
                  JOIN utenti u ON u.id = p2.id_utente
                 WHERE pg.id_prenotazione = ? AND pg.tipo_pagamento = 'acconto'
                 ORDER BY pg.id DESC LIMIT 1
             ) t"
        );
        $stPm->execute([$pid]);
        $pm = $stPm->fetch();
        $pmId = $pm['stripe_payment_method'] ?? null;
        $customerId = $pm['stripe_customer_id'] ?? null;
        if (!$pmId || !$customerId) {
            segna_azione_richiesta($cid, $pid, $uid, (string)($p['prodotto'] ?? 'un prodotto'), $saldo, 'Metodo di pagamento mancante');
            $res['azione_richiesta']++;
            continue;
        }
        try {
            // Senza redirect: l'account ha metodi con redirect abilitati,
            // quindi li escludiamo (l'addebito off_session non può reindirizzare).
            $pi = $stripe->paymentIntents->create([
                'amount' => (int)round($saldo * 100),
                'currency' => 'eur',
                'customer' => $customerId,
                'payment_method' => $pmId,
                'off_session' => true,
                'confirm' => true,
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                'metadata' => [
                    'tipo' => 'saldo',
                    'prenotazione_id' => $pid,
                    'colletta_id' => $cid,
                    'utente_id' => $uid,
                ],
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            $code = $e->getError()->code ?? '';
            $msg = $code === 'authentication_required'
                ? 'La banca richiede una nuova autorizzazione per addebitare il saldo.'
                : ('Addebito saldo fallito: ' . $e->getMessage());
            segna_azione_richiesta($cid, $pid, $uid, (string)($p['prodotto'] ?? 'un prodotto'), $saldo, $msg);
            $res['azione_richiesta']++;
            continue;
        } catch (Throwable $e) {
            segna_azione_richiesta($cid, $pid, $uid, (string)($p['prodotto'] ?? 'un prodotto'), $saldo, 'Addebito saldo fallito: ' . $e->getMessage());
            $res['azione_richiesta']++;
            continue;
        }
        if (($pi->status ?? '') === 'succeeded') {
            $pdo->beginTransaction();
            try {
                saldo_completa($pdo, $pid, $cid, $uid, (string)$pi->id, $saldo);
                $pdo->commit();
                $res['pagate']++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("chiusura colletta #$cid finalize saldo #$pid fallito: " . $e->getMessage());
                $res['saltate']++;
            }
        } else {
            segna_azione_richiesta($cid, $pid, $uid, (string)($p['prodotto'] ?? 'un prodotto'), $saldo, 'Addebito saldo non completato (stato Stripe: ' . ($pi->status ?? '?') . ').');
            $res['azione_richiesta']++;
        }
    }

    // Catena admin: pronta per la conferma manuale di invio al fornitore
    $pdo->prepare("UPDATE collette SET stato = 'ordine_pronto', data_agg_stato = NOW(), chiusura_data = NOW() WHERE id = ? AND chiusura_data IS NULL")->execute([$cid]);
    return $res;
}

/**
 * Rimborsa gli acconti versati di una campagna fallita/sotto-minimo.
 * Ritorna il numero di rimborsi eseguiti. Idempotente (salta rimborsate).
 */
function chiusura_rimborsi(int $cid): int
{
    $pdo = db();
    $cfg = require __DIR__ . '/../config.php';
    $stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);
    $n = 0;
    $st = $pdo->prepare(
        "SELECT p.id, p.id_utente, p.importo_acconto, pg.stripe_payment_intent_id
           FROM prenotazioni p
           LEFT JOIN pagamenti pg ON pg.id_prenotazione = p.id AND pg.tipo_pagamento = 'acconto'
          WHERE p.id_colletta = ? AND p.stato IN ('prenotata','confermata') AND COALESCE(p.importo_acconto, 0) > 0"
    );
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $p) {
        $pid = (int)$p['id'];
        try {
            if (!empty($p['stripe_payment_intent_id'])) {
                $stripe->refunds->create(['payment_intent' => $p['stripe_payment_intent_id']]);
            }
            $pdo->prepare("UPDATE prenotazioni SET stato = 'rimborsata' WHERE id = ? AND stato IN ('prenotata','confermata')")->execute([$pid]);
            $n++;
        } catch (Throwable $e) {
            error_log("chiusura rimborsi colletta #$cid prenotazione #$pid fallito: " . $e->getMessage());
        }
    }
    return $n;
}

/** Marca azione_richiesta + notifica con retry. */
function segna_azione_richiesta(int $cid, int $pid, int $uid, string $prodotto, float $saldo, string $dettaglio): void
{
    $pdo = db();
    $pdo->prepare("UPDATE prenotazioni SET stato = 'azione_richiesta' WHERE id = ?")->execute([$pid]);
    $pdo->prepare(
        'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
         VALUES (?, \'PAGAMENTO_FALLITO\', \'Azione richiesta\', ?, \'prenotazione\', ?)'
    )->execute([$uid, "Non siamo riusciti ad addebitare il saldo di €" . number_format($saldo, 2, ',', '.') . " per \"$prodotto\". $dettaglio Completa il pagamento dalla pagina I miei ordini.", $pid]);
}
/**
 * Elimina una campagna. Solo admin, solo se senza pagamenti (qualsiasi stato).
 * A cascata: prenotazioni, ordini_fornitore, scaglioni, qr. Notifiche e prodotti restano.
 * DELETE /campagne/{id}
 */
function campagne_elimina(int $id): void
{
    richiedi_login();
    if (!sono_admin()) throw new AppError('NON_ADMIN', 'Accesso riservato agli amministratori', 403);

    $st = db()->prepare('SELECT id FROM collette WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);

    $st = db()->prepare(
        'SELECT COUNT(*) FROM pagamenti pg
           JOIN prenotazioni p ON p.id = pg.id_prenotazione
          WHERE p.id_colletta = ?'
    );
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        throw new AppError('HA_PAGAMENTI', 'Impossibile eliminare: esistono pagamenti registrati', 409);
    }

    db()->prepare('DELETE FROM collette WHERE id = ?')->execute([$id]);
    json_ok(['eliminata' => true]);
}

/**
 * Valida e normalizza gli scaglioni prezzo [{soglia, prezzo}].
 * Accetta array o stringa JSON. Ritorna lista ordinata per soglia.
 * Array vuoto = nessun box scaglioni (valido).
 */
function valida_scaglioni($raw): array
{
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') return [];
        $dec = json_decode($raw, true);
        if (!is_array($dec)) throw new AppError('SCAGLIONI_NON_VALIDI', 'Formato scaglioni non valido');
        $raw = $dec;
    }
    if (!is_array($raw)) throw new AppError('SCAGLIONI_NON_VALIDI', 'Formato scaglioni non valido');
    if (count($raw) > 10) throw new AppError('SCAGLIONI_NON_VALIDI', 'Massimo 10 scaglioni');
    $out = [];
    $viste = [];
    foreach ($raw as $s) {
        if (!is_array($s)) throw new AppError('SCAGLIONI_NON_VALIDI', 'Formato scaglioni non valido');
        $soglia = (int)($s['soglia'] ?? $s['soglia_partecipanti'] ?? 0);
        $prezzo = (float)($s['prezzo'] ?? $s['prezzo_unitario'] ?? 0);
        if ($soglia < 1) throw new AppError('SCAGLIONI_NON_VALIDI', 'Ogni soglia deve essere almeno 1');
        if ($prezzo <= 0) throw new AppError('SCAGLIONI_NON_VALIDI', 'Ogni prezzo deve essere maggiore di 0');
        if (isset($viste[$soglia])) throw new AppError('SCAGLIONI_NON_VALIDI', 'Soglie duplicate non ammesse');
        $viste[$soglia] = true;
        $out[] = ['soglia' => $soglia, 'prezzo' => round($prezzo, 2)];
    }
    usort($out, fn($a, $b) => $a['soglia'] <=> $b['soglia']);
    return $out;
}

/**
 * Crea campagna (solo admin). POST /campagne.
 * Accetta multipart (con foto[]) o JSON. Due modalita:
 *  - prodotto_id: campagna su prodotto esistente
 *  - nome + id_fornitore + prezzi + moq: crea prodotto + campagna in un colpo
 */
function campagne_crea(): void
{
    $io = richiedi_login();
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $d = (stripos($ct, 'multipart/form-data') !== false) ? $_POST : corpo();
    $scadenza = trim((string)($d['scadenza'] ?? ''));

    $ts = strtotime($scadenza);
    if ($ts === false) throw new AppError('DATA_NON_VALIDA', 'Formato scadenza non valido');
    if ($ts <= time())  throw new AppError('DATA_NEL_PASSATO', 'La scadenza deve essere futura');

    $pdo = db();
    $pdo->beginTransaction();
    $fotoSalvate = [];
    try {
        $prodotto_id = isset($d['prodotto_id']) && $d['prodotto_id'] !== '' ? (int)$d['prodotto_id'] : 0;
        if ($prodotto_id < 1) {
            // Crea prodotto al volo
            $nome = trim((string)($d['nome'] ?? ''));
            if ($nome === '') throw new AppError('NOME_MANCANTE', 'Nome prodotto obbligatorio');
            if (mb_strlen($nome) > 80) throw new AppError('NOME_TROPPO_LUNGO', 'Massimo 80 caratteri');
            $fid = (int)($d['id_fornitore'] ?? 0);
            if ($fid < 1) throw new AppError('FORNITORE_MANCANTE', 'Fornitore obbligatorio');
            $st = $pdo->prepare('SELECT id FROM fornitori WHERE id = ?');
            $st->execute([$fid]);
            if (!$st->fetch()) throw new AppError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);
            $prezzoCorr = (float)($d['prezzo_corrente'] ?? 0);
            if ($prezzoCorr <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzo scontato non valido');
            $prezzoBase = isset($d['prezzo_base']) && $d['prezzo_base'] !== ''
                ? (float)$d['prezzo_base'] : $prezzoCorr;
            if ($prezzoBase <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzo di listino non valido');
            $moq = isset($d['quantita_minima']) && $d['quantita_minima'] !== '' ? (int)$d['quantita_minima'] : 1;
            if ($moq < 1) throw new AppError('MOQ_NON_VALIDO', 'Quantita minima deve essere almeno 1');
            $cat = isset($d['id_categoria']) && $d['id_categoria'] !== '' ? (int)$d['id_categoria'] : null;
            $pdo->prepare(
                'INSERT INTO prodotti (id_fornitore, id_categoria, nome, descrizione, prezzo_unitario, prezzo_base, quantita_minima, stato)
                 VALUES (?,?,?,?,?,?,?, \'attivo\')'
            )->execute([$fid, $cat, mb_substr($nome, 0, 80), trim((string)($d['descrizione'] ?? '')),
                $prezzoCorr, $prezzoBase, $moq]);
            $prodotto_id = (int)$pdo->lastInsertId();
            $p = ['quantita_minima' => $moq, 'prezzo_unitario' => $prezzoCorr, 'prezzo_base' => $prezzoBase];
        } else {
            $st = $pdo->prepare('SELECT quantita_minima, prezzo_unitario, prezzo_base FROM prodotti WHERE id = ?');
            $st->execute([$prodotto_id]);
            $p = $st->fetch();
            if (!$p) throw new AppError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);
        }

        $prezzoCorrC = isset($d['prezzo_corrente']) && $d['prezzo_corrente'] !== ''
            ? (float)$d['prezzo_corrente'] : (float)$p['prezzo_unitario'];
        $prezzoBaseC = isset($d['prezzo_base']) && $d['prezzo_base'] !== ''
            ? (float)$d['prezzo_base'] : (float)($p['prezzo_base'] ?? $prezzoCorrC);
        $moqC = isset($d['quantita_minima']) && $d['quantita_minima'] !== ''
            ? (int)$d['quantita_minima'] : (int)$p['quantita_minima'];
        if ($prezzoCorrC <= 0 || $prezzoBaseC <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzi non validi');
        if ($moqC < 1) throw new AppError('MOQ_NON_VALIDO', 'Quantita minima deve essere almeno 1');
        $comm = isset($d['percentuale_commissione']) && $d['percentuale_commissione'] !== ''
            ? (float)$d['percentuale_commissione'] : 10.0;
        if ($comm < 0 || $comm > 100) throw new AppError('COMMISSIONE_NON_VALIDA', 'Commissione tra 0 e 100');

        $st = $pdo->prepare(
            'INSERT INTO collette
               (id_prodotto, id_aperta_da, id_referente, id_sede,
                quantita_minima, data_limite, prezzo_base, prezzo_corrente, percentuale_commissione)
             VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $prodotto_id, $io, $io,
            isset($d['sede_id']) && $d['sede_id'] !== '' ? (int)$d['sede_id'] : null,
            $moqC,
            date('Y-m-d H:i:s', $ts),
            round($prezzoBaseC, 2), round($prezzoCorrC, 2), round($comm, 2),
        ]);
        $id = (int)$pdo->lastInsertId();

        // Scaglioni prezzo opzionali (JSON in campo 'scaglioni')
        if (isset($d['scaglioni'])) {
            $scaglioni = valida_scaglioni($d['scaglioni']);
            if (!empty($scaglioni)) {
                $stS = $pdo->prepare(
                    'INSERT INTO scaglioni_prezzo (id_colletta, soglia_partecipanti, prezzo_unitario) VALUES (?,?,?)'
                );
                foreach ($scaglioni as $s) {
                    $stS->execute([$id, $s['soglia'], $s['prezzo']]);
                }
            }
        }

        // Foto opzionali (foto[] multiplo): prima come principale
        $files = [];
        if (!empty($_FILES['foto'])) {
            $f = $_FILES['foto'];
            if (is_array($f['error'])) {
                foreach ($f['error'] as $i => $err) {
                    if ($err !== UPLOAD_ERR_NO_FILE) {
                        $files[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i],
                            'tmp_name' => $f['tmp_name'][$i], 'error' => $err, 'size' => $f['size'][$i]];
                    }
                }
            } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $files[] = $f;
            }
        }
        $ord = 0;
        foreach ($files as $i => $f) {
            $url = fornitore_salva_foto($f);
            $fotoSalvate[] = $url;
            $pdo->prepare(
                'INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,?,?)'
            )->execute([$prodotto_id, $url, $ord++, ($i === 0 ? 1 : 0)]);
        }

        $pdo->commit();
        json_ok(['id' => $id, 'id_prodotto' => $prodotto_id], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($fotoSalvate as $u) @unlink(__DIR__ . '/../' . $u);
        throw $e;
    }
}

/**
 * Elenco ordini per l'admin: campagne con almeno una partecipazione + dettaglio partecipazioni.
 */
function admin_ordini(): void
{
    richiedi_login();
    if (!sono_admin()) throw new AppError('NON_ADMIN', 'Accesso riservato agli amministratori', 403);

    $st = db()->query(
        "SELECT c.id, c.stato, c.quantita_minima, c.quantita_attuale, c.data_limite,
                pr.nome AS prodotto, f.nome_azienda AS fornitore
           FROM collette c
           JOIN prodotti pr  ON pr.id = c.id_prodotto
           JOIN fornitori f  ON f.id  = pr.id_fornitore
          WHERE (SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = c.id) > 0
          ORDER BY c.data_limite ASC"
    );
    $campagne = $st->fetchAll();

    foreach ($campagne as &$c) {
        $c = normalizza_colletta($c);
        $c['stato'] = ricalcola_stato($c['id']);

        $st2 = db()->prepare(
            'SELECT p.id, p.id_utente, u.nome, u.cognome, p.quantita, p.stato
               FROM prenotazioni p JOIN utenti u ON u.id = p.id_utente
              WHERE p.id_colletta = ? ORDER BY p.data_prenotazione ASC'
        );
        $st2->execute([$c['id']]);
        $c['partecipazioni'] = $st2->fetchAll();
    }

    json_ok($campagne);
}

/**
 * Invia ordine al fornitore.
 * Solo se tutte le prenotazioni sono 'pagata'.
 * POST /campagne/{id}/invia-fornitore
 */
function campagne_invia_fornitore(int $colletta_id): void
{
    $io = richiedi_login();
    if (!sono_admin()) throw new AppError('NON_ADMIN', 'Accesso riservato agli amministratori', 403);

    $ris = invia_ordine_fornitore_al($colletta_id, $io);
    json_ok([
        'messaggio' => 'Ordine inviato al fornitore con successo.',
        'totale_pezzi' => $ris['totale_pezzi'],
        'importo_ordine' => $ris['importo_ordine'],
    ]);
}

/**
 * Logica di invio ordine (riusabile senza sessione admin, es. webhook).
 * $id_admin null = invio automatico di sistema.
 */
function invia_ordine_fornitore_al(int $colletta_id, ?int $id_admin): array
{
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, stato, id_prodotto, quantita_attuale, prezzo_corrente FROM collette WHERE id = ? FOR UPDATE');
        $st->execute([$colletta_id]);
        $c = $st->fetch();
        if (!$c) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);

        if ($c['stato'] !== 'ordine_pronto') {
            throw new AppError('STATO_NON_VALIDO', 'La campagna deve essere in stato "ordine_pronto". Stato attuale: ' . $c['stato']);
        }

        // Verifica che TUTTE le prenotazioni siano pagate
        $st = $pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = ? AND stato != \'pagata\'');
        $st->execute([$colletta_id]);
        $nonPagati = (int)$st->fetchColumn();
        if ($nonPagati > 0) {
            throw new AppError('PAGAMENTI_INCOMPLETI', "Ci sono ancora $nonPagati partecipanti che non hanno pagato.");
        }

        // Crea o aggiorna ordine al fornitore
        $pezzi = (int)$c['quantita_attuale'];
        $importo_totale = round($pezzi * (float)$c['prezzo_corrente'], 2);

        $st = $pdo->prepare('SELECT id_fornitore FROM prodotti WHERE id = ?');
        $st->execute([(int)$c['id_prodotto']]);
        $fornitore_id = (int)$st->fetchColumn();

        // Verifica se esiste gia' un ordine per questa colletta
        $st = $pdo->prepare('SELECT id FROM ordini_fornitore WHERE id_colletta = ?');
        $st->execute([$colletta_id]);
        $ordineEsistente = $st->fetch();

        if ($ordineEsistente) {
            $st = $pdo->prepare(
                'UPDATE ordini_fornitore SET id_fornitore = ?, id_admin = ?, quantita_ordinata = ?, importo_totale = ?, stato = \'inviato\', data_ordine = NOW() WHERE id = ?'
            );
            $st->execute([$fornitore_id, $id_admin, $pezzi, $importo_totale, (int)$ordineEsistente['id']]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO ordini_fornitore (id_colletta, id_fornitore, id_admin, quantita_ordinata, importo_totale, stato, data_ordine)
                 VALUES (?,?,?,?,?,\'inviato\',NOW())'
            );
            $st->execute([$colletta_id, $fornitore_id, $id_admin, $pezzi, $importo_totale]);
        }

        // Aggiorna stato colletta
        $st = $pdo->prepare('UPDATE collette SET stato = \'ordine_fornitore\', data_agg_stato = NOW() WHERE id = ?');
        $st->execute([$colletta_id]);

        // Notifica tutti i partecipanti
        $stProdotto = $pdo->prepare('SELECT pr.nome FROM prodotti pr WHERE pr.id = ?');
        $stProdotto->execute([(int)$c['id_prodotto']]);
        $nomeProdotto = $stProdotto->fetchColumn() ?: 'un prodotto';

        $stFornitore = $pdo->prepare('SELECT nome_azienda FROM fornitori WHERE id = ?');
        $stFornitore->execute([$fornitore_id]);
        $nomeFornitore = $stFornitore->fetchColumn() ?: 'il fornitore';

        $stUtenti = $pdo->prepare('SELECT DISTINCT id_utente FROM prenotazioni WHERE id_colletta = ?');
        $stUtenti->execute([$colletta_id]);
        $utenti = $stUtenti->fetchAll(PDO::FETCH_COLUMN);

        $stNotifica = $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'ORDINE_INVIATO\', \'Ordine Inviato\', ?, \'colletta\', ?)'
        );
        foreach ($utenti as $utenteId) {
            $messaggio = "L'ordine per \"$nomeProdotto\" è stato inviato al fornitore $nomeFornitore. Riceverai una notifica quando sarà consegnato.";
            $stNotifica->execute([$utenteId, $messaggio, $colletta_id]);
        }

        if ($ownTransaction) $pdo->commit();

        return ['totale_pezzi' => $pezzi, 'importo_ordine' => $importo_totale];

    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Modifica una campagna (solo admin). POST /campagne/{id}/modifica.
 * Accetta JSON o multipart (per upload foto).
 * Campi: data_limite, quantita_minima, prezzo_corrente, prezzo_base, percentuale_commissione, foto (file), scaglioni (JSON).
 * Bloccato se ordine gia' partito (ordine_fornitore/consegnata).
 * Gli scaglioni non sono modificabili se la campagna ha gia' adesioni.
 */
function campagne_modifica(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);

    // Accept both JSON and multipart (FormData)
    $raw = file_get_contents('php://input');
    $isMultipart = !empty($_POST);
    if ($isMultipart) {
        $d = array_map(fn($v) => trim((string)$v), $_POST);
    } elseif ($raw !== false && $raw !== '') {
        $d = json_decode($raw, true);
        if (!is_array($d)) throw new AppError('JSON_NON_VALIDO', 'JSON non valido');
    } else {
        $d = [];
    }

    $hasFoto = !empty($_FILES['foto']) && (($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT id, stato, id_prodotto, quantita_attuale FROM collette WHERE id = ? FOR UPDATE'
        );
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            $pdo->rollBack();
            throw new AppError('CAMPAGNA_NON_TROVATA', 'Campagna non trovata', 404);
        }
        if (in_array($c['stato'], ['ordine_fornitore', 'consegnata'], true)) {
            $pdo->rollBack();
            throw new AppError('CAMPAGNA_NON_MODIFICABILE', 'Campagna non modificabile: ordine gia\' partito', 409);
        }

        $campi = [];
        $par = [];

        if (isset($d['data_limite']) && $d['data_limite'] !== '') {
            $dt = trim((string)$d['data_limite']);
            $ts = strtotime($dt);
            if ($ts === false || $ts < time()) {
                $pdo->rollBack();
                throw new AppError('SCADENZA_NON_VALIDA', 'La scadenza deve essere una data futura');
            }
            $campi[] = 'data_limite = ?';
            $par[] = date('Y-m-d H:i:s', $ts);
        }

        if (isset($d['quantita_minima']) && $d['quantita_minima'] !== '') {
            $moq = (int)$d['quantita_minima'];
            if ($moq < 1) {
                $pdo->rollBack();
                throw new AppError('MOQ_NON_VALIDO', 'MOQ deve essere almeno 1');
            }
            $campi[] = 'quantita_minima = ?';
            $par[] = $moq;
        }

        if (isset($d['prezzo_corrente']) && $d['prezzo_corrente'] !== '') {
            $pc = (float)$d['prezzo_corrente'];
            if ($pc <= 0) {
                $pdo->rollBack();
                throw new AppError('PREZZO_NON_VALIDO', 'Prezzo corrente deve essere maggiore di 0');
            }
            $campi[] = 'prezzo_corrente = ?';
            $par[] = round($pc, 2);
        }

        if (isset($d['prezzo_base']) && $d['prezzo_base'] !== '') {
            $pb = (float)$d['prezzo_base'];
            if ($pb <= 0) {
                $pdo->rollBack();
                throw new AppError('PREZZO_NON_VALIDO', 'Prezzo base deve essere maggiore di 0');
            }
            $campi[] = 'prezzo_base = ?';
            $par[] = round($pb, 2);
        }

        if (isset($d['percentuale_commissione']) && $d['percentuale_commissione'] !== '') {
            $comm = (float)$d['percentuale_commissione'];
            if ($comm < 0 || $comm > 100) {
                $pdo->rollBack();
                throw new AppError('COMMISSIONE_NON_VALIDA', 'Commissione deve essere tra 0 e 100');
            }
            $campi[] = 'percentuale_commissione = ?';
            $par[] = round($comm, 2);
        }

        if (!empty($campi)) {
            $par[] = $id;
            $pdo->prepare('UPDATE collette SET ' . implode(', ', $campi) . ' WHERE id = ?')->execute($par);
        }

        // Sostituzione scaglioni: bloccata se la campagna ha gia' adesioni
        if (isset($d['scaglioni']) && $d['scaglioni'] !== '') {
            $scaglioni = valida_scaglioni($d['scaglioni']);
            $stN = $pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = ?');
            $stN->execute([$id]);
            if ((int)$stN->fetchColumn() > 0) {
                $pdo->rollBack();
                throw new AppError('SCAGLIONI_BLOCCATI', 'Scaglioni non modificabili: la campagna ha gia\' adesioni', 409);
            }
            $pdo->prepare('DELETE FROM scaglioni_prezzo WHERE id_colletta = ?')->execute([$id]);
            if (!empty($scaglioni)) {
                $stS = $pdo->prepare(
                    'INSERT INTO scaglioni_prezzo (id_colletta, soglia_partecipanti, prezzo_unitario) VALUES (?,?,?)'
                );
                foreach ($scaglioni as $s) {
                    $stS->execute([$id, $s['soglia'], $s['prezzo']]);
                }
            }
        }

        // Sostituzione foto principale del prodotto collegato
        if ($hasFoto) {
            $nuova = fornitore_salva_foto($_FILES['foto']);
            try {
                $idProdotto = (int)$c['id_prodotto'];
                $stUrl = $pdo->prepare('SELECT url FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 1');
                $stUrl->execute([$idProdotto]);
                $vecchie = $stUrl->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare('UPDATE immagini_prodotto SET principale = 0 WHERE id_prodotto = ?')->execute([$idProdotto]);
                $pdo->prepare(
                    'INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,0,1)'
                )->execute([$idProdotto, $nuova]);
                foreach ($vecchie as $v) {
                    @unlink(__DIR__ . '/../' . $v);
                    $pdo->prepare('DELETE FROM immagini_prodotto WHERE id_prodotto = ? AND principale = 0 AND url = ?')->execute([$idProdotto, $v]);
                }
            } catch (Throwable $e) {
                @unlink(__DIR__ . '/../' . $nuova);
                throw $e;
            }
        }

        // Aggiunta immagini extra (foto[] multiplo): non sostituiscono la principale
        $extraFoto = [];
        if (!empty($_FILES['foto_extra'])) {
            $fe = $_FILES['foto_extra'];
            if (is_array($fe['error'])) {
                foreach ($fe['error'] as $i => $err) {
                    if ($err !== UPLOAD_ERR_NO_FILE) {
                        $extraFoto[] = ['name' => $fe['name'][$i], 'type' => $fe['type'][$i],
                            'tmp_name' => $fe['tmp_name'][$i], 'error' => $err, 'size' => $fe['size'][$i]];
                    }
                }
            }
        }
        if (!empty($extraFoto)) {
            $idProdotto = (int)$c['id_prodotto'];
            $stMax = $pdo->prepare('SELECT COALESCE(MAX(ordine), -1) FROM immagini_prodotto WHERE id_prodotto = ?');
            $stMax->execute([$idProdotto]);
            $nextOrd = (int)$stMax->fetchColumn() + 1;
            $salvate = [];
            try {
                foreach ($extraFoto as $f) {
                    $url = fornitore_salva_foto($f);
                    $salvate[] = $url;
                    $pdo->prepare(
                        'INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,?,0)'
                    )->execute([$idProdotto, $url, $nextOrd++]);
                }
            } catch (Throwable $e) {
                foreach ($salvate as $u) @unlink(__DIR__ . '/../' . $u);
                throw $e;
            }
        }

        $haScaglioni = isset($d['scaglioni']) && $d['scaglioni'] !== '';
        if (empty($campi) && !$hasFoto && empty($extraFoto) && !$haScaglioni) {
            $pdo->rollBack();
            throw new AppError('NESSUN_CAMPO', 'Nessun campo da aggiornare');
        }

        $nuovoStato = ricalcola_stato($id);

        $pdo->commit();
        json_ok(['id' => $id, 'stato' => $nuovoStato]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
