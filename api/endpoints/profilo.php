<?php
declare(strict_types=1);

function profilo_leggi(): void
{
    $io = richiedi_login();
    $st = db()->prepare(
        'SELECT id, nome, cognome, email, telefono, indirizzo, cap, citta, provincia,
                partita_iva, codice_fiscale, tipo, ruolo, data_iscrizione
           FROM utenti WHERE id = ?'
    );
    $st->execute([$io]);
    $u = $st->fetch();
    if (!$u) throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);
    $u['id'] = (int)$u['id'];
    json_ok($u);
}

function profilo_aggiorna(): void
{
    $io = richiedi_login();
    $d = corpo();
    $campi = [];
    $valori = [];
    foreach (['nome', 'cognome', 'telefono', 'indirizzo', 'cap', 'citta'] as $campo) {
        if (array_key_exists($campo, $d)) {
            $v = trim((string)$d[$campo]);
            if ($campo === 'cap' && mb_strlen($v) > 10) {
                throw new AppError('CAP_NON_VALIDO', 'CAP: massimo 10 caratteri');
            }
            if ($campo === 'citta' && mb_strlen($v) > 100) {
                throw new AppError('CITTA_NON_VALIDA', 'Citta: massimo 100 caratteri');
            }
            $campi[] = "$campo = ?";
            $valori[] = $v;
        }
    }
    if (array_key_exists('provincia', $d)) {
        $pr = strtoupper(trim((string)$d['provincia']));
        if ($pr !== '' && !preg_match('/^[A-Z]{2}$/', $pr)) {
            throw new AppError('PROVINCIA_NON_VALIDA', 'Provincia: 2 lettere (es. PG)');
        }
        $campi[] = 'provincia = ?';
        $valori[] = $pr;
    }
    if (array_key_exists('partita_iva', $d)) {
        $piva = preg_replace('/\s+/', '', (string)$d['partita_iva']);
        if ($piva !== '' && !preg_match('/^\d{11}$/', $piva)) {
            throw new AppError('PIVA_NON_VALIDA', 'Partita IVA: 11 cifre');
        }
        $campi[] = 'partita_iva = ?';
        $valori[] = ($piva !== '' ? $piva : null);
    }
    if (array_key_exists('codice_fiscale', $d)) {
        $cf = strtoupper(preg_replace('/\s+/', '', (string)$d['codice_fiscale']));
        if ($cf !== '' && !preg_match('/^[A-Z0-9]{16}$/', $cf)) {
            throw new AppError('CF_NON_VALIDO', 'Codice fiscale: 16 caratteri alfanumerici');
        }
        $campi[] = 'codice_fiscale = ?';
        $valori[] = ($cf !== '' ? $cf : null);
    }
    if (empty($campi)) {
        throw new AppError('NESSUN_CAMPO', 'Nessun campo da aggiornare');
    }
    $valori[] = $io;
    $st = db()->prepare('UPDATE utenti SET ' . implode(', ', $campi) . ' WHERE id = ?');
    $st->execute($valori);
    json_ok(['aggiornato' => true]);
}

function profilo_cambia_password(): void
{
    $io = richiedi_login();
    $d = corpo();
    $vecchia = campo($d, 'vecchia_password');
    $nuova = campo($d, 'nuova_password');

    if (strlen($nuova) < 8) {
        throw new AppError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri');
    }

    $st = db()->prepare('SELECT password_hash FROM utenti WHERE id = ?');
    $st->execute([$io]);
    $u = $st->fetch();
    if (!$u || !password_verify($vecchia, $u['password_hash'])) {
        throw new AppError('PASSWORD_VECCHIA_ERRATA', 'La password attuale non e\' corretta', 401);
    }

    $hash = password_hash($nuova, PASSWORD_DEFAULT);
    $st = db()->prepare('UPDATE utenti SET password_hash = ? WHERE id = ?');
    $st->execute([$hash, $io]);
    json_ok(['password_aggiornata' => true]);
}

/**
 * Export dati personali (diritto di accesso/portabilita').
 * GET /profilo/export
 */
function profilo_export(): void
{
    $io = richiedi_login();

    $st = db()->prepare(
        'SELECT id, nome, cognome, email, telefono, indirizzo, cap, citta, provincia,
                partita_iva, codice_fiscale, tipo, ruolo, stato, data_iscrizione, privacy_accettata_at
           FROM utenti WHERE id = ?'
    );
    $st->execute([$io]);
    $utente = $st->fetch();
    if (!$utente) throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);
    unset($utente['password_hash']);

    $st = db()->prepare(
        'SELECT p.id, p.quantita, p.stato, p.data_prenotazione, p.data_pagamento,
                c.id AS id_colletta, c.stato AS stato_colletta,
                pr.nome AS prodotto
           FROM prenotazioni p
           JOIN collette c ON c.id = p.id_colletta
           JOIN prodotti pr ON pr.id = c.id_prodotto
          WHERE p.id_utente = ? ORDER BY p.data_prenotazione DESC'
    );
    $st->execute([$io]);
    $partecipazioni = $st->fetchAll();

    $st = db()->prepare(
        'SELECT pg.id, pg.tipo_pagamento, pg.importo, pg.stato, pg.data_pagamento, p.id_colletta
           FROM pagamenti pg
           JOIN prenotazioni p ON p.id = pg.id_prenotazione
          WHERE p.id_utente = ? ORDER BY pg.data_pagamento DESC'
    );
    $st->execute([$io]);
    $pagamenti = $st->fetchAll();

    $st = db()->prepare(
        'SELECT id, tipo, titolo, messaggio, data_creazione FROM notifiche WHERE id_utente = ? ORDER BY data_creazione DESC'
    );
    $st->execute([$io]);
    $notifiche = $st->fetchAll();

    $st = db()->prepare(
        'SELECT pp.id_proposta, pp.nome_prodotto, pp.descrizione, pp.stato, pp.data_proposta,
                (SELECT COUNT(*) FROM voti_proposte v WHERE v.id_proposta = pp.id_proposta AND v.valore_voto = \'favore\') AS voti
           FROM proposte_prodotti pp WHERE pp.proponente_id = ? ORDER BY pp.data_proposta DESC'
    );
    $st->execute([$io]);
    $proposte = $st->fetchAll();

    $st = db()->prepare(
        'SELECT v.id_proposta, v.valore_voto, v.data_voto, pp.nome_prodotto
           FROM voti_proposte v JOIN proposte_prodotti pp ON pp.id_proposta = v.id_proposta
          WHERE v.id_utente = ? ORDER BY v.data_voto DESC'
    );
    $st->execute([$io]);
    $voti = $st->fetchAll();

    json_ok([
        'esportato_il'   => date('Y-m-d H:i:s'),
        'utente'         => $utente,
        'partecipazioni' => $partecipazioni,
        'pagamenti'      => $pagamenti,
        'notifiche'      => $notifiche,
        'proposte'       => $proposte,
        'voti'           => $voti,
    ]);
}

/**
 * Richiesta cancellazione account (diritto all'oblio).
 * La cancellazione effettiva resta manuale admin (DELETE /utenti con blocco se ha storico).
 * POST /profilo/cancellazione
 */
function profilo_cancellazione_richiesta(): void
{
    $io = richiedi_login();

    $st = db()->prepare('SELECT nome, cognome, email FROM utenti WHERE id = ?');
    $st->execute([$io]);
    $u = $st->fetch();
    if (!$u) throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);

    // Una sola richiesta pendente: se gia' notificata oggi, non duplicare
    $st = db()->prepare(
        "SELECT COUNT(*) FROM notifiche
          WHERE tipo = 'SISTEMA' AND titolo = 'Richiesta Cancellazione Account'
            AND messaggio LIKE ? AND data_creazione > DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $st->execute(['%' . $u['email'] . '%']);
    if ((int)$st->fetchColumn() === 0) {
        $stAdmin = db()->prepare("SELECT id FROM utenti WHERE ruolo = 'admin'");
        $stAdmin->execute();
        $stNot = db()->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'SISTEMA\', \'Richiesta Cancellazione Account\', ?, NULL, NULL)'
        );
        $nome = trim(($u['nome'] ?? '') . ' ' . ($u['cognome'] ?? ''));
        foreach ($stAdmin->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $stNot->execute([$adminId, "L'utente $nome ({$u['email']}) ha richiesto la cancellazione del proprio account. Verificare ed eliminare da Gestione Utenti."]);
        }
    }

    json_ok(['richiesta' => true]);
}
