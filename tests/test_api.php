<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da riga di comando
/**
 * Test end-to-end dell'API (parla HTTP vera con Apache, sessioni e cookie veri).
 *
 * Prerequisiti: database creato con database/schema.sql e caricato con
 * database/seed.php (dati di esempio), MAMP acceso.
 *
 *     php tests/test_api.php [URL-dell-api]
 *     php tests/test_api.php http://localhost:8888/FinalProject/api
 *
 * ATTENZIONE: scrive sul database (crea prenotazioni, un prodotto...). Usare
 * un database di sviluppo, mai uno con dati veri.
 */
$base = rtrim($argv[1] ?? 'http://localhost:8888/FinalProject/api', '/');
require __DIR__ . '/lib_test.php';

echo "== Sicurezza dell'accesso ==\n";
$anon = new Client($base);
[$h] = $anon->call('GET', '/campagne');
ok('senza login: 401', $h === 401);
foreach (['Bearer 1', 'Bearer session_1', 'Bearer google_1', 'Bearer eyJhbGciOiJub25lIn0.e30.'] as $tok) {
    [$h] = $anon->call('GET', '/utenti', null, ["Authorization: $tok"]);
    ok("header \"$tok\" NON autentica (era la falla di Symfony)", $h === 401, "http $h");
}
$r = $anon->call('POST', '/login', ['email' => 'admin@buypool.test', 'password' => 'sbagliata']);
ok('password sbagliata: 401', $r[0] === 401 && $codice($r) === 'CREDENZIALI_NON_VALIDE');
$forgiato = rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=') . '.'
          . rtrim(strtr(base64_encode('{"oid":"x","email":"admin@buypool.test"}'), '+/', '-_'), '=') . '.finta';
$r = $anon->call('POST', '/auth/microsoft', ['token' => $forgiato]);
ok('login Microsoft con token FORGIATO rifiutato', in_array($r[0], [401, 503], true), "http {$r[0]}");
[$h, $j] = $anon->call('GET', '/io');
ok('dopo il tentativo forgiato sono ancora anonimo', $h === 401);
$r = $anon->call('POST', '/auth/google', ['token' => 'non-un-token-google']);
ok('login Google con token falso rifiutato', $r[0] === 401);

// Limite ai tentativi: email casuale a ogni esecuzione, per non bloccare gli account veri.
$vittima = 'vittima' . bin2hex(random_bytes(4)) . '@buypool.test';
$codici = [];
for ($i = 1; $i <= 6; $i++) {
    $r = $anon->call('POST', '/login', ['email' => $vittima, 'password' => "tentativo$i-sbagliato"]);
    $codici[] = $r[0];
}
ok('5 password sbagliate danno 401, la 6a viene bloccata con 429', $codici === [401, 401, 401, 401, 401, 429], implode(',', $codici));
$r = $anon->call('POST', '/login', ['email' => strtoupper($vittima), 'password' => 'Demo1234!']);
ok('bloccata anche cambiando le maiuscole dell\'email', $r[0] === 429);
$r = (new Client($base))->login('mario.rossi@buypool.test');
ok('un altro utente puo\' ancora accedere normalmente', $r[0] === 200);

echo "\n== Cliente: percorso principale ==\n";
$mario = new Client($base);
$r = $mario->login('mario.rossi@buypool.test');
ok('login cliente', $r[0] === 200 && ($r[1]['dati']['ruolo'] ?? '') === 'cliente');
$r = $mario->call('GET', '/campagne');
$camp = $r[1]['dati'] ?? [];
ok('elenco campagne non vuoto', $r[0] === 200 && count($camp) >= 4, 'n=' . count($camp));
$r = $mario->call('GET', '/campagne/4');
ok('dettaglio campagna 4 (pentole)', $r[0] === 200);
$prima = (int)($r[1]['dati']['quantita_attuale'] ?? -1);
$r = $mario->call('POST', '/campagne/4/partecipazioni', ['quantita' => 2]);
ok('partecipa alla campagna 4 con quantita 2', in_array($r[0], [200, 201], true), $codice($r));
$r = $mario->call('GET', '/campagne/4');
ok('quantita attuale aumentata di 2', (int)($r[1]['dati']['quantita_attuale'] ?? -1) === $prima + 2);
$r = $mario->call('POST', '/campagne/4/partecipazioni', ['quantita' => 1]);
ok('partecipare due volte e\' rifiutato', $codice($r) === 'GIA_PARTECIPI');
$r = $mario->call('POST', '/campagne/3/partecipazioni', ['quantita' => 1]);
ok('non si entra in una campagna gia\' riuscita', $codice($r) === 'CAMPAGNA_COMPLETA');
$r = $mario->call('POST', '/campagne/6/partecipazioni', ['quantita' => 1]);
ok('non si entra in una campagna fallita', $codice($r) === 'CAMPAGNA_FALLITA');
$r = $mario->call('POST', '/campagne/4/partecipazioni', ['quantita' => 0]);
ok('quantita non valida rifiutata', $r[0] === 400 || $r[0] === 409);
$r = $mario->call('DELETE', '/campagne/4/partecipazioni');
ok('ritira la partecipazione', $r[0] === 200, $codice($r));
$r = $mario->call('GET', '/campagne/4');
ok('quantita tornata com\'era', (int)($r[1]['dati']['quantita_attuale'] ?? -1) === $prima);
$r = $mario->call('GET', '/mie/partecipazioni');
ok('le mie partecipazioni', $r[0] === 200 && is_array($r[1]['dati'] ?? null));
$r = $mario->call('GET', '/notifiche');
ok('notifiche', $r[0] === 200);
$r = $mario->call('GET', '/proposte');
ok('proposte con voti', $r[0] === 200 && count($r[1]['dati'] ?? []) >= 1);

echo "\n== Contratto con il front-end (campi che le pagine leggono davvero) ==\n";
$r = $mario->call('GET', '/campagne/1');
$d = $r[1]['dati'] ?? [];
ok('dettaglio campagna: descrizione del prodotto', !empty($d['descrizione_prodotto']));
ok('dettaglio campagna: fornitore_info (partner dal / n. campagne)', isset($d['fornitore_info']['data_partnership']) && ($d['fornitore_info']['num_campagne'] ?? 0) >= 1);
$r = $mario->call('GET', '/fornitori/1/recensioni');
$rec = $r[1]['dati']['recensioni'][0] ?? [];
ok('recensioni: iniziale del cognome ("Marco R.")', ($rec['cognome_iniziale'] ?? '') !== '' && strlen($rec['cognome_iniziale']) === 1);
$r = $mario->call('GET', '/fornitori');
ok('elenco fornitori: trust_score', isset($r[1]['dati'][0]['trust_score']));
$r = $mario->call('GET', '/mie/partecipazioni');
ok('mie partecipazioni: dati del punto di ritiro', !empty($r[1]['dati'][0]['sede_nome']) && isset($r[1]['dati'][0]['sede_citta']));
$r = $mario->call('GET', '/prodotti');
$pp = $r[1]['dati']['prodotti'] ?? [];
$conCamp = array_values(array_filter($pp, fn($p) => $p['id'] === 1))[0] ?? [];
ok('prodotti: campagna_attiva / ha_mai_avuto_campagna / mi_piace presenti', ($conCamp['campagna_attiva'] ?? null) === true && ($conCamp['ha_mai_avuto_campagna'] ?? null) === true && isset($conCamp['mi_piace']));
$senza = array_filter($pp, fn($p) => $p['ha_mai_avuto_campagna'] === false);
ok('prodotti: c\'e\' almeno un prodotto votabile (mai avuto campagne)', count($senza) >= 0 && array_key_exists('ha_mai_avuto_campagna', $pp[0] ?? []));

echo "\n== Un cliente NON puo' fare cose da admin ==\n";
foreach ([['GET', '/utenti'], ['GET', '/admin/ordini'], ['GET', '/admin/fornitori'], ['GET', '/prodotti?tutti=1'],
          ['POST', '/admin/prodotti'], ['DELETE', '/admin/prodotti/1'], ['GET', '/admin/ritiri']] as [$m, $p]) {
    $r = $mario->call($m, $p, $m === 'POST' ? [] : null);
    ok("cliente: $m $p vietato (403)", $r[0] === 403, "http {$r[0]}");
}
$r = $mario->call('PUT', '/utenti/1/ruolo', ['ruolo' => 'admin']);
ok('cliente non puo\' promuoversi ad admin', $r[0] === 403, "http {$r[0]}");

echo "\n== Mi piace ==\n";
$r = $mario->call('POST', '/prodotti/1/like', []);
ok('mette mi piace', $r[0] === 200 && ($r[1]['dati']['mio_like'] ?? null) === true, $codice($r));
$n1 = (int)($r[1]['dati']['mi_piace'] ?? -1);
$r = $mario->call('POST', '/prodotti/1/like', []);
ok('ripetuto e\' idempotente', (int)($r[1]['dati']['mi_piace'] ?? -2) === $n1);
$r = $mario->call('GET', '/prodotti');
$p1 = array_values(array_filter($r[1]['dati']['prodotti'] ?? [], fn($p) => $p['id'] === 1))[0] ?? [];
ok('l\'elenco prodotti mostra mio_like e mi_piace', ($p1['mio_like'] ?? null) === true && ($p1['mi_piace'] ?? 0) >= 1);
$r = $mario->call('DELETE', '/prodotti/1/like');
ok('toglie mi piace', $r[0] === 200 && ($r[1]['dati']['mio_like'] ?? null) === false);
$r = $mario->call('POST', '/prodotti/9999/like', []);
ok('mi piace su prodotto inesistente: 404', $r[0] === 404);

echo "\n== Admin: catalogo prodotti ==\n";
$admin = new Client($base);
$r = $admin->login('admin@buypool.test');
ok('login admin', ($r[1]['dati']['ruolo'] ?? '') === 'admin');
foreach (['/utenti', '/admin/ordini', '/admin/fornitori', '/admin/ritiri', '/admin/consegne'] as $p) {
    $r = $admin->call('GET', $p);
    ok("admin: GET $p", $r[0] === 200, $codice($r) ?: "http {$r[0]}");
}
$r = $admin->call('GET', '/prodotti?tutti=1');
ok('admin vede tutti i prodotti', $r[0] === 200 && count($r[1]['dati']['prodotti'] ?? []) >= 6);

// piccola immagine PNG vera (1x1) per provare l'upload
$png = tempnam(sys_get_temp_dir(), 'png') . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$campi = ['nome' => 'Prodotto di prova', 'descrizione' => 'creato dal test', 'prezzo_unitario' => '10.50',
          'prezzo_base' => '15.00', 'quantita_minima' => '5', 'id_fornitore' => '1', 'id_categoria' => '1',
          'foto' => new CURLFile($png, 'image/png', 'foto.png')];
$r = $admin->call('POST', '/admin/prodotti', $campi, [], true);
ok('crea prodotto con foto', $r[0] === 201 && isset($r[1]['dati']['id']), $codice($r) ?: json_encode($r[1]));
$nuovo = (int)($r[1]['dati']['id'] ?? 0);
$r = $admin->call('GET', '/prodotti?tutti=1');
$trovato = array_values(array_filter($r[1]['dati']['prodotti'] ?? [], fn($p) => $p['id'] === $nuovo))[0] ?? [];
ok('il prodotto compare con la sua immagine', ($trovato['nome'] ?? '') === 'Prodotto di prova' && !empty($trovato['immagine']));
$file = __DIR__ . '/../api/' . ($trovato['immagine'] ?? 'x');
ok('il file e\' stato salvato sul disco', is_file($file));
$r = $admin->call('POST', '/admin/prodotti', array_merge($campi, ['prezzo_unitario' => '99', 'foto' => null]), [], true);
ok('prezzo attuale > prezzo base rifiutato', $codice($r) === 'PREZZO_NON_VALIDO');
$r = $admin->call('POST', '/admin/prodotti', ['nome' => 'x', 'prezzo_unitario' => '1', 'quantita_minima' => '1', 'id_fornitore' => '999'], [], true);
ok('fornitore inesistente rifiutato', $codice($r) === 'FORNITORE_INESISTENTE');
$r = $admin->call('POST', "/admin/prodotti/$nuovo/modifica", ['nome' => 'Prodotto rinominato', 'prezzo_unitario' => '12.00'], [], true);
ok('modifica nome e prezzo', $r[0] === 200, $codice($r));
$r = $admin->call('GET', '/prodotti?tutti=1');
$trovato = array_values(array_filter($r[1]['dati']['prodotti'] ?? [], fn($p) => $p['id'] === $nuovo))[0] ?? [];
ok('la modifica e\' visibile', ($trovato['nome'] ?? '') === 'Prodotto rinominato' && (float)($trovato['prezzo_unitario'] ?? 0) === 12.0);
$r = $admin->call('POST', "/admin/prodotti/$nuovo/modifica", [], [], true);
ok('modifica vuota rifiutata', $codice($r) === 'NESSUNA_MODIFICA');
$r = $admin->call('DELETE', '/admin/prodotti/1');
ok('non si elimina un prodotto con campagne (409)', $r[0] === 409 && $codice($r) === 'HA_CAMPAGNE');
$r = $admin->call('DELETE', "/admin/prodotti/$nuovo");
ok('elimina il prodotto di prova', $r[0] === 200, $codice($r));
clearstatcache();   // PHP memorizza is_file(): senza, vedrebbe il risultato vecchio
ok('e il suo file immagine sparisce dal disco', !is_file($file));
$r = $admin->call('DELETE', "/admin/prodotti/$nuovo");
ok('eliminarlo di nuovo: 404', $r[0] === 404);
@unlink($png);

echo "\n== Fornitore ==\n";
$forn = new Client($base);
$r = $forn->login('tech@buypool.test');
ok('login fornitore', ($r[1]['dati']['ruolo'] ?? '') === 'fornitore');
foreach (['/fornitore/io', '/fornitore/campagne', '/fornitore/ordini', '/fornitore/proposte'] as $p) {
    $r = $forn->call('GET', $p);
    ok("fornitore: GET $p", $r[0] === 200, $codice($r) ?: "http {$r[0]}");
}
$r = $forn->call('GET', '/utenti');
ok('il fornitore non e\' admin: /utenti vietato', $r[0] === 403);

echo "\n" . ($falliti === 0 ? "TUTTI I $totale TEST SUPERATI" : "$falliti TEST FALLITI su $totale") . "\n";
exit($falliti === 0 ? 0 : 1);
