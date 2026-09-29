<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da riga di comando
/**
 * Test del ciclo di vita completo di una campagna, con pagamenti Stripe SIMULATI:
 * gli eventi del webhook sono firmati davvero (stesso schema HMAC di Stripe) con
 * il segreto di prova whsec_local_test_secret, quindi si esercita anche la
 * verifica della firma.
 *
 *   soglia raggiunta -> admin ripartisce -> i clienti pagano (webhook) ->
 *   l'ordine parte da solo al fornitore -> il fornitore evade -> ritiro con QR
 *
 * Prerequisiti: database appena creato + database/seed.php, e in api/config.php
 *     'stripe_webhook_secret' => 'whsec_local_test_secret'
 *
 *     php tests/test_ciclo_campagna.php [URL-dell-api]
 *
 * Usa la campagna 3 (riuscita) dei dati di esempio: va eseguito su un DB "fresco".
 */
$base = rtrim($argv[1] ?? 'http://localhost:8888/FinalProject/api', '/');
require __DIR__ . '/lib_test.php';

const SEGRETO = 'whsec_local_test_secret';
$codice = fn(array $r) => $r[1]['errore']['codice'] ?? '';

/** Invia al webhook un evento checkout.session.completed firmato come farebbe Stripe. */
function webhook(Client $c, string $idEvento, int $pren, int $colletta, int $utente,
                 ?string $segreto = SEGRETO, ?int $t = null): array
{
    $payload = json_encode([
        'id' => $idEvento, 'object' => 'event', 'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_' . $idEvento, 'object' => 'checkout.session', 'payment_status' => 'paid',
            'payment_intent' => 'pi_test_' . $idEvento,
            'metadata' => ['prenotazione_id' => (string)$pren, 'colletta_id' => (string)$colletta, 'utente_id' => (string)$utente],
        ]],
    ]);
    $t ??= time();
    $h = [];
    if ($segreto !== null) $h[] = "Stripe-Signature: t=$t,v1=" . hash_hmac('sha256', "$t.$payload", $segreto);
    return $c->call('POST', '/pagamento/webhook', $payload, $h);
}

$admin = new Client($base);  $admin->login('admin@buypool.test');
$forn  = new Client($base);  $forn->login('casa@buypool.test');      // CasaBella vende il robot
$utenti = ['mario.rossi', 'giulia.bianchi', 'luca.verdi', 'sara.neri'];
$cl = [];
foreach ($utenti as $u) { $cl[$u] = new Client($base); $r = $cl[$u]->login("$u@buypool.test"); $cl[$u]->id = (int)$r[1]['dati']['id']; }

echo "== Partenza ==\n";
$r = $admin->call('GET', '/campagne/3');
ok('la campagna 3 (robot) e\' "riuscita": soglia raggiunta', ($r[1]['dati']['stato'] ?? '') === 'riuscita', json_encode($r[1]['dati']['stato'] ?? null));

echo "\n== Admin ripartisce (conferma l'ordine) ==\n";
$r = $cl['mario.rossi']->call('POST', '/campagne/3/ripartisci', []);
ok('un cliente non puo\' ripartire', $r[0] === 403);
$r = $admin->call('POST', '/campagne/3/ripartisci', []);
ok('l\'admin ripartisce: 4 assegnazioni, 15 pezzi', ($r[1]['dati']['assegnazioni'] ?? 0) === 4 && ($r[1]['dati']['totale_pezzi'] ?? 0) === 15, json_encode($r[1]));
$r = $admin->call('GET', '/campagne/3');
ok('campagna in "ordine_pronto" (in attesa dei pagamenti)', ($r[1]['dati']['stato'] ?? '') === 'ordine_pronto');
$r = $admin->call('POST', '/campagne/3/ripartisci', []);
ok('ripartire due volte e\' rifiutato', $r[0] >= 400);
$r = $admin->call('POST', '/campagne/3/invia-fornitore', []);
ok('non si invia al fornitore prima che tutti paghino', $codice($r) === 'PAGAMENTI_INCOMPLETI');

// id delle prenotazioni dei 4 clienti sulla campagna 3
$pren = [];
foreach ($cl as $nome => $c) {
    $r = $c->call('GET', '/mie/partecipazioni');
    foreach ($r[1]['dati'] ?? [] as $p) if ((int)$p['id_colletta'] === 3) $pren[$nome] = (int)$p['id'];
}
ok('trovate le 4 prenotazioni', count($pren) === 4);
$r = $cl['mario.rossi']->call('POST', '/consegne/scelta', ['prenotazione_id' => $pren['mario.rossi'], 'modalita' => 'ritiro_sede']);
ok('Mario sceglie il ritiro in sede (prima di pagare)', in_array($r[0], [200, 201], true), $codice($r));

echo "\n== Pagamento ==\n";
$r = $cl['mario.rossi']->call('POST', '/pagamento/checkout', ['prenotazione_id' => $pren['mario.rossi']]);
ok('checkout senza chiave Stripe: errore chiaro, non un crash', $codice($r) === 'PAGAMENTI_NON_CONFIGURATI' && $r[0] === 503, "http {$r[0]} " . $codice($r));

$anon = new Client($base);
[$h] = webhook($anon, 'evt_senza_firma', $pren['mario.rossi'], 3, $cl['mario.rossi']->id, null);
ok('webhook SENZA firma rifiutato (400)', $h === 400, "http $h");
[$h] = webhook($anon, 'evt_firma_falsa', $pren['mario.rossi'], 3, $cl['mario.rossi']->id, 'un-segreto-inventato');
ok('webhook con firma FALSA rifiutato (400)', $h === 400, "http $h");
[$h] = webhook($anon, 'evt_vecchio', $pren['mario.rossi'], 3, $cl['mario.rossi']->id, SEGRETO, time() - 3600);
ok('webhook con firma valida ma vecchia di un\'ora rifiutato (replay)', $h === 400, "http $h");
$r = $cl['mario.rossi']->call('GET', '/mie/partecipazioni');
$stato = array_values(array_filter($r[1]['dati'], fn($p) => (int)$p['id'] === $pren['mario.rossi']))[0]['stato'] ?? '';
ok('i tentativi falsi NON hanno segnato nulla come pagato', $stato === 'confermata', $stato);

$i = 0;
foreach ($pren as $nome => $idp) {
    $i++;
    [$h] = webhook($anon, "evt_ok_$i", $idp, 3, $cl[$nome]->id);
    ok("pagamento di $nome (webhook firmato)", $h === 200, "http $h");
    if ($nome === 'mario.rossi') {
        [$h, $j] = webhook($anon, "evt_ok_$i", $idp, 3, $cl[$nome]->id);
        ok('lo stesso evento ripetuto e\' ignorato (idempotente)', $h === 200 && ($j['duplicate'] ?? false) === true);
    }
}
$r = $cl['mario.rossi']->call('GET', '/mie/partecipazioni');
$stato = array_values(array_filter($r[1]['dati'], fn($p) => (int)$p['id'] === $pren['mario.rossi']))[0]['stato'] ?? '';
ok('la prenotazione di Mario ora e\' "pagata"', $stato === 'pagata', $stato);
$r = $cl['mario.rossi']->call('GET', '/notifiche');
$tipi = array_column($r[1]['dati'] ?? [], 'tipo');
ok('Mario riceve la notifica di pagamento', in_array('PAGAMENTO_RIUSCITO', $tipi, true));
$r = $admin->call('GET', '/notifiche');
ok('l\'admin riceve la notifica di incasso', in_array('PAGAMENTO_RICEVUTO', array_column($r[1]['dati'] ?? [], 'tipo'), true));

echo "\n== Ordine al fornitore (parte da solo quando tutti hanno pagato) ==\n";
$r = $admin->call('GET', '/campagne/3');
ok('campagna in "ordine_fornitore"', ($r[1]['dati']['stato'] ?? '') === 'ordine_fornitore', $r[1]['dati']['stato'] ?? '');
$r = $forn->call('GET', '/fornitore/ordini');
$ord = $r[1]['dati'][0] ?? [];
ok('CasaBella vede l\'ordine: 15 pezzi', (int)($ord['quantita_ordinata'] ?? 0) === 15 && ($ord['stato'] ?? '') === 'inviato', json_encode($ord));
$altro = new Client($base); $altro->login('tech@buypool.test');
$r = $altro->call('GET', '/fornitore/ordini');
ok('TechWorld NON vede l\'ordine di CasaBella', count($r[1]['dati'] ?? []) === 0);
$r = $altro->call('POST', '/fornitore/ordini/' . ($ord['id'] ?? 0) . '/avanza', []);
ok('TechWorld non puo\' avanzare l\'ordine di un altro', $r[0] >= 400, "http {$r[0]}");

echo "\n== Ritiro con QR ==\n";
$r = $cl['mario.rossi']->call('GET', '/mie/assegnazioni/' . $pren['mario.rossi'] . '/qr');
$token = $r[1]['dati']['token'] ?? '';
ok('Mario ha il suo QR', strlen($token) >= 32, $codice($r));
$r = $cl['giulia.bianchi']->call('GET', '/mie/assegnazioni/' . $pren['mario.rossi'] . '/qr');
ok('Giulia NON puo\' vedere il QR di Mario', $r[0] >= 400 && empty($r[1]['dati']['token']), "http {$r[0]}");
$r = $cl['mario.rossi']->call('POST', "/ritiro/$token", []);
ok('un cliente non puo\' confermare il ritiro', $r[0] === 403, "http {$r[0]}");
$r = $admin->call('POST', "/ritiro/$token", []);
ok('l\'admin conferma il ritiro', in_array($r[0], [200, 201], true), $codice($r));
$r = $admin->call('POST', "/ritiro/$token", []);
ok('ritirare due volte e\' rifiutato', $r[0] >= 400, "http {$r[0]}");
$r = $admin->call('POST', '/ritiro/abcdef0123456789abcdef', []);
ok('token inesistente: 404', $r[0] === 404, "http {$r[0]}");

echo "\n== Il fornitore evade e la campagna si chiude ==\n";
foreach (['ricevuto', 'in_preparazione', 'evaso'] as $atteso) {
    $r = $forn->call('POST', '/fornitore/ordini/' . $ord['id'] . '/avanza', []);
    ok("ordine -> $atteso", ($r[1]['dati']['stato'] ?? '') === $atteso, json_encode($r[1]));
}
$r = $forn->call('POST', '/fornitore/ordini/' . $ord['id'] . '/avanza', []);
ok('oltre "evaso" non si va', $r[0] >= 400);
$r = $admin->call('GET', '/campagne/3');
ok('campagna "consegnata"', ($r[1]['dati']['stato'] ?? '') === 'consegnata');

echo "\n" . ($falliti === 0 ? "TUTTI I $totale TEST SUPERATI" : "$falliti TEST FALLITI su $totale") . "\n";
exit($falliti === 0 ? 0 : 1);
