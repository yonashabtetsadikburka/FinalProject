<?php
declare(strict_types=1);

function notifiche_elenco(): void
{
    $io = richiedi_login();
    $st = db()->prepare(
        'SELECT id, tipo, titolo, messaggio, tipo_riferimento, id_riferimento, letta, data_creazione
           FROM notifiche WHERE id_utente = ? ORDER BY data_creazione DESC LIMIT 50'
    );
    $st->execute([$io]);
    $out = array_map(function ($n) {
        $n['id'] = (int)$n['id'];
        $n['letta'] = (bool)$n['letta'];
        $n['id_riferimento'] = $n['id_riferimento'] !== null ? (int)$n['id_riferimento'] : null;
        return $n;
    }, $st->fetchAll());
    json_ok($out);
}

function notifiche_conta(): void
{
    $io = richiedi_login();
    $st = db()->prepare('SELECT COUNT(*) FROM notifiche WHERE id_utente = ? AND letta = 0');
    $st->execute([$io]);
    json_ok(['non_lette' => (int)$st->fetchColumn()]);
}

function notifiche_segna_letta(int $id): void
{
    $io = richiedi_login();
    $st = db()->prepare('UPDATE notifiche SET letta = 1, data_lettura = NOW() WHERE id = ? AND id_utente = ?');
    $st->execute([$id, $io]);
    if ($st->rowCount() === 0) {
        throw new AppError('NOTIFICA_INESISTENTE', 'Notifica non trovata', 404);
    }
    json_ok(['letta' => true]);
}

function notifiche_segna_tutte_lette(): void
{
    $io = richiedi_login();
    $st = db()->prepare('UPDATE notifiche SET letta = 1, data_lettura = NOW() WHERE id_utente = ? AND letta = 0');
    $st->execute([$io]);
    json_ok(['aggiornate' => $st->rowCount()]);
}

function notifiche_invia(): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin possono inviare notifiche', 403);
    $d = corpo();
    $id_utente = campo_int($d, 'id_utente');
    $tipo = campo($d, 'tipo');
    $titolo = campo($d, 'titolo');
    $messaggio = campo($d, 'messaggio');
    $tipo_rif = $d['tipo_riferimento'] ?? null;
    $id_rif = $d['id_riferimento'] ?? null;

    $tipi_validi = ['SCADENZA','ORDINE_DISPONIBILE','ORDINE_INVIATO','RITIRATO',
        'PROPOSTA_APPROVATA','PAGAMENTO_RIUSCITO','PAGAMENTO_RICEVUTO','PAGAMENTO_FALLITO',
        'RIMBORSO','MOQ_RAGGIUNTO','ORDINE_CONFERMATO','MERCE_PRONTA','SPEDITO',
        'NUOVO_SCAGLIONE','TRACCIAMENTO','SISTEMA','ORDINE_RICEVUTO'];
    if (!in_array($tipo, $tipi_validi, true)) {
        throw new AppError('TIPO_NON_VALIDO', 'Tipo notifica non valido');
    }

    $st = db()->prepare(
        'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$id_utente, $tipo, $titolo, $messaggio, $tipo_rif, $id_rif]);
    json_ok(['id' => (int)db()->lastInsertId()], 201);
}
