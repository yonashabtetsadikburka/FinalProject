<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da riga di comando
/**
 * Verifica MANUALE in un browser vero dell'XSS (stored cross-site scripting).
 *
 * Pianta del testo "ostile" in ogni campo che un utente puo' scrivere (proposta, nome e cognome,
 * fornitore, prodotto, campagna, notifica) e poi stampa uno snippet da incollare nella console del
 * browser: visita tutte le pagine e conta quante volte e' stato eseguito codice. Deve dire 0 ovunque.
 *
 *     php tests/xss_manuale.php http://localhost:8888/<cartella>/api
 *
 * Scrive sul database (crea dati di prova): usare un DB di sviluppo, poi rifare schema + seed.
 * I controlli automatici equivalenti sono   node tests/test_escape.mjs   e
 * node tests/test_frontend_sicurezza.mjs   (non richiedono browser).
 */
$base = rtrim($argv[1] ?? 'http://localhost:8888/FinalProject/api', '/');
require __DIR__ . '/lib_test.php';

$X = fn(string $etichetta) => '<img src=x onerror="window.__xss=(window.__xss||0)+1">' . $etichetta;

$admin = new Client($base); $admin->login('admin@buypool.test');
$mario = new Client($base); $mario->login('mario.rossi@buypool.test');
$stesso = fn($r) => $r[0] < 400 ? 'ok' : 'ERRORE ' . json_encode($r[1]);

echo "Pianto i dati ostili...\n";
echo '  proposta di un cliente:           ' . $stesso($mario->call('POST', '/proposte', ['nome_prodotto' => $X('PROPOSTA'), 'descrizione' => $X('DESC')])) . "\n";
$anon = new Client($base);
echo '  utente con nome/cognome ostili:   ' . $stesso($anon->call('POST', '/registrazione', ['nome' => $X('UTENTE'), 'cognome' => '"><svg onload=window.__xss=(window.__xss||0)+1>', 'email' => 'xss1@buypool.test', 'password' => 'Password123!', 'privacy' => true])) . "\n";
echo '  utente che tenta di uscire da una stringa JS: ' . $stesso($anon->call('POST', '/registrazione', ['nome' => "Bad'); window.__xss=(window.__xss||0)+100; ('", 'cognome' => "O'Brien", 'email' => 'xss2@buypool.test', 'password' => 'Password123!', 'privacy' => true])) . "\n";
echo '  fornitore:                        ' . $stesso($admin->call('POST', '/admin/fornitori', ['nome_azienda' => $X('FORNITORE'), 'email_contatto' => 'xss@buypool.test', 'categoria' => $X('CATEGORIA')])) . "\n";
$r = $admin->call('POST', '/admin/prodotti', ['nome' => $X('PRODOTTO'), 'descrizione' => $X('DESCRIZIONE'), 'prezzo_unitario' => '10', 'prezzo_base' => '20', 'quantita_minima' => '3', 'id_fornitore' => '1', 'id_categoria' => '1'], [], true);
echo '  prodotto:                         ' . $stesso($r) . "\n";
$idp = (int)($r[1]['dati']['id'] ?? 0);
$r = $admin->call('POST', '/campagne', ['prodotto_id' => $idp, 'scadenza' => date('Y-m-d H:i:s', strtotime('+9 days')), 'prezzo_base' => 20, 'prezzo_corrente' => 15, 'quantita_minima' => 5, 'percentuale_commissione' => 10]);
echo '  campagna su quel prodotto:        ' . $stesso($r) . "\n";
$idc = (int)($r[1]['dati']['id'] ?? 0);
foreach ([1, 2] as $uid) $admin->call('POST', '/notifiche', ['id_utente' => $uid, 'tipo' => 'SISTEMA', 'titolo' => $X('TITOLO'), 'messaggio' => $X('MESSAGGIO')]);
echo "  notifiche:                        ok\n";
echo "  (la recensione ostile va inserita a mano: serve un acquisto pagato)\n";

echo <<<TXT

Ora nel browser:
  1. Apri  {$base}/../frontend/index.html  e accedi come admin@buypool.test / Demo1234!
  2. Apri la console (F12) e incolla:

const routes=['#/admin','#/admin/campagne','#/admin/prodotti','#/admin/proposte','#/admin/utenti','#/admin/fornitori','#/admin/notifiche','#/notifiche','#/proposte','#/fornitori','#/fornitori/1','#/prodotti','#/campagne/{$idc}','#/profilo','#/'];
window.__xss=undefined; const out=[];
for (const r of routes){ location.hash=r; await new Promise(x=>setTimeout(x,1500));
  out.push(r+' | codice eseguito: '+(window.__xss??0)+' | <img> iniettate: '+document.querySelectorAll('img[src="x"]').length); }
console.table(out)

  RISULTATO ATTESO: 0 codice eseguito e 0 <img> iniettate in OGNI riga. Il testo ostile compare come
  testo normale sulle pagine che lo mostrano.
  3. Poi accedi come  xss1@buypool.test / Password123!  (il suo NOME e' il payload) e ripeti con
     le rotte cliente: '#/', '#/profilo', '#/dashboard', '#/ordini', '#/partecipazioni', '#/wallet'.
  4. Prova l'uscita da una stringa JS: da admin, in '#/admin/utenti' apri il dettaglio di xss2 e premi
     "Elimina" (metti prima  window.confirm=()=>false  in console): non deve eseguire nulla.

TXT;
