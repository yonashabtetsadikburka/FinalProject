import { apiGet } from '../../api.js';
import { Table } from '../../components/table.js';

const STATI_LABELS = {
  in_corso: 'In corso',
  riuscita: 'Riuscita',
  fallita: 'Fallita',
  ordine_fornitore: 'Ordine fornitore',
  consegnata: 'Consegnata',
  annullata: 'Annullata'
};

const STATI_BADGES = {
  in_corso: 'badge-success',
  riuscita: 'badge-secondary',
  fallita: 'badge-destructive',
  ordine_fornitore: 'badge-warning',
  consegnata: 'badge-outline',
  annullata: 'badge-destructive'
};

export async function FornitoreCollettePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Campagne</h1>
      </div>
      <div class="card">
        <div class="card-content">
          <p class="text-secondary" style="margin-bottom: var(--space-4);">
            Campagne lanciate sui tuoi prodotti dai clienti della piattaforma.
          </p>
          <div id="fornitore-collette-table">
            <p class="text-secondary">Caricamento...</p>
          </div>
        </div>
      </div>
    </div>
  `;

  try {
    const res = await apiGet('/fornitore/collette');
    const collette = res.dati || [];

    const rows = collette.map(c => {
      const avanzamento = Number(c.percentuale_avanzamento ?? 0);
      const prenotazioni = c.prenotazioni || [];

      return {
        prodotto: `
          <div class="font-medium">${c.prodotto?.nome || 'Prodotto'}</div>
          <div class="text-sm text-secondary">Campagna #${c.id}</div>
        `,
        quantita: `
          <span class="text-sm">${c.quantita_attuale ?? 0} / ${c.quantita_minima ?? 0}</span>
        `,
        avanzamento: `
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="progress-container" style="width:90px;"><div class="progress-bar ${avanzamento >= 100 ? 'progress-bar-success' : ''}" style="width:${avanzamento}%"></div></div>
            <span class="text-xs">${avanzamento}%</span>
          </div>
        `,
        prezzo: `<span class="text-sm">${c.prezzo_corrente != null ? '&euro;' + Number(c.prezzo_corrente).toFixed(2) : (c.prezzo_base != null ? '&euro;' + Number(c.prezzo_base).toFixed(2) : '—')}</span>`,
        prenotazioni: `<span class="text-sm">${prenotazioni.length}</span>`,
        scadenza: `<span class="text-sm text-secondary">${c.data_limite ? new Date(c.data_limite).toLocaleDateString('it-IT') : '—'}</span>`,
        stato: `<span class="badge ${STATI_BADGES[c.stato] || 'badge-secondary'}" style="text-transform: capitalize;">${STATI_LABELS[c.stato] || c.stato}</span>`
      };
    });

    document.getElementById('fornitore-collette-table').innerHTML = Table({
      columns: [
        { key: 'prodotto', label: 'Prodotto' },
        { key: 'quantita', label: 'Quantit&agrave;' },
        { key: 'avanzamento', label: 'Avanzamento' },
        { key: 'prezzo', label: 'Prezzo corrente' },
        { key: 'prenotazioni', label: 'Prenotazioni' },
        { key: 'scadenza', label: 'Termine' },
        { key: 'stato', label: 'Stato' }
      ],
      rows
    });
  } catch (e) {
    document.getElementById('fornitore-collette-table').innerHTML =
      `<p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p>`;
  }
}