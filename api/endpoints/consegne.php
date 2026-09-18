<?php
declare(strict_types=1);

/**
 * ============================================================
 *  SCELTA CONSEGNA — ritiro in sede (gratis) o spedizione
 *  a domicilio (costo fisso da config). Scelta possibile solo
 *  prima del pagamento (prenotazione 'confermata').
 * ============================================================
 */

/**
 * Costo spedizione configurato (per mostrarlo prima della scelta).
 * GET /consegne/costo
 */
function consegne_costo(): void
{
    richiedi_login();
    $cfg = require __DIR__ . '/../config.php';
    json_ok(['costo_spedizione' => round((float)($cfg['costo_spedizione'] ?? 0), 2)]);
}

/**
 * Salva/modifica la scelta di consegna.
 * POST /consegne/scelta  { prenotazione_id, modalita: ritiro_sede|consegna_domicilio }
 */
function consegne_scelta(): void
{
    $io = richiedi_utente_attivo();
    $d = corpo();
    $prenotazione_id = campo_int($d, 'prenotazione_id');
    $modalita = campo($d, 'modalita');
    if (!in_array($modalita, ['ritiro_sede', 'consegna_domicilio'], true)) {
        throw new AppError('MODALITA_NON_VALIDA', 'Modalita di consegna non valida');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT id, id_colletta, stato FROM prenotazioni WHERE id = ? AND id_utente = ? FOR UPDATE'
        );
        $st->execute([$prenotazione_id, $io]);
        $pren = $st->fetch();
        if (!$pren) {
            $pdo->rollBack();
            throw new AppError('PRENOTAZIONE_NON_TROVATA', 'Prenotazione non trovata', 404);
        }
        if ($pren['stato'] !== 'confermata') {
            $pdo->rollBack();
            throw new AppError('SCELTA_NON_CONSENTITA', 'La scelta e\' possibile solo prima del pagamento');
        }

        $importo = 0.0;
        $indirizzo = null;
        if ($modalita === 'consegna_domicilio') {
            $cfg = require __DIR__ . '/../config.php';
            $importo = round((float)($cfg['costo_spedizione'] ?? 0), 2);
            $st = $pdo->prepare('SELECT indirizzo, cap, citta, provincia FROM utenti WHERE id = ?');
            $st->execute([$io]);
            $u = $st->fetch();
            $via = trim((string)($u['indirizzo'] ?? ''));
            $cap = trim((string)($u['cap'] ?? ''));
            $citta = trim((string)($u['citta'] ?? ''));
            $prov = trim((string)($u['provincia'] ?? ''));
            if ($via === '' || $citta === '' || $cap === '') {
                $pdo->rollBack();
                throw new AppError('INDIRIZZO_MANCANTE', 'Completa prima indirizzo, CAP e citta nel tuo Profilo');
            }
            $indirizzo = $via . ', ' . $cap . ' ' . $citta . ($prov !== '' ? ' (' . $prov . ')' : '');
        }

        $st = $pdo->prepare(
            'INSERT INTO consegne (id_prenotazione, modalita, indirizzo_consegna, importo_consegna, stato)
             VALUES (?,?,?,?, \'in_attesa\')
             ON DUPLICATE KEY UPDATE modalita = VALUES(modalita),
                                     indirizzo_consegna = VALUES(indirizzo_consegna),
                                     importo_consegna = VALUES(importo_consegna)'
        );
        $st->execute([$prenotazione_id, $modalita, $indirizzo, $importo]);
        $pdo->commit();

        json_ok(['modalita' => $modalita, 'importo_consegna' => $importo]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Elenco consegne per l'admin (solo spedizioni da gestire + storico).
 * GET /admin/consegne
 */
function consegne_admin_elenco(): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $st = db()->query(
        'SELECT co.id, co.id_prenotazione, co.modalita, co.indirizzo_consegna,
                co.importo_consegna, co.stato AS stato_consegna,
                p.quantita, p.stato AS stato_prenotazione,
                u.nome, u.cognome,
                pr.nome AS prodotto, c.id AS id_colletta
           FROM consegne co
           JOIN prenotazioni p ON p.id = co.id_prenotazione
           JOIN utenti u ON u.id = p.id_utente
           JOIN collette c ON c.id = p.id_colletta
           JOIN prodotti pr ON pr.id = c.id_prodotto
          ORDER BY co.id DESC'
    );
    $out = array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['id_prenotazione'] = (int)$r['id_prenotazione'];
        $r['id_colletta'] = (int)$r['id_colletta'];
        $r['quantita'] = (int)$r['quantita'];
        $r['importo_consegna'] = (float)$r['importo_consegna'];
        return $r;
    }, $st->fetchAll());
    json_ok($out);
}

/**
 * Segna una spedizione come spedita + notifica al cliente.
 * POST /consegne/{id}/spedisci (solo admin)
 */
function consegne_spedisci(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT co.id, co.modalita, co.stato, p.id_utente, p.id_colletta, pr.nome AS prodotto
               FROM consegne co
               JOIN prenotazioni p ON p.id = co.id_prenotazione
               JOIN collette c ON c.id = p.id_colletta
               JOIN prodotti pr ON pr.id = c.id_prodotto
              WHERE co.id = ? FOR UPDATE'
        );
        $st->execute([$id]);
        $co = $st->fetch();
        if (!$co) {
            $pdo->rollBack();
            throw new AppError('CONSEGNA_INESISTENTE', 'Consegna non trovata', 404);
        }
        if ($co['modalita'] !== 'consegna_domicilio') {
            $pdo->rollBack();
            throw new AppError('NON_SPEDIBILE', 'Solo le spedizioni a domicilio si possono segnare come spedite');
        }
        if ($co['stato'] === 'spedita') {
            $pdo->rollBack();
            throw new AppError('GIA_SPEDITA', 'Gia\' segnata come spedita', 409);
        }
        if ($co['stato'] === 'consegnata') {
            $pdo->rollBack();
            throw new AppError('GIA_CONSEGNATA', 'Ordine gia\' ricevuto dal cliente', 409);
        }

        $stOrd = $pdo->prepare(
            'SELECT ofo.stato FROM ordini_fornitore ofo WHERE ofo.id_colletta = ?'
        );
        $stOrd->execute([(int)$co['id_colletta']]);
        $statoOrd = $stOrd->fetchColumn();
        if ($statoOrd !== 'evaso' && $statoOrd !== 'consegnato') {
            $pdo->rollBack();
            throw new AppError('SPEDIZIONE_BLOCCATA', 'Non puoi spedire prima che il fornitore evada l\'ordine.', 409);
        }

        $pdo->prepare(
            "UPDATE consegne SET stato = 'spedita', data_effettiva = NOW() WHERE id = ?"
        )->execute([$id]);

        $st = $pdo->prepare('SELECT id_prenotazione FROM consegne WHERE id = ?');
        $st->execute([$id]);
        $id_pren = (int)$st->fetchColumn();
        $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'SPEDITO\', \'Ordine Spedito\', ?, \'prenotazione\', ?)'
        )->execute([
            (int)$co['id_utente'],
            "Il tuo ordine \"{$co['prodotto']}\" è stato spedito! Lo riceverai nei prossimi giorni.",
            $id_pren,
        ]);

        $pdo->commit();
        json_ok(['spedita' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Conferma ricezione spedizione da parte del cliente.
 * POST /consegne/{id}/conferma-ricezione
 * Solo proprietario, solo consegna_domicilio, solo stato spedita.
 */
function consegne_conferma_ricezione(int $id): void
{
    $io = richiedi_login();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT co.id, co.modalita, co.stato, p.id_utente, p.id_colletta, pr.nome AS prodotto
               FROM consegne co
               JOIN prenotazioni p ON p.id = co.id_prenotazione
               JOIN collette c ON c.id = p.id_colletta
               JOIN prodotti pr ON pr.id = c.id_prodotto
              WHERE co.id = ? FOR UPDATE'
        );
        $st->execute([$id]);
        $co = $st->fetch();
        if (!$co) {
            $pdo->rollBack();
            throw new AppError('CONSEGNA_INESISTENTE', 'Consegna non trovata', 404);
        }
        if ((int)$co['id_utente'] !== $io) {
            $pdo->rollBack();
            throw new AppError('NON_AUTORIZZATO', 'Non puoi confermare la ricezione di un altro utente', 403);
        }
        if ($co['modalita'] !== 'consegna_domicilio') {
            $pdo->rollBack();
            throw new AppError('NON_CONFERMABILE', 'Solo le spedizioni a domicilio si possono confermare');
        }
        if ($co['stato'] !== 'spedita') {
            $pdo->rollBack();
            throw new AppError('NON_CONFERMABILE', 'La consegna deve essere spedita prima di confermare la ricezione', 409);
        }

        $pdo->prepare(
            "UPDATE consegne SET stato = 'consegnata', data_effettiva = NOW() WHERE id = ?"
        )->execute([$id]);

        $st = $pdo->prepare('SELECT id_prenotazione FROM consegne WHERE id = ?');
        $st->execute([$id]);
        $id_pren = (int)$st->fetchColumn();
        $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'ORDINE_RICEVUTO\', \'Ordine Ricevuto\', ?, \'prenotazione\', ?)'
        )->execute([
            $io,
            "Hai confermato la ricezione di \"{$co['prodotto']}\". Grazie per il tuo acquisto!",
            $id_pren,
        ]);

        $pdo->commit();
        json_ok(['consegnata' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
