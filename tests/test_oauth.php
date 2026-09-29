<?php
declare(strict_types=1);
/**
 * Test della verifica dei token Microsoft (nessuna rete, nessun database).
 * Eseguire:  php tests/test_oauth.php
 */
require __DIR__ . '/../api/vendor/autoload.php';
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/oauth.php';

use Firebase\JWT\JWT;

$falliti = 0;
function verifica(string $nome, bool $ok): void
{
    global $falliti;
    echo ($ok ? "  ok    " : "  FALLITO ") . $nome . "\n";
    if (!$ok) $falliti++;
}
/** @return string|null codice d'errore se rifiutato, null se accettato */
function esito(callable $f): ?string
{
    try { $f(); return null; } catch (AppError $e) { return $e->codice; }
}
function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }

/** Genera una coppia di chiavi RSA e il relativo JWKS (come lo pubblica Microsoft). */
function nuova_chiave(string $kid): array
{
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $priv);
    $d = openssl_pkey_get_details($k);
    $jwks = ['keys' => [[
        'kty' => 'RSA', 'use' => 'sig', 'kid' => $kid,
        'n' => b64u($d['rsa']['n']), 'e' => b64u($d['rsa']['e']),
    ]]];
    return [$priv, $jwks];
}

const CLIENT = 'nostro-client-id';
const TID    = '9188040d-6c67-4c5b-b112-36a304b66dad';
[$privA, $jwksA] = nuova_chiave('kid-A');
[$privB, ]       = nuova_chiave('kid-A');     // chiave DIVERSA, stesso kid: l'attaccante

function token(string $priv, array $override = []): string
{
    $claims = array_merge([
        'aud' => CLIENT, 'iss' => 'https://login.microsoftonline.com/' . TID . '/v2.0',
        'tid' => TID, 'oid' => 'oid-123', 'email' => 'mario@example.com',
        'iat' => time(), 'nbf' => time(), 'exp' => time() + 3600,
    ], $override);
    foreach ($claims as $k => $v) if ($v === null) unset($claims[$k]);
    return JWT::encode($claims, $priv, 'RS256', 'kid-A');
}

echo "Microsoft - token VALIDI\n";
$c = verifica_token_microsoft(token($privA), CLIENT, $jwksA);
verifica('token firmato correttamente e accettato', $c['oid'] === 'oid-123');

echo "Microsoft - token FALSI (devono essere tutti rifiutati)\n";
// Esattamente l'attacco: payload scelto dall'attaccante, firma inventata.
$forgiato = b64u('{"alg":"none"}') . '.' . b64u(json_encode(
    ['oid' => 'x', 'email' => 'admin@example.com', 'aud' => CLIENT, 'tid' => TID,
     'iss' => 'https://login.microsoftonline.com/' . TID . '/v2.0', 'exp' => time() + 3600])) . '.firma-finta';
verifica('token con firma inventata (l\'attacco originale)',
    esito(fn() => verifica_token_microsoft($forgiato, CLIENT, $jwksA)) === 'TOKEN_MICROSOFT_NON_VALIDO');
verifica('token con alg "none" e firma vuota',
    esito(fn() => verifica_token_microsoft(b64u('{"alg":"none","typ":"JWT"}') . '.' . b64u('{"oid":"x","email":"admin@example.com"}') . '.', CLIENT, $jwksA)) === 'TOKEN_MICROSOFT_NON_VALIDO');
verifica('firmato con un\'altra chiave (stesso kid)',
    esito(fn() => verifica_token_microsoft(token($privB), CLIENT, $jwksA)) === 'TOKEN_MICROSOFT_NON_VALIDO');
verifica('audience sbagliata (token emesso per un\'altra app)',
    esito(fn() => verifica_token_microsoft(token($privA, ['aud' => 'altra-app']), CLIENT, $jwksA)) === 'TOKEN_MICROSOFT_NON_VALIDO');
verifica('token scaduto',
    esito(fn() => verifica_token_microsoft(token($privA, ['exp' => time() - 3600, 'iat' => time() - 7200, 'nbf' => time() - 7200]), CLIENT, $jwksA)) === 'TOKEN_MICROSOFT_NON_VALIDO');
verifica('issuer che non corrisponde al tenant',
    esito(fn() => verifica_token_microsoft(token($privA, ['iss' => 'https://evil.example/' . TID . '/v2.0']), CLIENT, $jwksA)) === 'TOKEN_MICROSOFT_NON_VALIDO');
verifica('claim "oid" mancante',
    esito(fn() => verifica_token_microsoft(token($privA, ['oid' => null]), CLIENT, $jwksA)) === 'TOKEN_MICROSOFT_NON_VALIDO');
verifica('spazzatura non-JWT',
    esito(fn() => verifica_token_microsoft('abc', CLIENT, $jwksA)) === 'TOKEN_MICROSOFT_NON_VALIDO');
verifica('login Microsoft non configurato (client id vuoto) -> disabilitato',
    esito(fn() => verifica_token_microsoft(token($privA), '', $jwksA)) === 'LOGIN_NON_DISPONIBILE');

echo "\n" . ($falliti === 0 ? "TUTTI I TEST SUPERATI" : "$falliti TEST FALLITI") . "\n";
exit($falliti === 0 ? 0 : 1);
