<?php
declare(strict_types=1);

/**
 * Partecipa a una campagna.
 * NESSUN addebito immediato. Pagamento dopo conferma admin via Stripe.
 */
function partecipazioni_aderisci(int $colletta_id): void
{
    $io = richiedi_utente_attivo();
    $d  = corpo();
    $quantita = campo_int($d, 'quantita', 1);

    // Verifica stato campagna
    $statoPrima = ricalcola_stato($colletta_id);
    if ($statoPrima === 'fallita')  throw new AppError('CAMPAGNA_FALLITA', 'La campagna e\' fallita');
    if ($statoPrima === 'riuscita') throw new AppError('CAMPAGNA_COMPLETA', 'La campagna ha gia\' raggiunto la soglia');
    if ($statoPrima === 'ordine_pronto') throw new AppError('CAMPAGNA_ORDINATA', 'La campagna e\' in fase di conferma');
    if ($statoPrima === 'ordine_fornitore') throw new AppError('CAMPAGNA_ORDINATA', 'La campagna e\' gia\' stata ordinata al fornitore');
    if ($statoPrima === 'consegnata') throw new AppError('CAMPAGNA_CONSEGNATA', 'La campagna e\' gia\' stata consegnata');
    if ($statoPrima === 'annullata') throw new AppError('CAMPAGNA_ANNULLATA', 'La campagna e\' stata annullata');

    // Verifica che l'utente non abbia gia' partecipato
    $st = db()->prepare('SELECT id FROM prenotazioni WHERE id_colletta = ? AND id_utente = ?');
    $st->execute([$colletta_id, $io]);
    if ($st->fetch()) {
        throw new AppError('GIA_PARTECIPI', 'Hai gia\' partecipato a questa campagna', 409);
    }

    // Crea prenotazione SENZA acconto
    $st = db()->prepare(
        'INSERT INTO prenotazioni (id_colletta, id_utente, quantita, importo_acconto, stato)
         VALUES (?,?, ?, 0, \'prenotata\')'
    );
    $st->execute([$colletta_id, $io, $quantita]);

    // Incrementa quantita attuale della colletta
    $st = db()->prepare('UPDATE collette SET quantita_attuale = quantita_attuale + ? WHERE id = ?');
    $st->execute([$quantita, $colletta_id]);

    // Ricalcola stato
    $nuovoStato = ricalcola_stato($colletta_id);

    // Se la soglia e' appena stata raggiunta, notifica tutti i partecipanti
    if ($statoPrima === 'in_corso' && $nuovoStato === 'riuscita') {
        $st = db()->prepare('SELECT DISTINCT id_utente FROM prenotazioni WHERE id_colletta = ?');
        $st->execute([$colletta_id]);
        $utenti = $st->fetchAll();

        $stProdotto = db()->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?');
        $stProdotto->execute([$colletta_id]);
        $nomeProdotto = $stProdotto->fetchColumn() ?: 'una campagna';

        $stNotifica = db()->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'MOQ_RAGGIUNTO\', \'Soglia Raggiunta\', ?, \'colletta\', ?)'
        );
        foreach ($utenti as $u) {
            $messaggio = "La soglia minima per \"$nomeProdotto\" è stata raggiunta! Riceverai istruzioni per il pagamento.";
            $stNotifica->execute([(int)$u['id_utente'], $messaggio, $colletta_id]);
        }

        // Avvisa anche il fornitore (se ha l'account collegato) per preparare l'evasione
        try {
            $stForn = db()->prepare(
                'SELECT f.id_utente FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto
                  JOIN fornitori f ON f.id = pr.id_fornitore WHERE c.id = ?'
            );
            $stForn->execute([$colletta_id]);
            $fornUtente = $stForn->fetchColumn();
            if ($fornUtente) {
                $msgForn = "Soglia MOQ raggiunta per \"$nomeProdotto\"! Preparati a evadere l'ordine cumulativo.";
                $stNotifica->execute([(int)$fornUtente, $msgForn, $colletta_id]);
            }
        } catch (Throwable $eNotify) {
            error_log("MOQ fornitore notify fallita per colletta #$colletta_id: " . $eNotify->getMessage());
        }
    }

    json_ok([
        'stato' => $nuovoStato,
        'quantita' => $quantita,
        'messaggio' => 'Partecipazione registrata. Nessun addebito ora.'
    ]);
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

    $st = db()->prepare('SELECT id, quantita, stato FROM prenotazioni WHERE id_colletta = ? AND id_utente = ?');
    $st->execute([$colletta_id, $io]);
    $p = $st->fetch();
    if (!$p) {
        throw new AppError('NON_PARTECIPI', 'Non risulti iscritto a questa campagna', 404);
    }
    if (in_array($p['stato'], ['pagata', 'rimborsata'], true)) {
        throw new AppError('PAGAMENTO_EFFETTUATO', 'Non puoi annullare: pagamento già effettuato. Contatta l\'assistenza per un rimborso.');
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
                c.quantita_minima, c.quantita_attuale, c.data_limite, c.stato AS stato_colletta,
                pr.nome AS prodotto, f.nome_azienda AS fornitore,
                qr.stato AS stato_qr,
                co.id AS id_consegna, co.modalita AS consegna_modalita, co.importo_consegna, co.stato AS consegna_stato
           FROM prenotazioni p
           JOIN collette c   ON c.id  = p.id_colletta
           JOIN prodotti pr  ON pr.id = c.id_prodotto
           JOIN fornitori f  ON f.id  = pr.id_fornitore
      LEFT JOIN qr_codes qr ON qr.id_prenotazione = p.id
      LEFT JOIN consegne co ON co.id_prenotazione = p.id
          WHERE p.id_utente = ?
          ORDER BY p.data_prenotazione DESC'
    );
    $st->execute([$io]);
    json_ok($st->fetchAll());
}
