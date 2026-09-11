import { apiGet } from '../../api.js';
import { Table } from '../../components/table.js';

export async function AdminRitiriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';

  let prenotazioni = [];
  try {
    const res = await apiGet('/prenotazioni');
    prenotazioni = res.dati || [];
  } catch (e) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p></div>`;
    return;
  }

  const confermate = prenotazioni.filter(p => p.stato === 'confermata');

  const rows = confermate.map(p => {
    const nomeUtente = p.utente ? `${p.utente.nome || ''} ${p.utente.cognome || ''}` : 'N/A';
    const prodotto = p.colletta?.prodotto?.nome || 'N/A';
    const qr = p.qr;
    const QR_CODE_STATO = qr ? (qr.stato === 'scansionato' ? 'Ritirato' : 'In attesa') : 'Non generato';
    const qrToken = qr ? qr.token : '';
    return {
      utente: nomeUtente,
      prodotto,
      quantita: `${p.quantita} pezzi`,
      stato: `<span class="badge ${qr && qr.stato === 'scansionato' ? 'badge-success' : 'badge-warning'}">${QR_CODE_STATO}</span>`,
      azioni: `<button class="btn btn-default btn-sm" onclick="alert('Usa lo Scanner QR nella pagina Ordini per confermare il ritiro del token:\\n\\n${qrToken || 'QR non ancora generato'}')">Conferma</button>`
    };
  });

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Gestione Ritiri</h1>
      </div>
      <div class="card">
        <div class="card-content">
          ${Table({
            columns: [
              { key: 'utente', label: 'Utente' },
              { key: 'prodotto', label: 'Prodotto' },
              { key: 'quantita', label: 'Quantita' },
              { key: 'stato', label: 'Stato' },
              { key: 'azioni', label: 'Azioni' }
            ],
            rows
          })}
        </div>
      </div>
    </div>
  `;
}
