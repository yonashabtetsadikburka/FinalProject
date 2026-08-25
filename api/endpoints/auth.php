<?php
declare(strict_types=1);

function auth_registrazione(): void
{
    $d     = corpo();
    $nome  = campo($d, 'nome');
    $email = campo($d, 'email');
    $pass  = campo($d, 'password');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new AppError('EMAIL_NON_VALIDA', 'Indirizzo email non valido');
    }
    if (strlen($pass) < 8) {
        throw new AppError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri');
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    try {
        $st = db()->prepare('INSERT INTO utenti (nome, email, password_hash) VALUES (?,?,?)');
        $st->execute([$nome, $email, $hash]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new AppError('EMAIL_GIA_USATA', 'Esiste gia\' un account con questa email', 409);
        }
        throw $e;
    }
    json_ok(['id' => (int)db()->lastInsertId(), 'nome' => $nome], 201);
}

function auth_login(): void
{
    $d     = corpo();
    $email = campo($d, 'email');
    $pass  = campo($d, 'password');

    $st = db()->prepare('SELECT id, nome, ruolo, password_hash FROM utenti WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    // Stesso errore nei due casi: non rivelare se l'email esiste.
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        throw new AppError('CREDENZIALI_NON_VALIDE', 'Email o password non corretti', 401);
    }

    session_regenerate_id(true);            // contro session fixation
    $_SESSION['utente_id'] = (int)$u['id'];
    $_SESSION['ruolo']     = $u['ruolo'];

    json_ok(['id' => (int)$u['id'], 'nome' => $u['nome'], 'ruolo' => $u['ruolo']]);
}

function auth_logout(): void
{
    $_SESSION = [];
    session_destroy();
    json_ok(['uscito' => true]);
}

function auth_io(): void
{
    $id = richiedi_login();
    $st = db()->prepare('SELECT id, nome, email, ruolo FROM utenti WHERE id = ?');
    $st->execute([$id]);
    json_ok($st->fetch());
}
