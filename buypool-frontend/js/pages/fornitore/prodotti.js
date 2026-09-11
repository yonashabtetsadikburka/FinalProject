import { apiGet, apiPost, apiPut, apiDelete } from '../../api.js';
import { Table } from '../../components/table.js';
import { showToast } from '../../components/toast.js';

let prodottiAttuali = [];
let categorie = [];

function immaginePrincipale(prodotto) {
  const img = (prodotto.immagini || []).find(i => i.principale) || (prodotto.immagini || [])[0];
  return img ? `
    <img src="${img.url}" alt="${prodotto.nome || ''}" style="width:40px;height:40px;object-fit:cover;border-radius:var(--radius-sm);" onerror="this.style.display='none'">
  ` : `
    <div style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:var(--color-bg-secondary);border-radius:var(--radius-sm);color:var(--color-text-muted);">
      <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
    </div>
  `;
}

function statoBadge(stato) {
  if (stato === 'attivo') return '<span class="badge badge-success" style="text-transform: capitalize;">attivo</span>';
  return '<span class="badge badge-default" style="text-transform: capitalize;">archiviato</span>';
}

export async function FornitoreProdottiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>I miei prodotti</h1>
        <button class="btn btn-default" onclick="aproNuovoProdotto()">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          <span>Nuovo prodotto</span>
        </button>
      </div>
      <div class="card">
        <div class="card-content">
          <div id="fornitore-prodotti-table">
            <p class="text-secondary">Caricamento...</p>
          </div>
        </div>
      </div>
    </div>
  `;

  try {
    const [prodottiRes, categorieRes] = await Promise.all([apiGet('/fornitore/prodotti'), apiGet('/categorie')]);
    prodottiAttuali = prodottiRes.dati || [];
    categorie = categorieRes.dati || [];
    renderTabella();
  } catch (e) {
    document.getElementById('fornitore-prodotti-table').innerHTML =
      `<p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p>`;
  }
}

function renderTabella() {
  const rows = prodottiAttuali.map(p => ({
    prodotto: `<div style="display:flex;align-items:center;gap:var(--space-3);">
        ${immaginePrincipale(p)}
        <div>
          <div class="font-medium">${p.nome || '—'}</div>
          ${p.descrizione ? `<div class="text-sm text-secondary" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${p.descrizione}</div>` : ''}
        </div>
      </div>`,
    categoria: p.categoria?.nome || '<span class="text-secondary">—</span>',
    prezzo: `<span class="text-sm">&euro;${Number(p.prezzo_unitario || 0).toFixed(2)}</span>`,
    quantita: `<span class="text-sm">${p.quantita_minima || 0}</span>`,
    campagne: `<span class="text-sm">${(p.collette || []).length}</span>`,
    stato: statoBadge(p.stato),
    azioni: `
      <div style="display:flex;gap:var(--space-2);">
        <button class="btn btn-outline btn-sm" onclick="modificaProdotto(${p.id})">Modifica</button>
        <button class="btn btn-ghost btn-sm" onclick="cambiaStatoProdotto(${p.id}, '${p.stato === 'attivo' ? 'archiviato' : 'attivo'}')">${p.stato === 'attivo' ? 'Archivia' : 'Attiva'}</button>
        <button class="btn btn-ghost btn-sm" style="color:var(--color-error)" onclick="eliminaProdotto(${p.id})">Elimina</button>
      </div>
    `
  }));

  document.getElementById('fornitore-prodotti-table').innerHTML = Table({
    columns: [
      { key: 'prodotto', label: 'Prodotto' },
      { key: 'categoria', label: 'Categoria' },
      { key: 'prezzo', label: 'Prezzo unitario' },
      { key: 'quantita', label: 'Q.ta minima' },
      { key: 'campagne', label: 'Campagne' },
      { key: 'stato', label: 'Stato' },
      { key: 'azioni', label: 'Azioni' }
    ],
    rows
  });
}

function opzioniCategorie(selezionata) {
  return categorie.map(c =>
    `<option value="${c.id}" ${Number(selezionata) === Number(c.id) ? 'selected' : ''}>${c.nome}</option>`
  ).join('');
}

function modalProdotto(prodotto) {
  const p = prodotto || {};
  document.body.insertAdjacentHTML('beforeend', `
    <div id="prodotto-modal" class="modal-overlay" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;"
         onclick="if(event.target===this) chiudiProdottoModal()">
      <div class="modal-content card" style="max-width: 520px; width: 90%;">
        <div class="card-content">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4);">
            <h3 style="margin: 0;">${p.id ? 'Modifica prodotto' : 'Nuovo prodotto'}</h3>
            <button class="modal-close" onclick="chiudiProdottoModal()">
              <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <form id="prodotto-form">
            <div class="input-group">
              <label class="input-label">Nome *</label>
              <input type="text" class="input" name="nome" value="${p.nome || ''}" required maxlength="200">
            </div>
            <div class="input-group">
              <label class="input-label">Descrizione</label>
              <textarea class="input" name="descrizione" rows="3">${p.descrizione || ''}</textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3);">
              <div class="input-group">
                <label class="input-label">Prezzo unitario (&euro;) *</label>
                <input type="number" class="input" name="prezzo_unitario" step="0.01" min="0.01" value="${p.prezzo_unitario ?? ''}" required>
              </div>
              <div class="input-group">
                <label class="input-label">Quantit&agrave; minima *</label>
                <input type="number" class="input" name="quantita_minima" min="1" step="1" value="${p.quantita_minima ?? 1}" required>
              </div>
            </div>
            <div class="input-group">
              <label class="input-label">Categoria</label>
              <select class="input" name="id_categoria">
                <option value="">Nessuna</option>
                ${opzioniCategorie(p.id_categoria)}
              </select>
            </div>
            <div class="input-group">
              <label class="input-label">Immagine principale (URL)</label>
              <input type="url" class="input" name="immagine_principale" value="${(p.immagini || []).find(i => i.principale)?.url || (p.immagini || [])[0]?.url || ''}" placeholder="https://...">
            </div>
            <div id="prodotto-error" style="color: var(--color-error); font-size: var(--text-sm); display: none; margin-bottom: var(--space-3);"></div>
            <button type="submit" class="btn btn-default w-full">${p.id ? 'Salva modifiche' : 'Crea prodotto'}</button>
          </form>
        </div>
      </div>
    </div>
  `);

  document.getElementById('prodotto-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('prodotto-error');
    errorEl.style.display = 'none';

    const form = e.target;
    const payload = {
      nome: form.nome.value.trim(),
      descrizione: form.descrizione.value.trim() || null,
      prezzo_unitario: Number(form.prezzo_unitario.value),
      quantita_minima: Number(form.quantita_minima.value),
      id_categoria: form.id_categoria.value ? Number(form.id_categoria.value) : null
    };

    const urlImmagine = form.immagine_principale.value.trim();
    if (urlImmagine) {
      payload.immagini = [{ url: urlImmagine, ordine: 0, principale: true }];
    } else if (p.id) {
      payload.immagini = [];
    }

    try {
      if (p.id) {
        await apiPut(`/fornitore/prodotti/${p.id}`, payload);
      } else {
        await apiPost('/fornitore/prodotti', payload);
      }
      chiudiProdottoModal();
      showToast({ title: p.id ? 'Prodotto aggiornato' : 'Prodotto creato', variant: 'success' });
      FornitoreProdottiPage();
    } catch (err) {
      errorEl.textContent = err.message || 'Errore durante il salvataggio.';
      errorEl.style.display = 'block';
    }
  });
}

window.aproNuovoProdotto = function() {
  modalProdotto(null);
};

window.modificaProdotto = function(id) {
  const prodotto = prodottiAttuali.find(p => Number(p.id) === Number(id));
  if (prodotto) modalProdotto(prodotto);
};

window.chiudiProdottoModal = function() {
  document.getElementById('prodotto-modal')?.remove();
};

window.cambiaStatoProdotto = async function(id, stato) {
  try {
    await apiPut(`/fornitore/prodotti/${id}`, { stato });
    showToast({ title: stato === 'attivo' ? 'Prodotto attivato' : 'Prodotto archiviato', variant: 'success' });
    FornitoreProdottiPage();
  } catch (e) {
    showToast({ title: e.message || 'Errore durante l\'operazione', variant: 'error' });
  }
};

window.eliminaProdotto = async function(id) {
  if (!confirm('Eliminare definitivamente questo prodotto?')) return;
  try {
    const res = await apiDelete(`/fornitore/prodotti/${id}`);
    showToast({ title: res.dati?.messaggio || 'Prodotto eliminato', variant: 'success' });
    FornitoreProdottiPage();
  } catch (e) {
    showToast({ title: e.message || 'Errore durante l\'eliminazione', variant: 'error' });
  }
};