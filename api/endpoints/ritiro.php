<?php
declare(strict_types=1);

/**
 * QR del partecipante: mostra il token da mostrare al referente.
 */
function ritiro_qr(int $prenotazione_id): void
{
    $io = richiedi_login();

    $st = db()->prepare(
        'SELECT qr.token, qr.quantita_assegnata, qr.stato, qr.data_scansione,
                p.id_colletta, c.stato AS stato_colletta
           FROM qr_codes qr
           JOIN prenotazioni p ON p.id = qr.id_prenotazione
           JOIN collette c ON c.id = p.id_colletta
          WHERE qr.id_prenotazione = ? AND p.id_utente = ?'
    );
    $st->execute([$prenotazione_id, $io]);
    $q = $st->fetch();
    if (!$q) throw new AppError('QR_NON_TROVATO', 'QR code non trovato per questa prenotazione', 404);

    json_ok([
        'token'              => $q['token'],
        'quantita_assegnata' => (int)$q['quantita_assegnata'],
        'stato'              => $q['stato'],
        'scansionato'        => $q['stato'] === 'scansionato',
        'data_scansione'     => $q['data_scansione'],
        'stato_colletta'     => $q['stato_colletta'],
    ]);
}

/**
 * Storico ritiri per l'admin: tutti i QR con utente, prodotto e stato.
 * GET /admin/ritiri
 */
function ritiri_elenco(): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $st = db()->query(
        'SELECT qr.id, qr.token, qr.quantita_assegnata, qr.stato AS stato_qr, qr.data_scansione,
                p.id AS id_prenotazione, p.id_utente, p.id_colletta,
                u.nome, u.cognome,
                c.stato AS stato_colletta, pr.nome AS prodotto
           FROM qr_codes qr
           JOIN prenotazioni p ON p.id = qr.id_prenotazione
           JOIN utenti u ON u.id = p.id_utente
           JOIN collette c ON c.id = p.id_colletta
           JOIN prodotti pr ON pr.id = c.id_prodotto
          ORDER BY qr.data_scansione IS NULL, qr.id DESC'
    );
    $out = array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['id_prenotazione'] = (int)$r['id_prenotazione'];
        $r['id_utente'] = (int)$r['id_utente'];
        $r['id_colletta'] = (int)$r['id_colletta'];
        $r['quantita_assegnata'] = (int)$r['quantita_assegnata'];
        unset($r['token']);
        return $r;
    }, $st->fetchAll());
    json_ok($out);
}

/**
 * Scansione QR da parte del referente/admin.
 * Conferma il ritiro.
 *
 * FLUSSO:
 * 1. Valida il token
 * 2. Verifica che non sia gia' stato scansionato
 * 3. Solo admin puo' confermare
 * 4. Aggiorna stato QR a 'scansionato'
 * 5. Notifica al partecipante
 */
function ritiro_conferma(string $token): void
{
    $io = richiedi_login();
    if (!sono_admin()) {
        throw new AppError('NON_AUTORIZZATO', 'Solo un amministratore puo\' confermare il ritiro', 403);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1. Trova il QR con FOR UPDATE
        $st = $pdo->prepare(
            'SELECT qr.id, qr.quantita_assegnata, qr.stato,
                    p.id AS id_prenotazione, p.id_utente, p.id_colletta,
                    p.importo_saldo, p.importo_commissione
               FROM qr_codes qr
               JOIN prenotazioni p ON p.id = qr.id_prenotazione
              WHERE qr.token = ? FOR UPDATE'
        );
        $st->execute([$token]);
        $a = $st->fetch();

        if (!$a) {
            $pdo->rollBack();
            throw new AppError('TOKEN_NON_VALIDO', 'Token QR non valido', 404);
        }

        // 2. No doppio ritiro
        if ($a['stato'] === 'scansionato') {
            $pdo->rollBack();
            throw new AppError('GIA_RITIRATO', 'Questo articolo e\' gia\' stato ritirato', 409);
        }
        if ($a['stato'] === 'annullato') {
            $pdo->rollBack();
            throw new AppError('QR_ANNULLATO', 'Questo QR code e\' stato annullato');
        }

        // 3. Aggiorna stato QR
        $pdo->prepare(
            'UPDATE qr_codes SET stato = \'scansionato\', data_scansione = NOW() WHERE id = ?'
        )->execute([$a['id']]);

        // 4. Calcola importi
        $st = $pdo->prepare(
            'SELECT prezzo_corrente, percentuale_commissione FROM collette WHERE id = ?'
        );
        $st->execute([(int)$a['id_colletta']]);
        $colletta = $st->fetch();

        $prezzo_unitario = (float)$colletta['prezzo_corrente'];
        $commesso_pct   = (float)$colletta['percentuale_commissione'];
        $quantita       = (int)$a['quantita_assegnata'];
        $importo_totale = round($prezzo_unitario * $quantita, 2);
        $commissione    = round($importo_totale * $commesso_pct / 100, 2);

        // 5. Notifica all'utente
        $utente_id = (int)$a['id_utente'];
        $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'RITIRATO\', \'Ritiro Confermato\', ?, \'prenotazione\', ?)'
        )->execute([
            $utente_id,
            "Il tuo ordine \xC3\xA8 stato ritirato.",
            (int)$a['id_prenotazione'],
        ]);

        $pdo->commit();

        // Recupera dati utente per la risposta
        $st = db()->prepare('SELECT nome, cognome FROM utenti WHERE id = ?');
        $st->execute([$utente_id]);
        $u = $st->fetch();

        json_ok([
            'utente'            => trim(($u['nome'] ?? '') . ' ' . ($u['cognome'] ?? '')),
            'quantita'          => $quantita,
            'importo_totale'    => $importo_totale,
            'commissione'       => $commissione,
            'messaggio'         => "Ritiro confermato."
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
