// Test standalone: banner del dettaglio di un gruppo terminato. Uso: node tests/banner_fine_test.mjs
import assert from 'node:assert/strict';
import { modelloBannerFine, bannerFineHtml } from '../frontend/js/banner-fine.js';

let fail = 0;
function t(nome, fn) {
  try { fn(); console.log('[OK]   ' + nome); } catch (e) { fail++; console.log('[FAIL] ' + nome + '\n       ' + e.message); }
}
const testi = (m) => m.azioni.map(a => a.testo);

t('concluso, non aderente: titolo, dettaglio con prezzo finale, un solo pulsante', () => {
  const m = modelloBannerFine({ display: 'conclusa', aderente: false, prezzoFinale: 28.99 });
  assert.equal(m.titolo, 'Questo gruppo è concluso');
  assert.equal(m.dettaglio, 'Il gruppo ha raggiunto il minimo. Prezzo finale €28,99.');
  assert.deepEqual(testi(m), ['Vedi i gruppi aperti →']); assert.equal(m.icona, 'spunta');
});
t('concluso, aderente: stato reale e due azioni (primaria "Vai ai miei acquisti")', () => {
  const casi = [['inviato', 'ordine in preparazione'], ['pronto', 'pronto per il ritiro'], ['ritirato', 'ritirato']];
  for (const [vista, stato] of casi) {
    const m = modelloBannerFine({ display: 'conclusa', aderente: true, quantita: 4, vista });
    assert.equal(m.dettaglio, `Hai aderito con 4 pezzi · ${stato}`);
    assert.deepEqual(testi(m), ['Vai ai miei acquisti', 'Vedi i gruppi aperti →']);
    assert.equal(m.azioni[0].primaria, true); assert.equal(m.azioni[1].primaria, false);
  }
});
t('concluso, aderente: 1 pezzo (singolare) e consegna a domicilio', () => {
  assert.equal(modelloBannerFine({ display: 'conclusa', aderente: true, quantita: 1, vista: 'inviato' }).dettaglio, 'Hai aderito con 1 pezzo · ordine in preparazione');
  assert.equal(modelloBannerFine({ display: 'conclusa', aderente: true, quantita: 2, vista: 'ritirato', spedizione: true }).dettaglio, 'Hai aderito con 2 pezzi · consegnato');
});
t('concluso, aderente senza dati reali: ripiega su "ordine in preparazione"', () => {
  assert.equal(modelloBannerFine({ display: 'in_chiusura', aderente: true, quantita: 3 }).dettaglio, 'Hai aderito con 3 pezzi · ordine in preparazione');
});
t('non attivato, non aderente', () => {
  const m = modelloBannerFine({ display: 'non_riuscita', aderente: false });
  assert.equal(m.titolo, 'Questo gruppo non ha raggiunto il minimo');
  assert.equal(m.dettaglio, 'Gli acconti sono stati rimborsati.'); assert.equal(m.icona, 'info');
  assert.deepEqual(testi(m), ['Vedi i gruppi aperti →']);
});
t('non attivato, aderente: importo senza data (nessuna data di rimborso salvata)', () => {
  const m = modelloBannerFine({ display: 'non_riuscita', aderente: true, acconto: 15.8 });
  assert.equal(m.dettaglio, 'Il tuo acconto di €15,80 è stato rimborsato.');
  assert.ok(!/\d{2}\/\d{2}\/\d{4}/.test(m.dettaglio));
  assert.deepEqual(testi(m), ['Vai ai miei acquisti', 'Vedi i gruppi aperti →']);
  assert.equal(modelloBannerFine({ display: 'non_riuscita', aderente: true, acconto: 0 }).dettaglio, 'Il tuo acconto è stato rimborsato.');
});
t('HTML: una riga con icona aria-hidden, role status, testo escapato, link validi', () => {
  const h = bannerFineHtml({ icona: 'spunta', titolo: 'Titolo <b>x</b>', dettaglio: 'a & b', azioni: [{ testo: 'Vai', href: '#/acquisti?tab=in-arrivo', primaria: true }] });
  assert.ok(h.includes('role="status"') && h.includes('aria-labelledby="bp-banner-fine-titolo"'));
  assert.ok(h.includes('aria-hidden="true"') && h.includes('is-primaria'));
  assert.ok(!h.includes('<b>x</b>') && h.includes('a &amp; b'));
  assert.ok(h.includes('href="#/acquisti?tab=in-arrivo"'));
});
t('mai le parole "campagna" o "prenotazione" nei testi del banner', () => {
  for (const display of ['conclusa', 'non_riuscita']) for (const aderente of [true, false]) {
    const m = modelloBannerFine({ display, aderente, quantita: 2, vista: 'inviato', acconto: 5, prezzoFinale: 10 });
    assert.ok(!/campagna|prenotazione/i.test(JSON.stringify(m)));
  }
});

if (fail) { console.log(`\n${fail} test falliti`); process.exit(1); }
console.log('\nTutti i test passati');
