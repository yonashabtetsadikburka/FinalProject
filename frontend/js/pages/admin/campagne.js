import { apiGet, apiPostForm, apiDelete } from '../../api.js';
import { API_URL } from '../../constants.js';

function campImgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + path.replace(/^\//, '');
}
import { Table } from '../../components/table.js';
import { Badge } from '../../components/badge.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES, STATI_PRENOTAZIONE_LABELS, STATI_PRENOTAZIONE_BADGES } from '../../constants.js';

window.showCampagnaDettaglio = async function(campagnaId) {
  try {
    const res = await apiGet(`/campagne/${campagnaId}`);
    const c = res.dati;
    if (!c) return;

    const partecipazioni = c.partecipazioni || [];
    const aderentiHtml = partecipazioni.length > 0 ? partecipazioni.map(p => `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        <div>
          <div class="text-sm font-medium">${p.nome || ''} ${p.cognome || ''}</div>
          <div class="text-xs text-secondary">${p.quantita} pezzi &middot; ${p.data_prenotazione ? new Date(p.data_prenotazione).toLocaleDateString('it-IT') : ''}</div>
        </div>
        ${Badge({ variant: STATI_PRENOTAZIONE_BADGES[p.stato] || 'secondary', children: STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato })}
      </div>`).join('')
      : '<p class="text-sm text-secondary">Nessun aderente.</p>';

    const modalHtml = `
      <div id="campagna-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:640px;width:100%;max-height:90vh;overflow-y:auto;">
          <div class="card-content">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-4);">
              <div>
                <h3 style="font-size:var(--text-lg);font-weight:var(--font-bold);">${c.prodotto || 'Campagna #' + c.id}</h3>
                <div class="text-sm text-secondary">${c.fornitore || ''}${c.punto_ritiro ? ' &middot; ' + c.punto_ritiro : ''}</div>
              </div>
              ${Badge({ variant: STATI_CAMPAGNA_BADGES[c.stato] || 'default', children: STATI_CAMPAGNA_LABELS[c.stato] || c.stato })}
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:var(--space-3);margin-bottom:var(--space-4);">
              <div><div class="text-xs text-secondary">Prezzo base</div><div class="font-medium">&euro;${parseFloat(c.prezzo_base || 0).toFixed(2)}</div></div>
              <div><div class="text-xs text-secondary">Prezzo attuale</div><div class="font-medium">&euro;${parseFloat(c.prezzo_corrente || 0).toFixed(2)}</div></div>
              <div><div class="text-xs text-secondary">MOQ</div><div class="font-medium">${c.quantita_attuale || 0} / ${c.quantita_minima} pezzi</div></div>
              <div><div class="text-xs text-secondary">Commissione</div><div class="font-medium">${c.percentuale_commissione || 0}%</div></div>
              <div><div class="text-xs text-secondary">Scadenza</div><div class="font-medium">${c.data_limite ? (new Date(c.data_limite).toLocaleDateString('it-IT') + ' ore ' + new Date(c.data_limite).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' })) : '-'}</div></div>
              <div><div class="text-xs text-secondary">Pubblicata il</div><div class="font-medium">${c.data_inizio ? new Date(c.data_inizio).toLocaleDateString('it-IT') : '-'}</div></div>
              <div><div class="text-xs text-secondary">Partecipanti</div><div class="font-medium">${c.partecipanti || partecipazioni.length}</div></div>
            </div>
            <h4 style="font-size:var(--text-base);font-weight:var(--font-semibold);margin-bottom:var(--space-2);">Aderenti (${partecipazioni.length})</h4>
            ${aderentiHtml}
            <button class="btn btn-outline w-full" style="margin-top:var(--space-4);" onclick="document.getElementById('campagna-modal').remove()">Chiudi</button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
  } catch (err) {
    alert(err.message);
  }
};

window.eliminaCampagna = async function(campagnaId, nomeProdotto) {
  if (!confirm(`Eliminare la campagna "${nomeProdotto}"? Verranno cancellate anche partecipazioni e dati collegati. Consentito solo senza pagamenti.`)) return;
  try {
    await apiDelete(`/campagne/${campagnaId}`);
    AdminCampagnePage();
  } catch (err) {
    alert(err.message);
  }
};

window.modificaCampagna = async function(campagnaId) {
  try {
    const res = await apiGet(`/campagne/${campagnaId}`);
    const c = res.dati;
    if (!c) return;
    const scadenza = c.data_limite ? new Date(c.data_limite).toISOString().slice(0, 16) : '';
    const partecipazioni = c.partecipazioni || [];
    const immagineSrc = campImgUrl(c.immagine);
    const galleria = (c.immagini && c.immagini.length ? c.immagini : (c.immagine ? [{ url: c.immagine, principale: 1 }] : []));
    const thumbsHtml = galleria.length ? galleria.map((im, i) => `
      <div style="position:relative;width:88px;height:66px;flex-shrink:0;">
        <img src="${campImgUrl(im.url)}" alt="" data-idx="${i}" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius-sm);${parseInt(im.principale) ? 'border:2px solid var(--color-primary);' : 'border:2px solid var(--color-border);'}" />
        ${parseInt(im.principale) ? '<span style="position:absolute;bottom:0;left:0;background:var(--color-primary);color:#fff;font-size:9px;padding:0 4px;border-radius:0 var(--radius-sm) 0 var(--radius-sm);">Main</span>' : ''}
        ${im.id ? `<button type="button" title="Elimina" onclick="eliminaImmagineCampagna(${im.id}, ${c.id})" style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;border:none;background:var(--color-error);color:#fff;font-size:12px;line-height:1;cursor:pointer;">&#10005;</button>` : ''}
      </div>`).join('')
      : '<span style="color:var(--color-text-secondary);font-size:var(--text-sm);">Nessuna immagine</span>';

    const aderentiHtml = partecipazioni.length > 0 ? partecipazioni.map(p => `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        <div>
          <div class="text-sm font-medium">${p.nome || ''} ${p.cognome || ''}</div>
          <div class="text-xs text-secondary">${p.quantita} pezzi &middot; ${p.data_prenotazione ? new Date(p.data_prenotazione).toLocaleDateString('it-IT') : ''}</div>
        </div>
        ${Badge({ variant: STATI_PRENOTAZIONE_BADGES[p.stato] || 'secondary', children: STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato })}
      </div>`).join('')
      : '<p class="text-sm text-secondary">Nessun aderente.</p>';

    const modalHtml = `
      <div id="modifica-campagna-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:640px;width:100%;max-height:90vh;overflow-y:auto;">
          <div class="card-content">
            <h3 style="margin-bottom:var(--space-3);">Modifica campagna &mdash; ${c.prodotto || '#' + c.id}</h3>
            <div id="modifica-campagna-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>

            <div style="margin-bottom:var(--space-4);">
              <div style="width:100%;aspect-ratio:16/9;border-radius:var(--radius);overflow:hidden;background:var(--color-bg-secondary);border:1px solid var(--color-border);display:flex;align-items:center;justify-content:center;">
                <img id="modifica-campagna-anteprima" src="${immagineSrc}" alt="" style="width:100%;height:100%;object-fit:contain;display:${immagineSrc ? 'block' : 'none'};" />
                <span id="modifica-campagna-placeholder" style="color:var(--color-text-secondary);font-size:var(--text-sm);display:${immagineSrc ? 'none' : 'block'};">Nessuna immagine</span>
              </div>
              <div id="modifica-campagna-thumbs" style="display:flex;gap:var(--space-2);flex-wrap:wrap;margin-top:var(--space-2);">${thumbsHtml}</div>
            </div>

            <form onsubmit="salvaCampagna(event, ${c.id})" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3);">
              <label class="btn btn-outline btn-sm" style="grid-column:1/-1;cursor:pointer;text-align:center;">
                Sostituisci immagine
                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="aggiornaAnteprimaCampagna(this)" />
              </label>
              <label class="btn btn-outline btn-sm" style="grid-column:1/-1;cursor:pointer;text-align:center;">
                Aggiungi immagini
                <input type="file" name="foto_extra" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" />
              </label>
              <label class="text-sm">Scadenza<input type="datetime-local" name="data_limite" value="${scadenza}" required style="width:100%;" /></label>
              <label class="text-sm">MOQ<input type="number" name="quantita_minima" min="1" value="${c.quantita_minima}" required style="width:100%;" /></label>
              <label class="text-sm">Prezzo corrente (&euro;)<input type="number" name="prezzo_corrente" min="0.01" step="0.01" value="${c.prezzo_corrente}" required style="width:100%;" /></label>
              <label class="text-sm">Prezzo base (&euro;)<input type="number" name="prezzo_base" min="0.01" step="0.01" value="${c.prezzo_base}" required style="width:100%;" /></label>
              <label class="text-sm">Commissione (%)<input type="number" name="percentuale_commissione" min="0" max="100" step="0.01" value="${c.percentuale_commissione}" required style="width:100%;" /></label>
              <div style="grid-column:1/-1;display:flex;gap:var(--space-2);">
                <button type="submit" class="btn btn-default w-full">Salva</button>
                <button type="button" class="btn btn-outline w-full" onclick="document.getElementById('modifica-campagna-modal').remove()">Annulla</button>
              </div>
            </form>

            <div style="margin-top:var(--space-4);padding-top:var(--space-4);border-top:1px solid var(--color-border);">
              <h4 style="font-size:var(--text-base);font-weight:var(--font-semibold);margin-bottom:var(--space-2);">Aderenti (${partecipazioni.length})</h4>
              ${aderentiHtml}
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
  } catch (err) {
    alert(err.message);
  }
};

window.eliminaImmagineCampagna = async function(imgId, campagnaId) {
  if (!confirm('Eliminare questa immagine?')) return;
  try {
    await apiDelete(`/admin/immagini/${imgId}`);
    document.getElementById('modifica-campagna-modal')?.remove();
    modificaCampagna(campagnaId);
    AdminCampagnePage();
  } catch (err) {
    alert(err.message);
  }
};

window.aggiornaAnteprimaCampagna = function(input) {
  const file = input.files[0];
  const img = document.getElementById('modifica-campagna-anteprima');
  const placeholder = document.getElementById('modifica-campagna-placeholder');
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function(ev) {
    img.src = ev.target.result;
    img.style.display = 'block';
    placeholder.style.display = 'none';
  };
  reader.readAsDataURL(file);
};

window.salvaCampagna = async function(e, campagnaId) {
  e.preventDefault();
  const f = e.target;
  const errorEl = document.getElementById('modifica-campagna-error');
  errorEl.style.display = 'none';
  try {
    const fd = new FormData();
    fd.append('data_limite', f.data_limite.value);
    fd.append('quantita_minima', f.quantita_minima.value);
    fd.append('prezzo_corrente', f.prezzo_corrente.value);
    fd.append('prezzo_base', f.prezzo_base.value);
    fd.append('percentuale_commissione', f.percentuale_commissione.value);
    const fotoInput = f.querySelector('input[name="foto"]');
    if (fotoInput && fotoInput.files[0]) {
      fd.append('foto', fotoInput.files[0]);
    }
    const extraInput = f.querySelector('input[name="foto_extra"]');
    if (extraInput) {
      for (const file of extraInput.files) fd.append('foto_extra[]', file);
    }
    await apiPostForm(`/campagne/${campagnaId}/modifica`, fd);
    document.getElementById('modifica-campagna-modal')?.remove();
    AdminCampagnePage();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

window.nuovaCampagna = async function() {
  try {
    const fornRes = await apiGet('/admin/fornitori');
    const fornitori = fornRes.dati || [];
    const res = await apiGet('/campagne');
    const catMap = new Map();
    (res.dati || []).forEach(c => { if (c.id_categoria) catMap.set(c.id_categoria, c.categoria || ('Categoria ' + c.id_categoria)); });
    const catOptions = [...catMap.entries()].map(([id, nome]) => `<option value="${id}">${nome}</option>`).join('');
    const fornOptions = fornitori.map(f => `<option value="${f.id}">${f.nome_azienda}</option>`).join('');
    const dataDefault = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 16);
    const modalHtml = `
      <div id="nuova-campagna-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:600px;width:100%;max-height:90vh;overflow-y:auto;">
          <div class="card-content">
            <h3 style="margin-bottom:var(--space-3);">Nuova campagna</h3>
            <div id="nuova-campagna-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
            <form onsubmit="creaCampagna(event)" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:var(--space-3);">
              <label class="text-sm" style="grid-column:1/-1;">Nome prodotto* (max 80)<input type="text" name="nome" required maxlength="80" style="width:100%;" /></label>
              <label class="text-sm">Fornitore*<select name="id_fornitore" required style="width:100%;">${fornOptions}</select></label>
              <label class="text-sm">Categoria<select name="id_categoria" style="width:100%;"><option value="">-</option>${catOptions}</select></label>
              <label class="text-sm">Prezzo di listino (&euro;)*<input type="number" name="prezzo_base" min="0.01" step="0.01" required style="width:100%;" /></label>
              <label class="text-sm">Prezzo scontato (&euro;)*<input type="number" name="prezzo_corrente" min="0.01" step="0.01" required style="width:100%;" /></label>
              <label class="text-sm">Quantita minima*<input type="number" name="quantita_minima" min="1" value="1" required style="width:100%;" /></label>
              <label class="text-sm">Commissione (%)<input type="number" name="percentuale_commissione" min="0" max="100" step="0.5" value="10" style="width:100%;" /></label>
              <label class="text-sm" style="grid-column:1/-1;">Scadenza (giorno e ora)*<input type="datetime-local" name="scadenza" required value="${dataDefault}" style="width:100%;" /></label>
              <div class="text-sm" style="grid-column:1/-1;">
                <label>Immagini (tieni premuto Ctrl/Cmd per selezionarne piu di una)<input type="file" name="foto" accept="image/jpeg,image/png,image/webp" multiple style="width:100%;" onchange="mostraFotoSelezionate(this)" /></label>
                <div id="foto-selezionate" class="text-xs text-secondary" style="margin-top:4px;">Nessun file selezionato</div>
              </div>
              <label class="text-sm" style="grid-column:1/-1;">Descrizione<textarea name="descrizione" rows="2" style="width:100%;"></textarea></label>
              <div style="grid-column:1/-1;display:flex;gap:var(--space-2);">
                <button type="submit" class="btn btn-default w-full">Crea campagna</button>
                <button type="button" class="btn btn-outline w-full" onclick="document.getElementById('nuova-campagna-modal').remove()">Annulla</button>
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

window.mostraFotoSelezionate = function(input) {
  const box = document.getElementById('foto-selezionate');
  if (!box) return;
  const n = input.files ? input.files.length : 0;
  if (!n) { box.textContent = 'Nessun file selezionato'; return; }
  const nomi = [...input.files].map(f => f.name).join(', ');
  box.textContent = n === 1 ? `1 file: ${nomi}` : `${n} file: ${nomi}`;
};

window.creaCampagna = async function(e) {
  e.preventDefault();
  const f = e.target;
  const errorEl = document.getElementById('nuova-campagna-error');
  errorEl.style.display = 'none';
  try {
    const fd = new FormData();
    fd.append('nome', f.nome.value.trim());
    fd.append('id_fornitore', f.id_fornitore.value);
    if (f.id_categoria.value) fd.append('id_categoria', f.id_categoria.value);
    fd.append('prezzo_base', f.prezzo_base.value);
    fd.append('prezzo_corrente', f.prezzo_corrente.value);
    fd.append('quantita_minima', f.quantita_minima.value);
    fd.append('percentuale_commissione', f.percentuale_commissione.value);
    fd.append('scadenza', f.scadenza.value);
    fd.append('descrizione', f.descrizione.value.trim());
    for (const file of f.foto.files) fd.append('foto[]', file);
    await apiPostForm('/campagne', fd);
    document.getElementById('nuova-campagna-modal')?.remove();
    AdminCampagnePage();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

export async function AdminCampagnePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/campagne');
    const campaigns = res.dati || [];

    const rows = campaigns.map(c => {
      const qty = parseInt(c.quantita_attuale) || 0;
      const min = parseInt(c.quantita_minima) || 1;
      const percentage = Math.min(100, Math.round((qty / min) * 100));
      const dl = new Date(c.data_limite);
      return {
        nome: `<a href="javascript:void(0)" onclick="showCampagnaDettaglio(${c.id})" title="${(c.prodotto || 'N/A').replace(/"/g, '&quot;')}" style="color:var(--color-primary);font-weight:var(--font-medium);display:inline-block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;">${c.prodotto || 'N/A'}</a>`,
        stato: `<span class="badge ${STATI_CAMPAGNA_BADGES[c.stato] || 'badge-secondary'}">${STATI_CAMPAGNA_LABELS[c.stato] || c.stato}</span>`,
        partecipanti: `${qty} / ${min}`,
        avanzamento: `<div style="display:flex;align-items:center;gap:8px;"><div class="progress-container" style="width:80px;"><div class="progress-bar" style="width:${percentage}%"></div></div><span class="text-xs">${percentage}%</span></div>`,
        scadenza: dl.toLocaleDateString('it-IT') + ' ore ' + dl.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }),
        pubblicata: c.data_inizio ? new Date(c.data_inizio).toLocaleDateString('it-IT') : '-',
        azioni: `<div class="azioni-campagne">
          <button class="btn btn-outline btn-sm" onclick="modificaCampagna(${c.id})">Modifica</button>
          <button class="btn btn-destructive btn-sm" onclick="eliminaCampagna(${c.id}, '${(c.prodotto || '').replace(/'/g, "\\'")}')">Elimina</button>
        </div>`
      };
    });

    content.innerHTML = `
      <div class="content-area">
        <div class="admin-page-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h1>Gestione Campagne</h1>
          <button class="btn btn-default btn-sm" onclick="nuovaCampagna()">+ Nuova campagna</button>
        </div>
        <div class="card"><div class="card-content">
          ${Table({ columns: [
            { key: 'nome', label: 'Prodotto' },
            { key: 'stato', label: 'Stato' },
            { key: 'partecipanti', label: 'Partecipanti' },
            { key: 'avanzamento', label: 'Avanzamento' },
            { key: 'scadenza', label: 'Scadenza' },
            { key: 'pubblicata', label: 'Pubblicata' },
            { key: 'azioni', label: 'Azioni' }
          ], rows })}
        </div></div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
