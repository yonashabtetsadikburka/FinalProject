<?php
declare(strict_types=1);

/**
 * RIPARTIZIONE
 * Chiamata solo dall'admin quando la soglia MOQ e' raggiunta.
 * 1. Verifica che lo stato sia 'riuscita'
 * 2. Ripartisce le quantita' tra i partecipanti ( metodo dei resti maggiori )
 * 3. Salva la quantita' assegnata e imposta stato = 'confermata' (abilita pagamento)
 * 4. Aggiorna lo stato della colletta a 'ordine_pronto'
 * 5. Notifica tutti i partecipanti che possono procedere al pagamento
 */
function assegnazioni_ripartisci(int $colletta_id): void
{
    $io = richiedi_login();
    if (!sono_admin()) {
        throw new AppError('NON_AUTORIZZATO', 'Solo un amministratore puo\' confermare un ordine', 403);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1. Verifica che lo stato sia 'riuscita'
        $st = $pdo->prepare(
            'SELECT id, stato, quantita_attuale, quantita_minima,
                    id_prodotto, percentuale_commissione, prezzo_corrente
               FROM collette WHERE id = ? FOR UPDATE'
        );
        $st->execute([$colletta_id]);
        $c = $st->fetch();
        if (!$c) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);

        $stato = ricalcola_stato($colletta_id);
        if ($stato !== 'riuscita') {
            throw new AppError('CAMPAGNA_NON_RIUSCITA',
                'La campagna deve essere in stato "riuscita" per generare gli ordini. Stato attuale: ' . $stato
            );
        }

        // 2. Verifica idempotenza: se le prenotazioni sono gia' confermate, non rifare
        $st = $pdo->prepare('SELECT COUNT(*) FROM prenotazioni WHERE id_colletta = ? AND stato = \'confermata\'');
        $st->execute([$colletta_id]);
        if ((int)$st->fetchColumn() > 0) {
            $pdo->rollBack();
            throw new AppError('GIA_RIPARTITA', 'La ripartizione e\' gia\' stata effettuata', 409);
        }

        // 3. Leggi le prenotazioni attive
        $st = $pdo->prepare(
            'SELECT p.id AS id_prenotazione, p.id_utente AS utente_id, p.quantita, p.data_prenotazione AS created_at
               FROM prenotazioni p
              WHERE p.id_colletta = ? AND p.stato = \'prenotata\'
              ORDER BY p.data_prenotazione ASC'
        );
        $st->execute([$colletta_id]);
        $richieste = $st->fetchAll();

        if (empty($richieste)) {
            $pdo->rollBack();
            throw new AppError('NESSUNA_PARTECIPAZIONE', 'Nessuna prenotazione attiva per questa campagna');
        }

        // 4. Ripartisci le quantita' (pezzi interi)
        $pezzi = (int)$c['quantita_attuale'];
        $assegnazioni = ripartisci($richieste, $pezzi);

        // 5. Aggiorna prenotazioni: salva quantita' assegnata e imposta 'confermata'
        foreach ($assegnazioni as $asg) {
            $st = $pdo->prepare(
                'UPDATE prenotazioni SET quantita = ?, stato = \'confermata\' WHERE id = ?'
            );
            $st->execute([$asg['quantita'], $asg['id_prenotazione']]);
        }

        // 6. Aggiorna stato colletta a 'ordine_pronto' (in attesa dei pagamenti)
        $st = $pdo->prepare(
            'UPDATE collette SET stato = \'ordine_pronto\', id_admin_conferma = ?, data_conferma = NOW(), data_agg_stato = NOW()
             WHERE id = ?'
        );
        $st->execute([$io, $colletta_id]);

        // 7. Notifica tutti i partecipanti che possono procedere al pagamento
        $prezzo_corrente = (float)$c['prezzo_corrente'];
        $commissione_pct = (float)$c['percentuale_commissione'];

        $stProdotto = $pdo->prepare('SELECT pr.nome FROM prodotti pr WHERE pr.id = ?');
        $stProdotto->execute([(int)$c['id_prodotto']]);
        $nomeProdotto = $stProdotto->fetchColumn() ?: 'una campagna';

        $stNotifica = $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'ORDINE_CONFERMATO\', \'Ordine Confermato\', ?, \'colletta\', ?)'
        );
        foreach ($assegnazioni as $asg) {
            $importo_pezzi = round($prezzo_corrente * $asg['quantita'], 2);
            $commissione = round($importo_pezzi * $commissione_pct / 100, 2);
            $totale = round($importo_pezzi + $commissione, 2);
            $messaggio = "L'ordine per \"$nomeProdotto\" è stato confermato. Procedi al pagamento di EUR " . number_format($totale, 2, ',', '.') . ".";
            $stNotifica->execute([$asg['utente_id'], $messaggio, $colletta_id]);
        }

        $pdo->commit();

        json_ok([
            'totale_pezzi' => $pezzi,
            'assegnazioni' => count($assegnazioni),
            'messaggio' => 'Ordine confermato. Gli utenti possono procedere al pagamento.'
        ]);

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Elenco delle assegnazioni (QR) di una campagna.
 */
function assegnazioni_elenco(int $colletta_id): void
{
    richiedi_login();

    $st = db()->prepare(
        'SELECT qr.id, qr.quantita_assegnata, qr.stato, qr.token,
                p.id_utente, u.nome, u.cognome
           FROM qr_codes qr
           JOIN prenotazioni p ON p.id = qr.id_prenotazione
           JOIN utenti u ON u.id = p.id_utente
          WHERE p.id_colletta = ?
          ORDER BY u.nome'
    );
    $st->execute([$colletta_id]);

    json_ok(array_map(function ($row) {
        $row['id'] = (int)$row['id'];
        $row['quantita_assegnata'] = (int)$row['quantita_assegnata'];
        $row['id_utente'] = (int)$row['id_utente'];
        $row['utente_nome'] = trim($row['nome'] . ' ' . $row['cognome']);
        unset($row['nome'], $row['cognome']);
        return $row;
    }, $st->fetchAll()));
}
