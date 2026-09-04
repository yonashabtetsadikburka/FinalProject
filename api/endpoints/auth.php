<?php
declare(strict_types=1);

/**
 * Forma dell'oggetto utente restituito al client. Espone `id` (alias di
 * id_utente) perche' il front-end usa user.id ovunque.
 */
function _utente_pubblico(array $u): array
{
    return [
        'id'              => (int)$u['id_utente'],
        'nome'            => $u['nome'],
        'cognome'         => $u['cognome'],
        'email'           => $u['email'],
        'ruolo'           => $u['ruolo'],
        'telefono'        => $u['telefono']  ?? null,
        'indirizzo'       => $u['indirizzo'] ?? null,
        'stato'           => $u['stato']     ?? null,
        'data_iscrizione' => $u['data_iscrizione'] ?? null,
    ];
}

function auth_registrazione(): void
{
    $d       = corpo();
    $nome    = campo($d, 'nome');
    $cognome = campo($d, 'cognome');
    $email   = campo($d, 'email');
    $pass    = campo($d, 'password');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new AppError('EMAIL_NON_VALIDA', 'Indirizzo email non valido');
    }
    if (strlen($pass) < 8) {
        throw new AppError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri');
    }

    // La colonna si chiama `password` ma NON ci va mai il testo in chiaro:
    // dentro ci sta sempre l'hash bcrypt.
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    try {
        $st = db()->prepare(
            'INSERT INTO utenti (cognome, nome, email, password) VALUES (?,?,?,?)');
        $st->execute([$cognome, $nome, $email, $hash]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new AppError('EMAIL_GIA_USATA', 'Esiste gia\' un account con questa email', 409);
        }
        throw $e;
    }

    $id = (int)db()->lastInsertId();

    // Registrazione -> login immediato (il front-end si aspetta di essere
    // gia' dentro dopo il register).
    session_regenerate_id(true);
    $_SESSION['utente_id'] = $id;
    $_SESSION['ruolo']     = 'cliente';

    json_ok(_utente_pubblico([
        'id_utente' => $id, 'nome' => $nome, 'cognome' => $cognome,
        'email' => $email, 'ruolo' => 'cliente',
    ]), 201);
}

function auth_login(): void
{
    $d     = corpo();
    $email = campo($d, 'email');
    $pass  = campo($d, 'password');

    $st = db()->prepare(
        'SELECT id_utente, nome, cognome, email, ruolo, stato, telefono,
                indirizzo, data_iscrizione, password
           FROM utenti WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    // Stesso errore nei due casi: non rivelare se l'email esiste.
    if (!$u || !password_verify($pass, $u['password'])) {
        throw new AppError('CREDENZIALI_NON_VALIDE', 'Email o password non corretti', 401);
    }
    if (($u['stato'] ?? 'attivo') === 'sospeso') {
        throw new AppError('ACCOUNT_SOSPESO', 'Account sospeso. Contatta l\'assistenza.', 403);
    }

    session_regenerate_id(true);                 // contro session fixation
    $_SESSION['utente_id'] = (int)$u['id_utente'];
    $_SESSION['ruolo']     = $u['ruolo'];

    json_ok(_utente_pubblico($u));
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
    $st = db()->prepare(
        'SELECT id_utente, nome, cognome, email, ruolo, stato, telefono,
                indirizzo, data_iscrizione
           FROM utenti WHERE id_utente = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        // utente cancellato ma sessione ancora aperta
        $_SESSION = []; session_destroy();
        throw new AppError('NON_AUTENTICATO', 'Sessione non piu\' valida', 401);
    }
    json_ok(_utente_pubblico($u));
}

/**
 * Aggiornamento del proprio profilo (nome, cognome, telefono, indirizzo).
 * L'email e il ruolo NON si cambiano da qui.
 */
function auth_aggiorna_profilo(): void
{
    $id = richiedi_login();
    $d  = corpo();

    $campi = [];
    $par   = [];
    foreach (['nome', 'cognome', 'telefono', 'indirizzo'] as $k) {
        if (array_key_exists($k, $d)) {
            $campi[] = "$k = ?";
            $par[]   = trim((string)$d[$k]);
        }
    }
    if (!$campi) throw new AppError('NIENTE_DA_AGGIORNARE', 'Nessun campo da aggiornare');

    $par[] = $id;
    db()->prepare('UPDATE utenti SET ' . implode(', ', $campi) . ' WHERE id_utente = ?')
        ->execute($par);

    auth_io();   // restituisce il profilo aggiornato
}

/**
 * ============================================================
 *  LOGIN SOCIAL (Google / Microsoft) -- DA DECIDERE COL TEAM
 * ============================================================
 * Il DB di Abdu NON ha le colonne google_id / microsoft_id e non abbiamo
 * le chiavi OAuth. Finche' non si decide se e' in scope, restituiamo 501
 * cosi' il front-end riceve un errore pulito invece di un 404.
 */
function auth_google(): void
{
    throw new AppError('NON_IMPLEMENTATO',
        'Login con Google non ancora attivo (manca lo schema OAuth nel DB e le chiavi).', 501);
}

function auth_microsoft(): void
{
    throw new AppError('NON_IMPLEMENTATO',
        'Login con Microsoft non ancora attivo (manca lo schema OAuth nel DB e le chiavi).', 501);
}
