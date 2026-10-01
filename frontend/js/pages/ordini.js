import { apiGet, apiPost } from '../api.js';
import { setPageInterval } from '../page-timers.js';
import { showQrModal } from '../qr-modal.js';
import { deliveryType, statoOrdine, etichettaStato } from '../stato-ordine.js';

/** Micro-label secondaria solo per spedizione in fase fornitore o successiva. */
function microSpedizione(p) {
  if (deliveryType(p) !== 'spedizione') return '';
  const key = statoOrdine(p);
  if (key !== 'fornitore' && key !== 'finale') return '';
  const map = { in_attesa: 'In preparazione', pronta: 'In preparazione', spedita: 'Spedito' };
  const txt = map[p.consegna_stato] || '';
  return txt ? `<span class="text-xs text-secondary">${txt}</span>` : '';
}

let ordiniDati = [];
let ordiniFiltroQ = '';
let ordiniFiltroStato = '';
let costoSpedizione = 0;
let recensioniMie = {};
let recensioniMieProd = {};

async function caricaStatoRecensioni(ordini) {
  const finals = ordini.filter(p => statoOrdine(p) === 'finale');
  const fids = [...new Set(finals.map(p => p.fornitore_id).filter(fid => fid && !(fid in recensioniMie)))];
  const cids = [...new Set(finals.map(p => p.id_colletta).filter(cid => cid && !(cid in recensioniMieProd)))];
  await Promise.all([
    ...fids.map(async fid => {
      try {
        const rec = await apiGet(`/fornitori/${fid}/recensioni`);
        recensioniMie[fid] = !!rec.dati?.mia;
      } catch (_) {}
    }),
    ...cids.map(async cid => {
      try {
        const rec = await apiGet(`/campagne/${cid}/recensioni`);
        recensioniMieProd[cid] = !!rec.dati?.mia;
      } catch (_) {}
    })
  ]);
}

/** True se manca almeno una delle due recensioni (prodotto e/o fornitore). */
function mancaRecensione(p) {
  if (statoOrdine(p) !== 'finale') return false;
  const mancaF = p.fornitore_id && recensioniMie[p.fornitore_id] !== true;
  const mancaP = recensioniMieProd[p.id_colletta] !== true;
  return !!(mancaF || mancaP);
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

window.handleRiprovaOrdine = async function(prenotazioneId) {
  try {
    const res = await apiPost('/pagamento/riprova', { prenotazione_id: prenotazioneId });
    if (res.dati?.checkout_url) window.location.href = res.dati.checkout_url;
  } catch (err) {
    alert(err.message);
  }
};

let recPopupVotoProd = 0;
let recPopupVotoForn = 0;

window.recPopupSetVoto = function(target, v) {
  if (target === 'prod') recPopupVotoProd = v;
  else recPopupVotoForn = v;
  document.querySelectorAll(`#rec-popup-stars-${target} .rec-star`).forEach((s, i) =>
    s.classList.toggle('active', i < v));
};

function recPopupBlocco(target, titolo, nome, placeholder) {
  return `
    <div style="margin-bottom:var(--space-4);">
      <h4 style="font-size:var(--text-sm);font-weight:var(--font-semibold);margin-bottom:var(--space-1);">${titolo}</h4>
      <p class="text-xs text-secondary" style="margin-bottom:var(--space-2);">${nome}</p>
      <div id="rec-popup-stars-${target}" style="font-size:var(--text-2xl);cursor:pointer;margin-bottom:var(--space-2);">
        ${[1, 2, 3, 4, 5].map(i => `<span class="rec-star" onclick="recPopupSetVoto('${target}', ${i})" style="color:var(--color-border);">&#9733;</span>`).join('')}
      </div>
      <textarea id="rec-popup-testo-${target}" class="input" rows="2" maxlength="1000" placeholder="${placeholder}" style="height:auto;"></textarea>
      <div id="rec-popup-error-${target}" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-top:var(--space-1);"></div>
    </div>`;
}

window.apriRecensionePopup = async function(fornitoreId, nomeFornitore, campagnaId, nomeProdotto) {
  document.getElementById('rec-popup-modal')?.remove();
  let haF = false;
  let haP = false;
  try {
    const [resF, resP] = await Promise.all([
      fornitoreId ? apiGet(`/fornitori/${fornitoreId}/recensioni`).catch(() => null) : Promise.resolve(null),
      campagnaId ? apiGet(`/campagne/${campagnaId}/recensioni`).catch(() => null) : Promise.resolve(null)
    ]);
    haF = !!resF?.dati?.mia;
    haP = !!resP?.dati?.mia;
  } catch (err) {
    alert(err.message);
    return;
  }
  if (haF && haP) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Hai già lasciato entrambe le recensioni</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
    return;
  }
  const faProd = !haP && !!campagnaId;
  const faForn = !haF && !!fornitoreId;
  if (!faProd && !faForn) return;
  recPopupVotoProd = 0;
  recPopupVotoForn = 0;
  const modalHtml = `
    <div id="rec-popup-modal" class="modal-overlay" onclick="if(event.target===this)document.getElementById('rec-popup-modal').remove()">
      <div class="modal-content card" style="max-width:460px;width:92%;max-height:90vh;overflow-y:auto;">
        <div class="card-content">
          <h3 style="margin-bottom:var(--space-1);">Lascia una recensione...</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">...per il tuo acquisto ritirato.</p>
          ${faProd ? recPopupBlocco('prod', 'Il prodotto', nomeProdotto || 'questo prodotto', "Com'è il prodotto? (max 1000 caratteri)") : ''}
          ${faForn ? recPopupBlocco('forn', 'Il fornitore', nomeFornitore || 'questo fornitore', "Com'è andato l'acquisto? (max 1000 caratteri)") : ''}
          <div style="display:flex;gap:var(--space-2);">
            <button class="btn btn-default" style="flex:1;" onclick="inviaRecensioniPopup(${fornitoreId || 0}, ${campagnaId || 0}, ${faProd ? 1 : 0}, ${faForn ? 1 : 0})">Invia ${faProd && faForn ? 'recensioni' : 'recensione'}</button>
            <button class="btn btn-ghost" onclick="document.getElementById('rec-popup-modal').remove()">Chiudi</button>
          </div>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
};

window.inviaRecensioniPopup = async function(fornitoreId, campagnaId, faProd, faForn) {
  const errP = document.getElementById('rec-popup-error-prod');
  const errF = document.getElementById('rec-popup-error-forn');
  if (errP) errP.style.display = 'none';
  if (errF) errF.style.display = 'none';
  let valido = true;
  if (faProd && (!recPopupVotoProd || recPopupVotoProd < 1 || recPopupVotoProd > 5)) {
    if (errP) { errP.textContent = 'Seleziona un voto da 1 a 5 stelle per il prodotto.'; errP.style.display = 'block'; }
    valido = false;
  }
  if (faForn && (!recPopupVotoForn || recPopupVotoForn < 1 || recPopupVotoForn > 5)) {
    if (errF) { errF.textContent = 'Seleziona un voto da 1 a 5 stelle per il fornitore.'; errF.style.display = 'block'; }
    valido = false;
  }
  if (!valido) return;
  const esiti = await Promise.all([
    faProd
      ? apiPost(`/campagne/${campagnaId}/recensioni`, { voto: recPopupVotoProd, testo: document.getElementById('rec-popup-testo-prod')?.value.trim() || '' })
        .then(() => ({ ok: true }))
        .catch(err => ({ ok: false, target: 'prod', msg: err.message }))
      : Promise.resolve({ ok: true, skip: true }),
    faForn
      ? apiPost(`/fornitori/${fornitoreId}/recensioni`, { voto: recPopupVotoForn, testo: document.getElementById('rec-popup-testo-forn')?.value.trim() || '' })
        .then(() => ({ ok: true }))
        .catch(err => ({ ok: false, target: 'forn', msg: err.message }))
      : Promise.resolve({ ok: true, skip: true })
  ]);
  let tuttoOk = true;
  esiti.forEach(e => {
    if (e.ok) return;
    tuttoOk = false;
    const el = document.getElementById(`rec-popup-error-${e.target}`);
    if (el) { el.textContent = e.msg; el.style.display = 'block'; }
  });
  if (!tuttoOk) return;
  if (faProd && campagnaId) recensioniMieProd[campagnaId] = true;
  if (faForn && fornitoreId) recensioniMie[fornitoreId] = true;
  document.getElementById('rec-popup-modal')?.remove();
  const toast = document.createElement('div');
  toast.className = 'toast toast-success';
  toast.innerHTML = '<div class="toast-content"><div class="toast-title">Grazie per le recensioni!</div></div>';
  document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
  renderOrdiniList();
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
      const candidati = ordiniDati.filter(mancaRecensione);
      if (!shown && candidati.length > 0) {
        const r = candidati[0];
        sessionStorage.setItem('recPopupShown', '1');
        apriRecensionePopup(r.fornitore_id, r.fornitore || '', r.id_colletta, r.prodotto || '');
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

function ordineCardHtml(p) {
  const key = statoOrdine(p);
  const dt = deliveryType(p);
  const showQr = p.stato === 'pagata' && dt === 'ritiro' && key !== 'finale';
  const showRecensione = mancaRecensione(p);
  const daPagare = p.stato === 'confermata' && !(parseFloat(p.importo_acconto) > 0);
  const attesaSaldo = p.stato === 'confermata' && parseFloat(p.importo_acconto) > 0;
  const azioneRichiesta = p.stato === 'azione_richiesta';
  const spedizione = dt === 'spedizione';
  const haScelta = !!p.consegna_modalita;
  const nomeF = (p.fornitore || '').replace(/'/g, "\\'");
  const nomeP = (p.prodotto || '').replace(/'/g, "\\'");
  const dataTxt = new Date(p.data_prenotazione).toLocaleDateString('it-IT');
  const totaleTxt = (p.totale !== null && p.totale !== undefined && p.totale !== '') ? `€${parseFloat(p.totale).toFixed(2)}` : '—';
  const azioneHtml = showQr ? `<button class="btn btn-default btn-sm" onclick="showQr(${p.id})">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
      Mostra QR
    </button>`
    : (showRecensione ? `<button class="btn btn-outline btn-sm" onclick="apriRecensionePopup(${p.fornitore_id || 0}, '${nomeF}', ${p.id_colletta || 0}, '${nomeP}')">Lascia una recensione</button>` : '');
  const statoCls = { attesa: 'attesa', pagato: 'pagato', fornitore: 'fornitore', pronto: 'pronto', finale: 'finale', fallita: 'fallita', azione: 'azione' }[key] || 'attesa';
  const dataEventoRaw = key === 'finale' ? (dt === 'spedizione' ? p.data_consegna : p.data_ritiro) : null;
  const dataEventoTxt = (() => {
    if (!dataEventoRaw) return '';
    const t = new Date(dataEventoRaw);
    if (!Number.isFinite(t.getTime())) return '';
    return t.toLocaleDateString('it-IT') + ' - ' + t.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
  })();
  const ritiroBandHtml = (() => {
    if (dt !== 'ritiro' || !p.sede_nome) return '';
    const dettagli = [p.sede_indirizzo, p.sede_citta].filter(Boolean).join(', ');
    const etichetta = `Ritiro ${p.sede_nome}`;
    return `<span class="pickup-wrap">
      <button class="pickup-link pickup-link-band" title="${etichetta.replace(/"/g, '&quot;')}" onclick="togglePickupPopover(${p.id}, event)">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
        <span class="pickup-text">${etichetta}</span>
      </button>
      <span class="pickup-popover" id="pickup-pop-${p.id}" onclick="event.stopPropagation()">
        <span class="font-medium text-sm" style="display:block;margin-bottom:var(--space-1);">${p.sede_nome}</span>
        ${dettagli ? `<span class="text-sm text-secondary" style="display:block;">${dettagli}</span>` : ''}
        ${p.sede_orari ? `<span class="text-sm text-secondary" style="display:block;margin-top:var(--space-1);">${p.sede_orari}</span>` : ''}
        ${p.sede_telefono ? `<span class="text-sm text-secondary" style="display:block;margin-top:var(--space-1);">${p.sede_telefono}</span>` : ''}
      </span>
    </span>`;
  })();
  const spedBandTxt = dt === 'spedizione' ? (microSpedizione(p) || (!p.consegna_stato ? '<span class="text-xs">Spedizione a domicilio</span>' : '')) : '';
  return `
    <div class="card order-card card-elevate">
      <div class="order-summary-band">
        <span class="order-band-field">Prenotato il <strong>${dataTxt}</strong></span>
        <span class="order-band-field">Totale <strong>${totaleTxt}</strong></span>
        <span class="order-band-field order-number">Ordine #${p.id}</span>
      </div>
      <div class="order-product-row">
        ${thumbHtml(p)}
        <div class="order-product-text" style="min-width:0;">
          <a href="#/campagne/${p.id_colletta}" class="order-product-name" title="${(p.prodotto || '').replace(/"/g, '&quot;')}">${p.prodotto || 'Campagna #' + p.id_colletta}</a>
          <div class="text-xs text-secondary">${p.fornitore || ''} &middot; ${p.quantita} pezzi</div>
        </div>
        <div class="order-product-side">
          ${azioneHtml}
        </div>
      </div>
      ${azioneRichiesta ? `
      <div class="order-pay-block">
        <p class="text-sm" style="margin-bottom:var(--space-3);">Il pagamento del saldo non è andato a buon fine. Completa il pagamento per confermare il tuo ordine.</p>
        <button class="btn btn-default w-full" onclick="handleRiprovaOrdine(${p.id})">Completa pagamento</button>
      </div>` : ''}
      ${attesaSaldo ? `
      <div class="order-pay-block">
        <p class="text-sm text-secondary">Il saldo verrà addebitato automaticamente alla chiusura della campagna.</p>
      </div>` : ''}
      ${daPagare ? `
      <div class="order-pay-block">
        <div class="text-sm text-secondary" style="margin-bottom:var(--space-2);">Come vuoi ricevere l'articolo?</div>
        <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;margin-bottom:var(--space-2);">
          <button class="btn ${!spedizione ? 'btn-default' : 'btn-outline'} btn-sm" onclick="scegliConsegna(${p.id}, 'ritiro_sede')">Ritiro in sede (gratis)</button>
          <button class="btn ${spedizione ? 'btn-default' : 'btn-outline'} btn-sm" onclick="scegliConsegna(${p.id}, 'consegna_domicilio')">Spedizione (+&euro;${costoSpedizione.toFixed(2)})</button>
        </div>
        ${haScelta ? `<button class="btn btn-default btn-sm w-full" onclick="handlePagaOrdine(${p.id})">Completa pagamento</button>`
          : `<p class="text-xs text-secondary">Scegli come ricevere l'articolo per procedere al pagamento.</p>`}
      </div>` : ''}
      <div class="order-status-band order-status-${statoCls}">
        <span class="order-status-label">${etichettaStato(p)}</span>
        <span class="band-right">
          ${dataEventoTxt ? `<span class="text-sm">Data: ${dataEventoTxt}</span>` : ''}
          ${ritiroBandHtml}
          ${spedBandTxt}
        </span>
      </div>
    </div>`;
}

const FILTRO_STATI_ORDINE = [
  ['', 'Tutti gli stati'],
  ['attesa', 'In attesa'],
  ['pagato', 'Pagato'],
  ['fornitore', 'Ordine al fornitore'],
  ['pronto', 'Pronto per il ritiro'],
  ['finale', 'Ritirato'],
  ['azione', 'Azione richiesta']
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
  list.innerHTML = filtrati.length > 0 ? `<div class="cards-grid cards-grid-wide">` + filtrati.map(ordineCardHtml).join('') + `</div>`
    : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessun ordine</h2><p class="empty-state-description">Nessun ordine corrisponde ai filtri selezionati.</p><a href="#/" class="btn btn-default">Esplora le campagne</a></div>';
  const countEl = document.getElementById('ordini-count');
  if (countEl) countEl.textContent = `${filtrati.length} ${filtrati.length === 1 ? 'ordine' : 'ordini'}`;
}
