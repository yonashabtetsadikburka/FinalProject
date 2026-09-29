<?php
declare(strict_types=1);

function auth_registrazione(): void
{
    $d     = corpo();
    $nome  = campo($d, 'nome');
    $email = campo($d, 'email');
    $pass  = campo($d, 'password');
    $cognome = trim((string)($d['cognome'] ?? ''));
    $tipo    = trim((string)($d['tipo'] ?? 'privato'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new AppError('EMAIL_NON_VALIDA', 'Indirizzo email non valido');
    }
    if (strlen($pass) < 8) {
        throw new AppError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri');
    }
    if (!in_array($tipo, ['privato', 'b2b'], true)) {
        $tipo = 'privato';
    }
    if (empty($d['privacy'])) {
        throw new AppError('PRIVACY_NON_ACCETTATA', 'Devi accettare l\'informativa privacy per registrarti');
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    try {
        $st = db()->prepare('INSERT INTO utenti (nome, cognome, email, password_hash, tipo, privacy_accettata_at) VALUES (?,?,?,?,?,NOW())');
        $st->execute([$nome, $cognome, $email, $hash, $tipo]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new AppError('EMAIL_GIA_USATA', 'Esiste gia\' un account con questa email', 409);
        }
        throw $e;
    }
    json_ok(['id' => (int)db()->lastInsertId(), 'nome' => $nome, 'cognome' => $cognome, 'ruolo' => 'cliente'], 201);
}

function auth_login(): void
{
    $d     = corpo();
    $email = campo($d, 'email');
    $pass  = campo($d, 'password');

    login_controlla_limite($email);

    $st = db()->prepare('SELECT id, nome, cognome, email, ruolo, stato, password_hash FROM utenti WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    // Stesso errore nei due casi: non rivelare se l'email esiste.
    if (!$u || !password_verify($pass, (string)($u['password_hash'] ?? ''))) {
        login_registra_fallimento($email);
        throw new AppError('CREDENZIALI_NON_VALIDE', 'Email o password non corretti', 401);
    }
    login_azzera($email);

    session_regenerate_id(true);            // contro session fixation
    $_SESSION['utente_id'] = (int)$u['id'];
    $_SESSION['ruolo']     = $u['ruolo'];

    json_ok(['id' => (int)$u['id'], 'nome' => $u['nome'], 'cognome' => $u['cognome'] ?? '', 'email' => $u['email'], 'ruolo' => $u['ruolo'], 'stato' => $u['stato']]);
}

/**
 * Trova o crea l'utente di un accesso social e apre la sessione.
 *
 * $colonna         'google_id' | 'microsoft_id'  (whitelist, mai input utente)
 * $emailAffidabile true solo se il provider GARANTISCE che l'email e' verificata.
 *
 * Regole contro il furto di account:
 *  - se l'utente e' gia' collegato a questo provider, si entra e basta;
 *  - un account esistente si collega SOLO per email verificata (Google) e SOLO
 *    se e' un normale cliente: admin e fornitori entrano solo con la password;
 *  - con Microsoft l'email non e' verificata, quindi non si collega mai per email.
 */
function accesso_social(string $colonna, string $providerId, string $email,
                        string $nome, string $cognome, bool $emailAffidabile): void
{
    if (!in_array($colonna, ['google_id', 'microsoft_id'], true)) {
        throw new LogicException('colonna provider non ammessa');
    }
    $pdo = db();

    $st = $pdo->prepare("SELECT id, nome, cognome, ruolo, stato FROM utenti WHERE $colonna = ?");
    $st->execute([$providerId]);
    $u = $st->fetch();

    if (!$u) {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppError('EMAIL_MANCANTE', 'Il provider non ha fornito un indirizzo email valido', 401);
        }
        $st = $pdo->prepare('SELECT id, nome, cognome, ruolo, stato FROM utenti WHERE email = ?');
        $st->execute([$email]);
        $esistente = $st->fetch();

        if ($esistente) {
            if (!$emailAffidabile) {
                throw new AppError('EMAIL_ESISTENTE',
                    'Esiste gia\' un account con questa email: accedi con email e password', 409);
            }
            if ($esistente['ruolo'] !== 'cliente') {
                throw new AppError('ACCESSO_SOLO_PASSWORD',
                    'Questo account accede solo con email e password', 403);
            }
            $pdo->prepare("UPDATE utenti SET $colonna = ? WHERE id = ?")
                ->execute([$providerId, (int)$esistente['id']]);
            $u = $esistente;
        } else {
            $pdo->prepare("INSERT INTO utenti (nome, cognome, email, $colonna, ruolo) VALUES (?,?,?,?, 'cliente')")
                ->execute([$nome !== '' ? $nome : 'Utente', $cognome, $email, $providerId]);
            $u = ['id' => (int)$pdo->lastInsertId(), 'nome' => $nome, 'cognome' => $cognome,
                  'ruolo' => 'cliente', 'stato' => 'attivo'];
        }
    }

    session_regenerate_id(true);            // contro session fixation
    $_SESSION['utente_id'] = (int)$u['id'];
    $_SESSION['ruolo']     = $u['ruolo'];

    json_ok([
        'id'      => (int)$u['id'],
        'nome'    => $u['nome'],
        'cognome' => $u['cognome'] ?? '',
        'ruolo'   => $u['ruolo'],
        'stato'   => $u['stato'] ?? 'attivo',
    ]);
}

function auth_google_login(): void
{
    $d      = corpo();
    $config = require __DIR__ . '/../config.php';
    $p      = verifica_token_google(campo($d, 'token'), (string)($config['google_client_id'] ?? ''));

    accesso_social('google_id', (string)$p['sub'], (string)$p['email'],
                   (string)($p['given_name'] ?? ''), (string)($p['family_name'] ?? ''), true);
}

function auth_microsoft_login(): void
{
    $d      = corpo();
    $config = require __DIR__ . '/../config.php';
    $c      = verifica_token_microsoft(campo($d, 'token'), (string)($config['microsoft_client_id'] ?? ''));

    accesso_social('microsoft_id', (string)$c['oid'],
                   (string)($c['email'] ?? $c['preferred_username'] ?? ''),
                   (string)($c['given_name'] ?? ''), (string)($c['family_name'] ?? ''), false);
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
    $st = db()->prepare('SELECT id, nome, email, ruolo, stato FROM utenti WHERE id = ?');
    $st->execute([$id]);
    json_ok($st->fetch());
}
