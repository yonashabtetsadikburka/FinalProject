<?php
declare(strict_types=1);

/**
 * Sessione lato server. L'id dell'utente e il ruolo vivono in $_SESSION,
 * mai in un token che gira sul client. Il front-end (same-origin, sotto
 * /buypool) manda automaticamente il cookie di sessione.
 */
function utente_corrente_id(): ?int
{
    return isset($_SESSION['utente_id']) ? (int)$_SESSION['utente_id'] : null;
}

function ruolo_corrente(): ?string
{
    return $_SESSION['ruolo'] ?? null;
}

function sono_admin(): bool
{
    return ruolo_corrente() === 'admin';
}

function sono_fornitore(): bool
{
    return ruolo_corrente() === 'fornitore';
}

function richiedi_login(): int
{
    $id = utente_corrente_id();
    if ($id === null) throw new AppError('NON_AUTENTICATO', 'Devi effettuare il login', 401);
    return $id;
}

function richiedi_admin(): int
{
    $id = richiedi_login();
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Riservato agli amministratori', 403);
    return $id;
}
