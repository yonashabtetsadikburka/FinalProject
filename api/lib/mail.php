<?php
declare(strict_types=1);

/**
 * ============================================================
 *  INVIO EMAIL via SMTP (dev: Mailpit su 127.0.0.1:1025)
 * ============================================================
 *
 * Punto centrale di invio: TUTTE le email del progetto devono
 * passare da invia_email(). Mai usare mail() o PHPMailer
 * direttamente negli endpoint.
 *
 * Contratto anti-rottura:
 *  - se mail_enabled=false -> return false, nessun tentativo SMTP
 *  - indirizzo non valido  -> return false
 *  - errore SMTP           -> error_log + return false
 *  - MAI lancia eccezioni: le notifiche DB restano source of truth,
 *    l'email e' solo un canale in piu' (best-effort).
 *
 * PHPMailer e' vendored in lib/PHPMailer (v6.9.3) perche' la rete
 * di sviluppo blocca Packagist (curl error 60). Quando la rete lo
 * permette, passare a `composer require phpmailer/phpmailer` e
 * rimuovere questa cartella: mail.php funzionera' senza modifiche
 * perche' usa solo i namespace PHPMailer\PHPMailer\*.
 */

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** Config mail con default sicuri (tutto spento se manca config.php). */
function mail_config(): array
{
    $cfg = [];
    $file = __DIR__ . '/../config.php';
    if (file_exists($file)) {
        $tutto = require $file;
        if (is_array($tutto)) $cfg = $tutto;
    }
    return [
        'mail_enabled'   => (bool)($cfg['mail_enabled'] ?? false),
        'mail_from'      => (string)($cfg['mail_from'] ?? 'noreply@buypool.local'),
        'mail_from_nome' => (string)($cfg['mail_from_nome'] ?? 'BuyPool'),
        'smtp_host'      => (string)($cfg['smtp_host'] ?? '127.0.0.1'),
        'smtp_port'      => (int)($cfg['smtp_port'] ?? 1025),
        'smtp_auth'      => (bool)($cfg['smtp_auth'] ?? false),
        'smtp_user'      => (string)($cfg['smtp_user'] ?? ''),
        'smtp_pass'      => (string)($cfg['smtp_pass'] ?? ''),
        // '' in dev (Mailpit), 'tls' in prod (es. Brevo su 587)
        'smtp_secure'    => (string)($cfg['smtp_secure'] ?? ''),
        'frontend_url'   => rtrim((string)($cfg['frontend_url'] ?? 'http://localhost:8888/buypool/frontend'), '/'),
    ];
}

/** true solo se l'invio email e' abilitato da config. */
function mail_attiva(): bool
{
    return mail_config()['mail_enabled'] === true;
}

/** Costruisce il mailer da config. Ritorna null se disabilitato. */
function mailer_da_config(): ?PHPMailer
{
    $c = mail_config();
    if (!$c['mail_enabled']) return null;

    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = $c['smtp_host'];
    $m->Port = $c['smtp_port'];
    $m->SMTPAuth = $c['smtp_auth'];
    if ($c['smtp_auth']) {
        $m->Username = $c['smtp_user'];
        $m->Password = $c['smtp_pass'];
    }
    if ($c['smtp_secure'] !== '') {
        $m->SMTPSecure = $c['smtp_secure'];
    } else {
        $m->SMTPSecure = false;
        $m->SMTPAutoTLS = false;
    }
    $m->Timeout = 5;
    $m->CharSet = 'UTF-8';
    $m->setFrom($c['mail_from'], $c['mail_from_nome']);
    return $m;
}

/**
 * Invia una email. Non lancia mai eccezioni.
 * Ritorna true se il server SMTP ha accettato il messaggio.
 */
function invia_email(string $to, string $oggetto, string $html, string $testo = ''): bool
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[mail] destinatario non valido: ' . $to);
        return false;
    }
    if (!mail_attiva()) return false;
    $oggetto = trim($oggetto);
    if ($oggetto === '') {
        error_log('[mail] oggetto vuoto per ' . $to);
        return false;
    }
    if ($testo === '') {
        $testo = html_para_testo($html);
    }

    try {
        $m = mailer_da_config();
        if ($m === null) return false;
        $m->addAddress($to);
        $m->Subject = $oggetto;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $testo;
        return $m->send();
    } catch (MailerException $e) {
        error_log('[mail] invio fallito a ' . $to . ': ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        error_log('[mail] errore inatteso per ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

/** Converte HTML in testo semplice per AltBody. */
function html_para_testo(string $html): string
{
    $t = preg_replace('#<(br|p|div|li|h[1-6])[^>]*>#i', "\n", $html);
    $t = strip_tags((string)$t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string)preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t);
}

/** Layout unico BuyPool per tutte le email transazionali. */
function mail_template_layout(string $titolo, string $corpoHtml): string
{
    $t = htmlspecialchars($titolo, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head><meta charset="utf-8"><title>{$t}</title></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
<tr><td style="background:#1a7a4a;color:#ffffff;padding:20px 28px;font-size:20px;font-weight:bold;">BuyPool</td></tr>
<tr><td style="padding:28px;"><h1 style="font-size:18px;margin:0 0 16px;">{$t}</h1>{$corpoHtml}</td></tr>
<tr><td style="padding:16px 28px;color:#888;font-size:12px;border-top:1px solid #eee;">Email automatica BuyPool, non rispondere a questo indirizzo.</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/** Template: invito attivazione account fornitore. */
function mail_template_invito_fornitore(string $nomeAzienda, string $link, int $giorni = 7): array
{
    $azienda = htmlspecialchars($nomeAzienda, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $oggetto = "Invito BuyPool: attiva l'account di {$nomeAzienda}";
    $corpo = <<<HTML
<p>Ciao,</p>
<p>Sei stato invitato ad attivare l'account fornitore <strong>{$azienda}</strong> su BuyPool.</p>
<p><a href="{$l}" style="display:inline-block;background:#1a7a4a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;">Attiva il tuo account</a></p>
<p>Oppure copia questo link nel browser:<br><a href="{$l}">{$l}</a></p>
<p>Il link scade tra <strong>{$giorni} giorni</strong> e pu&ograve; essere usato una sola volta.</p>
HTML;
    $html = mail_template_layout("Attiva l'account fornitore", $corpo);
    $testo = "Sei stato invitato ad attivare l'account fornitore {$nomeAzienda} su BuyPool.\n"
        . "Attiva qui: {$link}\nIl link scade tra {$giorni} giorni e puo' essere usato una sola volta.";
    return ['oggetto' => $oggetto, 'html' => $html, 'testo' => $testo];
}

/** Template: reset password via link monouso. */
function mail_template_reset_password(string $nome, string $link, int $ore = 24): array
{
    $n = htmlspecialchars($nome !== '' ? $nome : 'utente', ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $oggetto = 'BuyPool: reimposta la tua password';
    $corpo = <<<HTML
<p>Ciao {$n},</p>
<p>Hai richiesto la reimpostazione della password. Clicca il bottone per sceglierne una nuova:</p>
<p><a href="{$l}" style="display:inline-block;background:#1a7a4a;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;">Reimposta password</a></p>
<p>Oppure copia questo link nel browser:<br><a href="{$l}">{$l}</a></p>
<p>Il link scade tra <strong>{$ore} ore</strong> e pu&ograve; essere usato una sola volta. Se non hai richiesto tu il reset, ignora questa email.</p>
HTML;
    $html = mail_template_layout('Reimposta la password', $corpo);
    $testo = "Ciao {$nome},\nHai richiesto la reimpostazione della password.\n"
        . "Usa questo link (scade tra {$ore} ore, monouso): {$link}\n"
        . "Se non hai richiesto tu il reset, ignora questa email.";
    return ['oggetto' => $oggetto, 'html' => $html, 'testo' => $testo];
}

/** Template generico: replica via email una notifica interna. */
function mail_template_notifica(string $titolo, string $messaggio): array
{
    $msg = nl2br(htmlspecialchars($messaggio, ENT_QUOTES, 'UTF-8'));
    $html = mail_template_layout($titolo, "<p>{$msg}</p>");
    return ['oggetto' => 'BuyPool: ' . $titolo, 'html' => $html, 'testo' => $titolo . "\n\n" . $messaggio];
}

/**
 * Template evento (pagamenti, rimborsi, gruppo): paragrafi, tabella chiave/valore e un pulsante.
 * Tutto l'input utente e' escapato qui.
 */
function mail_template_evento(string $titolo, array $paragrafi, array $righe, string $ctaTesto, string $ctaLink): string
{
    $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $corpo = '';
    foreach ($paragrafi as $p) $corpo .= '<p style="margin:0 0 12px;line-height:1.5;">' . $e((string)$p) . '</p>';
    if ($righe) {
        $corpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 16px;border-top:1px solid #E3E8F0;">';
        foreach ($righe as $k => $v) {
            $corpo .= '<tr><td style="padding:8px 0;border-bottom:1px solid #E3E8F0;color:#3B4A63;">' . $e((string)$k)
                . '</td><td align="right" style="padding:8px 0;border-bottom:1px solid #E3E8F0;font-weight:bold;color:#0F1B33;">' . $e((string)$v) . '</td></tr>';
        }
        $corpo .= '</table>';
    }
    $corpo .= '<p style="margin:20px 0 0;"><a href="' . $e($ctaLink) . '" style="display:inline-block;background:#0262BB;color:#ffffff;'
        . 'text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:bold;">' . $e($ctaTesto) . '</a></p>'
        . '<p style="margin:12px 0 0;font-size:12px;color:#5B6B82;">Se il pulsante non funziona copia questo link: ' . $e($ctaLink) . '</p>';
    return mail_template_layout($titolo, $corpo);
}
