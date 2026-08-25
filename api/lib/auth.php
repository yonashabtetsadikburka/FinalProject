<?php
declare(strict_types=1);

function utente_corrente_id(): ?int
{
    return isset($_SESSION['utente_id']) ? (int)$_SESSION['utente_id'] : null;
}

function sono_admin(): bool
{
    return ($_SESSION['ruolo'] ?? '') === 'admin';
}

function richiedi_login(): int
{
    $id = utente_corrente_id();
    if ($id === null) throw new AppError('NON_AUTENTICATO', 'Devi effettuare il login', 401);
    return $id;
}
