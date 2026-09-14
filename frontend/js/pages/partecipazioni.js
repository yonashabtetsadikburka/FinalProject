import { getState } from '../state.js';
import { apiGet } from '../api.js';
import { Badge } from '../components/badge.js';
import { STATI_PRENOTAZIONE_BADGES, STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../constants.js';

const STATI_LABELS = { prenotata: 'Prenotata', confermata: 'Da Pagare', pagata: 'Pagata', annullata: 'Annullata', rimborsata: 'Rimborsata' };

export async function PartecipazioniPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const { user } = getState();
    if (!user) { content.innerHTML = '<div class="content-area"><div class="empty-state"><h2>Devi effettuare il login</h2></div></div>'; return; }

    const res = await apiGet('/mie/partecipazioni');
    const mie = res.dati || [];

    const partecipazioniHtml = mie.length > 0 ? mie.map(p => {
      const ritirato = p.stato_qr === 'scansionato';
      return `
        <div class="card" style="margin-bottom:var(--space-3);">
          <div class="card-content">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-2);">
              <div>
                <h3 style="font-size:var(--text-base);font-weight:var(--font-semibold);margin-bottom:var(--space-1);">
                  <a href="#/campagne/${p.id_colletta}" style="color:inherit;text-decoration:none;">${p.prodotto || 'Campagna #' + p.id_colletta}</a>
                </h3>
                ${p.fornitore ? `<span class="text-xs text-secondary">${p.fornitore}</span>` : ''}
              </div>
              <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
                ${Badge({ variant: STATI_CAMPAGNA_BADGES[p.stato_colletta] || 'secondary', children: STATI_CAMPAGNA_LABELS[p.stato_colletta] || p.stato_colletta })}
                ${ritirato
                  ? Badge({ variant: 'success', children: 'Ritirato' })
                  : Badge({ variant: STATI_PRENOTAZIONE_BADGES[p.stato] || 'secondary', children: STATI_LABELS[p.stato] || p.stato })}
              </div>
            </div>
            <div style="display:flex;gap:var(--space-4);margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--color-border);">
              <div><div class="text-xs text-secondary">Quantita</div><div class="font-medium text-sm">${p.quantita} pezzi</div></div>
              <div><div class="text-xs text-secondary">Data</div><div class="font-medium text-sm">${new Date(p.data_prenotazione).toLocaleDateString('it-IT')}</div></div>
            </div>
          </div>
        </div>`;
    }).join('') : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessuna partecipazione</h2><p class="empty-state-description">Non partecipi ancora a nessuna campagna.</p><a href="#/" class="btn btn-default">Esplora le campagne</a></div>';

    content.innerHTML = `<div class="content-area"><div class="page-header"><h1>Le mie partecipazioni</h1></div>${partecipazioniHtml}</div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
