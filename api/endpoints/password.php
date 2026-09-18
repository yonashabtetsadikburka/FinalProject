<?php
declare(strict_types=1);

/**
 * ============================================================
 *  RESET PASSWORD via link monouso (nessuna email automatica:
 *  l'admin inoltra il link a mano, come gli inviti fornitore)
 * ============================================================
 */

/**
 * Richiesta pubblica di reset: notifica gli admin (risposta sempre generica).
 * POST /password/richiesta  { email }
 */
function password_richiesta(): void
{
    $d = corpo();
    $email = trim((string)($d['email'] ?? ''));

    if ($email !== '') {
        $st = db()->prepare('SELECT id, nome, cognome FROM utenti WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u) {
            $stAdmin = db()->prepare("SELECT id FROM utenti WHERE ruolo = 'admin'");
            $stAdmin->execute();
            $stNot = db()->prepare(
                'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                 VALUES (?, \'SISTEMA\', \'Richiesta Reset Password\', ?, NULL, NULL)'
            );
            $nome = trim(($u['nome'] ?? '') . ' ' . ($u['cognome'] ?? ''));
            foreach ($stAdmin->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                $stNot->execute([$adminId, "L'utente $nome ($email) ha richiesto il reset della password. Genera un link da Gestione Utenti."]);
            }
        }
    }

    json_ok(['richiesta' => true]);
}

/**
 * Genera link di reset per un utente (solo admin).
 * POST /admin/utenti/{id}/reset-link
 */
function password_reset_genera(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);

    $st = db()->prepare('SELECT id, email FROM utenti WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);

    // Invalida link precedenti non usati
    db()->prepare('UPDATE reset_password SET usato = 1 WHERE id_utente = ? AND usato = 0')->execute([$id]);

    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO reset_password (id_utente, token, scadenza) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 1 DAY))'
    )->execute([$id, $token]);

    $cfg = require __DIR__ . '/../config.php';
    $link = rtrim($cfg['frontend_url'], '/') . '/index.html#/password/reset?token=' . $token;

    json_ok(['token' => $token, 'link' => $link, 'scadenza_ore' => 24], 201);
}

/**
 * Verifica link di reset (pubblico, solo dati non sensibili).
 * GET /password/reset/{token}
 */
function password_reset_verifica(string $token): void
{
    $st = db()->prepare(
        'SELECT r.scadenza, r.usato, u.email, u.nome
           FROM reset_password r JOIN utenti u ON u.id = r.id_utente
          WHERE r.token = ?'
    );
    $st->execute([$token]);
    $inv = $st->fetch();
    if (!$inv) throw new AppError('LINK_NON_VALIDO', 'Link non valido', 404);
    if ((int)$inv['usato'] === 1 || strtotime($inv['scadenza']) < time()) {
        throw new AppError('LINK_SCADUTO', 'Questo link e\' scaduto o gia\' utilizzato', 410);
    }
    json_ok(['nome' => $inv['nome'], 'email' => $inv['email']]);
}

/**
 * Imposta nuova password via link (pubblico).
 * POST /password/reset  { token, password }
 */
function password_reset_esegui(): void
{
    $d = corpo();
    $token = trim((string)($d['token'] ?? ''));
    $password = (string)($d['password'] ?? '');
    if ($token === '') throw new AppError('TOKEN_MANCANTE', 'Token mancante');
    if (strlen($password) < 8) throw new AppError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, id_utente, scadenza, usato FROM reset_password WHERE token = ? FOR UPDATE');
        $st->execute([$token]);
        $inv = $st->fetch();
        if (!$inv) {
            $pdo->rollBack();
            throw new AppError('LINK_NON_VALIDO', 'Link non valido', 404);
        }
        if ((int)$inv['usato'] === 1 || strtotime($inv['scadenza']) < time()) {
            $pdo->rollBack();
            throw new AppError('LINK_SCADUTO', 'Questo link e\' scaduto o gia\' utilizzato', 410);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE utenti SET password_hash = ? WHERE id = ?')->execute([$hash, (int)$inv['id_utente']]);
        $pdo->prepare('UPDATE reset_password SET usato = 1 WHERE id = ?')->execute([(int)$inv['id']]);
        $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'SISTEMA\', \'Password Reimpostata\', ?, NULL, NULL)'
        )->execute([
            (int)$inv['id_utente'],
            'La tua password è stata reimpostata con successo. Se non sei stato tu, contatta subito l\'amministratore.',
        ]);
        $pdo->commit();

        json_ok(['reimpostata' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
