<?php
declare(strict_types=1);

use Google\Client;

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

    $st = db()->prepare('SELECT id, nome, cognome, email, ruolo, stato, password_hash FROM utenti WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    // Stesso errore nei due casi: non rivelare se l'email esiste.
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        throw new AppError('CREDENZIALI_NON_VALIDE', 'Email o password non corretti', 401);
    }

    session_regenerate_id(true);            // contro session fixation
    $_SESSION['utente_id'] = (int)$u['id'];
    $_SESSION['ruolo']     = $u['ruolo'];

    json_ok(['id' => (int)$u['id'], 'nome' => $u['nome'], 'cognome' => $u['cognome'] ?? '', 'email' => $u['email'], 'ruolo' => $u['ruolo'], 'stato' => $u['stato']]);
}

function auth_google_login(): void
{
    $d     = corpo();
    $token = campo($d, 'token');

    $config = require __DIR__ . '/../config.php';
    $client = new Client(['client_id' => $config['google_client_id']]);
    $client->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
    $payload = $client->verifyIdToken($token);

    if (!$payload) {
        throw new AppError('TOKEN_GOOGLE_NON_VALIDO', 'Token Google non valido', 401);
    }

    $googleId = $payload['sub'];
    $email    = $payload['email'];
    $nome     = $payload['given_name'] ?? '';
    $cognome  = $payload['family_name'] ?? '';

    // Cerca utente esistente per google_id o email
    $st = db()->prepare('SELECT id, nome, cognome, ruolo, stato FROM utenti WHERE google_id = ? OR email = ?');
    $st->execute([$googleId, $email]);
    $utente = $st->fetch();

    if ($utente) {
        // Utente esistente: aggiorna google_id se mancante
        if (empty($utente['google_id'])) {
            $stUp = db()->prepare('UPDATE utenti SET google_id = ? WHERE id = ?');
            $stUp->execute([$googleId, $utente['id']]);
        }
    } else {
        // Nuovo utente: crea account (senza password)
        $st = db()->prepare(
            'INSERT INTO utenti (nome, cognome, email, google_id, ruolo) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$nome, $cognome, $email, $googleId, 'cliente']);
        $utente = [
            'id'     => (int)db()->lastInsertId(),
            'nome'   => $nome,
            'cognome'=> $cognome,
            'ruolo'  => 'cliente',
        ];
    }

    session_regenerate_id(true);
    $_SESSION['utente_id'] = (int)$utente['id'];
    $_SESSION['ruolo']     = $utente['ruolo'];

    json_ok([
        'id'     => (int)$utente['id'],
        'nome'   => $utente['nome'],
        'cognome'=> $utente['cognome'] ?? '',
        'ruolo'  => $utente['ruolo'],
        'stato'  => $utente['stato'] ?? 'attivo',
    ]);
}

function auth_microsoft_login(): void
{
    $d     = corpo();
    $token = campo($d, 'token');

    $config = require __DIR__ . '/../config.php';
    $microsoftClientId = $config['microsoft_client_id'];

    // Verifica il token Microsoft JWT
    // Nota: in produzione usare una libreria JWT come firebase/php-jwt
    // Per ora decodifichiamo il payload (senza verifica signature — placeholder)
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        throw new AppError('TOKEN_MICROSOFT_NON_VALIDO', 'Token Microsoft non valido', 401);
    }

    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!$payload || !isset($payload['oid'])) {
        throw new AppError('TOKEN_MICROSOFT_NON_VALIDO', 'Token Microsoft non valido', 401);
    }

    $microsoftId = $payload['oid'];
    $email       = $payload['email'] ?? $payload['preferred_username'] ?? '';
    $nome        = $payload['given_name'] ?? '';
    $cognome     = $payload['family_name'] ?? '';

    // Cerca utente esistente per microsoft_id o email
    $st = db()->prepare('SELECT id, nome, cognome, ruolo, stato FROM utenti WHERE microsoft_id = ? OR email = ?');
    $st->execute([$microsoftId, $email]);
    $utente = $st->fetch();

    if ($utente) {
        // Utente esistente: aggiorna microsoft_id se mancante
        if (empty($utente['microsoft_id'])) {
            $stUp = db()->prepare('UPDATE utenti SET microsoft_id = ? WHERE id = ?');
            $stUp->execute([$microsoftId, $utente['id']]);
        }
    } else {
        // Nuovo utente: crea account (senza password)
        $st = db()->prepare(
            'INSERT INTO utenti (nome, cognome, email, microsoft_id, ruolo) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$nome, $cognome, $email, $microsoftId, 'cliente']);
        $utente = [
            'id'     => (int)db()->lastInsertId(),
            'nome'   => $nome,
            'cognome'=> $cognome,
            'ruolo'  => 'cliente',
        ];
    }

    session_regenerate_id(true);
    $_SESSION['utente_id'] = (int)$utente['id'];
    $_SESSION['ruolo']     = $utente['ruolo'];

    json_ok([
        'id'     => (int)$utente['id'],
        'nome'   => $utente['nome'],
        'cognome'=> $utente['cognome'] ?? '',
        'ruolo'  => $utente['ruolo'],
        'stato'  => $utente['stato'] ?? 'attivo',
    ]);
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
