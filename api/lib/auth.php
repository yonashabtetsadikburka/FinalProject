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

/**
 * Guardia per le rotte riservate agli admin.
 * 401 se non c'e' nessun utente collegato, 403 se e' collegato ma non e' admin
 * (cosi' il front-end distingue "sessione scaduta" da "non hai il permesso").
 */
function richiedi_admin(string $messaggio = 'Accesso riservato agli amministratori',
                        string $codice = 'NON_AUTORIZZATO'): int
{
    $id = richiedi_login();
    if (!sono_admin()) throw new AppError($codice, $messaggio, 403);
    return $id;
}

// ------------------------------------------------------------
//  Limite ai tentativi di login (contro il tentativo di indovinare le password)
//  Dopo troppi fallimenti in 15 minuti, per quell'email o da quell'indirizzo IP,
//  il login risponde 429 anche con la password giusta. La chiave e' l'email
//  ricevuta (esista o no), cosi' non si scopre quali email sono registrate.
// ------------------------------------------------------------
const LOGIN_FINESTRA_MIN = 15;
const LOGIN_MAX_PER_EMAIL = 5;
const LOGIN_MAX_PER_IP = 20;

function login_chiavi(string $email): array
{
    return [
        'email' => sha1('e:' . mb_strtolower(trim($email))),
        'ip'    => sha1('i:' . ($_SERVER['REMOTE_ADDR'] ?? '')),
    ];
}

function login_fallimenti(string $chiave): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM login_tentativi
                          WHERE chiave = ? AND data_tentativo > (NOW() - INTERVAL ' . LOGIN_FINESTRA_MIN . ' MINUTE)');
    $st->execute([$chiave]);
    return (int)$st->fetchColumn();
}

/** Lancia 429 se per questa email/IP ci sono gia' troppi fallimenti recenti. */
function login_controlla_limite(string $email): void
{
    $k = login_chiavi($email);
    if (login_fallimenti($k['email']) >= LOGIN_MAX_PER_EMAIL || login_fallimenti($k['ip']) >= LOGIN_MAX_PER_IP) {
        throw new AppError('TROPPI_TENTATIVI',
            'Troppi tentativi di accesso. Riprova tra ' . LOGIN_FINESTRA_MIN . ' minuti.', 429);
    }
}

function login_registra_fallimento(string $email): void
{
    $k = login_chiavi($email);
    $st = db()->prepare('INSERT INTO login_tentativi (chiave) VALUES (?)');
    $st->execute([$k['email']]);
    $st->execute([$k['ip']]);
    if (random_int(1, 50) === 1) {   // pulizia occasionale delle righe vecchie
        db()->exec('DELETE FROM login_tentativi WHERE data_tentativo < (NOW() - INTERVAL 1 DAY)');
    }
}

function login_azzera(string $email): void
{
    db()->prepare('DELETE FROM login_tentativi WHERE chiave = ?')->execute([login_chiavi($email)['email']]);
}
