import { apiGet, apiPut, apiDelete, apiPost } from '../../api.js';
import { getState } from '../../state.js';
import { Table } from '../../components/table.js';
import { RUOLI_LABELS, RUOLI_BADGES, STATI_PRENOTAZIONE_LABELS } from '../../constants.js';

window.cambiaRuoloUtente = async function(userId, nuovoRuolo, selectEl) {
  try {
    await apiPut(`/utenti/${userId}/ruolo`, { ruolo: nuovoRuolo });
    showUtentiToast('Ruolo aggiornato a ' + (RUOLI_LABELS[nuovoRuolo] || nuovoRuolo));
  } catch (err) {
    alert(err.message);
    AdminUtentiPage();
  }
};

window.cambiaStatoUtente = async function(userId, nuovoStato) {
  try {
    await apiPut(`/utenti/${userId}/stato`, { stato: nuovoStato });
    showUtentiToast('Stato aggiornato a ' + (nuovoStato === 'attivo' ? 'Attivo' : 'Sospeso'));
  } catch (err) {
    alert(err.message);
    AdminUtentiPage();
  }
};

function showUtentiToast(msg) {
  const toast = document.createElement('div');
  toast.className = 'toast toast-success';
  toast.innerHTML = `<div class="toast-content"><div class="toast-title">${msg}</div></div>`;
  document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 2500);
}

window.copiaResetLink = function() {
  const box = document.getElementById('reset-link-box');
  const link = box?.dataset.link || '';
  const done = (ok) => {
    const toast = document.createElement('div');
    toast.className = ok ? 'toast toast-success' : 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">${ok ? 'Link copiato' : 'Copia non riuscita'}</div></div>`;
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:1100;';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
  };
  try {
    navigator.clipboard.writeText(link).then(() => done(true)).catch(() => done(false));
  } catch (_) { done(false); }
};

window.generaResetLink = async function(userId) {
  const box = document.getElementById('reset-link-box');
  try {
    const res = await apiPost(`/admin/utenti/${userId}/reset-link`, {});
    const link = res.dati?.link || '';
    box.style.display = 'block';
    box.dataset.link = link;
    box.innerHTML = `Link valido 24h (usabile una volta sola):<br><strong>${link}</strong><br>
      <button class="btn btn-default btn-sm" style="margin-top:var(--space-2);" onclick="copiaResetLink()">Copia link</button>`;
  } catch (err) {
    box.style.display = 'block';
    box.textContent = err.message;
  }
};

window.eliminaUtente = async function(userId, nomeUtente) {  if (!confirm(`Eliminare l'utente ${nomeUtente}? L'operazione e' consentita solo se non ha partecipazioni o pagamenti.`)) return;
  try {
    await apiDelete(`/utenti/${userId}`);
    document.getElementById('utente-modal')?.remove();
    showUtentiToast('Utente eliminato');
    AdminUtentiPage();
  } catch (err) {
    alert(err.message);
  }
};

window.showUtenteDettaglio = async function(userId) {
  try {
    const [detRes, stoRes] = await Promise.all([
      apiGet(`/utenti/${userId}`),
      apiGet(`/utenti/${userId}/storico`).catch(() => ({ dati: { partecipazioni: [], pagamenti: [], notifiche: [], proposte: [] } }))
    ]);
    const u = detRes.dati;
    const stor = stoRes.dati || {};
    if (!u) return;

    const { user: me } = getState();
    const isSelf = me && me.id === u.id;

    const partHtml = (stor.partecipazioni || []).length > 0
      ? stor.partecipazioni.map(p => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div><div class="text-sm font-medium">${p.prodotto || 'Campagna #' + p.id_colletta}</div>
          <div class="text-xs text-secondary">${p.quantita} pezzi &middot; ${p.data_prenotazione ? new Date(p.data_prenotazione).toLocaleDateString('it-IT') : ''}</div></div>
          <span class="badge badge-secondary">${STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato}</span>
        </div>`).join('')
      : '<p class="text-sm text-secondary">Nessuna partecipazione.</p>';

    const pagHtml = (stor.pagamenti || []).length > 0
      ? stor.pagamenti.map(pg => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div><div class="text-sm font-medium">&euro;${parseFloat(pg.importo).toFixed(2)} — ${pg.prodotto || ''}</div>
          <div class="text-xs text-secondary">${pg.tipo_pagamento} &middot; ${pg.stato} &middot; ${pg.data_pagamento ? new Date(pg.data_pagamento).toLocaleDateString('it-IT') : ''}</div></div>
        </div>`).join('')
      : '<p class="text-sm text-secondary">Nessun pagamento.</p>';

    const notHtml = (stor.notifiche || []).length > 0
      ? stor.notifiche.slice(0, 10).map(n => `
        <div style="padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div class="text-sm font-medium">${n.titolo}</div>
          <div class="text-xs text-secondary">${n.tipo} &middot; ${n.data_creazione ? new Date(n.data_creazione).toLocaleDateString('it-IT') : ''}</div>
        </div>`).join('')
      : '<p class="text-sm text-secondary">Nessuna notifica.</p>';

    const propHtml = (stor.proposte || []).length > 0
      ? stor.proposte.map(pp => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div><div class="text-sm font-medium">${pp.nome_prodotto}</div>
          <div class="text-xs text-secondary">${pp.tot_voti} voti</div></div>
          <span class="badge badge-secondary">${pp.stato}</span>
        </div>`).join('')
      : '<p class="text-sm text-secondary">Nessuna proposta.</p>';

    const modalHtml = `
      <div id="utente-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:640px;width:100%;max-height:90vh;overflow-y:auto;">
          <div class="card-content">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-4);">
              <div>
                <h3 style="font-size:var(--text-lg);font-weight:var(--font-bold);">${u.nome} ${u.cognome || ''}</h3>
                <div class="text-sm text-secondary">${u.email}</div>
              </div>
              <span class="badge ${u.stato === 'attivo' ? 'badge-success' : 'badge-destructive'}">${u.stato === 'attivo' ? 'Attivo' : 'Sospeso'}</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:var(--space-3);margin-bottom:var(--space-4);">
              <div><div class="text-xs text-secondary">Ruolo</div><div class="font-medium">${RUOLI_LABELS[u.ruolo] || u.ruolo}</div></div>
              <div><div class="text-xs text-secondary">Tipo</div><div class="font-medium">${u.tipo || '-'}</div></div>
              <div><div class="text-xs text-secondary">Telefono</div><div class="font-medium">${u.telefono || '-'}</div></div>
              <div><div class="text-xs text-secondary">Indirizzo</div><div class="font-medium">${u.indirizzo || '-'}</div></div>
              <div><div class="text-xs text-secondary">Iscrizione</div><div class="font-medium">${u.data_iscrizione ? new Date(u.data_iscrizione).toLocaleDateString('it-IT') : '-'}</div></div>
            </div>
            <h4 style="font-weight:var(--font-semibold);margin-bottom:var(--space-2);">Partecipazioni (${(stor.partecipazioni || []).length})</h4>${partHtml}
            <h4 style="font-weight:var(--font-semibold);margin:var(--space-3) 0 var(--space-2);">Pagamenti (${(stor.pagamenti || []).length})</h4>${pagHtml}
            <h4 style="font-weight:var(--font-semibold);margin:var(--space-3) 0 var(--space-2);">Notifiche recenti</h4>${notHtml}
            <h4 style="font-weight:var(--font-semibold);margin:var(--space-3) 0 var(--space-2);">Proposte (${(stor.proposte || []).length})</h4>${propHtml}
            <div style="display:flex;gap:var(--space-2);margin-top:var(--space-4);">
              <button class="btn btn-outline w-full" onclick="document.getElementById('utente-modal').remove()">Chiudi</button>
              ${isSelf ? '' : `<button class="btn btn-outline w-full" onclick="generaResetLink(${u.id})">Genera link reset</button>`}
              ${isSelf ? '' : `<button class="btn btn-destructive w-full" onclick="eliminaUtente(${u.id}, '${u.nome} ${u.cognome || ''}')">Elimina</button>`}
            </div>
            <div id="reset-link-box" style="display:none;margin-top:var(--space-3);background:var(--color-primary-light);border-radius:var(--radius-sm);padding:var(--space-3);font-size:var(--text-sm);word-break:break-all;"></div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
  } catch (err) {
    alert(err.message);
  }
};

export async function AdminUtentiPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const res = await apiGet('/utenti');
    const users = res.dati || [];
    const { user: me } = getState();

    const rows = users.map(u => {
      const isSelf = me && me.id === u.id;
      return {
        nome: `<a href="javascript:void(0)" onclick="showUtenteDettaglio(${u.id})" style="color:var(--color-primary);font-weight:var(--font-medium);">${u.nome} ${u.cognome || ''}</a>`,
        email: u.email,
        ruolo: isSelf
          ? `<span class="badge ${RUOLI_BADGES[u.ruolo] || 'badge-secondary'}">${RUOLI_LABELS[u.ruolo] || u.ruolo}</span>`
          : `<select onchange="cambiaRuoloUtente(${u.id}, this.value, this)" style="border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:4px 8px;font-size:var(--text-sm);">
              ${['cliente', 'fornitore', 'admin'].map(r => `<option value="${r}" ${u.ruolo === r ? 'selected' : ''}>${RUOLI_LABELS[r] || r}</option>`).join('')}
            </select>`,
        stato: isSelf
          ? `<span class="badge ${u.stato === 'attivo' ? 'badge-success' : 'badge-destructive'}">${u.stato === 'attivo' ? 'Attivo' : 'Sospeso'}</span>`
          : `<select onchange="cambiaStatoUtente(${u.id}, this.value)" style="border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:4px 8px;font-size:var(--text-sm);">
              <option value="attivo" ${u.stato === 'attivo' ? 'selected' : ''}>Attivo</option>
              <option value="sospeso" ${u.stato === 'sospeso' ? 'selected' : ''}>Sospeso</option>
            </select>`,
        data_iscrizione: new Date(u.data_iscrizione).toLocaleDateString('it-IT'),
        azioni: `<button class="btn btn-outline btn-sm" onclick="showUtenteDettaglio(${u.id})">Dettagli</button>`
      };
    });

    content.innerHTML = `
      <div class="content-area">
        <div class="admin-page-header"><h1>Gestione Utenti</h1></div>
        <div class="card"><div class="card-content">
          ${Table({ columns: [
            { key: 'nome', label: 'Nome' },
            { key: 'email', label: 'Email' },
            { key: 'ruolo', label: 'Ruolo' },
            { key: 'stato', label: 'Stato' },
            { key: 'data_iscrizione', label: 'Iscrizione' },
            { key: 'azioni', label: 'Azioni' }
          ], rows })}
        </div></div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
