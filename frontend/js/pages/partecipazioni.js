import { getState } from '../state.js';
import { apiGet } from '../api.js';
import { Badge } from '../components/badge.js';
import { STATI_ORDINE, statoOrdine, etichettaStato } from '../stato-ordine.js';

let partDati = [];
let partFiltroQ = '';
let partFiltroStato = '';

const FILTRO_STATI_PART = [
  ['', 'Tutti gli stati'],
  ['attesa', 'In attesa'],
  ['pagato', 'Pagato'],
  ['fornitore', 'Ordine al fornitore'],
  ['pronto', 'Pronto per il ritiro'],
  ['finale', 'Ritirato'],
  ['fallita', 'Non riuscita']
];

window.partFiltra = function() {
  partFiltroQ = (document.getElementById('search-part')?.value || '').toLowerCase();
  partFiltroStato = document.getElementById('filtro-stato-part')?.value || '';
  renderPartList();
};

function partCardHtml(p) {
  const key = statoOrdine(p);
  const info = STATI_ORDINE[key];
  const fallita = key === 'fallita';
  return `
    <div class="card" style="margin-bottom:var(--space-3);">
      <div class="card-content">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-2);">
          <div>
            <h3 style="font-size:var(--text-base);font-weight:var(--font-semibold);margin-bottom:var(--space-1);">${p.prodotto || 'Campagna #' + p.id_colletta}</h3>
            ${p.fornitore ? `<span class="text-xs text-secondary">${p.fornitore}</span>` : ''}
          </div>
          <div style="flex-shrink:0;">
            ${Badge({ variant: info.variant, children: etichettaStato(p) })}
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--color-border);">
          ${fallita ? `
          <div style="display:flex;align-items:center;gap:var(--space-2);">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:var(--color-text-secondary);flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <span class="text-sm text-secondary">Obiettivo non raggiunto — nessun addebito effettuato</span>
          </div>` : `
          <div style="display:flex;gap:var(--space-4);">
            <div><div class="text-xs text-secondary">Quantita</div><div class="font-medium text-sm">${p.quantita} pezzi</div></div>
            <div><div class="text-xs text-secondary">Data</div><div class="font-medium text-sm">${new Date(p.data_prenotazione).toLocaleDateString('it-IT')}</div></div>
          </div>`}
          <a href="#/campagne/${p.id_colletta}" class="btn btn-ghost btn-sm" style="flex-shrink:0;">Vedi campagna &rarr;</a>
        </div>
      </div>
    </div>`;
}

export async function PartecipazioniPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const { user } = getState();
    if (!user) { content.innerHTML = '<div class="content-area"><div class="empty-state"><h2>Devi effettuare il login</h2></div></div>'; return; }

    const res = await apiGet('/mie/partecipazioni');
    partDati = res.dati || [];
    partFiltroQ = '';
    partFiltroStato = '';

    content.innerHTML = `
      <div class="content-area">
        <div class="page-header"><h1>Le mie partecipazioni</h1></div>
        <div class="search-filter-bar">
          <div class="search-input-wrapper" style="flex:1;">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" autocomplete="off" aria-label="Cerca partecipazioni" class="input" placeholder="Cerca partecipazioni..." id="search-part" oninput="partFiltra()">
          </div>
          <select id="filtro-stato-part" class="input" style="max-width:220px;" aria-label="Stato partecipazione" onchange="partFiltra()">
            ${FILTRO_STATI_PART.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
          </select>
        </div>
        <div class="text-sm text-secondary" id="part-count" style="margin-bottom:var(--space-3);"></div>
        <div id="part-list"></div>
      </div>`;
    renderPartList();
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}

function renderPartList() {
  const list = document.getElementById('part-list');
  if (!list) return;
  const filtrate = partDati.filter(p =>
    ((p.prodotto || '').toLowerCase().includes(partFiltroQ) || (p.fornitore || '').toLowerCase().includes(partFiltroQ)) &&
    (partFiltroStato === '' || statoOrdine(p) === partFiltroStato));
  list.innerHTML = filtrate.length > 0 ? filtrate.map(partCardHtml).join('')
    : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessuna partecipazione</h2><p class="empty-state-description">Nessuna partecipazione corrisponde ai filtri selezionati.</p><a href="#/" class="btn btn-default">Esplora le campagne</a></div>';
  const countEl = document.getElementById('part-count');
  if (countEl) countEl.textContent = `${filtrate.length} ${filtrate.length === 1 ? 'partecipazione' : 'partecipazioni'}`;
}
