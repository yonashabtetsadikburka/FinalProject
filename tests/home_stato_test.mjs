// Test standalone: stato unico della card gruppo, filtro "Conclusi", whitelist ?stato=.
// Uso: node tests/home_stato_test.mjs
import assert from 'node:assert/strict';
import { statoCard, isTerminato, normalizzaStato, dataFine, displayCampagna, FILTRI_STATO } from '../frontend/js/stato-card.js';

let fail = 0;
function t(nome, fn) {
  try { fn(); console.log('[OK]   ' + nome); } catch (e) { fail++; console.log('[FAIL] ' + nome + '\n       ' + e.message); }
}

const futuro = new Date(Date.now() + 3 * 86400000).toISOString();
const passato = new Date(Date.now() - 2 * 86400000).toISOString();
const base = { stato: 'in_corso', stato_campagna: 'in_corso', data_limite: futuro, quantita_minima: 10, quantita_attuale: 3, scaglioni: [{ soglia: 10, prezzo: 50 }] };

t('Aperto: minimo non raggiunto → chip "Aperto", Unisciti al gruppo', () => {
  const s = statoCard(base);
  assert.equal(s.id, 'aperto'); assert.equal(s.chip, 'Aperto'); assert.equal(s.cta, 'unisciti'); assert.equal(s.terminato, false);
});
t('Gruppo attivo: minimo raggiunto, ancora aperto', () => {
  const s = statoCard({ ...base, quantita_attuale: 12 });
  assert.equal(s.id, 'attivo'); assert.equal(s.chip, 'Gruppo attivo ✓'); assert.equal(s.cta, 'unisciti');
});
t('Hai aderito ha la precedenza su Aperto e Gruppo attivo', () => {
  for (const q of [3, 12]) {
    const s = statoCard({ ...base, quantita_attuale: q, mia_partecipazione: { quantita: 4, stato: 'prenotata' } });
    assert.equal(s.id, 'aderito'); assert.equal(s.chip, 'Hai aderito · 4 pezzi'); assert.equal(s.cta, 'vedi_acquisto');
  }
  assert.equal(statoCard({ ...base, mia_partecipazione: { quantita: 1 } }).chip, 'Hai aderito · 1 pezzo');
});
t('Concluso (conclusa e in_chiusura) → nessun "Unisciti"', () => {
  for (const d of ['conclusa', 'in_chiusura']) {
    const s = statoCard({ ...base, stato_campagna: d, data_limite: passato, quantita_attuale: 12 });
    assert.equal(s.id, 'concluso'); assert.equal(s.chip, 'Concluso'); assert.equal(s.cta, 'vedi_gruppo'); assert.equal(s.terminato, true);
  }
});
t('Non attivato (non_riuscita) → "Vedi il gruppo"', () => {
  const s = statoCard({ ...base, stato_campagna: 'non_riuscita', data_limite: passato });
  assert.equal(s.id, 'non_attivato'); assert.equal(s.chip, 'Non attivato'); assert.equal(s.cta, 'vedi_gruppo');
});
t('Un gruppo terminato ignora mia_partecipazione (niente "Hai aderito")', () => {
  const s = statoCard({ ...base, stato_campagna: 'conclusa', mia_partecipazione: { quantita: 2 } });
  assert.equal(s.id, 'concluso');
});
t('Fallback senza stato_campagna dal backend', () => {
  assert.equal(displayCampagna({ ...base, stato_campagna: undefined }), 'in_corso');
  assert.equal(displayCampagna({ ...base, stato_campagna: undefined, data_limite: passato }), 'non_riuscita');
  assert.equal(displayCampagna({ ...base, stato_campagna: undefined, data_limite: passato, quantita_attuale: 12 }), 'in_chiusura');
});
t('isTerminato separa aperti e conclusi', () => {
  assert.equal(isTerminato(base), false);
  assert.equal(isTerminato({ ...base, stato_campagna: 'non_riuscita' }), true);
});
t('?stato= in whitelist; "concluse" è alias di "conclusi"; valori sconosciuti → tutti', () => {
  assert.deepEqual(FILTRI_STATO, ['tutti', 'quasi', 'scadenza', 'attive', 'conclusi']);
  assert.equal(normalizzaStato('conclusi'), 'conclusi');
  assert.equal(normalizzaStato('concluse'), 'conclusi');
  assert.equal(normalizzaStato('<script>'), 'tutti');
  assert.equal(normalizzaStato('constructor'), 'tutti');
});
t('Conclusi ordinati dal più recente', () => {
  const a = { chiusura_data: '2026-10-01 10:00:00', data_limite: '2026-09-30 10:00:00' };
  const b = { chiusura_data: null, data_limite: '2026-10-05 10:00:00' };
  assert.deepEqual([a, b].sort((x, y) => dataFine(y) - dataFine(x)), [b, a]);
});

if (fail) { console.log(`\n${fail} test falliti`); process.exit(1); }
console.log('\nTutti i test passati');
