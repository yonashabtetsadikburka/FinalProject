import { apiGet, apiPost, apiPut } from '../../api.js';
import { Table } from '../../components/table.js';
import { Badge } from '../../components/badge.js';

function statoAccesso(f) {
  if (f.id_utente) return Badge({ variant: 'success', children: 'Attivo' });
  if ((f.inviti_pendenti || 0) > 0) return Badge({ variant: 'warning', children: 'Invito pendente' });
  return Badge({ variant: 'secondary', children: 'Non invitato' });
}

window.linkCopiatoToast = function(ok) {
  const toast = document.createElement('div');
  toast.className = ok ? 'toast toast-success' : 'toast toast-destructive';
  toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:1100;';
  toast.innerHTML = `<div class="toast-content"><div class="toast-title">${ok ? 'Link copiato' : 'Copia non riuscita'}</div></div>`;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 2500);
};

window.generaInvitoFornitore = async function(fornitoreId, nomeAzienda) {
  try {
    const res = await apiPost(`/admin/fornitori/${fornitoreId}/invito`, {});
    const link = res.dati?.link || '';
    const modalHtml = `
      <div id="invito-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:520px;width:100%;">
          <div class="card-content" style="text-align:center;">
            <h3 style="margin-bottom:var(--space-3);">Invito generato</h3>
            <p class="text-secondary text-sm" style="margin-bottom:var(--space-3);">Inoltra questo link al fornitore via email. Scade tra 7 giorni e puo' essere usato una sola volta.</p>
            <div style="background:var(--color-primary-light);border-radius:var(--radius-sm);padding:var(--space-3);margin-bottom:var(--space-4);font-size:var(--text-sm);word-break:break-all;">${link}</div>
            <div style="display:flex;gap:var(--space-2);">
              <button class="btn btn-default w-full" onclick="navigator.clipboard.writeText('${link}').then(() => linkCopiatoToast(true)).catch(() => linkCopiatoToast(false))">Copia link</button>
              <button class="btn btn-outline w-full" onclick="document.getElementById('invito-modal').remove()">Chiudi</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    AdminFornitoriPage();
  } catch (err) {
    alert(err.message);
  }
};

window.collegaUtenteFornitore = async function(fornitoreId, nomeAzienda) {
  try {
    const res = await apiGet('/utenti');
    const users = (res.dati || []).filter(u => u.ruolo !== 'admin');
    const options = users.map(u => `<option value="${u.id}">${u.nome} ${u.cognome || ''} — ${u.email} (${u.ruolo})</option>`).join('');
    const modalHtml = `
      <div id="collega-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:480px;width:100%;">
          <div class="card-content">
            <h3 style="margin-bottom:var(--space-3);">Collega account a ${nomeAzienda}</h3>
            <div id="collega-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
            <label class="text-sm">Utente esistente
              <select id="collega-user" style="width:100%;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);">${options}</select>
            </label>
            <p class="text-xs text-secondary" style="margin:var(--space-2) 0;">Se l'utente non e' admin, il ruolo verra' impostato a "fornitore".</p>
            <div style="display:flex;gap:var(--space-2);margin-top:var(--space-3);">
              <button class="btn btn-default w-full" onclick="confermaCollegaUtente(${fornitoreId})">Collega</button>
              <button class="btn btn-outline w-full" onclick="document.getElementById('collega-modal').remove()">Annulla</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
  } catch (err) {
    alert(err.message);
  }
};

window.confermaCollegaUtente = async function(fornitoreId) {
  const select = document.getElementById('collega-user');
  const errorEl = document.getElementById('collega-error');
  try {
    await apiPost(`/admin/fornitori/${fornitoreId}/collega`, { utente_id: parseInt(select.value) });
    document.getElementById('collega-modal')?.remove();
    AdminFornitoriPage();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

window.modificaFornitore = async function(fornitoreId) {
  try {
    const res = await apiGet('/admin/fornitori');
    const f = (res.dati || []).find(x => x.id === fornitoreId);
    if (!f) return;
    const esc = v => (v || '').replace(/"/g, '&quot;');
    const modalHtml = `
      <div id="modifica-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:560px;width:100%;max-height:90vh;overflow-y:auto;">
          <div class="card-content">
            <h3 style="margin-bottom:var(--space-3);">Modifica ${f.nome_azienda}</h3>
            <div id="modifica-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
            <form onsubmit="salvaFornitore(event, ${f.id})" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:var(--space-3);">
              <label class="text-sm">Nome azienda*<input type="text" name="nome_azienda" required value="${esc(f.nome_azienda)}" style="width:100%;" /></label>
              <label class="text-sm">Email contatto<input type="email" name="email_contatto" value="${esc(f.email_contatto)}" style="width:100%;" /></label>
              <label class="text-sm">P.IVA<input type="text" name="piva" value="${esc(f.piva)}" style="width:100%;" /></label>
              <label class="text-sm">Categoria<input type="text" name="categoria" value="${esc(f.categoria)}" style="width:100%;" /></label>
              <label class="text-sm">Telefono<input type="text" name="telefono" value="${esc(f.telefono)}" style="width:100%;" /></label>
              <label class="text-sm">Indirizzo<input type="text" name="indirizzo" value="${esc(f.indirizzo)}" style="width:100%;" /></label>
              <label class="text-sm" style="grid-column:1/-1;">Sito web / catalogo<input type="url" name="sito_web" placeholder="https://..." value="${esc(f.sito_web)}" style="width:100%;" /></label>
              <label class="text-sm" style="grid-column:1/-1;">Descrizione<textarea name="descrizione" rows="2" style="width:100%;">${f.descrizione || ''}</textarea></label>
              <div style="grid-column:1/-1;display:flex;gap:var(--space-2);">
                <button type="submit" class="btn btn-default w-full">Salva</button>
                <button type="button" class="btn btn-outline w-full" onclick="document.getElementById('modifica-modal').remove()">Annulla</button>
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

window.salvaFornitore = async function(e, fornitoreId) {
  e.preventDefault();
  const f = e.target;
  const errorEl = document.getElementById('modifica-error');
  errorEl.style.display = 'none';
  try {
    await apiPut(`/admin/fornitori/${fornitoreId}`, {
      nome_azienda: f.nome_azienda.value.trim(),
      email_contatto: f.email_contatto.value.trim() || null,
      piva: f.piva.value.trim() || null,
      categoria: f.categoria.value.trim() || null,
      telefono: f.telefono.value.trim() || null,
      indirizzo: f.indirizzo.value.trim() || null,
      sito_web: f.sito_web.value.trim() || null,
      descrizione: f.descrizione.value.trim() || null
    });
    document.getElementById('modifica-modal')?.remove();
    AdminFornitoriPage();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

window.toggleFornitoreForm = function() {
  const form = document.getElementById('nuovo-fornitore-form');
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
};

window.creaFornitore = async function(e) {
  e.preventDefault();
  const f = e.target;
  const errorEl = document.getElementById('fornitore-error');
  errorEl.style.display = 'none';
  try {
    await apiPost('/admin/fornitori', {
      nome_azienda: f.nome_azienda.value.trim(),
      email_contatto: f.email_contatto.value.trim() || null,
      telefono: f.telefono.value.trim() || null,
      indirizzo: f.indirizzo.value.trim() || null,
      descrizione: f.descrizione.value.trim() || null,
      piva: f.piva.value.trim() || null,
      categoria: f.categoria.value.trim() || null,
      sito_web: f.sito_web.value.trim() || null
    });
    AdminFornitoriPage();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

export async function AdminFornitoriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/admin/fornitori');
    const fornitori = res.dati || [];

    const rows = fornitori.map(f => ({
      nome: `<span class="font-medium">${f.nome_azienda}</span>`,
      categoria: f.categoria || '-',
      piva: f.piva || '-',
      email: f.email_contatto || '-',
      accesso: statoAccesso(f),
      azioni: `<div style="display:flex;gap:4px;flex-wrap:wrap;">
        <button class="btn btn-ghost btn-sm" onclick="modificaFornitore(${f.id})">Modifica</button>
        ${f.id_utente ? `<span class="text-xs text-secondary">${f.email_account || ''}</span>`
          : `<button class="btn btn-outline btn-sm" onclick="generaInvitoFornitore(${f.id}, '${(f.nome_azienda || '').replace(/'/g, "\\'")}')">Genera invito</button>
             <button class="btn btn-ghost btn-sm" onclick="collegaUtenteFornitore(${f.id}, '${(f.nome_azienda || '').replace(/'/g, "\\'")}')">Collega</button>`}
      </div>`
    }));

    content.innerHTML = `
      <div class="content-area">
        <div class="admin-page-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h1>Gestione Fornitori</h1>
          <button class="btn btn-default btn-sm" onclick="toggleFornitoreForm()">+ Nuovo fornitore</button>
        </div>
        <div class="card" id="nuovo-fornitore-form" style="display:none;margin-bottom:var(--space-3);">
          <div class="card-content">
            <h3 style="margin-bottom:var(--space-3);">Nuova scheda fornitore</h3>
            <div id="fornitore-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
            <form onsubmit="creaFornitore(event)" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:var(--space-3);">
              <label class="text-sm">Nome azienda*<input type="text" name="nome_azienda" required style="width:100%;" /></label>
              <label class="text-sm">Email contatto<input type="email" name="email_contatto" style="width:100%;" /></label>
              <label class="text-sm">P.IVA<input type="text" name="piva" style="width:100%;" /></label>
              <label class="text-sm">Categoria<input type="text" name="categoria" style="width:100%;" /></label>
              <label class="text-sm">Telefono<input type="text" name="telefono" style="width:100%;" /></label>
              <label class="text-sm">Indirizzo<input type="text" name="indirizzo" style="width:100%;" /></label>
              <label class="text-sm">Sito web / catalogo<input type="url" name="sito_web" placeholder="https://..." style="width:100%;" /></label>
              <label class="text-sm" style="grid-column:1/-1;">Descrizione<textarea name="descrizione" rows="2" style="width:100%;"></textarea></label>
              <div style="grid-column:1/-1;"><button type="submit" class="btn btn-default">Crea scheda</button></div>
            </form>
          </div>
        </div>
        <div class="card"><div class="card-content">
          ${Table({ columns: [
            { key: 'nome', label: 'Azienda' },
            { key: 'categoria', label: 'Categoria' },
            { key: 'piva', label: 'P.IVA' },
            { key: 'email', label: 'Email' },
            { key: 'accesso', label: 'Accesso' },
            { key: 'azioni', label: 'Azioni' }
          ], rows })}
        </div></div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
