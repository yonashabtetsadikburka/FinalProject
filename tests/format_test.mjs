// Test standalone degli helper di formattazione. Uso: node tests/format_test.mjs (nessuna dipendenza)
import { euro, plurale, pezzi, voti, gruppi, ritiri, recensioni, persone, scadenzaTesto, scadenzaDaSecondi, dataRelativa, dataGGMMAAAA, dataGGMM, oraHHMM, motivoRimborso } from '../frontend/js/format.js';

let fail = 0;
function verifica(nome, ok, extra = '') {
  console.log(`${ok ? '[OK]  ' : '[FAIL]'} ${nome}${ok || !extra ? '' : `  (${extra})`}`);
  if (!ok) fail++;
}
const uguale = (nome, ottenuto, atteso) => verifica(nome, ottenuto === atteso, `ottenuto "${ottenuto}", atteso "${atteso}"`);

uguale('importo con migliaia', euro(3659.58), '€3.659,58');
uguale('importo piccolo', euro(5), '€5,00');
uguale('importo zero', euro(0), '€0,00');
uguale('importo milioni', euro(1234567.5), '€1.234.567,50');
uguale('importo 999,99 senza migliaia', euro(999.99), '€999,99');
uguale('importo non numerico', euro('abc'), '€0,00');
uguale('importo stringa numerica', euro('12.3'), '€12,30');
uguale('importo con errore binario', euro(0.1 + 0.2), '€0,30');

uguale('pezzo singolare', pezzi(1), '1 pezzo');
uguale('pezzi plurale', pezzi(3), '3 pezzi');
uguale('0 voti', voti(0), '0 voti');
uguale('1 voto', voti(1), '1 voto');
uguale('1 gruppo', gruppi(1), '1 gruppo');
uguale('2 gruppi', gruppi(2), '2 gruppi');
uguale('1 ritiro', ritiri(1), '1 ritiro');
uguale('3 ritiri', ritiri(3), '3 ritiri');
uguale('plurale generico', plurale(2, 'cane', 'cani'), '2 cani');

// 2 novembre: nessun cambio d'ora legale tra 'ora' e le scadenze di prova
// Plurali condivisi (recensioni, persone, gruppi, pezzi) e prezzi in formato italiano
uguale('1 recensione', recensioni(1), '1 recensione');
uguale('2 recensioni', recensioni(2), '2 recensioni');
uguale('0 recensioni', recensioni(0), '0 recensioni');
uguale('1 persona', persone(1), '1 persona');
uguale('3 persone', persone(3), '3 persone');
uguale('1 gruppo / 2 gruppi', [gruppi(1), gruppi(2)].join(' | '), '1 gruppo | 2 gruppi');
uguale('1 pezzo / 4 pezzi', [pezzi(1), pezzi(4)].join(' | '), '1 pezzo | 4 pezzi');
uguale('prezzo con virgola: €16,99', euro(16.99), '€16,99');
uguale('listino €19,99', euro(19.99), '€19,99');
uguale('prezzo in stringa dal server', euro('16.99'), '€16,99');

const ora = new Date('2026-11-02T10:00:00').getTime();
uguale('scadenza giorni e ore', scadenzaTesto('2026-11-20 22:00:00', ora), 'Scade tra 18g 12h');
uguale('scadenza sotto il giorno', scadenzaTesto('2026-11-02 15:20:00', ora), 'Scade tra 5h 20m');
uguale('scadenza fra pochi minuti', scadenzaTesto('2026-11-02 10:05:00', ora), 'Scade tra 0h 5m');
uguale('scaduto', scadenzaTesto('2026-11-01 10:00:00', ora), 'Scaduto');
uguale('data non valida', scadenzaTesto('boh', ora), '');

uguale('oggi', dataRelativa('2026-11-02 08:00:00', ora), 'oggi');
uguale('ieri (anche a ore di distanza minime)', dataRelativa('2026-11-01 23:59:00', ora), 'ieri');
uguale('2 giorni fa', dataRelativa('2026-10-31 12:00:00', ora), '2 giorni fa');
uguale('oltre una settimana: data', dataRelativa('2026-10-10 12:00:00', ora), new Date('2026-10-10T12:00:00').toLocaleDateString('it-IT'));

uguale('secondi -> giorni e ore', scadenzaDaSecondi(6 * 86400 + 20 * 3600 + 120), 'Scade tra 6g 20h');
uguale('secondi -> ore e minuti', scadenzaDaSecondi(5 * 3600 + 20 * 60 + 30), 'Scade tra 5h 20m');
uguale('secondi: meno di un minuto', scadenzaDaSecondi(30), 'Scade a momenti');
uguale('secondi: scaduto', scadenzaDaSecondi(0), 'Scaduto');
uguale('secondi: non valido', scadenzaDaSecondi(null), 'Scaduto');
uguale('data GG/MM/AAAA', dataGGMMAAAA('2026-10-12 09:30:00'), '12/10/2026');
uguale('data GG/MM', dataGGMM('2026-10-02 09:30:00'), '02/10');
uguale('ora HH:MM', oraHHMM('2026-10-12 09:05:00'), '09:05');
uguale('data non valida -> vuoto', dataGGMMAAAA('boh'), '');

uguale('motivo rimborso (esempio del brief)', motivoRimborso({ scadenza: '2026-10-12 00:00:00', pezzi: 3, minimo: 6 }),
  'Il gruppo non ha raggiunto il minimo entro la scadenza (12/10/2026): 3 pezzi su 6 richiesti.');
uguale('motivo rimborso: singolare', motivoRimborso({ scadenza: '2026-10-12 00:00:00', pezzi: 1, minimo: 6 }),
  'Il gruppo non ha raggiunto il minimo entro la scadenza (12/10/2026): 1 pezzo su 6 richiesti.');
uguale('motivo rimborso: minimo raggiunto -> messaggio generico', motivoRimborso({ scadenza: '2026-10-12 00:00:00', pezzi: 8, minimo: 6 }),
  "Il gruppo non è andato a buon fine: ti restituiamo l'acconto.");
uguale('motivo rimborso: senza dati', motivoRimborso(null), '');

console.log(fail === 0 ? '\nTutti i test superati' : `\n${fail} test FALLITI`);
process.exit(fail === 0 ? 0 : 1);
