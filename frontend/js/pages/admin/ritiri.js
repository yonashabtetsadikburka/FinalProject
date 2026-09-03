import { PRENOTAZIONI, PRODOTTI, COLLETTE, UTENTI } from '../../mock.js';
import { Table } from '../../components/table.js';
import { Badge } from '../../components/badge.js';

export function AdminRitiriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const rows = PRENOTAZIONI.filter(p => p.stato === 'confermata').map(p => {
    const colletta = COLLETTE.find(c => c.id === p.id_colletta);
    const prodotto = colletta ? PRODOTTI.find(pr => pr.id === colletta.id_prodotto) : null;
    const utente = UTENTI.find(u => u.id === p.id_utente);
    return {
      utente: utente ? `${utente.nome} ${utente.cognome}` : 'N/A',
      prodotto: prodotto?.nome || 'N/A',
      stato: `<span class="badge badge-warning">In attesa</span>`,
      azioni: `<button class="btn btn-default btn-sm" onclick="alert('Ritiro confermato!')">Conferma</button>`
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
