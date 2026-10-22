// Test della mappatura stati -> percorso a 6 passi. Uso: node tests/stato_percorso_test.mjs
import { percorsoOrdine } from '../frontend/js/stato-ordine.js';

let fail = 0;
function verifica(nome, p, atteso) {
  const r = percorsoOrdine(p);
  const ok = Object.entries(atteso).every(([k, v]) => r[k] === v);
  console.log(`${ok ? '[OK]  ' : '[FAIL]'} ${nome}${ok ? '' : `  ottenuto ${JSON.stringify(r)}`}`);
  if (!ok) fail++;
}

verifica('prenotata, gruppo aperto -> Gruppo in corso (passo 3)',
  { stato: 'prenotata', stato_colletta: 'in_corso' }, { etichetta: 'Gruppo in corso', passo: 3, tono: 'gruppo' });
verifica('confermata (minimo raggiunto, saldo da addebitare) -> Gruppo in corso',
  { stato: 'confermata', stato_colletta: 'riuscita' }, { etichetta: 'Gruppo in corso', passo: 3 });
verifica('azione_richiesta -> Pagamento non riuscito (passo 4)',
  { stato: 'azione_richiesta', stato_colletta: 'ordine_pronto' }, { etichetta: 'Pagamento non riuscito', passo: 4, tono: 'ambra' });
verifica('pagata, spedizione, colletta in avanzamento -> inviato al fornitore (passo 5)',
  { stato: 'pagata', stato_colletta: 'ordine_fornitore', consegna_modalita: 'consegna_domicilio' },
  { etichetta: 'Ordine inviato al fornitore', passo: 5, tono: 'blu' });
verifica('pagata, spedizione, nessuna consegna ancora -> inviato al fornitore (saldo pagato)',
  { stato: 'pagata', stato_colletta: 'ordine_pronto', consegna_modalita: 'consegna_domicilio' },
  { etichetta: 'Ordine inviato al fornitore', passo: 5 });
verifica('pagata + ritiro in sede, QR generato -> Pronto per il ritiro (passo 6, ambra)',
  { stato: 'pagata', stato_colletta: 'consegnata', consegna_modalita: 'ritiro_sede', stato_qr: 'generato' },
  { etichetta: 'Pronto per il ritiro', passo: 6, tono: 'ambra', concluso: false });
verifica('pagata + ritiro + QR generato ma colletta ordine_fornitore -> inviato (non ancora pronto)',
  { stato: 'pagata', stato_colletta: 'ordine_fornitore', consegna_modalita: 'ritiro_sede', stato_qr: 'generato' },
  { etichetta: 'Ordine inviato al fornitore', passo: 5, tono: 'blu' });
verifica('QR scansionato -> Ritirato (passo 6, verde, concluso)',
  { stato: 'pagata', consegna_modalita: 'ritiro_sede', stato_qr: 'scansionato' },
  { etichetta: 'Ritirato', passo: 6, tono: 'verde', concluso: true });
verifica('spedizione consegnata -> Consegnato',
  { stato: 'pagata', consegna_modalita: 'consegna_domicilio', consegna_stato: 'consegnata' },
  { etichetta: 'Consegnato', passo: 6, concluso: true });
verifica('rimborsata -> Gruppo non riuscito (nessun passo)',
  { stato: 'rimborsata', stato_colletta: 'fallita' }, { etichetta: 'Gruppo non riuscito', passo: 0, tono: 'grigio' });
verifica('annullata -> Gruppo non riuscito',
  { stato: 'annullata' }, { etichetta: 'Gruppo non riuscito', passo: 0 });
verifica('colletta fallita con prenotazione ancora prenotata -> Gruppo non riuscito',
  { stato: 'prenotata', stato_colletta: 'fallita' }, { etichetta: 'Gruppo non riuscito', passo: 0 });

console.log(fail === 0 ? '\nTutti i test superati' : `\n${fail} test FALLITI`);
process.exit(fail === 0 ? 0 : 1);
