// Test del redirect delle vecchie rotte. Uso: node tests/acquisti_front_test.mjs
import { destinazioneAcquisti } from '../frontend/js/acquisti-redirect.js';

let fail = 0;
function uguale(nome, ottenuto, atteso) {
  const ok = ottenuto === atteso;
  console.log(`${ok ? '[OK]  ' : '[FAIL]'} ${nome}${ok ? '' : `  ottenuto "${ottenuto}", atteso "${atteso}"`}`);
  if (!ok) fail++;
}

uguale('partecipazioni -> In corso', destinazioneAcquisti('/partecipazioni', ''), '/acquisti?tab=in-corso');
uguale('ordini -> In arrivo', destinazioneAcquisti('/ordini', ''), '/acquisti?tab=in-arrivo');
uguale('ordini con ?id=56 -> acquisto 56 (scheda scelta dal server)', destinazioneAcquisti('/ordini', 'id=56'), '/acquisti?id=56');
uguale('partecipazioni con ?id=7', destinazioneAcquisti('/partecipazioni', 'id=7'), '/acquisti?id=7');
uguale('?id non numerico ignorato', destinazioneAcquisti('/ordini', 'id=abc'), '/acquisti?tab=in-arrivo');
uguale('?id con codice iniettato ignorato', destinazioneAcquisti('/ordini', 'id=1%26tab%3Dx'), '/acquisti?id=1');
uguale('parametri sconosciuti non vengono riportati', destinazioneAcquisti('/partecipazioni', 'x=<script>'), '/acquisti?tab=in-corso');
uguale('rotta non gestita -> null', destinazioneAcquisti('/wallet', ''), null);

console.log(fail === 0 ? '\nTutti i test superati' : `\n${fail} test FALLITI`);
process.exit(fail === 0 ? 0 : 1);
