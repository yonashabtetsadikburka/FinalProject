import { COLLETTE, PRODOTTI, FORNITORI } from '../mock.js';
import { getCollettaById } from '../mock.js';
import { Progress } from '../components/progress.js';
import { Badge } from '../components/badge.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../constants.js';

export function HomePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const campaigns = COLLETTE.map(c => getCollettaById(c.id)).filter(Boolean);

  const campaignCards = campaigns.map(c => {
    const percentage = Math.round((c.quantita_attuale / c.quantita_minima) * 100);
    const discount = Math.round((1 - c.prezzo_corrente / c.prezzo_base) * 100);
    const deadline = new Date(c.data_limite);
    const now = new Date();
    const daysLeft = Math.max(0, Math.ceil((deadline - now) / (1000 * 60 * 60 * 24)));

    return `
      <div class="card campaign-card" onclick="window.location.hash='#/campagne/${c.id}'">
        <div class="campaign-card-image" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--color-primary-light), var(--gray-100));">
          <svg width="64" height="64" fill="none" stroke="var(--color-primary)" viewBox="0 0 24 24" opacity="0.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        </div>
        <div class="campaign-card-body">
          <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-2);">
            <div class="campaign-card-title">${c.prodotto?.nome || 'Prodotto'}</div>
            <span class="discount-badge">-${discount}%</span>
          </div>
          <div class="campaign-card-supplier">${c.fornitore?.nome_azienda || 'Fornitore'}</div>
          <div class="campaign-card-progress">
            <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-1);">
              <span class="text-xs text-secondary">${c.quantita_attuale} / ${c.quantita_minima} pezzi</span>
              <span class="text-xs font-medium">${percentage}%</span>
            </div>
            ${Progress({ value: c.quantita_attuale, max: c.quantita_minima })}
          </div>
          <div class="campaign-card-meta">
            <div class="countdown">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              ${daysLeft} giorni rimanenti
            </div>
            ${Badge({ variant: STATI_CAMPAGNA_BADGES[c.stato] || 'secondary', children: STATI_CAMPAGNA_LABELS[c.stato] || c.stato })}
          </div>
          <div class="campaign-card-price">
            <span class="price-current">&euro;${c.prezzo_corrente.toFixed(2)}</span>
            <span class="price-original">&euro;${c.prezzo_base.toFixed(2)}</span>
          </div>
        </div>
      </div>
    `;
  }).join('');

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Campagne Attive</h1>
        <a href="#/wishlist" class="btn btn-outline">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Proponi prodotto
        </a>
      </div>
      <div class="search-filter-bar">
        <div class="search-input-wrapper">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="text" class="input" placeholder="Cerca campagne..." id="search-campaigns">
        </div>
        <div class="filter-tabs">
          <button class="filter-tab active" data-filter="all">Tutte</button>
          <button class="filter-tab" data-filter="elettronica">Elettronica</button>
          <button class="filter-tab" data-filter="sport">Sport</button>
          <button class="filter-tab" data-filter="tech">Tech</button>
        </div>
      </div>
      <div class="homepage-grid" id="campaigns-grid">
        ${campaignCards}
      </div>
    </div>
  `;

  document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
    });
  });
}
