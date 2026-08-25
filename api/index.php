<?php
declare(strict_types=1);

/**
 * Front controller: tutte le richieste passano da qui.
 * URL base con MAMP:  http://localhost:8888/buypool/api/
 */

ini_set('display_errors', '0');       // gli errori nel log, mai nella risposta
error_reporting(E_ALL);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

require __DIR__ . '/lib/risposta.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/stato.php';
require __DIR__ . '/lib/ripartizione.php';
require __DIR__ . '/endpoints/auth.php';
require __DIR__ . '/endpoints/catalogo.php';
require __DIR__ . '/endpoints/campagne.php';
require __DIR__ . '/endpoints/partecipazioni.php';
require __DIR__ . '/endpoints/assegnazioni.php';
require __DIR__ . '/endpoints/ritiro.php';

/** Percorso richiesto, senza la cartella base e senza query string. */
function percorso(): string
{
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    return trim($uri, '/');
}

// metodo, schema del percorso, funzione da chiamare
$rotte = [
    ['GET',    'salute',                            fn() => json_ok(['stato' => 'ok', 'php' => PHP_VERSION])],
    ['POST',   'registrazione',                     'auth_registrazione'],
    ['POST',   'login',                             'auth_login'],
    ['POST',   'logout',                            'auth_logout'],
    ['GET',    'io',                                'auth_io'],
    ['GET',    'fornitori',                         'catalogo_fornitori'],
    ['GET',    'prodotti',                          'catalogo_prodotti'],
    ['GET',    'punti-ritiro',                      'catalogo_punti_ritiro'],
    ['GET',    'campagne',                          'campagne_elenco'],
    ['POST',   'campagne',                          'campagne_crea'],
    ['GET',    'campagne/{id}',                     'campagne_dettaglio'],
    ['POST',   'campagne/{id}/partecipazioni',      'partecipazioni_aderisci'],
    ['DELETE', 'campagne/{id}/partecipazioni',      'partecipazioni_ritira'],
    ['POST',   'campagne/{id}/ripartisci',          'assegnazioni_ripartisci'],
    ['GET',    'campagne/{id}/assegnazioni',        'assegnazioni_elenco'],
    ['GET',    'mie/assegnazioni/{id}/qr',          'ritiro_qr'],
    ['POST',   'ritiro/{token}',                    'ritiro_conferma'],
];

function abbina(string $schema, string $percorso, ?array &$par): bool
{
    $s = $schema   === '' ? [] : explode('/', $schema);
    $p = $percorso === '' ? [] : explode('/', $percorso);
    if (count($s) !== count($p)) return false;

    $par = [];
    foreach ($s as $i => $seg) {
        if ($seg === '{id}') {
            if (!ctype_digit($p[$i])) return false;
            $par[] = (int)$p[$i];
        } elseif ($seg === '{token}') {
            if (!ctype_alnum($p[$i])) return false;
            $par[] = $p[$i];
        } elseif ($seg !== $p[$i]) {
            return false;
        }
    }
    return true;
}

$metodo   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$percorso = percorso();

try {
    $trovato_percorso = false;
    foreach ($rotte as [$m, $schema, $fn]) {
        $par = null;
        if (!abbina($schema, $percorso, $par)) continue;
        $trovato_percorso = true;
        if ($m !== $metodo) continue;
        $fn(...($par ?? []));
        exit;
    }
    if ($trovato_percorso) {
        json_errore('METODO_NON_AMMESSO', "Metodo $metodo non ammesso su /$percorso", 405);
    }
    json_errore('ROTTA_INESISTENTE', "Endpoint non trovato: /$percorso", 404);

} catch (AppError $e) {
    json_errore($e->codice, $e->getMessage(), $e->http);
} catch (Throwable $e) {
    error_log('ERRORE: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_errore('ERRORE_INTERNO', 'Errore interno del server', 500);
}
