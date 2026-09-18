<?php
declare(strict_types=1);

function proposte_elenco(): void
{
    $io = richiedi_login();
    // Rifiutate/respinte visibili solo all'autore (l'admin vede tutto)
    $filtro_rifiutate = sono_admin()
        ? ''
        : " AND (pp.stato NOT IN ('rifiutata','respinta_votazione') OR pp.proponente_id = " . (int)$io . ")";
    $sql = "SELECT pp.*, u.nome AS proponente_nome, u.cognome AS proponente_cognome,
                   f.nome_azienda AS fornitore_nome,
                   (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta = pp.id_proposta AND valore_voto = 'favore') AS tot_voti_calcolati,
                   (SELECT valore_voto FROM voti_proposte WHERE id_proposta = pp.id_proposta AND id_utente = " . (int)$io . ") AS mio_voto" .
        " FROM proposte_prodotti pp
                  JOIN utenti u ON u.id = pp.proponente_id
             LEFT JOIN fornitori f ON f.id = pp.id_fornitore_suggerito
             WHERE 1 = 1" . $filtro_rifiutate . "
             ORDER BY pp.data_proposta DESC";
    $st = db()->query($sql);
    $out = array_map(function ($p) {
        $p['id_proposta'] = (int)$p['id_proposta'];
        $p['proponente_id'] = (int)$p['proponente_id'];
        $p['id_fornitore_suggerito'] = $p['id_fornitore_suggerito'] !== null ? (int)$p['id_fornitore_suggerito'] : null;
        $p['tot_voti'] = (int)$p['tot_voti_calcolati'];
        return $p;
    }, $st->fetchAll());
    json_ok($out);
}

function proposte_crea(): void
{
    $io = richiedi_utente_attivo();
    $d = corpo();
    $nome = campo($d, 'nome_prodotto');
    $descrizione = trim((string)($d['descrizione'] ?? ''));
    $fornitore_id = isset($d['id_fornitore_suggerito']) ? campo_int($d, 'id_fornitore_suggerito') : null;

    $st = db()->prepare(
        'INSERT INTO proposte_prodotti (proponente_tipo, proponente_id, nome_prodotto, descrizione, id_fornitore_suggerito, stato)
         VALUES (?, ?, ?, ?, ?, \'in_attesa\')'
    );
    $ruolo = $_SESSION['ruolo'] ?? 'cliente';
    $st->execute([$ruolo, $io, $nome, $descrizione, $fornitore_id]);
    json_ok(['id_proposta' => (int)db()->lastInsertId()], 201);
}

function proposte_vota(int $id): void
{
    $io = richiedi_utente_attivo();
    $d = corpo();
    $valore = campo($d, 'valore_voto');
    if (!in_array($valore, ['favore', 'contrario'], true)) {
        throw new AppError('VOTO_NON_VALIDO', 'Valore voto non valido');
    }

    // Verifica che la proposta esista e sia in votazione
    $st = db()->prepare('SELECT stato FROM proposte_prodotti WHERE id_proposta = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) throw new AppError('PROPOSTA_INESISTENTE', 'Proposta non trovata', 404);
    if ($p['stato'] !== 'in_votazione') {
        throw new AppError('PROPOSTA_NON_VOTABILE', 'La proposta non e\' in fase di votazione');
    }

    // Inserisci o aggiorna voto
    try {
        $st = db()->prepare(
            'INSERT INTO voti_proposte (id_proposta, id_utente, valore_voto) VALUES (?, ?, ?)'
        );
        $st->execute([$id, $io, $valore]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Voto gia' espresso: aggiorna
            $st = db()->prepare(
                'UPDATE voti_proposte SET valore_voto = ?, data_voto = NOW() WHERE id_proposta = ? AND id_utente = ?'
            );
            $st->execute([$valore, $id, $io]);
        } else {
            throw $e;
        }
    }

    // Aggiorna tot_voti (conta solo voti favorevoli)
    $st = db()->prepare(
        'UPDATE proposte_prodotti SET tot_voti = (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta = ? AND valore_voto = ?) WHERE id_proposta = ?'
    );
    $st->execute([$id, 'favore', $id]);

    json_ok(['voto_registrato' => $valore]);
}

/**
 * Ritira il proprio voto.
 * DELETE /proposte/{id}/vota
 */
function proposte_ritira_voto(int $id): void
{
    $io = richiedi_login();

    $st = db()->prepare('SELECT id_proposta FROM proposte_prodotti WHERE id_proposta = ?');
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('PROPOSTA_INESISTENTE', 'Proposta non trovata', 404);

    $st = db()->prepare('DELETE FROM voti_proposte WHERE id_proposta = ? AND id_utente = ?');
    $st->execute([$id, $io]);
    if ($st->rowCount() === 0) {
        throw new AppError('VOTO_INESISTENTE', 'Non hai votato questa proposta', 404);
    }

    $st = db()->prepare(
        'UPDATE proposte_prodotti SET tot_voti = (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta = ? AND valore_voto = ?) WHERE id_proposta = ?'
    );
    $st->execute([$id, 'favore', $id]);

    json_ok(['voto_ritirato' => true]);
}

/**
 * Cambio stato proposta (solo admin).
 * PUT /proposte/{id}/stato  { stato, motivo?, id_fornitore? }
 * Su pubblicata: crea prodotto + campagna, blocco ripubblicazione, broadcast a tutti.
 */
function proposte_cambia_stato(int $id): void
{
    $io = richiedi_login();
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);

    $d = corpo();
    $nuovo = campo($d, 'stato');
    $validi = ['in_votazione', 'approvata_admin', 'rifiutata', 'pubblicata', 'respinta_votazione'];
    if (!in_array($nuovo, $validi, true)) throw new AppError('STATO_NON_VALIDO', 'Stato non valido');
    $motivo = trim((string)($d['motivo'] ?? ''));
    $fornitore_param = isset($d['id_fornitore']) ? (int)$d['id_fornitore'] : null;

    // Parametri di pubblicazione (solo per stato=pubblicata)
    $pub = [];
    if (array_key_exists('prezzo_base', $d) && $d['prezzo_base'] !== '' && $d['prezzo_base'] !== null) {
        $pub['prezzo_base'] = (float)$d['prezzo_base'];
        if ($pub['prezzo_base'] <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzo base non valido');
    }
    if (array_key_exists('prezzo_corrente', $d) && $d['prezzo_corrente'] !== '' && $d['prezzo_corrente'] !== null) {
        $pub['prezzo_corrente'] = (float)$d['prezzo_corrente'];
        if ($pub['prezzo_corrente'] <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzo attuale non valido');
    }
    if (array_key_exists('moq', $d) && $d['moq'] !== '' && $d['moq'] !== null) {
        $pub['moq'] = (int)$d['moq'];
        if ($pub['moq'] < 1) throw new AppError('MOQ_NON_VALIDO', 'MOQ deve essere almeno 1');
    }
    if (!empty($d['scadenza'])) {
        $ts = strtotime((string)$d['scadenza']);
        if ($ts === false) throw new AppError('DATA_NON_VALIDA', 'Formato scadenza non valido');
        if ($ts <= time()) throw new AppError('DATA_NEL_PASSATO', 'La scadenza deve essere futura');
        $pub['scadenza'] = date('Y-m-d H:i:s', $ts);
    }
    if (array_key_exists('commissione', $d) && $d['commissione'] !== '' && $d['commissione'] !== null) {
        $pub['commissione'] = (float)$d['commissione'];
        if ($pub['commissione'] < 0 || $pub['commissione'] > 100) {
            throw new AppError('COMMISSIONE_NON_VALIDA', 'Commissione tra 0 e 100');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM proposte_prodotti WHERE id_proposta = ? FOR UPDATE');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            $pdo->rollBack();
            throw new AppError('PROPOSTA_INESISTENTE', 'Proposta non trovata', 404);
        }

        // Pubblicazione consentita solo da votazione (o legacy approvata_admin)
        if ($nuovo === 'pubblicata' && !in_array($p['stato'], ['in_votazione', 'approvata_admin'], true)) {
            $pdo->rollBack();
            throw new AppError('PUBBLICAZIONE_NON_CONSENTITA', 'Solo una proposta in votazione puo\' essere pubblicata');
        }

        // Blocco ripubblicazione
        if ($nuovo === 'pubblicata') {
            $st = $pdo->prepare('SELECT id FROM prodotti WHERE id_proposta_origine = ?');
            $st->execute([$id]);
            if ($st->fetch()) {
                $pdo->rollBack();
                throw new AppError('GIA_PUBBLICATA', 'Questa proposta e\' gia\' stata pubblicata', 409);
            }
        }

        $pdo->prepare(
            'UPDATE proposte_prodotti SET stato = ?, id_admin_gestione = ?, data_gestione = NOW(), motivo = ? WHERE id_proposta = ?'
        )->execute([$nuovo, $io, $motivo !== '' ? $motivo : null, $id]);

        // Notifica al proponente
        $titolo = 'Proposta Aggiornata';
        $tipo = 'SISTEMA';
        $msg = "La tua proposta \"{$p['nome_prodotto']}\" è ora in stato: $nuovo.";
        if ($nuovo === 'approvata_admin') {
            $tipo = 'PROPOSTA_APPROVATA';
            $titolo = 'Proposta Approvata';
            $msg = "La tua proposta \"{$p['nome_prodotto']}\" è stata approvata! Sarà pubblicata come campagna.";
        } elseif ($nuovo === 'in_votazione' && $p['stato'] === 'in_attesa') {
            $tipo = 'PROPOSTA_APPROVATA';
            $titolo = 'Proposta Ammessa al Voto';
            $msg = "La tua proposta \"{$p['nome_prodotto']}\" è stata approvata ed è ora in votazione!";
        } elseif ($nuovo === 'rifiutata') {
            $titolo = 'Proposta Rifiutata';
            $msg = "La tua proposta \"{$p['nome_prodotto']}\" è stata rifiutata."
                . ($motivo !== '' ? " Motivo: $motivo" : '');
        }
        $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, ?, ?, ?, \'proposta\', ?)'
        )->execute([(int)$p['proponente_id'], $tipo, $titolo, $msg, $id]);

        $auto = null;
        if ($nuovo === 'pubblicata') {
            $auto = proposte_auto_crea($p, $io, $fornitore_param, $pub, $pdo);

            // Broadcast a tutti gli utenti attivi
            $stUt = $pdo->query("SELECT id FROM utenti WHERE stato = 'attivo' AND ruolo = 'cliente'");
            $stBc = $pdo->prepare(
                'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                 VALUES (?, \'ORDINE_DISPONIBILE\', \'Nuova Campagna\', ?, \'colletta\', ?)'
            );
            $msgBc = "Nuova campagna pubblicata: \"{$p['nome_prodotto']}\"! Partecipa prima della scadenza.";
            foreach ($stUt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $stBc->execute([(int)$uid, $msgBc, (int)$auto['id_colletta']]);
            }
        }

        $pdo->commit();
        json_ok(['stato' => $nuovo, 'auto_creazione' => $auto]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Auto-crea prodotto + campagna + scaglioni da proposta pubblicata (qualsiasi proponente).
 * Ritorna ['id_prodotto'=>..,'id_colletta'=>..].
 */
function proposte_auto_crea(array $p, int $admin_id, ?int $fornitore_param, array $pub, PDO $pdo): array
{
    $id_proposta = (int)$p['id_proposta'];

    // Fornitore proprietario: parametro admin, poi suggerito, poi account collegato
    $fornitore_id = $fornitore_param;
    if (!$fornitore_id && !empty($p['id_fornitore_suggerito'])) {
        $fornitore_id = (int)$p['id_fornitore_suggerito'];
    }
    if (!$fornitore_id) {
        $st = $pdo->prepare('SELECT id FROM fornitori WHERE id_utente = ?');
        $st->execute([(int)$p['proponente_id']]);
        $fornitore_id = (int)$st->fetchColumn();
    }
    if (!$fornitore_id) throw new AppError('FORNITORE_MANCANTE', 'Indicare il fornitore per pubblicare questa proposta');
    $st = $pdo->prepare('SELECT id FROM fornitori WHERE id = ?');
    $st->execute([$fornitore_id]);
    if (!$st->fetch()) throw new AppError('FORNITORE_INESISTENTE', 'Fornitore indicato non trovato', 404);

    $st = $pdo->prepare('SELECT id_proposta, soglia, prezzo FROM proposta_scaglioni WHERE id_proposta = ? ORDER BY soglia ASC');
    $st->execute([$id_proposta]);
    $scaglioni = $st->fetchAll();

    $prezzo = $pub['prezzo_base'] ?? ($p['prezzo_base'] !== null ? (float)$p['prezzo_base'] : null);
    if ($prezzo === null && !empty($scaglioni)) $prezzo = (float)$scaglioni[0]['prezzo'];
    if ($prezzo === null) $prezzo = 0.0;
    $prezzo_corrente = $pub['prezzo_corrente'] ?? ($p['prezzo_corrente'] !== null ? (float)$p['prezzo_corrente'] : $prezzo);
    $moq = $pub['moq'] ?? ($p['moq_richiesto'] !== null ? max(1, (int)$p['moq_richiesto']) : 1);
    $scadenza = $pub['scadenza'] ?? date('Y-m-d H:i:s', strtotime('+30 days'));
    $commissione = $pub['commissione'] ?? 10.00;

    $st = $pdo->prepare(
        'INSERT INTO prodotti (id_fornitore, id_proposta_origine, nome, descrizione, prezzo_unitario, quantita_minima, stato)
         VALUES (?,?,?,?,?,?,\'attivo\')'
    );
    $st->execute([$fornitore_id, $id_proposta, $p['nome_prodotto'], $p['descrizione'] ?? '', $prezzo, $moq]);
    $id_prodotto = (int)$pdo->lastInsertId();

    if (!empty($p['foto_path'])) {
        $pdo->prepare(
            'INSERT INTO immagini_prodotto (id_prodotto, url, ordine, principale) VALUES (?,?,0,1)'
        )->execute([$id_prodotto, $p['foto_path']]);
    }

    $st = $pdo->prepare(
        'INSERT INTO collette (id_prodotto, id_aperta_da, id_referente, quantita_minima, quantita_attuale,
                               data_limite, stato, prezzo_base, prezzo_corrente, percentuale_commissione)
         VALUES (?,?,?, ?, 0, ?, \'in_corso\', ?, ?, ?)'
    );
    $st->execute([$id_prodotto, $admin_id, $admin_id, $moq, $scadenza, $prezzo, $prezzo_corrente, $commissione]);
    $id_colletta = (int)$pdo->lastInsertId();

    if (!empty($scaglioni)) {
        $stS = $pdo->prepare('INSERT INTO scaglioni_prezzo (id_colletta, soglia_partecipanti, prezzo_unitario) VALUES (?,?,?)');
        foreach ($scaglioni as $s) {
            $stS->execute([$id_colletta, (int)$s['soglia'], (float)$s['prezzo']]);
        }
    }

    return ['id_prodotto' => $id_prodotto, 'id_colletta' => $id_colletta];
}
