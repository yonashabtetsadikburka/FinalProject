// Test standalone: stima di acconto/saldo nella finestra di adesione, riga prezzi "Listino" delle card.
// Uso: node tests/saldo_stima_test.mjs
import assert from 'node:assert/strict';
import { accontoStima, saldoStima } from '../frontend/js/saldo-stima.js';
import { euro } from '../frontend/js/format.js';

globalThis.window = { location: { hash: '#/' }, addEventListener() {} };
globalThis.document = { getElementById: () => null, querySelectorAll: () => [], addEventListener() {} };
globalThis.history = {};
const { scalaPrezziHtml, scalaPrezziAria } = await import('../frontend/js/pages/home.js');

let fail = 0;
function t(nome, fn) {
  try { fn(); console.log('[OK]   ' + nome); } catch (e) { fail++; console.log('[FAIL] ' + nome + '\n       ' + e.message); }
}

t('acconto: 1 paio di cuffie, 20% di 30,99 = 6,20 (formato italiano €6,20)', () => {
  assert.equal(accontoStima(1, 30.99, 20), 6.2);
  assert.equal(euro(accontoStima(1, 30.99, 20)), '€6,20');
});
t('acconto: arrotondamento sul totale (3 x 29,99 x 20% = 17,99)', () => assert.equal(accontoStima(3, 29.99, 20), 17.99));
t('saldo come il server: 3 x 28,99, comm 10%, acconto 17,99 → 77,68', () => {
  const s = saldoStima({ quantita: 3, prezzo: 28.99, commissionePct: 10, spedizione: 0, acconto: 17.99 });
  assert.deepEqual([s.merce, s.commissione, s.totale, s.saldo], [86.97, 8.7, 95.67, 77.68]);
});
t('saldo con spedizione 4,90 → 82,58', () => {
  const s = saldoStima({ quantita: 3, prezzo: 28.99, commissionePct: 10, spedizione: 4.9, acconto: 17.99 });
  assert.equal(s.saldo, 82.58); assert.equal(s.spedizione, 4.9);
});
t('spedizione negativa ignorata', () => assert.equal(saldoStima({ quantita: 1, prezzo: 10, commissionePct: 0, spedizione: -3 }).spedizione, 0));

const scaglioni = [{ soglia: 10, prezzo: 30.99 }, { soglia: 15, prezzo: 28.99 }];
const gruppo = { prezzo_base: 49.9, prezzo_corrente: 30.99, quantita_attuale: 3, scaglioni };

t('card: prima casella "Listino" con prezzo barrato, mai "Oggi"', () => {
  const h = scalaPrezziHtml(gruppo);
  assert.ok(h.includes('Listino') && h.includes('<s>€49,90</s>'));
  assert.ok(!h.includes('Oggi'));
});
t('card: scaglioni in formato italiano e prossimo traguardo evidenziato', () => {
  const h = scalaPrezziHtml(gruppo);
  assert.ok(h.includes('10 pezzi') && h.includes('€30,99') && h.includes('15 pezzi') && h.includes('€28,99'));
  assert.equal((h.match(/bp-step-next/g) || []).length, 1);
  assert.ok(/bp-step-next"><span class="bp-step-label">10 pezzi/.test(h));
});
t('card: senza listino la casella manca (nessun valore inventato)', () => {
  const h = scalaPrezziHtml({ ...gruppo, prezzo_base: 0 });
  assert.ok(!h.includes('Listino') && !h.includes('Oggi'));
  assert.ok(!h.startsWith('<span class="bp-step-arrow"'));
});
t('card: aria-label con listino e scaglioni', () => {
  assert.equal(scalaPrezziAria(gruppo), 'prezzo di listino €49,90, 10 pezzi €30,99, 15 pezzi €28,99');
  assert.equal(scalaPrezziAria({ ...gruppo, prezzo_base: 0 }), '10 pezzi €30,99, 15 pezzi €28,99');
});

if (fail) { console.log(`\n${fail} test falliti`); process.exit(1); }
console.log('\nTutti i test passati');
