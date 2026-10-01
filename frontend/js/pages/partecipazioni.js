import { getState } from '../state.js';
import { apiGet, apiDelete } from '../api.js';
import { API_URL } from '../constants.js';

let partDati = [];
let partFiltroQ = '';
let partFiltroStato = '';

const FILTRO_STATI_PART = [
  ['', 'Tutti gli stati'],
  ['in_attesa', 'In attesa'],
  ['confermata', 'Confermata'],
  ['non_riuscita', 'Non riuscita']
];

const STATI_PART_LABELS = {
  in_attesa: 'In attesa',
  confermata: 'Confermata',
  non_riuscita: 'Non riuscita'
};

/** Stato partecipazione per questa pagina: solo i 3 stati storici, resto escluso (va in Ordini). */
function statoPartecipazione(p) {
  if (['fallita', 'annullata'].includes(p.stato_colletta)) return 'non_riuscita';
  if (p.stato === 'confermata') return 'confermata';
  if (p.stato === 'prenotata') {
    const qty = parseInt(p.quantita_attuale) || 0;
    const min = parseInt(p.quantita_minima) || 1;
    const t = new Date(p.data_limite).getTime();
    if (Number.isFinite(t) && t < Date.now() && qty < min) return 'non_riuscita';
    return 'in_attesa';
  }
  return null;
}

function partImgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + String(path).replace(/^\//, '');
}

function partThumb(p) {
  const src = partImgUrl(p.immagine);
  if (!src) {
    return `<div class="order-thumb order-thumb-empty">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
    </div>`;
  }
  return `<img class="order-thumb" src="${src}" alt="${(p.prodotto || 'Prodotto').replace(/"/g, '&quot;')}" loading="lazy" />`;
}

function partCardHtml(p) {
  const key = statoPartecipazione(p);
  const dataTxt = new Date(p.data_prenotazione).toLocaleDateString('it-IT');
  const scaduta = Number.isFinite(new Date(p.data_limite).getTime()) && new Date(p.data_limite).getTime() < Date.now();
  const acconto = parseFloat(p.importo_acconto) || 0;
  return `
    <div class="card card-elevate">
      <div class="part-head part-head-${key}">
        <span class="order-status-label">${STATI_PART_LABELS[key]}</span>
        <span class="text-xs">${dataTxt}</span>
      </div>
      <div class="card-content">
        <div style="display:flex;gap:var(--space-3);">
          ${partThumb(p)}
          <div style="flex:1;min-width:0;">
            <a href="#/campagne/${p.id_colletta}" class="order-product-name" title="${(p.prodotto || '').replace(/"/g, '&quot;')}">${p.prodotto || 'Campagna #' + p.id_colletta}</a>
            <div class="text-xs text-secondary">${p.fornitore || ''}</div>
            <div class="text-xs text-secondary" style="margin-top:var(--space-1);">${p.quantita} pezzi</div>
          </div>
        </div>
        ${key === 'non_riuscita' ? `
        <div style="display:flex;align-items:center;gap:var(--space-2);margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--color-border);">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:var(--color-text-secondary);flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          <span class="text-sm text-secondary">Acconto rimborsato al 100%</span>
        </div>` : `
        <div style="margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--color-border);">
          ${!scaduta ? `<button class="btn btn-outline btn-sm w-full" style="color:var(--color-error);border-color:var(--color-error);margin-bottom:var(--space-2);" onclick="annullaPrenotazione(${p.id_colletta}, '${(p.prodotto || '').replace(/'/g, "\\'")}', ${acconto})">Annulla prenotazione</button>` : ''}
          <a href="#/campagne/${p.id_colletta}" class="btn btn-ghost btn-sm w-full">Vedi campagna &rarr;</a>
        </div>`}
      </div>
    </div>`;
}

window.partFiltra = function() {
  partFiltroQ = (document.getElementById('search-part')?.value || '').toLowerCase();
  partFiltroStato = document.getElementById('filtro-stato-part')?.value || '';
  renderPartList();
};

window.annullaPrenotazione = async function(collettaId, nomeProdotto, acconto) {
  const dettaglioAcconto = acconto > 0 ? ` L'acconto di €${acconto.toFixed(2)} ti verrà rimborsato.` : '';
  if (!confirm(`Vuoi annullare la tua prenotazione di ${nomeProdotto}?${dettaglioAcconto}`)) return;
  try {
    const res = await apiDelete(`/campagne/${collettaId}/partecipazioni`);
    const dati = res.dati || {};
    if (dati.soglia_persa) {
      alert(`Prenotazione annullata.${dettaglioAcconto} Il gruppo non ha più raggiunto il minimo: ${dati.notificati ?? 0} partecipanti avvisati.`);
    } else {
      alert(`Prenotazione annullata.${dettaglioAcconto}`);
    }
    const r = await apiGet('/mie/partecipazioni');
    partDati = r.dati || [];
    renderPartList();
  } catch (err) {
    alert(err.message);
  }
};

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
  const filtrate = partDati.filter(p => {
    const key = statoPartecipazione(p);
    if (!key) return false;
    return ((p.prodotto || '').toLowerCase().includes(partFiltroQ) || (p.fornitore || '').toLowerCase().includes(partFiltroQ)) &&
      (partFiltroStato === '' || key === partFiltroStato);
  });
  list.innerHTML = filtrate.length > 0 ? `<div class="cards-grid">` + filtrate.map(partCardHtml).join('') + `</div>`
    : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessuna partecipazione</h2><p class="empty-state-description">Nessuna partecipazione corrisponde ai filtri selezionati.</p><a href="#/" class="btn btn-default">Esplora le campagne</a></div>';
  const countEl = document.getElementById('part-count');
  if (countEl) countEl.textContent = `${filtrate.length} ${filtrate.length === 1 ? 'partecipazione' : 'partecipazioni'}`;
}
