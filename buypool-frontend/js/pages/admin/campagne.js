import { apiGet } from '../../api.js';
import { Table } from '../../components/table.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../../constants.js';

export async function AdminCampagnePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';

  let campaigns = [];
  try {
    const res = await apiGet('/collette');
    campaigns = res.dati || [];
  } catch (e) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p></div>`;
    return;
  }

  const rows = campaigns.map(c => {
    const percentage = Number(c.quantita_minima) > 0
      ? Math.round((Number(c.quantita_attuale) / Number(c.quantita_minima)) * 100)
      : 0;
    return {
      nome: c.prodotto?.nome || 'N/A',
      stato: `<span class="badge ${STATI_CAMPAGNA_BADGES[c.stato] || 'badge-secondary'}">${STATI_CAMPAGNA_LABELS[c.stato] || c.stato}</span>`,
      partecipanti: `${c.quantita_attuale} / ${c.quantita_minima}`,
      avanzamento: `<div style="display:flex;align-items:center;gap:8px;"><div class="progress-container" style="width:80px;"><div class="progress-bar" style="width:${percentage}%"></div></div><span class="text-xs">${percentage}%</span></div>`,
      scadenza: new Date(c.data_limite).toLocaleDateString('it-IT'),
      azioni: `<div class="admin-table-actions">
        <button class="btn btn-outline btn-sm" onclick="alert('Modifica in arrivo!')">Modifica</button>
        <button class="btn btn-ghost btn-sm" style="color:var(--color-error);" onclick="if(confirm('Eliminare?')) alert('Eliminato!')">Elimina</button>
      </div>`
    };
  });

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Gestione Campagne</h1>
        <button class="btn btn-default" onclick="alert('Nuova campagna in arrivo!')">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Nuova campagna
        </button>
      </div>
      <div class="card">
        <div class="card-content">
          ${Table({
            columns: [
              { key: 'nome', label: 'Prodotto' },
              { key: 'stato', label: 'Stato' },
              { key: 'partecipanti', label: 'Partecipanti' },
              { key: 'avanzamento', label: 'Avanzamento' },
              { key: 'scadenza', label: 'Scadenza' },
              { key: 'azioni', label: 'Azioni' }
            ],
            rows
          })}
        </div>
      </div>
    </div>
  `;
}
