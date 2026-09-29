<?php
declare(strict_types=1);

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Verifica dei token dei provider di accesso (Google / Microsoft).
 *
 * REGOLA: un token si crede solo dopo aver verificato la FIRMA con le chiavi
 * pubbliche del provider. Leggere i campi del token senza verificarla
 * significa che chiunque puo' fabbricarsene uno con l'email di un altro.
 */

const MICROSOFT_JWKS_URL = 'https://login.microsoftonline.com/common/discovery/v2.0/keys';

/**
 * Opzioni HTTP sicure: la verifica del certificato TLS resta SEMPRE attiva.
 * Se PHP (es. MAMP) non trova i certificati radice, indicare il file in
 * config.php -> 'ca_bundle' invece di disattivare la verifica.
 */
function oauth_http_options(): array
{
    $cfg = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : [];
    $ca = $cfg['ca_bundle'] ?? '';
    return ['verify' => $ca !== '' ? $ca : true, 'timeout' => 8];
}

/** Chiavi pubbliche Microsoft (JWKS), in cache su file per un'ora. */
function microsoft_jwks(): array
{
    $cache = sys_get_temp_dir() . '/buypool_ms_jwks.json';
    if (is_file($cache) && filemtime($cache) > time() - 3600) {
        $j = json_decode((string)file_get_contents($cache), true);
        if (is_array($j) && isset($j['keys'])) return $j;
    }
    try {
        $r = (new \GuzzleHttp\Client(oauth_http_options()))->get(MICROSOFT_JWKS_URL);
        $j = json_decode((string)$r->getBody(), true);
    } catch (Throwable $e) {
        error_log('JWKS Microsoft: ' . $e->getMessage());
        throw new AppError('PROVIDER_NON_RAGGIUNGIBILE', 'Impossibile verificare il token Microsoft', 502);
    }
    if (!is_array($j) || empty($j['keys'])) {
        throw new AppError('PROVIDER_NON_RAGGIUNGIBILE', 'Impossibile verificare il token Microsoft', 502);
    }
    @file_put_contents($cache, json_encode($j));
    return $j;
}

/**
 * Verifica un id_token Microsoft e ne restituisce i claim.
 * Controlla: firma (RS256), scadenza, audience (il NOSTRO client id) ed issuer.
 * $jwks e' iniettabile solo per i test.
 */
function verifica_token_microsoft(string $token, string $clientId, ?array $jwks = null): array
{
    if ($clientId === '') {
        throw new AppError('LOGIN_NON_DISPONIBILE', 'Accesso con Microsoft non configurato', 503);
    }
    try {
        JWT::$leeway = 60;
        $claims = (array)JWT::decode($token, JWK::parseKeySet($jwks ?? microsoft_jwks(), 'RS256'));
    } catch (AppError $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new AppError('TOKEN_MICROSOFT_NON_VALIDO', 'Token Microsoft non valido', 401);
    }

    $tid = (string)($claims['tid'] ?? '');
    $iss = (string)($claims['iss'] ?? '');
    if (($claims['aud'] ?? null) !== $clientId
        || $tid === '' || $iss !== "https://login.microsoftonline.com/$tid/v2.0"
        || empty($claims['oid'])) {
        throw new AppError('TOKEN_MICROSOFT_NON_VALIDO', 'Token Microsoft non valido', 401);
    }
    return $claims;
}

/** Verifica un id_token Google (firma e audience inclusi) e ne restituisce i claim. */
function verifica_token_google(string $token, string $clientId): array
{
    $client = new \Google\Client(['client_id' => $clientId]);
    $client->setHttpClient(new \GuzzleHttp\Client(oauth_http_options()));
    try {
        $payload = $client->verifyIdToken($token);
    } catch (Throwable $e) {
        error_log('Google verifyIdToken: ' . $e->getMessage());
        $payload = false;
    }
    if (!$payload) {
        throw new AppError('TOKEN_GOOGLE_NON_VALIDO', 'Token Google non valido', 401);
    }
    // Un'email non verificata non prova nulla: non ci si puo' fidare.
    if (empty($payload['email']) || empty($payload['email_verified'])) {
        throw new AppError('EMAIL_NON_VERIFICATA', 'L\'email del tuo account Google non e\' verificata', 401);
    }
    return $payload;
}
