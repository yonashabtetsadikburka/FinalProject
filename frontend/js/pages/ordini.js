import { apiGet, apiPost } from '../api.js';
import { setPageInterval } from '../page-timers.js';
import { Badge } from '../components/badge.js';
import { showQrModal } from '../qr-modal.js';
import { STATI_ORDINE, deliveryType, statoOrdine, etichettaStato } from '../stato-ordine.js';

/** Micro-label secondaria solo per spedizione in fase fornitore o successiva. */
function microSpedizione(p) {
  if (deliveryType(p) !== 'spedizione') return '';
  const key = statoOrdine(p);
  if (key !== 'fornitore' && key !== 'finale') return '';
  const map = { in_attesa: 'In preparazione', pronta: 'In preparazione', spedita: 'Spedito', consegnata: 'Consegnato oggi', ritirata: 'Consegnato oggi' };
  return `<span class="text-xs text-secondary">${map[p.consegna_stato] || 'In preparazione'}</span>`;
}

let ordiniDati = [];
let ordiniFiltroQ = '';
let ordiniFiltroStato = '';
let costoSpedizione = 0;
let recensioniMie = {};

async function caricaStatoRecensioni(ordini) {
  const fids = [...new Set(ordini
    .filter(p => statoOrdine(p) === 'finale' && p.fornitore_id)
    .map(p => p.fornitore_id))].filter(fid => !(fid in recensioniMie));
  await Promise.all(fids.map(async fid => {
    try {
      const rec = await apiGet(`/fornitori/${fid}/recensioni`);
      recensioniMie[fid] = !!rec.dati?.mia;
    } catch (_) {}
  }));
}

window.ordiniFiltra = function() {
  ordiniFiltroQ = (document.getElementById('search-ordini')?.value || '').toLowerCase();
  ordiniFiltroStato = document.getElementById('filtro-stato-ordine')?.value || '';
  renderOrdiniList();
};

window.togglePickupPopover = function(prenotazioneId, event) {
  if (event) event.stopPropagation();
  const el = document.getElementById('pickup-pop-' + prenotazioneId);
  const wasOpen = el?.classList.contains('open');
  document.querySelectorAll('.pickup-popover.open').forEach(m => m.classList.remove('open'));
  if (el && !wasOpen) el.classList.add('open');
};

if (!window._pickupPopoverListener) {
  window._pickupPopoverListener = true;
  document.addEventListener('click', () => {
    document.querySelectorAll('.pickup-popover.open').forEach(m => m.classList.remove('open'));
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.pickup-popover.open').forEach(m => m.classList.remove('open'));
  });
}

window.showQr = function(prenotazioneId) {
  showQrModal(prenotazioneId);
};

window.scegliConsegna = async function(prenotazioneId, modalita) {
  try {
    await apiPost('/consegne/scelta', { prenotazione_id: prenotazioneId, modalita });
    const res = await apiGet('/mie/partecipazioni');
    ordiniDati = res.dati || [];
    renderOrdiniList();
  } catch (err) {
    alert(err.message);
  }
};

window.handlePagaOrdine = async function(prenotazioneId) {
  try {
    const res = await apiPost('/pagamento/checkout', { prenotazione_id: prenotazioneId });
    if (res.dati?.checkout_url) window.location.href = res.dati.checkout_url;
  } catch (err) {
    alert(err.message);
  }
};

let recPopupVoto = 0;

window.recPopupSetVoto = function(v) {
  recPopupVoto = v;
  document.querySelectorAll('#rec-popup-stars .rec-star').forEach((s, i) =>
    s.classList.toggle('active', i < v));
};

window.apriRecensionePopup = async function(fornitoreId, nomeFornitore) {
  document.getElementById('rec-popup-modal')?.remove();
  let dati = { media: null, totale: 0, mia: null, recensioni: [] };
  try {
    const res = await apiGet(`/fornitori/${fornitoreId}/recensioni`);
    dati = res.dati || dati;
  } catch (err) {
    alert(err.message);
    return;
  }
  if (dati.mia) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Hai già recensito questo fornitore</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
    return;
  }
  recPopupVoto = 0;
  const modalHtml = `
    <div id="rec-popup-modal" class="modal-overlay" onclick="if(event.target===this)document.getElementById('rec-popup-modal').remove()">
      <div class="modal-content card" style="max-width:420px;width:92%;">
        <div class="card-content">
          <h3 style="margin-bottom:var(--space-1);">Lascia una recensione...</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">...a ${nomeFornitore || 'questo fornitore'} per il tuo acquisto ritirato.</p>
          <div id="rec-popup-stars" style="font-size:var(--text-2xl);cursor:pointer;margin-bottom:var(--space-2);">
            ${[1, 2, 3, 4, 5].map(i => `<span class="rec-star" onclick="recPopupSetVoto(${i})" style="color:var(--color-border);">&#9733;</span>`).join('')}
          </div>
          <textarea id="rec-popup-testo" class="input" rows="3" maxlength="1000" placeholder="Com'è andato il tuo acquisto? (max 1000 caratteri)" style="height:auto;margin-bottom:var(--space-2);"></textarea>
          <div id="rec-popup-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
          <div style="display:flex;gap:var(--space-2);">
            <button class="btn btn-default" style="flex:1;" onclick="inviaRecensioneFornitore(${fornitoreId})">Invia recensione</button>
            <button class="btn btn-ghost" onclick="document.getElementById('rec-popup-modal').remove()">Chiudi</button>
          </div>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
};

window.inviaRecensioneFornitore = async function(fornitoreId) {
  const errorEl = document.getElementById('rec-popup-error');
  errorEl.style.display = 'none';
  if (!recPopupVoto || recPopupVoto < 1 || recPopupVoto > 5) {
    errorEl.textContent = 'Seleziona un voto da 1 a 5 stelle.';
    errorEl.style.display = 'block';
    return;
  }
  try {
    const testo = document.getElementById('rec-popup-testo')?.value.trim() || '';
    await apiPost(`/fornitori/${fornitoreId}/recensioni`, { voto: recPopupVoto, testo });
    document.getElementById('rec-popup-modal')?.remove();
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Grazie per la recensione!</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

export async function OrdiniPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const [partRes, costoRes] = await Promise.all([
      apiGet('/mie/partecipazioni'),
      apiGet('/consegne/costo').catch(() => ({ dati: { costo_spedizione: 0 } }))
    ]);
    ordiniDati = partRes.dati || [];
    costoSpedizione = parseFloat(costoRes.dati?.costo_spedizione) || 0;
    ordiniFiltroQ = '';
    ordiniFiltroStato = '';

    renderOrdiniPage();
    await caricaStatoRecensioni(ordiniDati);
    renderOrdiniList();

    try {
      const shown = sessionStorage.getItem('recPopupShown');
      const ritirati = ordiniDati.filter(p => p.stato_qr === 'scansionato' && p.fornitore_id);
      if (!shown && ritirati.length > 0) {
        for (const r of ritirati) {
          try {
            const rec = await apiGet(`/fornitori/${r.fornitore_id}/recensioni`);
            if (!rec.dati?.mia) {
              sessionStorage.setItem('recPopupShown', '1');
              apriRecensionePopup(r.fornitore_id, r.fornitore || '');
              break;
            }
          } catch (_) {}
        }
      }
    } catch (_) {}

    const hasConfermata = ordiniDati.some(p => p.stato === 'confermata');
    if (hasConfermata) {
      let attempts = 0;
      const maxAttempts = 5;
      const pollInterval = setPageInterval(async () => {
        attempts++;
        if (attempts >= maxAttempts) { clearInterval(pollInterval); return; }
        try {
          const res = await apiGet('/mie/partecipazioni');
          ordiniDati = res.dati || [];
          const stillConfermata = ordiniDati.some(p => p.stato === 'confermata');
          if (!stillConfermata || ordiniDati.every(p => p.stato === 'pagata')) {
            clearInterval(pollInterval);
          }
          renderOrdiniList();
        } catch (_) {}
      }, 2000);
    }
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}

import { API_URL } from '../constants.js';

function ordImgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + String(path).replace(/^\//, '');
}

function thumbHtml(p) {
  const src = ordImgUrl(p.immagine);
  if (!src) {
    return `<div class="order-thumb order-thumb-empty">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
    </div>`;
  }
  return `<img class="order-thumb" src="${src}" alt="${(p.prodotto || 'Prodotto').replace(/"/g, '&quot;')}" loading="lazy" />`;
}

function ritiroFasciaHtml(p) {
  if (deliveryType(p) === 'spedizione' || !p.sede_nome) return '';
  const dettagli = [p.sede_indirizzo, p.sede_citta].filter(Boolean).join(', ');
  return `<span class="order-band-field">Ritiro
    <span class="pickup-wrap">
      <button class="pickup-link" onclick="togglePickupPopover(${p.id}, event)">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
        ${p.sede_nome}
      </button>
      <span class="pickup-popover" id="pickup-pop-${p.id}" onclick="event.stopPropagation()">
        <span class="font-medium text-sm" style="display:block;margin-bottom:var(--space-1);">${p.sede_nome}</span>
        ${dettagli ? `<span class="text-sm text-secondary" style="display:block;">${dettagli}</span>` : ''}
        ${p.sede_orari ? `<span class="text-sm text-secondary" style="display:block;margin-top:var(--space-1);">${p.sede_orari}</span>` : ''}
        ${p.sede_telefono ? `<span class="text-sm text-secondary" style="display:block;margin-top:var(--space-1);">${p.sede_telefono}</span>` : ''}
      </span>
    </span>
  </span>`;
}

function ordineCardHtml(p) {
  const key = statoOrdine(p);
  const info = STATI_ORDINE[key];
  const dt = deliveryType(p);
  const showQr = p.stato === 'pagata' && dt === 'ritiro' && key !== 'finale';
  const showRecensione = key === 'finale' && p.fornitore_id && recensioniMie[p.fornitore_id] !== true;
  const daPagare = p.stato === 'confermata';
  const spedizione = dt === 'spedizione';
  const haScelta = !!p.consegna_modalita;
  const nomeF = (p.fornitore || '').replace(/'/g, "\\'");
  const dataTxt = new Date(p.data_prenotazione).toLocaleDateString('it-IT');
  const totaleTxt = (p.totale !== null && p.totale !== undefined && p.totale !== '') ? `€${parseFloat(p.totale).toFixed(2)}` : '—';
  const azioneHtml = showQr ? `<button class="btn btn-default btn-sm" onclick="showQr(${p.id})">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
      Mostra QR
    </button>`
    : (showRecensione ? `<button class="btn btn-outline btn-sm" onclick="apriRecensionePopup(${p.fornitore_id}, '${nomeF}')">Lascia una recensione</button>` : '');
  return `
    <div class="card order-card">
      <div class="order-summary-band">
        <span class="order-band-field">Prenotato il <strong>${dataTxt}</strong></span>
        <span class="order-band-field">Totale <strong>${totaleTxt}</strong></span>
        ${ritiroFasciaHtml(p)}
        <span class="order-band-field order-number">Ordine #${p.id}</span>
      </div>
      <div class="order-product-row">
        ${thumbHtml(p)}
        <div class="order-product-text">
          <a href="#/campagne/${p.id_colletta}" class="order-product-name">${p.prodotto || 'Campagna #' + p.id_colletta}</a>
          <div class="text-xs text-secondary">${p.fornitore || ''} &middot; ${p.quantita} pezzi</div>
        </div>
        <div class="order-product-side">
          <span style="display:inline-flex;align-items:center;gap:var(--space-2);">
            ${Badge({ variant: info.variant, children: etichettaStato(p) })}
            ${microSpedizione(p)}
          </span>
          ${azioneHtml}
        </div>
      </div>
      ${daPagare ? `
      <div class="order-pay-block">
        <div class="text-sm text-secondary" style="margin-bottom:var(--space-2);">Come vuoi ricevere l'articolo?</div>
        <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;margin-bottom:var(--space-3);">
          <button class="btn ${!spedizione ? 'btn-default' : 'btn-outline'} btn-sm" onclick="scegliConsegna(${p.id}, 'ritiro_sede')">Ritiro in sede (gratis)</button>
          <button class="btn ${spedizione ? 'btn-default' : 'btn-outline'} btn-sm" onclick="scegliConsegna(${p.id}, 'consegna_domicilio')">Spedizione (+&euro;${costoSpedizione.toFixed(2)})</button>
        </div>
        ${haScelta ? `<button class="btn btn-default w-full" onclick="handlePagaOrdine(${p.id})">Completa pagamento</button>`
          : `<p class="text-xs text-secondary">Scegli come ricevere l'articolo per procedere al pagamento.</p>`}
      </div>` : ''}
    </div>`;
}

const FILTRO_STATI_ORDINE = [
  ['', 'Tutti gli stati'],
  ['attesa', 'In attesa'],
  ['pagato', 'Pagato'],
  ['fornitore', 'Ordine al fornitore'],
  ['pronto', 'Pronto per il ritiro'],
  ['finale', 'Ritirato']
];

function renderOrdiniPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header"><h1>I miei ordini</h1></div>
      <div class="search-filter-bar">
        <div class="search-input-wrapper" style="flex:1;">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="search" autocomplete="off" aria-label="Cerca ordini" class="input" placeholder="Cerca ordini..." id="search-ordini" value="${ordiniFiltroQ.replace(/"/g, '&quot;')}" oninput="ordiniFiltra()">
        </div>
        <select id="filtro-stato-ordine" class="input" style="max-width:220px;" aria-label="Stato ordine" onchange="ordiniFiltra()">
          ${FILTRO_STATI_ORDINE.map(([v, l]) => `<option value="${v}"${ordiniFiltroStato === v ? ' selected' : ''}>${l}</option>`).join('')}
        </select>
      </div>
      <div class="text-sm text-secondary" id="ordini-count" style="margin-bottom:var(--space-3);"></div>
      <div id="ordini-list"></div>
    </div>`;
  renderOrdiniList();
}

function renderOrdiniList() {
  const list = document.getElementById('ordini-list');
  if (!list) return;
  const filtrati = ordiniDati.filter(p =>
    statoOrdine(p) !== 'fallita' && p.stato !== 'prenotata' &&
    ((p.prodotto || '').toLowerCase().includes(ordiniFiltroQ) || (p.fornitore || '').toLowerCase().includes(ordiniFiltroQ)) &&
    (ordiniFiltroStato === '' || statoOrdine(p) === ordiniFiltroStato));
  list.innerHTML = filtrati.length > 0 ? filtrati.map(ordineCardHtml).join('')
    : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessun ordine</h2><p class="empty-state-description">Nessun ordine corrisponde ai filtri selezionati.</p><a href="#/" class="btn btn-default">Esplora le campagne</a></div>';
  const countEl = document.getElementById('ordini-count');
  if (countEl) countEl.textContent = `${filtrati.length} ${filtrati.length === 1 ? 'ordine' : 'ordini'}`;
}
