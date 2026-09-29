import { apiGet, apiPostForm, apiDelete } from '../../api.js';
import { API_URL } from '../../constants.js';
import { Table } from '../../components/table.js';

function imgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + path.replace(/^\//, '');
}

window.modificaProdotto = async function(prodottoId) {
  try {
    const res = await apiGet('/prodotti?tutti=1');
    const p = ((res.dati && res.dati.prodotti) || res.dati || []).find(x => x.id === prodottoId);
    if (!p) return;
    const esc = v => String(v ?? '').replace(/"/g, '&quot;');
    const modalHtml = `
      <div id="modifica-prodotto-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:560px;width:100%;max-height:90vh;overflow-y:auto;">
          <div class="card-content">
            <h3 style="margin-bottom:var(--space-3);">Modifica ${p.nome}</h3>
            <div id="modifica-prodotto-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
            <form onsubmit="salvaProdotto(event, ${p.id})" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:var(--space-3);">
              <label class="text-sm">Nome*<input type="text" name="nome" required value="${esc(p.nome)}" style="width:100%;" /></label>
              <label class="text-sm">Prezzo base (&euro;)*<input type="number" name="prezzo_base" min="0.01" step="0.01" required value="${p.prezzo_base}" style="width:100%;" /></label>
              <label class="text-sm">Prezzo attuale (&euro;)*<input type="number" name="prezzo_unitario" min="0.01" step="0.01" required value="${p.prezzo_unitario}" style="width:100%;" /></label>
              <label class="text-sm">MOQ<input type="number" name="quantita_minima" min="1" value="${p.quantita_minima}" style="width:100%;" /></label>
              <label class="text-sm">Sostituisci immagine<input type="file" name="foto" accept="image/jpeg,image/png,image/webp" style="width:100%;" /></label>
              <label class="text-sm" style="grid-column:1/-1;">Descrizione<textarea name="descrizione" rows="2" style="width:100%;">${p.descrizione || ''}</textarea></label>
              <div style="grid-column:1/-1;display:flex;gap:var(--space-2);">
                <button type="submit" class="btn btn-default w-full">Salva</button>
                <button type="button" class="btn btn-outline w-full" onclick="document.getElementById('modifica-prodotto-modal').remove()">Annulla</button>
              </div>
            </form>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
  } catch (err) {
    alert(err.message);
  }
};

window.salvaProdotto = async function(e, prodottoId) {
  e.preventDefault();
  const f = e.target;
  const errorEl = document.getElementById('modifica-prodotto-error');
  errorEl.style.display = 'none';
  try {
    const fd = new FormData();
    fd.append('nome', f.nome.value.trim());
    fd.append('descrizione', f.descrizione.value.trim());
    fd.append('prezzo_base', f.prezzo_base.value);
    fd.append('prezzo_unitario', f.prezzo_unitario.value);
    fd.append('quantita_minima', f.quantita_minima.value);
    if (f.foto.files[0]) fd.append('foto', f.foto.files[0]);
    await apiPostForm(`/admin/prodotti/${prodottoId}/modifica`, fd);
    document.getElementById('modifica-prodotto-modal')?.remove();
    AdminProdottiPage();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

window.eliminaProdotto = async function(prodottoId, nomeProdotto) {
  if (!confirm(`Eliminare il prodotto "${nomeProdotto}"? Possibile solo senza campagne collegate.`)) return;
  try {
    await apiDelete(`/admin/prodotti/${prodottoId}`);
    AdminProdottiPage();
  } catch (err) {
    alert(err.message);
  }
};

window.toggleProdottoForm = function() {
  const form = document.getElementById('nuovo-prodotto-form');
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
};

window.creaProdotto = async function(e) {
  e.preventDefault();
  const f = e.target;
  const errorEl = document.getElementById('prodotto-error');
  errorEl.style.display = 'none';
  try {
    const fd = new FormData();
    fd.append('nome', f.nome.value.trim());
    fd.append('descrizione', f.descrizione.value.trim());
    fd.append('prezzo_unitario', f.prezzo_unitario.value);
    fd.append('prezzo_base', f.prezzo_base.value);
    fd.append('quantita_minima', f.quantita_minima.value);
    fd.append('id_fornitore', f.id_fornitore.value);
    fd.append('id_categoria', f.id_categoria.value);
    if (f.foto.files[0]) fd.append('foto', f.foto.files[0]);
    await apiPostForm('/admin/prodotti', fd);
    AdminProdottiPage();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

export async function AdminProdottiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const [prodRes, fornRes] = await Promise.all([
      apiGet('/prodotti?tutti=1'),
      apiGet('/admin/fornitori')
    ]);
    const prodotti = (prodRes.dati && prodRes.dati.prodotti) || prodRes.dati || [];
    const fornitori = fornRes.dati || [];

    const catMap = new Map();
    prodotti.forEach(p => {
      if (p.categoria_id && p.categoria) catMap.set(p.categoria_id, p.categoria);
    });
    const catOptions = [...catMap.entries()].map(([id, nome]) => `<option value="${id}">${nome}</option>`).join('');
    const fornOptions = fornitori.map(f => `<option value="${f.id}">${f.nome_azienda}</option>`).join('');

    const rows = prodotti.map(p => ({
      nome: `<span style="display:flex;align-items:center;gap:var(--space-2);">${p.immagine ? `<img src="${imgUrl(p.immagine)}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:var(--radius-sm);" />` : `<span class="supplier-logo" style="width:40px;height:40px;font-size:var(--text-base);flex-shrink:0;">${(p.nome || '?')[0]}</span>`}<span class="font-medium">${p.nome}</span></span>`,
      fornitore: p.fornitore || '-',
      categoria: p.categoria || '-',
      prezzo: `&euro;${parseFloat(p.prezzo_unitario).toFixed(2)} <span class="text-xs text-secondary" style="text-decoration:line-through;">&euro;${parseFloat(p.prezzo_base).toFixed(2)}</span>`,
      moq: p.quantita_minima,
      like: `${p.mi_piace || 0}`,
      azioni: `<div style="display:flex;gap:4px;flex-wrap:wrap;">
        <button class="btn btn-ghost btn-sm" onclick="modificaProdotto(${p.id})">Modifica</button>
        <button class="btn btn-destructive btn-sm" onclick="eliminaProdotto(${p.id}, '${(p.nome || '').replace(/'/g, "\\'")}')">Elimina</button>
      </div>`
    }));

    content.innerHTML = `
      <div class="content-area">
        <div class="admin-page-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h1>Gestione Prodotti</h1>
          <button class="btn btn-default btn-sm" onclick="toggleProdottoForm()">+ Nuovo prodotto</button>
        </div>
        <div class="card" id="nuovo-prodotto-form" style="display:none;margin-bottom:var(--space-3);">
          <div class="card-content">
            <h3 style="margin-bottom:var(--space-3);">Nuovo prodotto votabile</h3>
            <div id="prodotto-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
            <form onsubmit="creaProdotto(event)" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:var(--space-3);">
              <label class="text-sm">Nome*<input type="text" name="nome" required style="width:100%;" /></label>
              <label class="text-sm">Fornitore*<select name="id_fornitore" style="width:100%;">${fornOptions}</select></label>
              <label class="text-sm">Categoria<select name="id_categoria" style="width:100%;"><option value="">-</option>${catOptions}</select></label>
              <label class="text-sm">Prezzo base (&euro;)*<input type="number" name="prezzo_base" min="0.01" step="0.01" required style="width:100%;" /></label>
              <label class="text-sm">Prezzo attuale (&euro;)*<input type="number" name="prezzo_unitario" min="0.01" step="0.01" required style="width:100%;" /></label>
              <label class="text-sm">MOQ<input type="number" name="quantita_minima" min="1" value="1" style="width:100%;" /></label>
              <label class="text-sm">Scegli immagine<input type="file" name="foto" accept="image/jpeg,image/png,image/webp" style="width:100%;" /></label>
              <label class="text-sm" style="grid-column:1/-1;">Descrizione<textarea name="descrizione" rows="2" style="width:100%;"></textarea></label>
              <div style="grid-column:1/-1;"><button type="submit" class="btn btn-default">Crea prodotto</button></div>
            </form>
          </div>
        </div>
        <div class="card"><div class="card-content">
          ${Table({ columns: [
            { key: 'nome', label: 'Prodotto' },
            { key: 'fornitore', label: 'Fornitore' },
            { key: 'categoria', label: 'Categoria' },
            { key: 'prezzo', label: 'Prezzo' },
            { key: 'moq', label: 'MOQ' },
            { key: 'like', label: 'Like' },
            { key: 'azioni', label: 'Azioni' }
          ], rows })}
        </div></div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
