// Test standalone: etichetta di tipo delle notifiche in dashboard. Uso: node tests/notifiche_tipo_test.mjs
import assert from 'node:assert/strict';
import { etichettaNotifica } from '../frontend/js/notifiche-tipo.js';

const casi = [
  [{ tipo: 'PAGAMENTO_RIUSCITO' }, 'PAGAMENTO'], [{ tipo: 'ORDINE_CONFERMATO' }, 'PAGAMENTO'], [{ tipo: 'PAGAMENTO_FALLITO' }, 'PAGAMENTO'],
  [{ tipo: 'RIMBORSO' }, 'RIMBORSO'],
  [{ tipo: 'NUOVO_SCAGLIONE' }, 'GRUPPO'], [{ tipo: 'MOQ_RAGGIUNTO' }, 'GRUPPO'],
  [{ tipo: 'MERCE_PRONTA' }, 'RITIRO'], [{ tipo: 'RITIRATO' }, 'RITIRO'],
  [{ tipo: 'SISTEMA', tipo_riferimento: 'proposta' }, 'PROPOSTA'],
  [{ tipo: 'SISTEMA', tipo_riferimento: 'colletta' }, 'NOTIFICA'], [null, 'NOTIFICA']
];
for (const [n, atteso] of casi) assert.equal(etichettaNotifica(n).testo, atteso, JSON.stringify(n));
assert.equal(etichettaNotifica({ tipo: 'PAGAMENTO_RIUSCITO' }).classe, 'dsh-tipo-pagamento');
assert.equal(etichettaNotifica({ tipo: 'RIMBORSO' }).classe, 'dsh-tipo-rimborso');
assert.equal(etichettaNotifica({ tipo: 'RITIRATO' }).classe, 'dsh-tipo-ritiro');
console.log('[OK]   etichette notifiche');
