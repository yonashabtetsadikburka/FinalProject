// Controlli STATICI anti-XSS sul codice del front-end: leggono i file e cercano i modelli
// pericolosi. Nessun server, nessun browser:   node tests/test_frontend_sicurezza.mjs
// (Le regole sono spiegate in frontend/js/escape.js.)
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const RADICE = new URL('../frontend/js/', import.meta.url).pathname;
let falliti = 0, totale = 0;
const ok = (nome, cond, dettagli = []) => {
  totale++;
  console.log((cond ? '  ok      ' : '  FALLITO ') + nome);
  if (!cond) { falliti++; for (const d of dettagli.slice(0, 8)) console.log('            ' + d); }
};

const file = [];
(function scandisci(dir) {
  for (const n of readdirSync(dir)) {
    const p = join(dir, n);
    if (statSync(p).isDirectory()) { if (n !== 'lib') scandisci(p); }   // lib/ = qrcode.js di terzi
    else if (n.endsWith('.js')) file.push(p);
  }
})(RADICE);
const testo = Object.fromEntries(file.map(f => [relative(RADICE, f), readFileSync(f, 'utf8')]));
const righe = (t) => t.split('\n');

console.log('Tutte le risposte dell\'API passano da escapeDeep()');
ok('api.js: apiRequest e apiPostForm escapano dati', (testo['api.js'].match(/escapeDeep\(data\.dati\)/g) || []).length === 2);
ok('pages/fornitore-attiva.js (ha un fetch suo): escapa in entrambe le funzioni', (testo['pages/fornitore-attiva.js'].match(/escapeDeep\(data\.dati\)/g) || []).length === 2);
const fetchFuori = Object.entries(testo).filter(([f, t]) => /\bfetch\(/.test(t) && !['api.js', 'pages/fornitore-attiva.js'].includes(f)).map(([f]) => f);
ok('nessun altro file fa fetch() direttamente (aggirerebbe l\'escape)', fetchFuori.length === 0, fetchFuori);

console.log('\nLo stato salvato nel localStorage non e\' fidato');
ok('state.js sanifica l\'utente sia quando lo legge sia quando lo salva', (testo['state.js'].match(/sanificaDeep\(/g) || []).length >= 2);

console.log('\nHandler inline (onclick="...", onchange="..."): mai testo dentro, solo id e costanti');
// Il browser decodifica le entita' PRIMA di eseguire il JavaScript di un handler: una stringa
// escapata dentro onclick="f('${nome}')" si trasforma di nuovo in codice. Nei handler si mettono
// solo id numerici (o costanti del codice); i testi passano da  data-x="${testo}"  +  this.dataset.x.
const CONSENTITE = /^(?:[A-Za-z_]\w*\.)*(?:id|id_\w+|\w+_id|\w+Id|i|idx|v|closeHandler|onClick)$|^[\w.]+ \? 'true' : 'false'$/;
const proibite = [];
for (const [f, t] of Object.entries(testo)) {
  righe(t).forEach((riga, n) => {
    if (/^\s*(\*|\/\/|\/\*)/.test(riga)) return;                 // commenti
    for (const m of riga.matchAll(/\bon[a-z]+="([^"]*)"/g)) {
      for (const e of m[1].matchAll(/\$\{([^}]*)\}/g)) {
        const espr = e[1].trim();
        if (!CONSENTITE.test(espr)) proibite.push(`${f}:${n + 1}  \${${espr}} dentro un handler: usare data-x + this.dataset.x`);
      }
    }
  });
}
ok('ogni ${...} dentro un handler inline e\' un id numerico o una costante', proibite.length === 0, proibite);

console.log('\nMessaggi d\'errore e import');
const grezzi = [];
for (const [f, t] of Object.entries(testo)) righe(t).forEach((r, n) => { if (/\$\{(?:err|e|error)\??\.message/.test(r)) grezzi.push(`${f}:${n + 1}`); });
ok('nessun ${err.message} scritto in un template senza esc()', grezzi.length === 0, grezzi);
const senzaImport = [];
for (const [f, t] of Object.entries(testo)) {
  if (f === 'escape.js') continue;
  const usa = (n) => new RegExp(`(?<![\\w.])${n}\\(`).test(t.replace(/^import .*$/gm, ''));
  const imp = t.match(/^import \{([^}]*)\} from '[^']*escape\.js';/m);
  const importati = imp ? imp[1].split(',').map(x => x.trim()) : [];
  for (const nome of ['esc', 'unesc', 'escapeDeep']) if (usa(nome) && !importati.includes(nome)) senzaImport.push(`${f}: usa ${nome}() ma non lo importa`);
}
ok('chi usa esc()/unesc()/escapeDeep() li importa (altrimenti errore solo a runtime)', senzaImport.length === 0, senzaImport);

console.log('\nAltri punti sensibili');
const urlPericolosi = [];
for (const [f, t] of Object.entries(testo)) righe(t).forEach((r, n) => { if (/href="\$\{(?!.*(?:imgUrl|#\/))[^}]*\}"/.test(r) && !r.includes('https?:')) urlPericolosi.push(`${f}:${n + 1}  ${r.trim().slice(0, 90)}`); });
ok('nessun href="${dato}" senza controllo dello schema http(s)', urlPericolosi.length === 0, urlPericolosi);
const eval_ = Object.entries(testo).filter(([, t]) => /\beval\(|new Function\(|document\.write\(/.test(t)).map(([f]) => f);
ok('nessun eval(), new Function() o document.write()', eval_.length === 0, eval_);

console.log('\n' + (falliti === 0 ? `TUTTI I ${totale} TEST SUPERATI` : `${falliti} TEST FALLITI su ${totale}`));
process.exit(falliti === 0 ? 0 : 1);
