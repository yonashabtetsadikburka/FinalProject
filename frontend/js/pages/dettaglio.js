import { getState } from '../state.js';
import { isAuthenticated } from '../auth.js';
import { apiGet, apiPost, apiDelete } from '../api.js';
import { API_URL } from '../constants.js';
import { Progress } from '../components/progress.js';
import { Badge } from '../components/badge.js';
import { Card } from '../components/card.js';
import { Avatar } from '../components/dropdown.js';
import { startCountdowns, formatCountdown } from '../countdown.js';

let currentIdColletta = null;
let currentCampagna = null;
let currentFornitore = null;
let currentRecensioni = { media: null, totale: 0, recensioni: [] };

/** Stelle con supporto mezze stelle (overlay a larghezza percentuale). */
function starsMedia(voto, size) {
  size = size || 'var(--text-base)';
  const pct = Math.max(0, Math.min(100, ((parseFloat(voto) || 0) / 5) * 100));
  return `<span class="stars-wrap" style="font-size:${size};"><span class="stars-bg">★★★★★</span><span class="stars-fg" style="width:${pct}%;">★★★★★</span></span>`;
}

/** Nome privacy: "Marco R." (solo nome se manca l'iniziale). */
function nomeRecensore(r) {
  const nome = (r.autore || 'Utente').trim();
  const ini = (r.cognome_iniziale || '').trim();
  return ini ? `${nome} ${ini}.` : nome;
}

function inizialiRecensore(r) {
  const nome = (r.autore || 'U').trim();
  const ini = (r.cognome_iniziale || '').trim();
  return (nome[0] || 'U') + (ini || '');
}

/** Sezione recensioni fornitore full-width: riepilogo + distribuzione + lista. */
function recensioniFornitoreHtml() {
  const rd = currentRecensioni;
  const totale = rd.totale || 0;
  if (totale === 0) {
    return Card({ children: `
      <div class="card-content">
        <h3 style="margin-bottom: var(--space-2);">Recensioni del fornitore</h3>
        <p class="text-secondary">Nessuna recensione ancora per questo fornitore.</p>
      </div>
    ` });
  }
  const lista = rd.recensioni || [];
  const dist = [5, 4, 3, 2, 1].map(v => {
    const n = lista.filter(r => r.voto === v).length;
    const pct = Math.round((n / totale) * 100);
    return { v, n, pct };
  });
  const distHtml = dist.map(d => `
    <div class="rec-dist-row">
      <span class="text-xs text-secondary" style="width:12px;">${d.v}</span>
      <span style="color:var(--color-warning);font-size:var(--text-xs);">★</span>
      <div class="rec-dist-bar"><div class="rec-dist-fill" style="width:${d.pct}%;"></div></div>
      <span class="text-xs text-secondary" style="width:36px;text-align:right;">${d.pct}%</span>
    </div>`).join('');
  const prime = lista.slice(0, 3).map(r => `
    <div class="rec-item">
      ${Avatar({ fallback: inizialiRecensore(r), size: 'sm' })}
      <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap;margin-bottom:var(--space-1);">
          <span class="font-medium text-sm">${nomeRecensore(r)}</span>
          ${starsMedia(r.voto, 'var(--text-sm)')}
          ${r.verificata ? Badge({ variant: 'success', children: 'Partecipante verificato' }) : ''}
        </div>
        ${r.testo ? `<p class="text-sm" style="margin-bottom:var(--space-1);">${r.testo}</p>` : ''}
        <div class="text-xs text-secondary">${new Date(r.data_creazione).toLocaleDateString('it-IT')}</div>
      </div>
    </div>`).join('');
  const fid = currentCampagna?.fornitore_id;
  return Card({ children: `
    <div class="card-content">
      <h3 style="margin-bottom: var(--space-4);">Recensioni del fornitore</h3>
      <div class="reviews-layout">
        <div class="reviews-summary">
          <div class="reviews-media">${rd.media}</div>
          ${starsMedia(rd.media, 'var(--text-lg)')}
          <div class="text-sm text-secondary" style="margin:var(--space-1) 0 var(--space-3);">${totale} ${totale === 1 ? 'recensione' : 'recensioni'}</div>
          ${distHtml}
        </div>
        <div class="reviews-list">
          ${prime}
          ${fid ? `<a href="#/fornitori/${fid}" class="btn btn-ghost btn-sm" style="margin-top:var(--space-3);">Vedi tutte le ${totale} recensioni</a>` : ''}
        </div>
      </div>
    </div>
  ` });
}

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
      <div class="detail-image detail-image-43 gallery-main" style="padding:0;overflow:hidden;cursor:zoom-in;position:relative;" onclick="apriImmagineCampagna(document.getElementById('gallery-main-img').src)">
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
  const testo = `Partecipa a "${c.prodotto || 'questa campagna'}" su BuyPool: \u20AC${curr.toFixed(2)} invece di \u20AC${base.toFixed(2)}! Mancano ${mancanti} pezzi all'obiettivo minimo.`;
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
  const sogliaRaggiunta = (qty / min) >= 1;
  const scaduta = Number.isFinite(new Date(c.data_limite).getTime()) && new Date(c.data_limite).getTime() < Date.now();
  const fallita = ['fallita', 'annullata'].includes(c.stato) || (scaduta && !sogliaRaggiunta);
  const soglia = sogliaRaggiunta || ['riuscita', 'ordine_pronto', 'ordine_fornitore', 'consegnata'].includes(c.stato);
  const attiva = !soglia && !fallita;
  const conclusaDettaglio = soglia || fallita;
  const percentage = Math.min(100, Math.round((qty / min) * 100));
  const base = parseFloat(c.prezzo_base) || 0;
  const curr = parseFloat(c.prezzo_corrente) || 0;
  const discount = base > 0 ? Math.round((1 - curr / base) * 100) : 0;
  const deadline = new Date(c.data_limite);
  const partecipato = c.mia_partecipazione !== null && c.mia_partecipazione !== undefined;
  const sospeso = getState().user?.stato === 'sospeso';
  const nomeProdotto = c.prodotto || 'Prodotto';
  const nomeFornitore = c.fornitore || 'Fornitore';
  const scadenzaTxt = deadline.toLocaleDateString('it-IT') + ' ore ' + deadline.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
  const pubblicataTxt = c.data_inizio ? new Date(c.data_inizio).toLocaleDateString('it-IT') : null;
  const microPrezzo = soglia
    ? 'al pezzo, con obiettivo attuale raggiunto'
    : (fallita ? 'al pezzo · campagna non riuscita' : 'al pezzo · Il prezzo finale dipende dall\u2019obiettivo raggiunto');
  const scaglioni = Array.isArray(c.scaglioni) ? c.scaglioni : [];
  let scaglioneAttivo = -1;
  scaglioni.forEach((s, i) => { if (qty >= s.soglia) scaglioneAttivo = i; });
  const scaglioniHtml = scaglioni.length > 0 ? `
    <div class="scaglioni-box">
      ${scaglioni.map((s, i) => `
        <div class="scaglione${i === scaglioneAttivo ? ' scaglione-attivo' : ''}">.
          <div class="scaglione-soglia">${s.soglia} pezzi</div>
          <div class="scaglione-prezzo">&euro;${s.prezzo.toFixed(2)}</div>
        </div>`).join('')}
    </div>` : '';
  const f = currentFornitore || {};
  const partnershipTxt = f.data_partnership ? new Date(f.data_partnership).toLocaleDateString('it-IT') : null;

  content.innerHTML = `
    <div class="content-area">
      <div style="margin-bottom: var(--space-4);">
        <a href="#/" class="btn btn-ghost btn-sm">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          Torna alle campagne
        </a>
      </div>
      ${!isAuthenticated() ? `
      <div class="guest-banner">
        <span>Stai visualizzando questa campagna come ospite</span>
        <button class="btn btn-ghost btn-sm" onclick="guestAccedi()">Accedi</button>
        <button class="btn btn-default btn-sm" onclick="guestRegistrati()">Registrati</button>
      </div>` : ''}
      <div class="detail-layout detail-layout-shop">
        <div class="detail-left-col">
          ${galleryHtml(c, nomeProdotto)}
        </div>
        <div class="detail-panel">
          ${Card({ children: `
            <div class="card-content">
              <div class="shop-title-row">
                <h1 class="shop-title">${nomeProdotto}</h1>
                <span class="countdown-box" data-urgency data-scadenza="${c.data_limite || ''}">
                  <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  ${conclusaDettaglio
                    ? '<span>Terminata</span>'
                    : `<span class="countdown-live" data-scadenza="${c.data_limite || ''}">${formatCountdown(c.data_limite) || '—'}</span>`}
                </span>
              </div>
              <div class="shop-supplier-row">
                <a href="#/fornitori/${c.fornitore_id}">${nomeFornitore}</a>
                ${c.fornitore_rating && c.fornitore_rating.totale > 0
                  ? `<span style="display:inline-flex;align-items:center;gap:4px;">${starsMedia(c.fornitore_rating.media, 'var(--text-sm)')} <span class="text-xs text-secondary">(${c.fornitore_rating.totale})</span></span>`
                  : ''}
                ${f.num_campagne ? `<span class="text-xs text-secondary">&middot; ${f.num_campagne} ${f.num_campagne === 1 ? 'campagna' : 'campagne'}</span>` : ''}
              </div>
              <div class="shop-price-row">
                <span class="shop-price">&euro;${curr.toFixed(2)}</span>
                <span class="price-original">&euro;${base.toFixed(2)}</span>
                ${discount > 0 ? `<span class="discount-badge">-${discount}%</span>` : ''}
              </div>
              <p class="text-xs text-secondary" style="margin-bottom:var(--space-4);">${microPrezzo}</p>
              ${scaglioniHtml}
              <div style="margin-bottom: var(--space-3);">
                <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-2);">
                  <span class="text-sm text-secondary">${qty} su ${min} pezzi per sbloccare questo prezzo</span>
                  <span class="text-sm font-medium">${percentage}%</span>
                </div>
                ${Progress({ value: qty, max: min })}
              </div>
              <div class="scade-row">
                ${pubblicataTxt ? `<span class="text-sm text-secondary">Pubblicata ${pubblicataTxt}</span>` : ''}
                <span class="text-sm text-secondary">Scade ${scadenzaTxt}</span>
              </div>
              ${fallita ? `
                <div style="margin-bottom:var(--space-3);">
                  ${Badge({ variant: 'destructive', children: 'Campagna non riuscita' })}
                  <p class="text-sm text-secondary" style="margin-top:var(--space-2);">L'obiettivo minimo non &egrave; stato raggiunto. Nessun pagamento &egrave; stato addebitato.</p>
                </div>
              ` : soglia ? `
                <div style="margin-bottom:var(--space-3);">
                  ${Badge({ variant: 'success', children: 'Obiettivo raggiunto, ordine in preparazione' })}
                  ${partecipato ? `<p class="text-sm" style="margin-top:var(--space-2);">Hai aderito con ${c.mia_partecipazione.quantita} ${c.mia_partecipazione.quantita === 1 ? 'pezzo' : 'pezzi'}. <a href="#/partecipazioni">Vai alle mie partecipazioni</a></p>` : ''}
                </div>
              ` : partecipato ? `
                <div style="margin-bottom:var(--space-3);">
                  ${Badge({ variant: 'info', children: `Hai aderito · ${c.mia_partecipazione.quantita} ${c.mia_partecipazione.quantita === 1 ? 'pezzo' : 'pezzi'}` })}
                  <div style="margin-top:var(--space-2);display:flex;gap:var(--space-2);flex-wrap:wrap;">
                    <a href="#/partecipazioni" class="btn btn-outline btn-sm">Vai alle mie partecipazioni</a>
                    ${c.mia_partecipazione.stato === 'prenotata' ? `<button class="btn btn-ghost btn-sm" style="color:var(--color-error);" onclick="handleAnnullaAdesione(${c.id})">Annulla adesione</button>` : ''}
                  </div>
                </div>
              ` : (sospeso ? `
                <div class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Account sospeso: non puoi aderire a nuove campagne.</div>
              ` : `
                <div style="margin-bottom:var(--space-3);">
                  <label class="text-sm text-secondary" style="display:block;margin-bottom:var(--space-2);">Quantit&agrave;</label>
                  <div style="display:flex;align-items:center;gap:var(--space-2);">
                    <button class="btn btn-ghost btn-sm" onclick="changeQty(-1)" style="width:36px;height:36px;padding:0;font-size:var(--text-lg);">-</button>
                    <input id="qty-input" type="number" value="1" min="1" max="99" style="width:60px;text-align:center;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);font-size:var(--text-base);" />
                    <button class="btn btn-ghost btn-sm" onclick="changeQty(1)" style="width:36px;height:36px;padding:0;font-size:var(--text-lg);">+</button>
                  </div>
                </div>
                <button class="btn btn-default w-full" onclick="handleAderisci()">Prenota</button>
                <p class="text-xs text-secondary" style="text-align:center;margin-top:var(--space-2);display:flex;align-items:center;justify-content:center;gap:4px;">
                  <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                  Paghi solo se l'obiettivo verr&agrave; raggiunto
                </p>
              `)}
              <button class="btn btn-outline w-full" style="margin-top:var(--space-2);" onclick="apriCondividi()">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                Condividi
              </button>
            </div>
          ` })}
        </div>
      </div>
      <div class="detail-bottom">
        <div class="detail-desc-col">
          ${Card({ children: `
            <div class="card-content">
              <h3 style="margin-bottom: var(--space-3);">Descrizione</h3>
              <p class="text-secondary">${c.descrizione_prodotto || 'Nessuna descrizione disponibile.'}</p>
            </div>
          ` })}
        </div>
        <div class="detail-supplier-col">
          ${Card({ children: `
            <div class="card-content supplier-box supplier-box-clickable" onclick="window.location.hash='#/fornitori/${c.fornitore_id}'" title="Vedi profilo fornitore">
              <div class="font-medium" style="margin-bottom:var(--space-1);color:var(--color-primary);">${nomeFornitore}</div>
              ${c.fornitore_rating && c.fornitore_rating.totale > 0
                ? `<div class="text-sm" style="display:flex;align-items:center;gap:4px;margin-bottom:var(--space-2);">${starsMedia(c.fornitore_rating.media, 'var(--text-sm)')} <span class="text-secondary">(${c.fornitore_rating.totale} ${c.fornitore_rating.totale === 1 ? 'recensione' : 'recensioni'})</span></div>`
                : `<div class="text-xs text-secondary" style="margin-bottom:var(--space-2);">Nessuna recensione</div>`}
              ${partnershipTxt ? `<div class="text-sm text-secondary" style="margin-bottom:var(--space-1);">Partner dal ${partnershipTxt}</div>` : ''}
              ${f.num_campagne ? `<div class="text-sm text-secondary" style="margin-bottom:var(--space-3);">${f.num_campagne} ${f.num_campagne === 1 ? 'campagna' : 'campagne'}</div>` : ''}
              <a href="#/fornitori/${c.fornitore_id}" class="btn btn-ghost btn-sm" onclick="event.stopPropagation()">Vedi tutte le sue campagne &rarr;</a>
            </div>
          ` })}
        </div>
      </div>
      <div class="detail-reviews-full">
        ${recensioniFornitoreHtml()}
      </div>
    </div>
  `;
}

function apriGateModal(quantita) {
  document.getElementById('gate-modal')?.remove();
  const pezzi = quantita === 1 ? 'pezzo' : 'pezzi';
  const modalHtml = `
    <div id="gate-modal" class="modal-overlay" onclick="if(event.target===this)document.getElementById('gate-modal').remove()">
      <div class="modal-content card" style="max-width:420px;width:92%;">
        <div class="card-content" style="text-align:center;">
          <div style="display:flex;justify-content:center;margin-bottom:var(--space-3);">
            <span class="how-circle" style="margin:0;width:48px;height:48px;">
              <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            </span>
          </div>
          <h3 style="margin-bottom:var(--space-2);">Crea un account per aderire</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-4);">Bastano 30 secondi. Torni subito qui, con la tua scelta di ${quantita} ${pezzi} gi&agrave; salvata.</p>
          <button class="btn btn-default w-full" onclick="gateRegistrati()">Registrati e continua</button>
          <button class="btn btn-ghost w-full" style="margin-top:var(--space-2);" onclick="gateAccedi()">Hai gi&agrave; un account? Accedi</button>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
}

window.gateRegistrati = function() {
  document.getElementById('gate-modal')?.remove();
  if (typeof window.guestRegistrati === 'function') window.guestRegistrati();
  else window.location.href = 'index.html#/register';
};

window.gateAccedi = function() {
  document.getElementById('gate-modal')?.remove();
  if (typeof window.guestAccedi === 'function') window.guestAccedi();
  else window.location.href = 'index.html#/login';
};

window.changeQty = function(delta) {
  const input = document.getElementById('qty-input');
  if (!input) return;
  let val = parseInt(input.value) || 1;
  val = Math.max(1, Math.min(99, val + delta));
  input.value = val;
};

window.handleAderisci = async function() {
  if (!currentIdColletta) return;
  const input = document.getElementById('qty-input');
  const quantita = input ? Math.max(1, Math.min(99, parseInt(input.value) || 1)) : 1;
  if (!isAuthenticated()) {
    try {
      sessionStorage.setItem('buypool_pending_adesione', JSON.stringify({ id: currentIdColletta, quantita }));
    } catch (e) {}
    apriGateModal(quantita);
    return;
  }
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
    shareToast.innerHTML = `<div class="toast-content"><div class="toast-title">Condividi la campagna sui social per raggiungere l'obiettivo minimo</div><div style="display:flex;gap:var(--space-2);margin-top:var(--space-2);"><button class="btn btn-default btn-sm" onclick="apriCondividi();this.closest('.toast').remove()">Condividi ora</button><button class="btn btn-ghost btn-sm" onclick="this.closest('.toast').remove()">Chiudi</button></div></div>`;
    document.querySelector('.toast-container')?.appendChild(shareToast) || document.body.appendChild(shareToast);
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

window.handleAnnullaAdesione = async function(campagnaId) {
  if (!confirm('Vuoi davvero annullare la tua adesione a questa campagna?')) return;
  try {
    await apiDelete(`/campagne/${campagnaId}/partecipazioni`);
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Adesione annullata</div></div>';
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

export async function DettaglioPage(params) {
  currentIdColletta = params.id;
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet(`/campagne/${params.id}`);
    currentCampagna = res.dati;
    const fid = currentCampagna.fornitore_id;
    currentFornitore = currentCampagna.fornitore_info || null;
    try {
      const recRes = fid ? await apiGet(`/fornitori/${fid}/recensioni`) : { dati: null };
      currentRecensioni = recRes.dati || { media: null, totale: 0, recensioni: [] };
    } catch (_) {
      currentRecensioni = { media: null, totale: 0, recensioni: [] };
    }
    renderDettaglio();
    try {
      const pend = JSON.parse(sessionStorage.getItem('buypool_pending_adesione') || 'null');
      if (pend && String(pend.id) === String(params.id)) {
        const qi = document.getElementById('qty-input');
        if (qi) qi.value = Math.max(1, Math.min(99, parseInt(pend.quantita) || 1));
        sessionStorage.removeItem('buypool_pending_adesione');
      }
    } catch (_) {}
    startCountdowns();
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p><a href="#/" class="btn btn-default">Torna alle campagne</a></div></div>`;
  }
}
