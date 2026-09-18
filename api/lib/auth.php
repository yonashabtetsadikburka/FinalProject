<?php
declare(strict_types=1);

function utente_corrente_id(): ?int
{
    return isset($_SESSION['utente_id']) ? (int)$_SESSION['utente_id'] : null;
}

function utente_email(): string
{
    $id = utente_corrente_id();
    if (!$id) return '';
    $st = db()->prepare('SELECT email FROM utenti WHERE id = ?');
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

function sono_admin(): bool
{
    return ($_SESSION['ruolo'] ?? '') === 'admin';
}

function sono_fornitore(): bool
{
    return ($_SESSION['ruolo'] ?? '') === 'fornitore';
}

/**
 * Risolve l'anagrafica fornitore collegata all'utente corrente.
 * Ritorna l'id da `fornitori` oppure null se non collegato.
 */
function fornitore_id_corrente(): ?int
{
    $id = utente_corrente_id();
    if ($id === null) return null;
    $st = db()->prepare('SELECT id FROM fornitori WHERE id_utente = ?');
    $st->execute([$id]);
    $fid = $st->fetchColumn();
    return $fid !== false ? (int)$fid : null;
}

function richiedi_fornitore(): int
{
    $io = richiedi_login();
    if (!sono_fornitore()) throw new AppError('NON_AUTORIZZATO', 'Accesso riservato ai fornitori', 403);
    $fid = fornitore_id_corrente();
    if ($fid === null) throw new AppError('FORNITORE_NON_COLLEGATO', 'Nessuna anagrafica fornitore collegata a questo account', 403);
    return $fid;
}

function richiedi_login(): int
{
    $id = utente_corrente_id();
    if ($id === null) throw new AppError('NON_AUTENTICATO', 'Devi effettuare il login', 401);
    return $id;
}

/**
 * Come richiedi_login(), ma blocca gli account sospesi (sola lettura).
 * Da usare negli endpoint di scrittura (adesioni, pagamenti, consegne, voti, recensioni).
 */
function richiedi_utente_attivo(): int
{
    $id = richiedi_login();
    $st = db()->prepare('SELECT stato FROM utenti WHERE id = ?');
    $st->execute([$id]);
    if ($st->fetchColumn() !== 'attivo') {
        throw new AppError('UTENTE_SOSPESO', 'Account sospeso: puoi consultare storico e profilo ma non effettuare nuove operazioni', 403);
    }
    return $id;
}
