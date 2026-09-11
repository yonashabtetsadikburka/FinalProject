import { apiGet } from '../../api.js';
import { Table } from '../../components/table.js';

const STATI_LABELS = {
  da_negoziare: 'Da negoziare',
  inviato: 'Inviato',
  consegnato: 'Consegnato',
  annullato: 'Annullato'
};

const STATI_BADGES = {
  da_negoziare: 'badge-warning',
  inviato: 'badge-secondary',
  consegnato: 'badge-success',
  annullato: 'badge-destructive'
};

export async function FornitoreOrdiniPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Ordini</h1>
      </div>
      <div class="card">
        <div class="card-content">
          <p class="text-secondary" style="margin-bottom: var(--space-4);">
            Ordini inoltrati verso la tua azienda a valle delle campagne andate a buon fine.
          </p>
          <div id="fornitore-ordini-table">
            <p class="text-secondary">Caricamento...</p>
          </div>
        </div>
      </div>
    </div>
  `;

  try {
    const res = await apiGet('/fornitore/ordini');
    const ordini = res.dati || [];

    const rows = ordini.map(o => {
      const colletta = o.colletta;
      const prodotto = colletta?.prodotto;
      const avanzamento = colletta?.percentuale_avanzamento ?? 0;

      return {
        campagna: `
          <div class="font-medium">${prodotto?.nome || `Ordine #${o.id}`}</div>
          ${colletta ? `<div class="text-sm text-secondary">Campagna #${colletta.id}</div>` : ''}
        `,
        quantita: `<span class="text-sm">${o.quantita_ordinata || 0}</span>`,
        prezzo_negoziato: `<span class="text-sm">${o.prezzo_negoziato != null ? '&euro;' + Number(o.prezzo_negoziato).toFixed(2) : '—'}</span>`,
        importo: `<span class="text-sm">&euro;${Number(o.importo_totale || 0).toFixed(2)}</span>`,
        avanzamento: `
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="progress-container" style="width:90px;"><div class="progress-bar" style="width:${avanzamento}%"></div></div>
            <span class="text-xs">${avanzamento}%</span>
          </div>
        `,
        stato: `<span class="badge ${STATI_BADGES[o.stato] || 'badge-secondary'}" style="text-transform: capitalize;">${STATI_LABELS[o.stato] || o.stato}</span>`,
        data: `<span class="text-sm text-secondary">${o.data_ordine ? new Date(o.data_ordine).toLocaleDateString('it-IT') : '—'}</span>`,
        consegna: `<span class="text-sm text-secondary">${o.data_consegna ? new Date(o.data_consegna).toLocaleDateString('it-IT') : '—'}</span>`
      };
    });

    document.getElementById('fornitore-ordini-table').innerHTML = Table({
      columns: [
        { key: 'campagna', label: 'Campagna' },
        { key: 'quantita', label: 'Quantit&agrave;' },
        { key: 'prezzo_negoziato', label: 'Prezzo negoziato' },
        { key: 'importo', label: 'Importo totale' },
        { key: 'avanzamento', label: 'Avanzamento' },
        { key: 'stato', label: 'Stato' },
        { key: 'data', label: 'Data ordine' },
        { key: 'consegna', label: 'Consegna' }
      ],
      rows
    });
  } catch (e) {
    document.getElementById('fornitore-ordini-table').innerHTML =
      `<p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p>`;
  }
}