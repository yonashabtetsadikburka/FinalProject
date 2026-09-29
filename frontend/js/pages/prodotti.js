import { apiGet, apiPost, apiDelete } from '../api.js';
import { API_URL } from '../constants.js';
import { Badge } from '../components/badge.js';

function imgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + path.replace(/^\//, '');
}

let prodottiTutti = [];
let prodottiFiltroCategoria = '';

window.prodottiCategoria = function(cat) {
  prodottiFiltroCategoria = cat;
  document.querySelectorAll('.prodotti-cat-chip').forEach(ch =>
    ch.classList.toggle('active', ch.dataset.cat === cat));
  renderProdotti();
};

window.toggleLikeProdotto = async function(id, liked) {
  try {
    const res = liked
      ? await apiDelete(`/prodotti/${id}/like`)
      : await apiPost(`/prodotti/${id}/like`, {});
    const p = prodottiTutti.find(x => x.id === id);
    if (p) {
      p.mio_like = res.dati?.mio_like ?? !liked;
      p.mi_piace = res.dati?.mi_piace ?? p.mi_piace;
    }
    if (res.dati?.id_colletta) {
      prodottiTutti = prodottiTutti.filter(x => x.id !== id);
      const toast = document.createElement('div');
      toast.className = 'toast toast-success';
      toast.innerHTML = '<div class="toast-content"><div class="toast-title">Soglia raggiunta! Nuova campagna creata.</div></div>';
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 3500);
    }
    renderProdotti();
  } catch (err) {
    alert(err.message);
  }
};

window.apriImmagineProdotto = function(src) {
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

function renderProdotti() {
  const grid = document.getElementById('prodotti-grid');
  if (!grid) return;
  const lista = prodottiTutti.filter(p =>
    !p.ha_mai_avuto_campagna && (prodottiFiltroCategoria === '' || (prodottiFiltroCategoria === '__senza__' ? !(p.categoria) : (p.categoria || '') === prodottiFiltroCategoria)));
  grid.innerHTML = lista.map(p => `
    <div class="card" style="margin-bottom:var(--space-3);">
      <div class="card-content">
        <div style="display:flex;gap:var(--space-3);flex-wrap:wrap;">
          ${p.immagine
            ? `<button onclick="apriImmagineProdotto('${imgUrl(p.immagine)}')" style="flex:0 0 120px;padding:0;border:none;background:none;cursor:zoom-in;" aria-label="Ingrandisci immagine">
                 <img src="${imgUrl(p.immagine)}" alt="${p.nome}" style="width:120px;max-width:100%;height:120px;object-fit:cover;border-radius:var(--radius-md);" />
               </button>`
            : `<div class="supplier-logo" style="flex:0 0 120px;width:120px;max-width:100%;height:120px;font-size:var(--text-3xl);">${(p.nome || '?')[0]}</div>`}
          <div style="flex:1;min-width:220px;">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-1);">
              <div class="font-medium">${p.nome}</div>
              ${p.campagna_attiva ? Badge({ variant: 'success', children: 'In campagna' }) : ''}
            </div>
            <div class="text-xs text-secondary" style="margin-bottom:var(--space-1);">${p.fornitore || ''}${p.categoria ? ' &middot; ' + p.categoria : ''}</div>
            ${p.descrizione ? `<p class="text-sm text-secondary" style="margin-bottom:var(--space-2);">${p.descrizione}</p>` : ''}
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div><span class="font-bold">&euro;${parseFloat(p.prezzo_unitario).toFixed(2)}</span>
              <span class="text-xs text-secondary" style="text-decoration:line-through;">&euro;${parseFloat(p.prezzo_base).toFixed(2)}</span>
              <span class="text-xs text-secondary"> &middot; MOQ ${p.quantita_minima}</span></div>
          <button class="btn ${p.mio_like ? 'btn-default' : 'btn-outline'} btn-sm" onclick="toggleLikeProdotto(${p.id}, ${p.mio_like ? 'true' : 'false'})">
            <svg width="14" height="14" fill="${p.mio_like ? 'currentColor' : 'none'}" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            ${p.mi_piace || 0}
          </button>
        </div>
      </div>
    </div>
  </div>
</div>`).join('')
    || '<div class="empty-state"><p class="text-secondary">Nessun prodotto disponibile.</p></div>';
}

export async function ProdottiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/prodotti');
    prodottiTutti = res.dati?.prodotti || [];
    prodottiFiltroCategoria = '';
    const categorie = [...new Set(prodottiTutti.map(p => p.categoria || '__senza__'))].sort((a, b) => {
      if (a === '__senza__') return 1;
      if (b === '__senza__') return -1;
      return a.localeCompare(b);
    });
    const chips = ['<button class="wishlist-tab prodotti-cat-chip active" data-cat="" onclick="prodottiCategoria(\'\')">Tutte</button>']
      .concat(categorie.map(c => {
        const label = c === '__senza__' ? 'Senza categoria' : c;
        return `<button class="wishlist-tab prodotti-cat-chip" data-cat="${c}" onclick="prodottiCategoria('${c.replace(/'/g, "\\'")}')">${label}</button>`;
      })).join('');
    content.innerHTML = `
      <div class="content-area">
        <div class="page-header"><h1>Prodotti</h1></div>
        <div class="wishlist-tabs" style="margin-bottom:var(--space-4);">${chips}</div>
        <div class="homepage-grid" id="prodotti-grid"></div>
      </div>`;
    renderProdotti();
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
