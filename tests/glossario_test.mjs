// Test standalone: nessun testo visibile con "campagna" nei punti riscritti. Uso: node tests/glossario_test.mjs
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const radice = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
function elenca(dir, ext, out = []) {
  for (const f of readdirSync(dir)) {
    if (['vendor', 'PHPMailer', 'node_modules', '.git'].includes(f)) continue;
    const p = join(dir, f);
    if (statSync(p).isDirectory()) elenca(p, ext, out); else if (ext.some(e => p.endsWith(e))) out.push(p);
  }
  return out;
}

// Frasi del glossario che non devono più comparire come testo visibile
const VIETATE = [
  'Prenota ora', 'Campagna attiva', 'Campagne attive', 'Torna alle campagne', 'Campagna scaduta', 'Campagna non trovata',
  'pezzi prenotati', 'Prenotato il', 'attivare la campagna', 'La campagna si attiva', 'Dettaglio campagna', 'Campagna non riuscita',
  'Vedi campagna', 'Gruppo concluso</span>'
];
const file = [...elenca(join(radice, 'frontend/js'), ['.js']), ...elenca(join(radice, 'api'), ['.php'])]
  .filter(p => !p.endsWith('mock.js'));

let fail = 0;
for (const p of file) {
  const testo = readFileSync(p, 'utf8');
  for (const v of VIETATE) if (testo.includes(v)) { fail++; console.log(`[FAIL] "${v}" in ${relative(radice, p)}`); }
}
if (fail) process.exit(1);
console.log(`[OK]   glossario: nessuna frase vietata in ${file.length} file`);
