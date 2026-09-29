import { apiGet } from '../api.js';
import { API_URL } from '../constants.js';
import { Progress } from '../components/progress.js';
import { startCountdowns, formatCountdown } from '../countdown.js';

function imgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + path.replace(/^\//, '');
}

let homeCampaigns = [];
const cardImgs = {};
let homeFiltroStato = 'in_corso'; // 'in_corso' | 'concluse'

function progressoGrezzo(c) {
  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 0;
  if (!Number.isFinite(qty) || !Number.isFinite(min) || min <= 0) return 0;
  return qty / min;
}

const haRaggiuntoSoglia = (c) => progressoGrezzo(c) >= 1;
const isScaduta = (c) => {
  const t = new Date(c.data_limite).getTime();
  return Number.isFinite(t) && t < Date.now();
};
const isInCorso = (c) => c.stato === 'in_corso' && !haRaggiuntoSoglia(c) && !isScaduta(c);
const isConclusa = (c) => haRaggiuntoSoglia(c) || isScaduta(c) || ['fallita', 'consegnata', 'annullata'].includes(c.stato);

function campaignCardHtml(c) {
  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 1;
  const percentage = Math.min(100, Math.round((qty / min) * 100));
  const base = parseFloat(c.prezzo_base) || 0;
  const curr = parseFloat(c.prezzo_corrente) || 0;
  const discount = base > 0 ? Math.round((1 - curr / base) * 100) : 0;
  const pubblicataTxt = c.data_inizio ? new Date(c.data_inizio).toLocaleDateString('it-IT') : null;
  const conclusa = isConclusa(c);

  const imgs = (c.immagini && c.immagini.length ? c.immagini.map(i => i.url) : (c.immagine ? [c.immagine] : []));
  cardImgs[c.id] = imgs;
  const imgBlock = imgs.length === 0
    ? `<div class="campaign-card-image" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--color-primary-light), var(--gray-100));">
        <svg width="64" height="64" fill="none" stroke="var(--color-primary)" viewBox="0 0 24 24" opacity="0.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
      </div>`
    : `<div class="campaign-card-image card-carousel" style="padding:0;overflow:hidden;position:relative;">
        <img id="card-img-${c.id}" src="${imgUrl(imgs[0])}" alt="${c.prodotto || ''}" data-idx="0" style="width:100%;height:100%;object-fit:contain;" />
        ${imgs.length > 1 ? `
        <button class="carousel-arrow carousel-prev" onclick="cardCarousel(${c.id}, -1, event)" aria-label="Precedente">&#10094;</button>
        <button class="carousel-arrow carousel-next" onclick="cardCarousel(${c.id}, 1, event)" aria-label="Successiva">&#10095;</button>
        <div class="carousel-dots" id="card-dots-${c.id}">${imgs.map((_, i) => `<span class="carousel-dot${i === 0 ? ' active' : ''}"></span>`).join('')}</div>` : ''}
      </div>`;

  return `
    <div class="card campaign-card" onclick="window.location.hash='#/campagne/${c.id}'">
      ${imgBlock}
      <div class="campaign-card-body">
        <div class="campaign-card-title">${c.prodotto || 'Prodotto'}</div>
        ${discount > 0 ? `<div style="margin-bottom: var(--space-2);"><span class="discount-badge">-${discount}% di sconto</span></div>` : ''}
        <div class="campaign-card-supplier">${c.fornitore || 'Fornitore'}</div>
        <div class="campaign-card-progress">
          <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-1);">
            <span class="text-xs text-secondary">Obiettivo (${qty}/${min})</span>
            <span class="text-xs font-medium">${percentage}%</span>
          </div>
          ${Progress({ value: qty, max: min })}
        </div>
        <div class="countdown countdown-row" style="margin-bottom: var(--space-3);">
          ${pubblicataTxt ? `<span class="text-sm pub-date">Pubblicata ${pubblicataTxt}</span>` : ''}
          <span class="countdown-right">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            ${conclusa
              ? '<span>Terminata</span>'
              : `<span class="text-sm">Scade tra&nbsp;</span><span class="countdown-live" data-scadenza="${c.data_limite || ''}">${formatCountdown(c.data_limite) || '—'}</span>`}
          </span>
        </div>
        <div class="campaign-card-price">
          <span class="price-current">&euro;${curr.toFixed(2)}</span>
          <span class="price-original">&euro;${base.toFixed(2)}</span>
        </div>
        <button class="btn ${conclusa ? 'btn-secondary' : 'btn-default'} w-full" style="margin-top: var(--space-3);" ${conclusa ? 'disabled' : `onclick="event.stopPropagation();window.location.hash='#/campagne/${c.id}'"`}>${conclusa ? 'Conclusa' : 'Prenota'}</button>
      </div>
    </div>
  `;
}

let homeFiltroCategoria = '';
let homeFiltroFornitore = '';

window.homeFiltraCampagne = function() {
  const q = (document.getElementById('search-campaigns')?.value || '').toLowerCase();
  const grid = document.getElementById('campaigns-grid');
  if (!grid) return;
  const filtrate = homeCampaigns.filter(c =>
    ((c.prodotto || '').toLowerCase().includes(q) || (c.fornitore || '').toLowerCase().includes(q)) &&
    (homeFiltroCategoria === '' || (homeFiltroCategoria === '__senza__' ? !(c.categoria) : (c.categoria || '') === homeFiltroCategoria)) &&
    (homeFiltroFornitore === '' || (c.fornitore || '') === homeFiltroFornitore) &&
    (homeFiltroStato === 'in_corso' ? isInCorso(c) : isConclusa(c)));
  const filtriAttivi = q.trim() !== '' || homeFiltroCategoria !== '' || homeFiltroFornitore !== '' || homeFiltroStato !== 'in_corso';
  grid.innerHTML = filtrate.map(campaignCardHtml).join('')
    || (filtriAttivi
      ? `<div class="empty-state"><p class="text-secondary">Nessuna campagna trovata.</p><p style="margin:var(--space-3) 0;">Non trovi il prodotto che desideri? Proponilo tu</p><a href="#/proposte" class="btn btn-default">Proponi prodotto</a></div>`
      : `<div class="empty-state"><p class="text-secondary">${homeFiltroStato === 'in_corso' ? 'Nessuna campagna in corso al momento.' : 'Nessuna campagna conclusa.'}</p></div>`);
  const countEl = document.getElementById('campaigns-count');
  if (countEl) countEl.textContent = `${filtrate.length} ${filtrate.length === 1 ? 'campagna' : 'campagne'}`;
  const resetEl = document.getElementById('filtri-reset');
  if (resetEl) resetEl.style.display = filtriAttivi ? '' : 'none';
};

window.homeFiltroCambiato = function() {
  homeFiltroStato = document.getElementById('filtro-stato')?.value || 'in_corso';
  homeFiltroCategoria = document.getElementById('filtro-categoria')?.value || '';
  homeFiltroFornitore = document.getElementById('filtro-fornitore')?.value || '';
  homeFiltraCampagne();
};

window.homeResetFiltri = function() {
  const q = document.getElementById('search-campaigns');
  if (q) q.value = '';
  homeFiltroStato = 'in_corso';
  homeFiltroCategoria = '';
  homeFiltroFornitore = '';
  const fs = document.getElementById('filtro-stato');
  if (fs) fs.value = 'in_corso';
  const fc = document.getElementById('filtro-categoria');
  if (fc) fc.value = '';
  const ff = document.getElementById('filtro-fornitore');
  if (ff) ff.value = '';
  homeFiltraCampagne();
};

window.cardCarousel = function(cid, dir, event) {
  if (event) event.stopPropagation();
  const imgs = cardImgs[cid] || [];
  if (imgs.length < 2) return;
  const el = document.getElementById('card-img-' + cid);
  if (!el) return;
  let idx = (parseInt(el.dataset.idx) || 0) + dir;
  idx = ((idx % imgs.length) + imgs.length) % imgs.length;
  el.dataset.idx = idx;
  el.src = imgUrl(imgs[idx]);
  document.querySelectorAll('#card-dots-' + cid + ' .carousel-dot').forEach((d, i) =>
    d.classList.toggle('active', i === idx));
};



function howItWorksHtml() {
  const steps = [
    { label: 'Partecipa', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>' },
    { label: 'Obiettivo raggiunto', icon: '<circle cx="12" cy="12" r="9" stroke-width="2"/><circle cx="12" cy="12" r="5" stroke-width="2"/><circle cx="12" cy="12" r="1.2" fill="currentColor" stroke="none"/>' },
    { label: 'Paghi', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>' },
    { label: 'Ordine inviato', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17.5" r="1.8" stroke-width="2"/><circle cx="17" cy="17.5" r="1.8" stroke-width="2"/>' },
    { label: 'Ritira il pacco', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>' }
  ];
  return `
    <section class="how-section" aria-label="Come funziona">
      <h2 class="how-title">Insieme si risparmia di più.</h2>
      <p class="how-sub">Unisciti insieme ad altri per raggiungere il prezzo all'ingrosso.</p>
      <ol class="how-steps">
        ${steps.map(s => `
        <li class="how-step">
          <span class="how-circle"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">${s.icon}</svg></span>
          <span class="how-label">${s.label}</span>
        </li>`).join('')}
      </ol>
      <hr class="how-divider" />
    </section>`;
}

export async function HomePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/campagne');
    homeCampaigns = res.dati || [];
    homeFiltroCategoria = '';
    homeFiltroFornitore = '';
    homeFiltroStato = 'in_corso';

    const categorie = [...new Set(homeCampaigns.map(c => c.categoria || '__senza__'))].sort((a, b) => {
      if (a === '__senza__') return 1;
      if (b === '__senza__') return -1;
      return a.localeCompare(b);
    });
    const catOptions = ['<option value="">Tutte le categorie</option>']
      .concat(categorie.map(c => {
        const label = c === '__senza__' ? 'Senza categoria' : c;
        const esc = String(c).replace(/"/g, '&quot;');
        return `<option value="${esc}">${label}</option>`;
      })).join('');
    const fornitori = [...new Set(homeCampaigns.map(c => c.fornitore || '').filter(Boolean))].sort((a, b) => a.localeCompare(b));
    const fornOptions = ['<option value="">Tutti i fornitori</option>']
      .concat(fornitori.map(f => {
        const esc = String(f).replace(/"/g, '&quot;');
        return `<option value="${esc}">${f}</option>`;
      })).join('');

    content.innerHTML = `
      <div class="content-area">
        ${howItWorksHtml()}
        <div class="search-filter-bar campagne-filter-bar">
          <div class="search-input-wrapper campagne-search">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" autocomplete="off" aria-label="Cerca campagne" class="input" placeholder="Cerca campagne..." id="search-campaigns" oninput="homeFiltraCampagne()">
          </div>
          <select id="filtro-stato" class="input campagne-select" aria-label="Stato campagne" onchange="homeFiltroCambiato()">
            <option value="in_corso" selected>In corso</option>
            <option value="concluse">Concluse</option>
          </select>
          <select id="filtro-categoria" class="input campagne-select" aria-label="Categoria" onchange="homeFiltroCambiato()">${catOptions}</select>
          <select id="filtro-fornitore" class="input campagne-select" aria-label="Fornitore" onchange="homeFiltroCambiato()">${fornOptions}</select>
          <button id="filtri-reset" class="btn btn-ghost btn-sm" onclick="homeResetFiltri()" style="display:none;flex-shrink:0;">Reset</button>
        </div>
        <div class="text-sm text-secondary" id="campaigns-count" style="margin-bottom:var(--space-3);"></div>
        <div class="homepage-grid" id="campaigns-grid">
          ${homeCampaigns.map(campaignCardHtml).join('') || '<div class="empty-state"><p class="text-secondary">Nessuna campagna in corso al momento.</p></div>'}
        </div>
      </div>
    `;
    homeFiltraCampagne();
    let homeExpireHandled = false;
    startCountdowns(() => {
      if (!homeExpireHandled && [...document.querySelectorAll('.countdown-live')].some(el => el.textContent === 'Terminata')) {
        homeExpireHandled = true;
        homeFiltraCampagne();
      }
    });
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore di caricamento</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
