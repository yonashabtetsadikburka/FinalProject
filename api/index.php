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

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/lib/risposta.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/stato.php';
require __DIR__ . '/lib/ripartizione.php';
require __DIR__ . '/endpoints/auth.php';
require __DIR__ . '/endpoints/catalogo.php';
require __DIR__ . '/endpoints/prodotti.php';
require __DIR__ . '/endpoints/campagne.php';
require __DIR__ . '/endpoints/partecipazioni.php';
require __DIR__ . '/endpoints/assegnazioni.php';
require __DIR__ . '/endpoints/ritiro.php';
require __DIR__ . '/endpoints/pagamenti.php';
require __DIR__ . '/endpoints/pagamento.php';
require __DIR__ . '/endpoints/notifiche.php';
require __DIR__ . '/endpoints/proposte.php';
require __DIR__ . '/endpoints/utenti.php';
require __DIR__ . '/endpoints/password.php';
require __DIR__ . '/endpoints/fornitori.php';
require __DIR__ . '/endpoints/recensioni.php';
require __DIR__ . '/endpoints/consegne.php';
require __DIR__ . '/endpoints/profilo.php';

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
    ['POST',   'auth/google',                       'auth_google_login'],
    ['POST',   'auth/microsoft',                    'auth_microsoft_login'],
    ['POST',   'logout',                            'auth_logout'],
    ['GET',    'io',                                'auth_io'],
    ['GET',    'fornitori',                         'catalogo_fornitori'],
    ['GET',    'prodotti',                          'catalogo_prodotti'],
    ['GET',    'admin/prodotti/{id}/immagini',        'prodotti_immagini_elenco'],
    ['POST',   'admin/prodotti/{id}/immagini',        'prodotti_immagini_aggiungi'],
    ['DELETE', 'admin/immagini/{id}',                 'prodotti_immagine_elimina'],
    ['GET',    'sedi',                             'catalogo_sedi'],
    ['GET',    'campagne',                          'campagne_elenco'],
    ['POST',   'campagne',                          'campagne_crea'],
    ['GET',    'campagne/{id}',                     'campagne_dettaglio'],
    ['DELETE', 'campagne/{id}',                     'campagne_elimina'],
    ['POST',   'campagne/{id}/modifica',            'campagne_modifica'],
    ['GET',    'admin/ordini',                      'admin_ordini'],
    ['POST',   'campagne/{id}/partecipazioni',      'partecipazioni_aderisci'],
    ['DELETE', 'campagne/{id}/partecipazioni',      'partecipazioni_ritira'],
    ['GET',    'mie/partecipazioni',                'partecipazioni_mie'],
    ['GET',    'mie/statistiche',                   'mie_statistiche'],
    ['POST',   'consegne/scelta',                   'consegne_scelta'],
    ['GET',    'consegne/costo',                    'consegne_costo'],
    ['GET',    'admin/consegne',                    'consegne_admin_elenco'],
    ['POST',   'consegne/{id}/spedisci',            'consegne_spedisci'],
    ['POST',   'consegne/{id}/conferma-ricezione',  'consegne_conferma_ricezione'],
    ['POST',   'campagne/{id}/ripartisci',          'assegnazioni_ripartisci'],
    ['POST',   'campagne/{id}/invia-fornitore',     'campagne_invia_fornitore'],
    ['GET',    'campagne/{id}/assegnazioni',        'assegnazioni_elenco'],
    ['GET',    'mie/assegnazioni/{id}/qr',          'ritiro_qr'],
    ['GET',    'admin/ritiri',                      'ritiri_elenco'],
    ['POST',   'ritiro/{token}',                    'ritiro_conferma'],
    ['GET',    'wallet',                            'wallet_dettaglio'],
    ['GET',    'wallet/movimenti',                  'wallet_movimenti'],
    ['GET',    'wallet/{id}',                       'wallet_dettaglio_utente'],
    ['GET',    'wallet/statistiche',                'wallet_statistiche'],
    ['POST',   'pagamento/checkout',                'pagamento_checkout'],
    ['GET',    'pagamento/stato',                   'pagamento_stato'],
    ['GET',    'notifiche',                         'notifiche_elenco'],
    ['GET',    'notifiche/conta',                   'notifiche_conta'],
    ['PUT',    'notifiche/{id}/letta',              'notifiche_segna_letta'],
    ['POST',   'notifiche/marca-tutte-lette',       'notifiche_segna_tutte_lette'],
    ['POST',   'notifiche',                         'notifiche_invia'],
    ['GET',    'proposte',                          'proposte_elenco'],
    ['POST',   'proposte',                          'proposte_crea'],
    ['POST',   'proposte/{id}/vota',              'proposte_vota'],
    ['DELETE', 'proposte/{id}/vota',              'proposte_ritira_voto'],
    ['PUT',    'proposte/{id}/stato',             'proposte_cambia_stato'],
    ['GET',    'utenti',                            'utenti_elenco'],
    ['GET',    'utenti/{id}',                       'utenti_dettaglio'],
    ['PUT',    'utenti/{id}/stato',                 'utenti_aggiorna_stato'],
    ['PUT',    'utenti/{id}/ruolo',                 'utenti_aggiorna_ruolo'],
    ['DELETE', 'utenti/{id}',                       'utenti_elimina'],
    ['GET',    'utenti/{id}/storico',               'utenti_storico'],
    ['POST',   'password/richiesta',                'password_richiesta'],
    ['POST',   'admin/utenti/{id}/reset-link',      'password_reset_genera'],
    ['GET',    'password/reset/{token}',            'password_reset_verifica'],
    ['POST',   'password/reset',                    'password_reset_esegui'],
    ['GET',    'admin/fornitori',                   'fornitori_admin_elenco'],
    ['POST',   'admin/fornitori',                   'fornitori_admin_crea'],
    ['PUT',    'admin/fornitori/{id}',              'fornitori_admin_aggiorna'],
    ['POST',   'admin/fornitori/{id}/invito',       'fornitori_invito_genera'],
    ['POST',   'admin/fornitori/{id}/collega',      'fornitori_collega'],
    ['GET',    'fornitori/invito/{token}',          'fornitori_invito_verifica'],
    ['POST',   'fornitori/attiva',                  'fornitori_attiva'],
    ['GET',    'fornitore/io',                      'fornitore_io'],
    ['PUT',    'fornitore/io',                      'fornitore_io_aggiorna'],
    ['POST',   'fornitore/proposte',                'fornitore_proposte_crea'],
    ['GET',    'fornitori/{id}/recensioni',         'recensioni_elenco'],
    ['POST',   'fornitori/{id}/recensioni',         'recensioni_salva'],
    ['DELETE', 'fornitori/recensioni/{id}',         'recensione_elimina'],
    ['GET',    'campagne/{id}/recensioni',             'recensioni_campagna_elenco'],
    ['POST',   'campagne/{id}/recensioni',             'recensioni_campagna_salva'],
    ['DELETE', 'campagne/recensioni/{id}',             'recensione_campagna_elimina'],
    ['GET',    'fornitore/proposte',                'fornitore_proposte_mie'],
    ['GET',    'fornitore/campagne',                'fornitore_campagne'],
    ['GET',    'fornitore/ordini',                  'fornitore_ordini'],
    ['POST',   'fornitore/ordini/{id}/avanza',      'fornitore_ordine_avanza'],
    ['GET',    'profilo',                           'profilo_leggi'],
    ['PUT',    'profilo',                           'profilo_aggiorna'],
    ['POST',   'profilo/password',                  'profilo_cambia_password'],
    ['GET',    'profilo/export',                    'profilo_export'],
    ['POST',   'profilo/cancellazione',             'profilo_cancellazione_richiesta'],
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

    // Webhook Stripe: POST /pagamento/webhook — bypass del router normale
    if ($metodo === 'POST' && $percorso === 'pagamento/webhook') {
        pagamento_webhook();
        exit;
    }

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
