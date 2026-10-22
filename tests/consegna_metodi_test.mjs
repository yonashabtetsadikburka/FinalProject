// Test standalone: modalita di consegna offerte nella finestra di adesione. Uso: node tests/consegna_metodi_test.mjs
import assert from 'node:assert/strict';
import { metodiConsegna, NON_DISPONIBILE } from '../frontend/js/consegna-metodi.js';

let fail = 0;
function t(nome, fn) {
  try { fn(); console.log('[OK]   ' + nome); } catch (e) { fail++; console.log('[FAIL] ' + nome + '\n       ' + e.message); }
}
const attive = (m) => m.schede.filter(s => s.attiva).map(s => s.id);

t('entrambe disponibili: ritiro preselezionato, nessun messaggio, cambio sempre possibile', () => {
  const m = metodiConsegna({ sedeId: 1 });
  assert.equal(m.selezionata, 'ritiro_sede'); assert.equal(m.messaggio, '');
  assert.deepEqual(attive(m), ['ritiro_sede', 'consegna_domicilio']);
});
t('solo ritiro: spedizione visibile ma disattivata con la spiegazione, ritiro selezionato', () => {
  const m = metodiConsegna({ sedeId: 1, spedizioneDisponibile: false });
  assert.equal(m.selezionata, 'ritiro_sede');
  assert.equal(m.messaggio, 'Per questo prodotto è disponibile solo il ritiro in sede');
  const sped = m.schede.find(s => s.id === 'consegna_domicilio');
  assert.equal(sped.attiva, false); assert.equal(sped.motivo, NON_DISPONIBILE);
  assert.equal(NON_DISPONIBILE, 'Non disponibile per questo prodotto');
});
t('solo spedizione: ritiro disattivato, spedizione selezionata', () => {
  const m = metodiConsegna({ sedeId: 1, ritiroDisponibile: false });
  assert.equal(m.selezionata, 'consegna_domicilio');
  assert.equal(m.messaggio, 'Per questo prodotto è disponibile solo la spedizione a domicilio');
  assert.equal(m.schede.find(s => s.id === 'ritiro_sede').motivo, NON_DISPONIBILE);
});
t('nessuna modalita: nessuna selezione, messaggio chiaro, entrambe disattivate', () => {
  const m = metodiConsegna({ sedeId: 1, ritiroDisponibile: false, spedizioneDisponibile: false });
  assert.equal(m.selezionata, null); assert.deepEqual(attive(m), []);
  assert.ok(m.messaggio.includes('nessuna modalità'));
});
t('senza sede il ritiro non e offerto (il server fornisce sempre la sede predefinita)', () => {
  const m = metodiConsegna({ sedeId: 0 });
  assert.equal(m.selezionata, 'consegna_domicilio'); assert.equal(m.schede[0].attiva, false);
});
t('le due schede ci sono SEMPRE, in ordine fisso', () => {
  for (const o of [{ sedeId: 1 }, { sedeId: 0 }, { sedeId: 1, ritiroDisponibile: false, spedizioneDisponibile: false }]) {
    assert.deepEqual(metodiConsegna(o).schede.map(s => s.id), ['ritiro_sede', 'consegna_domicilio']);
  }
});

if (fail) { console.log(`\n${fail} test falliti`); process.exit(1); }
console.log('\nTutti i test passati');
