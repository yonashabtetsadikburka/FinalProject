import { getState } from '../state.js';
import { apiGet, apiPost, apiDelete } from '../api.js';
import { API_URL } from '../constants.js';
import { Progress } from '../components/progress.js';
import { Badge } from '../components/badge.js';
import { Card } from '../components/card.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../constants.js';
import { showQrModal } from '../qr-modal.js';

let currentIdColletta = null;
let currentCampagna = null;
let costoSpedizione = 0;
let currentRecensioni = { media: null, totale: 0, mia: null, puo_recensire: false, recensioni: [] };
let recCampVoto = 0;

function recCampStars(voto, size) {
  size = size || 'var(--text-base)';
  let h = '';
  for (let i = 1; i <= 5; i++) {
    h += `<span style="font-size:${size};color:${i <= Math.round(voto) ? 'var(--color-warning)' : 'var(--color-border)'};">&#9733;</span>`;
  }
  return `<span style="white-space:nowrap;">${h}</span>`;
}

window.recCampSetVoto = function(v) {
  recCampVoto = v;
  document.querySelectorAll('.rec-camp-star').forEach((s, i) =>
    s.classList.toggle('active', i < v));
};

function recCampCardHtml() {
  const rd = currentRecensioni;
  const lista = (rd.recensioni || []).map(r => `
    <div style="padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border);">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-1);">
        <span class="font-medium text-sm">${r.autore || 'Utente'}</span>
        ${recCampStars(r.voto, 'var(--text-sm)')}
      </div>
      ${r.testo ? `<p class="text-sm" style="margin-bottom:var(--space-1);">${r.testo}</p>` : ''}
      <div class="text-xs text-secondary">${new Date(r.data_creazione).toLocaleDateString('it-IT')}</div>
    </div>`).join('') || '<p class="text-secondary">Nessuna recensione ancora.</p>';

  const form = rd.puo_recensire ? `
    <div style="margin-top:var(--space-4);border-top:1px solid var(--color-border);padding-top:var(--space-3);">
      <h4 style="font-size:var(--text-sm);font-weight:var(--font-semibold);margin-bottom:var(--space-2);">${rd.mia ? 'La tua recensione' : 'Lascia una recensione'}</h4>
      <div style="margin-bottom:var(--space-2);font-size:var(--text-2xl);cursor:pointer;">
        ${[1, 2, 3, 4, 5].map(i => `<span class="rec-camp-star${i <= recCampVoto ? ' active' : ''}" onclick="recCampSetVoto(${i})" style="color:var(--color-border);">&#9733;</span>`).join('')}
      </div>
      <textarea id="rec-camp-testo" class="input" rows="3" maxlength="1000" placeholder="Com'e andata con questo acquisto? (max 1000 caratteri)" style="height:auto;margin-bottom:var(--space-2);">${rd.mia ? (rd.mia.testo || '') : ''}</textarea>
      <div id="rec-camp-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
      <div style="display:flex;gap:var(--space-2);">
        <button class="btn btn-default btn-sm" onclick="recCampInvia()">${rd.mia ? 'Aggiorna' : 'Invia'}</button>
        ${rd.mia ? `<button class="btn btn-ghost btn-sm" onclick="recCampElimina(${rd.mia.id})">Elimina</button>` : ''}
      </div>
    </div>` : `
    <p class="text-xs text-secondary" style="margin-top:var(--space-3);">Potrai recensire questa campagna dopo aver ricevuto la consegna.</p>`;

  return Card({ children: `
    <div class="card-content">
      <h3 style="margin-bottom: var(--space-2);">Recensioni</h3>
      ${rd.totale > 0
        ? `<div style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:var(--space-3);">${recCampStars(rd.media, 'var(--text-xl)')} <span class="font-bold">${rd.media}</span> <span class="text-sm text-secondary">(${rd.totale} ${rd.totale === 1 ? 'recensione' : 'recensioni'})</span></div>`
        : ''}
      ${lista}
      ${form}
    </div>
  ` });
}

async function recCampRicarica() {
  try {
    const recRes = await apiGet(`/campagne/${currentIdColletta}/recensioni`);
    currentRecensioni = recRes.dati || { media: null, totale: 0, mia: null, puo_recensire: false, recensioni: [] };
    recCampVoto = (currentRecensioni.mia && currentRecensioni.mia.voto) || 0;
    renderDettaglio();
  } catch (err) {
    alert(err.message);
  }
}

window.recCampInvia = async function() {
  const errorEl = document.getElementById('rec-camp-error');
  errorEl.style.display = 'none';
  if (!recCampVoto || recCampVoto < 1 || recCampVoto > 5) {
    errorEl.textContent = 'Seleziona un voto da 1 a 5 stelle.';
    errorEl.style.display = 'block';
    return;
  }
  try {
    const testo = document.getElementById('rec-camp-testo')?.value.trim() || '';
    await apiPost(`/campagne/${currentIdColletta}/recensioni`, { voto: recCampVoto, testo });
    recCampRicarica();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

window.recCampElimina = async function(recId) {
  if (!confirm('Eliminare la tua recensione?')) return;
  try {
    await apiDelete(`/campagne/recensioni/${recId}`);
    recCampRicarica();
  } catch (err) {
    alert(err.message);
  }
};

window.scegliConsegnaDettaglio = async function(prenotazioneId, modalita) {
  try {
    await apiPost('/consegne/scelta', { prenotazione_id: prenotazioneId, modalita });
    const res = await apiGet(`/campagne/${currentIdColletta}`);
    currentCampagna = res.dati;
    renderDettaglio();
  } catch (err) {
    alert(err.message);
  }
};

function imgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + path.replace(/^\//, '');
}

window.apriImmagineCampagna = function(src) {
  if (!src) return;
  const modalHtml = `
    <div id="img-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:1100;padding:var(--space-4);" onclick="if(event.target===this)document.getElementById('img-modal').remove()">
      <div style="position:relative;max-width:90vw;max-height:85vh;">
        <img src="${src}" alt="Immagine prodotto" style="max-width:90vw;max-height:80vh;object-fit:contain;border-radius:var(--radius-md);" />
        <button class="btn btn-outline btn-sm" style="position:absolute;top:8px;right:8px;background:var(--color-white);" onclick="document.getElementById('img-modal').remove()">Chiudi</button>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
};

function galleryImgs(c) {
  if (c.immagini && c.immagini.length) return c.immagini.map(i => i.url);
  return c.immagine ? [c.immagine] : [];
}

function galleryHtml(c, nomeProdotto) {
  const imgs = galleryImgs(c);
  if (imgs.length === 0) {
    return `<div class="detail-image" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--color-primary-light), var(--gray-100));">
      <svg width="96" height="96" fill="none" stroke="var(--color-primary)" viewBox="0 0 24 24" opacity="0.4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
    </div>`;
  }
  const thumbs = imgs.length > 1 ? `
    <div class="gallery-thumbs">
      ${imgs.map((u, i) => `<img src="${imgUrl(u)}" alt="Miniatura ${i + 1}" data-gi="${i}" class="gallery-thumb${i === 0 ? ' active' : ''}" onclick="gallerySeleziona(${i})" />`).join('')}
    </div>` : '';
  const arrows = imgs.length > 1 ? `
    <button class="carousel-arrow carousel-prev gallery-arrow" onclick="event.stopPropagation();galleryMuovi(-1)" aria-label="Precedente">&#10094;</button>
    <button class="carousel-arrow carousel-next gallery-arrow" onclick="event.stopPropagation();galleryMuovi(1)" aria-label="Successiva">&#10095;</button>` : '';
  return `
    <div class="detail-gallery">
      <div class="detail-image gallery-main" style="padding:0;overflow:hidden;cursor:zoom-in;position:relative;" onclick="apriImmagineCampagna(document.getElementById('gallery-main-img').src)">
        <img id="gallery-main-img" src="${imgUrl(imgs[0])}" alt="${nomeProdotto}" data-idx="0" style="width:100%;height:100%;object-fit:contain;" />
        ${arrows}
      </div>
      ${thumbs}
    </div>`;
}

window.gallerySeleziona = function(idx) {
  const c = currentCampagna;
  const imgs = galleryImgs(c || {});
  if (!imgs[idx]) return;
  const main = document.getElementById('gallery-main-img');
  if (main) { main.src = imgUrl(imgs[idx]); main.dataset.idx = idx; }
  document.querySelectorAll('.gallery-thumb').forEach((t, i) =>
    t.classList.toggle('active', i === idx));
};

window.galleryMuovi = function(dir) {
  const c = currentCampagna;
  const imgs = galleryImgs(c || {});
  if (imgs.length < 2) return;
  const main = document.getElementById('gallery-main-img');
  const cur = main ? (parseInt(main.dataset.idx) || 0) : 0;
  const idx = ((cur + dir) % imgs.length + imgs.length) % imgs.length;
  gallerySeleziona(idx);
};

function condividiDati() {
  const c = currentCampagna || {};
  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 1;
  const mancanti = Math.max(0, min - qty);
  const curr = parseFloat(c.prezzo_corrente) || 0;
  const base = parseFloat(c.prezzo_base) || 0;
  const url = window.location.origin + window.location.pathname + '#/campagne/' + (c.id || currentIdColletta);
  const testo = `Partecipa a "${c.prodotto || 'questa campagna'}" su BuyPool: \u20AC${curr.toFixed(2)} invece di \u20AC${base.toFixed(2)}! Mancano ${mancanti} pezzi alla soglia minima.`;
  return { url, testo };
}

window.apriCondividi = function() {
  document.getElementById('condividi-modal')?.remove();
  const { url, testo } = condividiDati();
  const eu = encodeURIComponent(url);
  const et = encodeURIComponent(testo);
  const nativa = (navigator.share)
    ? `<button class="btn btn-default w-full" onclick="condividiNativa()">Condividi...</button>` : '';
  const modalHtml = `
    <div id="condividi-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1100;padding:var(--space-4);" onclick="if(event.target===this)document.getElementById('condividi-modal').remove()">
      <div class="modal-content card" style="max-width:420px;width:100%;">
        <div class="card-content">
          <h3 style="margin-bottom:var(--space-3);">Condividi la campagna</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-4);">Aiutaci a raggiungere la quantita minima: condividi con i tuoi amici!</p>
          <div style="display:flex;flex-direction:column;gap:var(--space-2);">
            ${nativa}
            <a class="btn btn-outline w-full" href="https://wa.me/?text=${et}%20${eu}" target="_blank" rel="noopener">WhatsApp</a>
            <a class="btn btn-outline w-full" href="https://www.facebook.com/sharer/sharer.php?u=${eu}" target="_blank" rel="noopener">Facebook</a>
            <a class="btn btn-outline w-full" href="https://twitter.com/intent/tweet?text=${et}&url=${eu}" target="_blank" rel="noopener">X</a>
            <a class="btn btn-outline w-full" href="https://t.me/share/url?url=${eu}&text=${et}" target="_blank" rel="noopener">Telegram</a>
            <button class="btn btn-outline w-full" onclick="copiaLinkCondividi()">Copia link</button>
            <button class="btn btn-ghost w-full" onclick="document.getElementById('condividi-modal').remove()">Chiudi</button>
          </div>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
};

window.condividiNativa = async function() {
  const { url, testo } = condividiDati();
  try {
    await navigator.share({ title: 'BuyPool', text: testo, url });
  } catch (e) { /* annullato dall'utente */ }
  document.getElementById('condividi-modal')?.remove();
};

window.copiaLinkCondividi = async function() {
  const { url } = condividiDati();
  try {
    await navigator.clipboard.writeText(url);
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Link copiato!</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
  } catch (e) {
    prompt('Copia il link:', url);
  }
  document.getElementById('condividi-modal')?.remove();
};

function renderDettaglio() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const c = currentCampagna;
  if (!c) {
    content.innerHTML = '<div class="empty-state"><h2>Campagna non trovata</h2><a href="#/" class="btn btn-default">Torna alle campagne</a></div>';
    return;
  }

  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 1;
  const percentage = Math.min(100, Math.round((qty / min) * 100));
  const base = parseFloat(c.prezzo_base) || 0;
  const curr = parseFloat(c.prezzo_corrente) || 0;
  const discount = base > 0 ? Math.round((1 - curr / base) * 100) : 0;
  const deadline = new Date(c.data_limite);
  const now = new Date();
  const daysLeft = Math.max(0, Math.ceil((deadline - now) / (1000 * 60 * 60 * 24)));
  const partecipato = c.mia_partecipazione !== null;
  const statoPrenotazione = c.mia_partecipazione?.stato || null;
  const sospeso = getState().user?.stato === 'sospeso';
  const devePagare = (c.stato === 'ordine_pronto' || c.stato === 'ordine_fornitore') && partecipato && statoPrenotazione === 'confermata';
  const haPagato = partecipato && (statoPrenotazione === 'pagata');
  const ritirato = c.mia_partecipazione?.stato_qr === 'scansionato';
  const spedizione = c.mia_partecipazione?.consegna_modalita === 'consegna_domicilio';
  const haScelta = !!c.mia_partecipazione?.consegna_modalita;
  const spedita = c.mia_partecipazione?.consegna_stato === 'spedita';
  const devePagareEff = devePagare && haScelta;
  const importoSped = spedizione ? parseFloat(c.mia_partecipazione?.importo_consegna || costoSpedizione) : 0;
  const nomeProdotto = c.prodotto || 'Prodotto';
  const nomeFornitore = c.fornitore || 'Fornitore';
  const scadenzaTxt = deadline.toLocaleDateString('it-IT') + ' ore ' + deadline.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
  const pubblicataTxt = c.data_inizio ? new Date(c.data_inizio).toLocaleDateString('it-IT') : null;

  content.innerHTML = `
    <div class="content-area">
      <div style="margin-bottom: var(--space-4);">
        <a href="#/" class="btn btn-ghost btn-sm">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          Torna alle campagne
        </a>
      </div>
      <div class="detail-layout">
        <div class="detail-main">
          ${galleryHtml(c, nomeProdotto)}
          ${Card({ children: `
            <div class="card-content">
              <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-3);">
                <h2 style="font-size: var(--text-2xl); font-weight: var(--font-bold);">${nomeProdotto}</h2>
                ${discount > 0 ? `<span class="discount-badge" style="font-size: var(--text-sm); padding: 4px 8px;">-${discount}%</span>` : ''}
              </div>
              <p class="text-secondary" style="margin-bottom: var(--space-4);">${c.descrizione_prodotto || ''}</p>
              <div style="margin-bottom: var(--space-4);">
                <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-2);">
                  <span class="text-sm text-secondary">Avanzamento</span>
                  <span class="text-sm font-medium">${qty} / ${min} pezzi (${percentage}%)</span>
                </div>
                ${Progress({ value: qty, max: min })}
              </div>
              <div style="margin-bottom: var(--space-4);">
                <div class="countdown" style="font-size: var(--text-base);">
                  <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  ${daysLeft} giorni rimanenti
                </div>
                <div class="text-sm text-secondary" style="margin-top:var(--space-1);">Scade: ${scadenzaTxt}</div>
                ${pubblicataTxt ? `<div class="text-sm text-secondary" style="margin-top:2px;">Pubblicata il ${pubblicataTxt}</div>` : ''}
              </div>
            </div>
          ` })}
          ${recCampCardHtml()}
        </div>
        <div class="detail-sidebar">
          ${Card({ children: `
            <div class="card-content">
              <h3 style="margin-bottom: var(--space-4);">Riepilogo</h3>
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">Prezzo scontato</span>
                <span class="font-bold text-primary" style="font-size: var(--text-xl);">&euro;${curr.toFixed(2)}</span>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">Prezzo di listino</span>
                <span class="text-sm" style="text-decoration: line-through;">&euro;${base.toFixed(2)}</span>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">Scadenza</span>
                <span class="text-sm font-medium">${scadenzaTxt}</span>
              </div>
              ${pubblicataTxt ? `
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">Pubblicata il</span>
                <span class="text-sm font-medium">${pubblicataTxt}</span>
              </div>` : ''}
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">Quantita Minima</span>
                <span class="text-sm font-medium">${min} pezzi</span>
              </div>
              ${devePagareEff ? `
                <button class="btn btn-default w-full" onclick="handlePaga(${c.mia_partecipazione.id})">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                  Paga ora — &euro;${(() => { const q = c.mia_partecipazione.quantita; const prezzo = parseFloat(c.prezzo_corrente)||0; const comm = parseFloat(c.percentuale_commissione)||10; return (prezzo * q * (1 + comm/100) + importoSped).toFixed(2); })()}
                </button>
              ` : ritirato ? `
                <button class="btn btn-secondary w-full" disabled style="opacity: 1;">
                  Articolo ritirato
                </button>
              ` : (haPagato && spedizione) ? `
                ${spedita && c.mia_partecipazione?.consegna_stato !== 'consegnata' ? `
                  <button class="btn btn-default w-full" onclick="confermaRicezioneDettaglio(${c.mia_partecipazione.id_consegna})">
                    Conferma ricezione
                  </button>
                ` : c.mia_partecipazione?.consegna_stato === 'consegnata' ? `
                  <button class="btn btn-secondary w-full" disabled style="opacity: 1;">
                    Ricevuto
                  </button>
                ` : `
                  <button class="btn btn-secondary w-full" disabled style="opacity: 1;">In attesa di spedizione</button>
                `}
              ` : haPagato ? `
                <button class="btn btn-default w-full" onclick="showQrFromDettaglio(${c.mia_partecipazione.id})">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                  Mostra QR Code
                </button>
              ` : (partecipato && statoPrenotazione === 'confermata') ? `
                <div style="margin-bottom:var(--space-3);">
                  <div class="text-sm text-secondary" style="margin-bottom:var(--space-2);">Come vuoi ricevere l'articolo?</div>
                  <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
                    <button class="btn ${!spedizione ? 'btn-default' : 'btn-outline'} btn-sm" onclick="scegliConsegnaDettaglio(${c.mia_partecipazione.id}, 'ritiro_sede')">Ritiro in sede (gratis)</button>
                    <button class="btn ${spedizione ? 'btn-default' : 'btn-outline'} btn-sm" onclick="scegliConsegnaDettaglio(${c.mia_partecipazione.id}, 'consegna_domicilio')">Spedizione (+&euro;${costoSpedizione.toFixed(2)})</button>
                  </div>
                  ${spedizione ? `<div class="text-xs text-secondary" style="margin-top:var(--space-1);">L'articolo verrà spedito all'indirizzo del tuo Profilo.</div>` : ''}
                </div>
              ` : partecipato ? `
                <button class="btn btn-secondary w-full" disabled style="opacity: 1;">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                  Partecipato — In attesa
                </button>
                ${statoPrenotazione === 'prenotata' && ['in_corso', 'riuscita'].includes(c.stato) ? `
                  <button class="btn btn-outline btn-sm w-full" style="margin-top:var(--space-2);color:var(--color-error);border-color:var(--color-error);" onclick="handleAnnullaPartecipazione(${c.id})">
                    Annulla partecipazione
                  </button>
                ` : ''}
              ` : c.stato === 'in_corso' ? (sospeso ? `
                <div class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Account sospeso: non puoi partecipare a nuove campagne.</div>
              ` : `
                <div style="margin-bottom:var(--space-3);">
                  <label class="text-sm text-secondary" style="display:block;margin-bottom:var(--space-2);">Quantita</label>
                  <div style="display:flex;align-items:center;gap:var(--space-2);">
                    <button class="btn btn-ghost btn-sm" onclick="changeQty(-1)" style="width:36px;height:36px;padding:0;font-size:var(--text-lg);">-</button>
                    <input id="qty-input" type="number" value="1" min="1" max="99" style="width:60px;text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);font-size:var(--text-base);" />
                    <button class="btn btn-ghost btn-sm" onclick="changeQty(1)" style="width:36px;height:36px;padding:0;font-size:var(--text-lg);">+</button>
                  </div>
                </div>
                <button class="btn btn-default w-full" onclick="handlePartecipa()">Partecipa ora</button>
              `) : `
                <button class="btn btn-secondary w-full" disabled>Campagna non attiva</button>
              `}
              <button class="btn btn-outline w-full" style="margin-top:var(--space-2);" onclick="apriCondividi()">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                Condividi
              </button>
            </div>
          ` })}
          ${Card({ children: `
            <div class="card-content">
              <h3 style="margin-bottom: var(--space-3);">Fornitore</h3>
              <div style="display: flex; align-items: center; gap: var(--space-3);cursor:pointer;" onclick="window.location.hash='#/fornitori/${c.fornitore_id}'" title="Vedi profilo fornitore">
                <div class="supplier-logo" style="width: 48px; height: 48px; font-size: var(--text-lg);">
                  ${(nomeFornitore)[0]}
                </div>
                <div>
                  <div class="font-medium" style="color:var(--color-primary);">${nomeFornitore}</div>
                  ${c.fornitore_rating && c.fornitore_rating.totale > 0
                    ? `<div class="text-sm" style="color:var(--color-warning);">&#9733; ${c.fornitore_rating.media} <span class="text-secondary">(${c.fornitore_rating.totale} ${c.fornitore_rating.totale === 1 ? 'recensione' : 'recensioni'})</span></div>`
                    : `<div class="text-xs text-secondary">Nessuna recensione</div>`}
                </div>
              </div>
            </div>
          ` })}
        </div>
      </div>
    </div>
  `;
}

window.changeQty = function(delta) {
  const input = document.getElementById('qty-input');
  if (!input) return;
  let val = parseInt(input.value) || 1;
  val = Math.max(1, Math.min(99, val + delta));
  input.value = val;
};

window.handlePartecipa = async function() {
  if (!currentIdColletta) return;
  const input = document.getElementById('qty-input');
  const quantita = input ? parseInt(input.value) || 1 : 1;
  try {
    const res = await apiPost(`/campagne/${currentIdColletta}/partecipazioni`, { quantita });
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Partecipazione confermata!</div><div class="toast-description">${res.dati?.messaggio || 'Nessun addebito ora.'}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
    const dettaglio = await apiGet(`/campagne/${currentIdColletta}`);
    currentCampagna = dettaglio.dati;
    renderDettaglio();
    const shareToast = document.createElement('div');
    shareToast.className = 'toast toast-success';
    shareToast.innerHTML = `<div class="toast-content"><div class="toast-title">Condividi la campagna sui social per raggiungere la soglia minima</div><div style="display:flex;gap:var(--space-2);margin-top:var(--space-2);"><button class="btn btn-default btn-sm" onclick="apriCondividi();this.closest('.toast').remove()">Condividi ora</button><button class="btn btn-ghost btn-sm" onclick="this.closest('.toast').remove()">Chiudi</button></div></div>`;
    document.querySelector('.toast-container')?.appendChild(shareToast) || document.body.appendChild(shareToast);
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

window.handleAnnullaPartecipazione = async function(campagnaId) {
  if (!confirm('Vuoi davvero annullare la tua partecipazione a questa campagna?')) return;
  try {
    await apiDelete(`/campagne/${campagnaId}/partecipazioni`);
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Partecipazione annullata</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
    const dettaglio = await apiGet(`/campagne/${campagnaId}`);
    currentCampagna = dettaglio.dati;
    renderDettaglio();
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

window.handlePaga = async function(prenotazioneId) {
  try {
    const res = await apiPost('/pagamento/checkout', { prenotazione_id: prenotazioneId });
    const dati = res.dati;
    if (dati?.checkout_url) {
      window.location.href = dati.checkout_url;
    }
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore pagamento</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

window.confermaRicezioneDettaglio = async function(consegnaId) {
  if (!confirm('Confermi di aver ricevuto il pacco?')) return;
  try {
    await apiPost(`/consegne/${consegnaId}/conferma-ricezione`, {});
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Ricezione confermata</div><div class="toast-description">Grazie per il tuo acquisto!</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
    renderDettaglio();
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

window.showQrFromDettaglio = function(prenotazioneId) {
  showQrModal(prenotazioneId);
};

export async function DettaglioPage(params) {
  currentIdColletta = params.id;
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const [res, costoRes, recRes] = await Promise.all([
      apiGet(`/campagne/${params.id}`),
      apiGet('/consegne/costo').catch(() => ({ dati: { costo_spedizione: 0 } })),
      apiGet(`/campagne/${params.id}/recensioni`).catch(() => ({ dati: null }))
    ]);
    currentCampagna = res.dati;
    costoSpedizione = parseFloat(costoRes.dati?.costo_spedizione) || 0;
    currentRecensioni = recRes.dati || { media: null, totale: 0, mia: null, puo_recensire: false, recensioni: [] };
    recCampVoto = (currentRecensioni.mia && currentRecensioni.mia.voto) || 0;
    renderDettaglio();
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p><a href="#/" class="btn btn-default">Torna alle campagne</a></div></div>`;
  }
}
