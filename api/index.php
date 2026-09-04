<?php
declare(strict_types=1);

/**
 * Front controller: tutte le richieste passano da qui.
 * URL base con MAMP:  http://localhost:8888/buypool/api/
 * Database: collette_acquisto_gruppo (schema di Abdu).
 */

ini_set('display_errors', '0');       // gli errori nel log, mai nella risposta
error_reporting(E_ALL);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

require __DIR__ . '/lib/risposta.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/stato.php';
require __DIR__ . '/endpoints/auth.php';
require __DIR__ . '/endpoints/catalogo.php';
require __DIR__ . '/endpoints/collette.php';
require __DIR__ . '/endpoints/prenotazioni.php';
require __DIR__ . '/endpoints/proposte.php';
require __DIR__ . '/endpoints/notifiche.php';
require __DIR__ . '/endpoints/pagamenti.php';
require __DIR__ . '/endpoints/admin.php';

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
    ['GET',    'salute',                        fn() => json_ok(['stato' => 'ok', 'php' => PHP_VERSION])],

    // --- autenticazione ---
    ['POST',   'registrazione',                 'auth_registrazione'],
    ['POST',   'login',                         'auth_login'],
    ['POST',   'logout',                        'auth_logout'],
    ['GET',    'io',                            'auth_io'],
    ['PUT',    'io',                            'auth_aggiorna_profilo'],
    ['POST',   'auth/google',                   'auth_google'],
    ['POST',   'auth/microsoft',                'auth_microsoft'],

    // --- catalogo ---
    ['GET',    'fornitori',                     'catalogo_fornitori'],
    ['GET',    'fornitori/{id}',                'catalogo_fornitore'],
    ['GET',    'prodotti',                      'catalogo_prodotti'],
    ['GET',    'sedi',                          'catalogo_sedi'],

    // --- collette (campagne) ---
    ['GET',    'collette',                      'collette_elenco'],
    ['POST',   'collette',                      'collette_crea'],
    ['GET',    'collette/{id}',                 'collette_dettaglio'],
    ['PUT',    'collette/{id}',                 'collette_aggiorna'],
    ['POST',   'collette/{id}/prenotazioni',    'prenotazioni_crea'],

    // --- prenotazioni ---
    ['GET',    'mie/prenotazioni',              'prenotazioni_mie'],
    ['DELETE', 'prenotazioni/{id}',             'prenotazioni_annulla'],

    // --- proposte / voti ---
    ['GET',    'proposte',                      'proposte_elenco'],
    ['POST',   'proposte',                      'proposte_crea'],
    ['POST',   'proposte/{id}/voto',            'proposte_vota'],

    // --- notifiche ---
    ['GET',    'notifiche',                     'notifiche_mie'],
    ['POST',   'notifiche/{id}/letta',          'notifiche_segna_letta'],

    // --- pagamenti / wallet (stub, in attesa di decisione) ---
    ['POST',   'pagamento/checkout',            'pagamento_checkout'],
    ['GET',    'pagamento/stato',               'pagamento_stato'],
    ['GET',    'wallet',                        'wallet_saldo'],
    ['GET',    'wallet/movimenti',              'wallet_movimenti'],

    // --- admin ---
    ['GET',    'admin/utenti',                  'admin_utenti'],
    ['GET',    'admin/statistiche',             'admin_statistiche'],
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
