import { apiGet } from '../api.js';
import { API_URL } from '../constants.js';
import { Progress } from '../components/progress.js';
import { Badge } from '../components/badge.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../constants.js';

function imgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + path.replace(/^\//, '');
}

let homeCampaigns = [];

function campaignCardHtml(c) {
  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 1;
  const percentage = Math.round((qty / min) * 100);
  const base = parseFloat(c.prezzo_base) || 0;
  const curr = parseFloat(c.prezzo_corrente) || 0;
  const discount = base > 0 ? Math.round((1 - curr / base) * 100) : 0;
  const deadline = new Date(c.data_limite);
  const now = new Date();
  const daysLeft = Math.max(0, Math.ceil((deadline - now) / (1000 * 60 * 60 * 24)));

  return `
    <div class="card campaign-card" onclick="window.location.hash='#/campagne/${c.id}'">
      ${c.immagine
        ? `<div class="campaign-card-image" style="padding:0;overflow:hidden;"><img src="${imgUrl(c.immagine)}" alt="${c.prodotto || ''}" style="width:100%;height:100%;object-fit:contain;" /></div>`
        : `<div class="campaign-card-image" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--color-primary-light), var(--gray-100));">
        <svg width="64" height="64" fill="none" stroke="var(--color-primary)" viewBox="0 0 24 24" opacity="0.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
      </div>`}
      <div class="campaign-card-body">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-2);">
          <div class="campaign-card-title">${c.prodotto || 'Prodotto'}</div>
          ${discount > 0 ? `<span class="discount-badge">-${discount}%</span>` : ''}
        </div>
        <div class="campaign-card-supplier">${c.fornitore || 'Fornitore'}</div>
        <div class="campaign-card-progress">
          <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-1);">
            <span class="text-xs text-secondary">${qty} / ${min} pezzi</span>
            <span class="text-xs font-medium">${percentage}%</span>
          </div>
          ${Progress({ value: qty, max: min })}
        </div>
        <div class="campaign-card-meta">
          <div class="countdown">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            ${daysLeft} giorni rimanenti
          </div>
          ${Badge({ variant: STATI_CAMPAGNA_BADGES[c.stato] || 'secondary', children: STATI_CAMPAGNA_LABELS[c.stato] || c.stato })}
        </div>
        <div class="campaign-card-price">
          <span class="price-current">&euro;${curr.toFixed(2)}</span>
          <span class="price-original">&euro;${base.toFixed(2)}</span>
        </div>
      </div>
    </div>
  `;
}

let homeFiltroCategoria = '';

window.homeFiltraCampagne = function() {
  const q = (document.getElementById('search-campaigns')?.value || '').toLowerCase();
  const grid = document.getElementById('campaigns-grid');
  if (!grid) return;
  const filtrate = homeCampaigns.filter(c =>
    ((c.prodotto || '').toLowerCase().includes(q) || (c.fornitore || '').toLowerCase().includes(q)) &&
    (homeFiltroCategoria === '' || (homeFiltroCategoria === '__senza__' ? !(c.categoria) : (c.categoria || '') === homeFiltroCategoria)));
  grid.innerHTML = filtrate.map(campaignCardHtml).join('')
    || '<div class="empty-state"><p class="text-secondary">Nessuna campagna trovata.</p></div>';
};

window.homeCategoria = function(cat) {
  homeFiltroCategoria = cat;
  document.querySelectorAll('.campagne-cat-chip').forEach(ch =>
    ch.classList.toggle('active', ch.dataset.cat === cat));
  homeFiltraCampagne();
};

export async function HomePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/campagne');
    homeCampaigns = res.dati || [];
    homeFiltroCategoria = '';

    const categorie = [...new Set(homeCampaigns.map(c => c.categoria || '__senza__'))].sort((a, b) => {
      if (a === '__senza__') return 1;
      if (b === '__senza__') return -1;
      return a.localeCompare(b);
    });
    const chips = ['<button class="wishlist-tab campagne-cat-chip active" data-cat="" onclick="homeCategoria(\'\')">Tutte</button>']
      .concat(categorie.map(c => {
        const label = c === '__senza__' ? 'Senza categoria' : c;
        return `<button class="wishlist-tab campagne-cat-chip" data-cat="${c}" onclick="homeCategoria('${c.replace(/'/g, "\\'")}')">${label}</button>`;
      })).join('');

    content.innerHTML = `
      <div class="content-area">
        <div class="page-header">
          <h1>Campagne Attive</h1>
          <a href="#/proposte" class="btn btn-outline">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Proponi prodotto
          </a>
        </div>
        <div class="search-filter-bar">
          <div class="search-input-wrapper">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" autocomplete="off" aria-label="Cerca campagne" class="input" placeholder="Cerca campagne..." id="search-campaigns" oninput="homeFiltraCampagne()">
          </div>
        </div>
        <div class="wishlist-tabs" style="margin-bottom:var(--space-4);">${chips}</div>
        <div class="homepage-grid" id="campaigns-grid">
          ${homeCampaigns.map(campaignCardHtml).join('') || '<div class="empty-state"><p class="text-secondary">Nessuna campagna attiva al momento.</p></div>'}
        </div>
      </div>
    `;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore di caricamento</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
