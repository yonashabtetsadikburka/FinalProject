import { apiGet } from '../api.js';

let fornitoriTutti = [];
let filtroCategoria = '';

window.fornitoriFiltra = function() {
  const q = (document.getElementById('search-fornitori')?.value || '').toLowerCase();
  const grid = document.getElementById('fornitori-grid');
  if (!grid) return;
  grid.innerHTML = fornitoriHtml(fornitoriTutti.filter(f =>
    ((f.nome || '').toLowerCase().includes(q)) &&
    (filtroCategoria === '' || (filtroCategoria === '__senza__' ? !(f.categoria) : (f.categoria || '') === filtroCategoria))
  ));
};

window.fornitoriCategoria = function(cat) {
  filtroCategoria = cat;
  document.querySelectorAll('.fornitori-cat-chip').forEach(c =>
    c.classList.toggle('active', c.dataset.cat === cat));
  fornitoriFiltra();
};

function fornitoreCard(f, i) {
  const position = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : '';
  return `
    <div class="card supplier-card" style="cursor: pointer;" onclick="window.location.hash='#/fornitori/${f.id}'">
      <div class="supplier-logo">${f.nome[0]}</div>
      <div class="supplier-info">
        <div class="supplier-name">${position} ${f.nome}</div>
        <div class="supplier-meta">
          <span class="text-sm text-secondary">${f.num_campagne || 0} campagne${f.categoria ? ' &middot; ' + f.categoria : ''}</span>
          <div class="trust-score">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            Trust: ${f.trust_score}/100
          </div>
        </div>
      </div>
    </div>
  `;
}

function fornitoriHtml(lista) {
  return lista.map(fornitoreCard).join('')
    || '<div class="empty-state"><p class="text-secondary">Nessun fornitore trovato.</p></div>';
}

export async function FornitoriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/fornitori');
    fornitoriTutti = res.dati || [];
    filtroCategoria = '';

    const categorie = [...new Set(fornitoriTutti.map(f => f.categoria || '__senza__'))].sort((a, b) => {
      if (a === '__senza__') return 1;
      if (b === '__senza__') return -1;
      return a.localeCompare(b);
    });
    const chipLabel = c => c === '__senza__' ? 'Senza categoria' : c;
    const chips = ['<button class="wishlist-tab fornitori-cat-chip active" data-cat="" onclick="fornitoriCategoria(\'\')">Tutte</button>']
      .concat(categorie.map(c =>
        `<button class="wishlist-tab fornitori-cat-chip" data-cat="${c}" onclick="fornitoriCategoria('${c.replace(/'/g, "\\'")}')">${chipLabel(c)}</button>`))
      .join('');

    content.innerHTML = `
      <div class="content-area">
        <div class="page-header"><h1>Fornitori</h1></div>
        <div class="search-filter-bar">
          <div class="search-input-wrapper">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" autocomplete="off" aria-label="Cerca fornitori" class="input" placeholder="Cerca fornitori..." id="search-fornitori" oninput="fornitoriFiltra()">
          </div>
        </div>
        <div class="wishlist-tabs" style="margin-bottom:var(--space-4);">${chips}</div>
        <div class="supplier-list" id="fornitori-grid">${fornitoriHtml(fornitoriTutti)}</div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
