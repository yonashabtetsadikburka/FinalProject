import { UTENTI } from '../../mock.js';
import { Table } from '../../components/table.js';
import { Badge } from '../../components/badge.js';
import { RUOLI_LABELS, RUOLI_BADGES } from '../../constants.js';

export function AdminUtentiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const rows = UTENTI.map(u => ({
    nome: `${u.nome} ${u.cognome}`,
    email: u.email,
    ruolo: `<span class="badge ${RUOLI_BADGES[u.ruolo] || 'badge-secondary'}">${RUOLI_LABELS[u.ruolo] || u.ruolo}</span>`,
    stato: `<span class="badge ${u.attivo ? 'badge-success' : 'badge-destructive'}">${u.attivo ? 'Attivo' : 'Sospeso'}</span>`,
    data_iscrizione: new Date(u.data_iscrizione).toLocaleDateString('it-IT')
  }));

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Gestione Utenti</h1>
        <div class="search-field">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="text" class="input" placeholder="Cerca utenti...">
        </div>
      </div>
      <div class="card">
        <div class="card-content">
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
  `;
}
