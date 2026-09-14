import { apiGet, apiPostForm, apiDelete } from '../../api.js';
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
              <div><div class="text-xs text-secondary">Scadenza</div><div class="font-medium">${c.data_limite ? new Date(c.data_limite).toLocaleDateString('it-IT') : '-'}</div></div>
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
    const immagineSrc = c.immagine ? (c.immagine.startsWith('http') ? c.immagine : 'api/' + c.immagine) : '';

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
            </div>

            <form onsubmit="salvaCampagna(event, ${c.id})" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3);">
              <label class="btn btn-outline btn-sm" style="grid-column:1/-1;cursor:pointer;text-align:center;">
                Sostituisci immagine
                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="aggiornaAnteprimaCampagna(this)" />
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
    await apiPostForm(`/campagne/${campagnaId}/modifica`, fd);
    document.getElementById('modifica-campagna-modal')?.remove();
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
      const percentage = Math.round((qty / min) * 100);
      return {
        nome: `<a href="javascript:void(0)" onclick="showCampagnaDettaglio(${c.id})" style="color:var(--color-primary);font-weight:var(--font-medium);">${c.prodotto || 'N/A'}</a>`,
        stato: `<span class="badge ${STATI_CAMPAGNA_BADGES[c.stato] || 'badge-secondary'}">${STATI_CAMPAGNA_LABELS[c.stato] || c.stato}</span>`,
        partecipanti: `${qty} / ${min}`,
        avanzamento: `<div style="display:flex;align-items:center;gap:8px;"><div class="progress-container" style="width:80px;"><div class="progress-bar" style="width:${percentage}%"></div></div><span class="text-xs">${percentage}%</span></div>`,
        scadenza: new Date(c.data_limite).toLocaleDateString('it-IT'),
        azioni: `<div style="display:flex;gap:4px;flex-wrap:wrap;">
          <button class="btn btn-outline btn-sm" onclick="modificaCampagna(${c.id})">Modifica</button>
          <button class="btn btn-destructive btn-sm" onclick="eliminaCampagna(${c.id}, '${(c.prodotto || '').replace(/'/g, "\\'")}')">Elimina</button>
        </div>`
      };
    });

    content.innerHTML = `
      <div class="content-area">
        <div class="admin-page-header"><h1>Gestione Campagne</h1></div>
        <div class="card"><div class="card-content">
          ${Table({ columns: [
            { key: 'nome', label: 'Prodotto' },
            { key: 'stato', label: 'Stato' },
            { key: 'partecipanti', label: 'Partecipanti' },
            { key: 'avanzamento', label: 'Avanzamento' },
            { key: 'scadenza', label: 'Scadenza' },
            { key: 'azioni', label: 'Azioni' }
          ], rows })}
        </div></div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
