import { getState } from '../state.js';
import { apiGet, apiPost, apiDelete } from '../api.js';
import { Badge } from '../components/badge.js';

const STATI_PROPOSTA_LABELS = {
  in_attesa: 'In Attesa', approvata_admin: 'Approvata', rifiutata: 'Rifiutata',
  in_votazione: 'In Votazione', pubblicata: 'Pubblicata', respinta_votazione: 'Respinta'
};

function renderPropostaCard(p) {
  const { user } = getState();
  const badgeVariant = p.stato === 'in_votazione' ? 'default' : p.stato === 'in_attesa' ? 'warning' : 'secondary';
  return `
    <div class="card" style="margin-bottom:var(--space-3);">
      <div class="card-content">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-2);">
          <h3 style="font-size:var(--text-base);font-weight:var(--font-semibold);">${p.nome_prodotto}</h3>
          ${Badge({ variant: badgeVariant, children: STATI_PROPOSTA_LABELS[p.stato] || p.stato })}
        </div>
        <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">${p.descrizione || ''}</p>
        ${p.motivo ? `<div class="text-xs" style="margin-bottom:var(--space-2);padding:var(--space-2);background:var(--color-bg);border-radius:var(--radius-sm);">Motivo: ${p.motivo}</div>` : ''}
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span class="text-xs text-secondary">Proposto da: ${p.proponente_nome || ''} ${p.proponente_cognome || ''}</span>
          <div style="display:flex;align-items:center;gap:var(--space-2);">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/></svg>
            <span class="text-sm font-medium">${p.tot_voti || 0} voti</span>
          </div>
        </div>
        ${(p.stato === 'in_votazione') && user ? `
          <div style="margin-top:var(--space-3);display:flex;gap:var(--space-2);">
            ${p.mio_voto === 'favore' ? `
            <button class="btn btn-secondary btn-sm" onclick="handleNonVotare(${p.id_proposta})">
              <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              Non votare
            </button>` : `
            <button class="btn btn-outline btn-sm" onclick="handleVota(${p.id_proposta})">
              <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/></svg>
              Vota
            </button>`}
          </div>` : ''}
      </div>
    </div>`;
}

window.handleVota = async function(idProposta) {
  try {
    await apiPost(`/proposte/${idProposta}/vota`, { valore_voto: 'favore' });
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Voto registrato!</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
    renderTabContent();
  } catch (err) {
    alert(err.message);
  }
};

window.handleNonVotare = async function(idProposta) {
  try {
    await apiDelete(`/proposte/${idProposta}/vota`);
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Voto ritirato</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
    renderTabContent();
  } catch (err) {
    alert(err.message);
  }
};

let currentTab = 'votazione';
let allProposte = [];

async function renderTabContent() {
  const content = document.getElementById('proposte-tab-content');
  if (!content) return;
  try {
    const res = await apiGet('/proposte');
    allProposte = res.dati || [];
  } catch (e) { allProposte = []; }

  if (currentTab === 'votazione') {
    const proposte = allProposte.filter(p => p.stato === 'in_votazione');
    content.innerHTML = proposte.length > 0 ? proposte.map(p => renderPropostaCard(p)).join('') : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessuna proposta in votazione</h2></div>';
  } else if (currentTab === 'approvate') {
    const proposte = allProposte.filter(p => ['approvata_admin', 'pubblicata'].includes(p.stato));
    content.innerHTML = proposte.length > 0 ? proposte.map(p => renderPropostaCard(p)).join('') : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessuna proposta approvata</h2></div>';
  } else if (currentTab === 'mie') {
    const { user: me } = getState();
    const proposte = allProposte.filter(p => me && p.proponente_id === me.id);
    content.innerHTML = proposte.length > 0 ? proposte.map(p => renderPropostaCard(p)).join('') : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Non hai ancora proposto nulla</h2></div>';
  } else if (currentTab === 'rifiutate') {
    const { user: me } = getState();
    const proposte = allProposte.filter(p => ['rifiutata', 'respinta_votazione'].includes(p.stato) && me && p.proponente_id === me.id);
    content.innerHTML = proposte.length > 0 ? proposte.map(p => renderPropostaCard(p)).join('') : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessuna proposta rifiutata</h2></div>';
  } else if (currentTab === 'nuova') {
    content.innerHTML = `
      <div class="card"><div class="card-content">
        <h3 style="font-size:var(--text-lg);font-weight:var(--font-semibold);margin-bottom:var(--space-4);">Nuova Proposta</h3>
        <form id="proposta-form" style="display:flex;flex-direction:column;gap:var(--space-4);">
          <div class="input-group"><label class="input-label">Nome Prodotto *</label><input type="text" name="nome_prodotto" class="input" placeholder="Es: Smartwatch XYZ" required></div>
          <div class="input-group"><label class="input-label">Descrizione</label><textarea name="descrizione" class="input" placeholder="Descrivi il prodotto..."></textarea></div>
          <div id="proposta-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;"></div>
          <div id="proposta-success" style="color:var(--color-success);font-size:var(--text-sm);display:none;"></div>
          <div style="display:flex;gap:var(--space-3);flex-wrap:wrap;">
            <button type="submit" class="btn btn-default">Invia Proposta</button>
            <button type="button" class="btn btn-outline" onclick="switchProposteTab('votazione')">Torna alle proposte</button>
          </div>
        </form>
      </div></div>`;
    document.getElementById('proposta-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const errorEl = document.getElementById('proposta-error');
      const successEl = document.getElementById('proposta-success');
      errorEl.style.display = 'none'; successEl.style.display = 'none';
      const nome = form.nome_prodotto.value.trim();
      if (!nome) { errorEl.textContent = 'Il nome e obbligatorio.'; errorEl.style.display = 'block'; return; }
      try {
        await apiPost('/proposte', { nome_prodotto: nome, descrizione: form.descrizione.value.trim() });
        successEl.textContent = 'Proposta inviata con successo!'; successEl.style.display = 'block';
        form.reset();
        setTimeout(() => switchProposteTab('votazione'), 1500);
      } catch (err) { errorEl.textContent = err.message; errorEl.style.display = 'block'; }
    });
  }
}

window.switchProposteTab = function(tab) {
  currentTab = tab;
  document.querySelectorAll('.wishlist-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
  renderTabContent();
  const btn = document.getElementById('proposte-add-btn');
  if (btn) btn.style.display = currentTab === 'nuova' ? 'none' : 'inline-flex';
};

export async function PropostePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Proposte</h1>
        <button class="btn btn-default" id="proposte-add-btn" onclick="switchProposteTab('nuova')">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Nuova Proposta
        </button>
      </div>
      <div class="wishlist-tabs">
        <button class="wishlist-tab active" data-tab="votazione" onclick="switchProposteTab('votazione')">In Votazione</button>
        <button class="wishlist-tab" data-tab="approvate" onclick="switchProposteTab('approvate')">Approvate</button>
        <button class="wishlist-tab" data-tab="mie" onclick="switchProposteTab('mie')">Le mie proposte</button>
        <button class="wishlist-tab" data-tab="rifiutate" onclick="switchProposteTab('rifiutate')">Rifiutate</button>
      </div>
      <div id="proposte-tab-content"><div class="loading-spinner">Caricamento...</div></div>
    </div>`;
  renderTabContent();
}
