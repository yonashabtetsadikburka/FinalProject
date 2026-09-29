// Test delle funzioni anti-XSS del front-end (frontend/js/escape.js).
// Nessun server, nessun browser:   node tests/test_escape.mjs
import { esc, unesc, escapeDeep, sanificaDeep } from '../frontend/js/escape.js';

let falliti = 0, totale = 0;
const ok = (nome, cond, dettaglio = '') => {
  totale++;
  console.log((cond ? '  ok      ' : '  FALLITO ') + nome + (!cond && dettaglio ? '   -> ' + dettaglio : ''));
  if (!cond) falliti++;
};

console.log('esc / unesc');
ok('i 5 caratteri pericolosi diventano entita\'', esc(`<>&"'`) === '&lt;&gt;&amp;&quot;&#39;');
ok('un testo normale non cambia', esc('Cuffie Bluetooth 30 ore') === 'Cuffie Bluetooth 30 ore');
ok('accenti e simboli non cambiano', esc('caffè €5 – ok') === 'caffè €5 – ok');
ok('null e undefined diventano stringa vuota', esc(null) === '' && esc(undefined) === '');
ok('i numeri diventano stringhe', esc(42) === '42' && esc(0) === '0');
ok('unesc inverte esc', unesc(esc(`Tom & "Jerry" <b>'x'</b>`)) === `Tom & "Jerry" <b>'x'</b>`);
ok('unesc non decodifica due volte', unesc('&amp;lt;') === '&lt;');
ok('unesc lascia stare le entita\' che non conosce', unesc('&euro; &nbsp;') === '&euro; &nbsp;');

console.log('\nrobustezza su 100.000 stringhe casuali');
let a = 12345; const rnd = () => (a = (a * 1103515245 + 12345) & 0x7fffffff);
const alfabeto = ['a', 'B', '1', ' ', '&', '<', '>', '"', "'", ';', '#', 'amp', 'lt', '&amp;', '&#39;', '\\', '`', '${', 'é'];
let tondo = 0, residui = 0;
for (let i = 0; i < 100000; i++) {
  let s = ''; for (let k = rnd() % 12; k > 0; k--) s += alfabeto[rnd() % alfabeto.length];
  if (unesc(esc(s)) !== s) tondo++;
  if (/[<>"']/.test(esc(s))) residui++;
}
ok('unesc(esc(x)) == x per ogni stringa', tondo === 0, `${tondo} casi`);
ok('dopo esc() non resta MAI un < > " \'', residui === 0, `${residui} casi`);

console.log('\npayload XSS classici: dopo esc() non contengono piu\' nessun tag ne\' virgolette');
const payload = [
  '<script>alert(1)</script>', '<img src=x onerror="window.__xss=1">', '"><svg onload=alert(1)>',
  "' onmouseover='alert(1)", '<a href="javascript:alert(1)">x</a>', "x'); fetch('/api/utenti'); ('",
  '</textarea><script>1</script>', '<iframe srcdoc="<script>1</script>">',
];
for (const p of payload) {
  const e = esc(p);
  ok(`neutralizzato: ${p.slice(0, 40)}`, !/[<>"']/.test(e));
}

console.log('\nescapeDeep');
const risposta = {
  nome: '<b>x</b>', n: 5, vero: true, nullo: null, lista: ['a&b', 3, { annidato: '"q"' }],
  checkout_url: 'https://pay.example/c?a=1&b=2', url: 'https://x/?a=1&b=2',
};
const out = escapeDeep(risposta);
ok('escapa le stringhe a ogni livello', out.nome === '&lt;b&gt;x&lt;/b&gt;' && out.lista[0] === 'a&amp;b' && out.lista[2].annidato === '&quot;q&quot;');
ok('numeri, booleani e null restano uguali', out.n === 5 && out.vero === true && out.nullo === null && out.lista[1] === 3);
ok('le chiavi non cambiano', JSON.stringify(Object.keys(out)) === JSON.stringify(Object.keys(risposta)));
ok('checkout_url (URL del server) resta intatto', out.checkout_url === 'https://pay.example/c?a=1&b=2');
ok('un altro campo url invece si escapa', out.url === 'https://x/?a=1&amp;b=2');
ok('non modifica l\'oggetto originale', risposta.nome === '<b>x</b>');
ok('gestisce undefined/null/array vuoti', escapeDeep(undefined) === undefined && escapeDeep(null) === null && escapeDeep([]).length === 0);

console.log('\nsanificaDeep (idempotente: per il localStorage, che puo\' contenere testo vecchio o modificato a mano)');
const grezzo = { nome: '<img src=x onerror="window.__xss=1">Mario', cognome: "O'Brien & figli", n: 7, lista: ['<b>'] };
const sano = sanificaDeep(grezzo);
ok('un testo GREZZO diventa sicuro', !/[<>"']/.test(sano.nome + sano.cognome) && sano.nome.startsWith('&lt;img'));
ok('un testo GIA\' ESCAPATO resta identico (nessun doppio escape)', JSON.stringify(sanificaDeep(sano)) === JSON.stringify(sano));
ok('applicarlo 5 volte da lo stesso risultato', JSON.stringify(sanificaDeep(sanificaDeep(sanificaDeep(sanificaDeep(sanificaDeep(grezzo)))))) === JSON.stringify(sano));
ok('i tipi non stringa restano uguali', sano.n === 7 && sano.lista.length === 1);
let idem = 0;
for (let i = 0; i < 20000; i++) { let t = ''; for (let k = rnd() % 10; k > 0; k--) t += alfabeto[rnd() % alfabeto.length]; if (esc(unesc(esc(t))) !== esc(t)) idem++; }
ok('esc(unesc(esc(x))) == esc(x) su 20.000 stringhe casuali', idem === 0, `${idem} casi`);

console.log('\n' + (falliti === 0 ? `TUTTI I ${totale} TEST SUPERATI` : `${falliti} TEST FALLITI su ${totale}`));
process.exit(falliti === 0 ? 0 : 1);
