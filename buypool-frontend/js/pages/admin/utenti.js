import { apiGet } from '../../api.js';
import { Table } from '../../components/table.js';
import { RUOLI_LABELS, RUOLI_BADGES } from '../../constants.js';

export async function AdminUtentiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';

  let utenti = [];
  try {
    const res = await apiGet('/utenti');
    utenti = res.dati || [];
  } catch (e) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p></div>`;
    return;
  }

  const isAttivo = (u) => u.stato === 'attivo' && u.attivo !== false;

  const rows = utenti.map(u => ({
    nome: `${u.nome} ${u.cognome}`,
    email: u.email,
    ruolo: `<span class="badge ${RUOLI_BADGES[u.ruolo] || 'badge-secondary'}">${RUOLI_LABELS[u.ruolo] || u.ruolo}</span>`,
    stato: `<span class="badge ${isAttivo(u) ? 'badge-success' : 'badge-destructive'}">${isAttivo(u) ? 'Attivo' : 'Sospeso'}</span>`,
    data_iscrizione: new Date(u.data_iscrizione).toLocaleDateString('it-IT')
  }));

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Gestione Utenti</h1>
        <div class="search-field">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="text" class="input" placeholder="Cerca utenti..." oninput="filterUtenti(this.value)">
        </div>
      </div>
      <div class="card">
        <div class="card-content">
          <div id="utenti-table">
            ${Table({
              columns: [
                { key: 'nome', label: 'Nome' },
                { key: 'email', label: 'Email' },
                { key: 'ruolo', label: 'Ruolo' },
                { key: 'stato', label: 'Stato' },
                { key: 'data_iscrizione', label: 'Iscrizione' }
              ],
              rows
            })}
          </div>
        </div>
      </div>
    </div>
  `;

  window.filterUtenti = function(value) {
    const filter = value.trim().toLowerCase();
    const filtered = utenti
      .filter(u => {
        if (!filter) return true;
        return `${u.nome} ${u.cognome}`.toLowerCase().includes(filter)
          || (u.email || '').toLowerCase().includes(filter);
      })
      .map(u => ({
        nome: `${u.nome} ${u.cognome}`,
        email: u.email,
        ruolo: `<span class="badge ${RUOLI_BADGES[u.ruolo] || 'badge-secondary'}">${RUOLI_LABELS[u.ruolo] || u.ruolo}</span>`,
        stato: `<span class="badge ${isAttivo(u) ? 'badge-success' : 'badge-destructive'}">${isAttivo(u) ? 'Attivo' : 'Sospeso'}</span>`,
        data_iscrizione: new Date(u.data_iscrizione).toLocaleDateString('it-IT')
      }));
    document.getElementById('utenti-table').innerHTML = Table({
      columns: [
        { key: 'nome', label: 'Nome' },
        { key: 'email', label: 'Email' },
        { key: 'ruolo', label: 'Ruolo' },
        { key: 'stato', label: 'Stato' },
        { key: 'data_iscrizione', label: 'Iscrizione' }
      ],
      rows: filtered
    });
  };
}
