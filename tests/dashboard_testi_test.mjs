// Test standalone: frase di riepilogo della Dashboard e modello del blocco "Da fare".
// Uso: node tests/dashboard_testi_test.mjs
import assert from 'node:assert/strict';
import { fraseRiepilogo, modelloDaFare, sedeTesto, LINK_RITIRI, LINK_GRUPPI } from '../frontend/js/dashboard-testi.js';

let fail = 0;
function t(nome, fn) {
  try { fn(); console.log('[OK]   ' + nome); } catch (e) { fail++; console.log('[FAIL] ' + nome + '\n       ' + e.message); }
}
const testo = (h) => h.replace(/<[^>]+>/g, '');

t('2 ritiri e 2 gruppi', () => assert.equal(testo(fraseRiepilogo(2, 2)), 'Hai 2 ritiri pronti e partecipi a 2 gruppi in corso.'));
t('3 ritiri e 1 gruppo (plurali misti)', () => assert.equal(testo(fraseRiepilogo(3, 1)), 'Hai 3 ritiri pronti e partecipi a 1 gruppo in corso.'));
t('1 ritiro e 2 gruppi', () => assert.equal(testo(fraseRiepilogo(1, 2)), 'Hai 1 ritiro pronto e partecipi a 2 gruppi in corso.'));
t('solo ritiri', () => {
  assert.equal(testo(fraseRiepilogo(3, 0)), 'Hai 3 ritiri pronti.');
  assert.equal(testo(fraseRiepilogo(1, 0)), 'Hai 1 ritiro pronto.');
});
t('solo gruppi', () => {
  assert.equal(testo(fraseRiepilogo(0, 2)), 'Partecipi a 2 gruppi in corso.');
  assert.equal(testo(fraseRiepilogo(0, 1)), 'Partecipi a 1 gruppo in corso.');
});
t('nessuno dei due: unico caso con il messaggio di scoperta', () => {
  assert.equal(testo(fraseRiepilogo(0, 0)), 'Non hai gruppi in corso. Scopri i gruppi a cui puoi unirti.');
  for (const [r, g] of [[1, 0], [0, 1], [2, 2]]) assert.ok(!fraseRiepilogo(r, g).includes('Scopri'));
});
t('numeri non validi → trattati come 0', () => assert.equal(testo(fraseRiepilogo(undefined, 'x')), 'Non hai gruppi in corso. Scopri i gruppi a cui puoi unirti.'));
t('i numeri sono link: ritiri → In arrivo, gruppi → In corso', () => {
  const h = fraseRiepilogo(2, 3);
  assert.ok(h.includes(`href="${LINK_RITIRI}"><strong>2 ritiri pronti`));
  assert.ok(h.includes(`href="${LINK_GRUPPI}"><strong>3 gruppi in corso`));
  assert.equal(LINK_RITIRI, '#/acquisti?tab=in-arrivo');
  assert.equal(LINK_GRUPPI, '#/acquisti?tab=in-corso');
});

t('"Da fare" vuoto → vuoto, nessuna sede', () => {
  const m = modelloDaFare({ ritiri: [], saldi_non_riusciti: [], totale: 0 });
  assert.equal(m.vuoto, true); assert.deepEqual(m.sedi, []);
  assert.equal(modelloDaFare(null).vuoto, true);
});
t('"Da fare": ritiri con sede reale, una sola riga sede per sede distinta', () => {
  const sede = { sede_nome: 'Sede Centrale Perugia', sede_indirizzo: 'Via Assisana 10', sede_citta: 'Perugia' };
  const m = modelloDaFare({ ritiri: [{ id: 1, prodotto: 'A', quantita: 2, ...sede }, { id: 2, prodotto: 'B', quantita: 1, ...sede }], saldi_non_riusciti: [] });
  assert.equal(m.righe.length, 2); assert.deepEqual(m.sedi, ['Sede Centrale Perugia: Via Assisana 10, Perugia']);
});
t('"Da fare": i saldi non riusciti vengono prima dei ritiri e portano la scadenza', () => {
  const m = modelloDaFare({
    ritiri: [{ id: 1, prodotto: 'A', quantita: 1, sede_nome: 'S' }],
    saldi_non_riusciti: [{ id: 9, prodotto: 'B', quantita: 3, saldo_scadenza: '2026-10-23 14:30:00' }]
  });
  assert.deepEqual(m.righe.map(r => r.tipo), ['saldo', 'ritiro']);
  assert.equal(m.righe[0].scadenza, '2026-10-23 14:30:00');
});
t('"Da fare": solo saldo non riuscito → nessuna riga sede', () => {
  const m = modelloDaFare({ ritiri: [], saldi_non_riusciti: [{ id: 9, prodotto: 'B', quantita: 3 }] });
  assert.deepEqual(m.sedi, []); assert.equal(m.vuoto, false);
});
t('sede senza dati → stringa vuota', () => assert.equal(sedeTesto({}), ''));

if (fail) { console.log(`\n${fail} test falliti`); process.exit(1); }
console.log('\nTutti i test passati');
