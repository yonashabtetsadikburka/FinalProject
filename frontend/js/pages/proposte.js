import { getState } from '../state.js';
import { apiGet, apiPost, apiDelete } from '../api.js';
import { Badge } from '../components/badge.js';
import { Progress } from '../components/progress.js';
import { Avatar } from '../components/dropdown.js';
import { SOGLIA_VOTI_PROPOSTA } from '../constants.js';

const STATI_MIE_LABELS = {
  in_votazione: 'In votazione', in_attesa: 'In attesa',
  approvata_admin: 'Approvata', pubblicata: 'Approvata',
  rifiutata: 'Rifiutata', respinta_votazione: 'Rifiutata'
};

const STATI_MIE_BADGES = {
  in_votazione: 'warning', in_attesa: 'warning',
  approvata_admin: 'success', pubblicata: 'success',
  rifiutata: 'secondary', respinta_votazione: 'secondary'
};

function inizialiProponente(p) {
  const nome = (p.proponente_nome || 'U').trim();
  const cog = (p.proponente_cognome || '').trim();
  return (nome[0] || 'U') + (cog ? cog[0] : '');
}

function nomeProponente(p) {
  const nome = (p.proponente_nome || 'Utente').trim();
  const cog = (p.proponente_cognome || '').trim();
  return cog ? `${nome} ${cog[0]}.` : nome;
}

function renderPropostaCard(p) {
  const { user } = getState();
  const voti = parseInt(p.tot_voti) || 0;
  const pct = Math.min(100, Math.round((voti / SOGLIA_VOTI_PROPOSTA) * 100));
  const votato = p.mio_voto === 'favore';
  const mia = user && p.proponente_id === user.id;
  const votabile = p.stato === 'in_votazione' && user && !mia;
  const headTone = (p.stato === 'rifiutata' || p.stato === 'respinta_votazione') ? 'danger'
    : (STATI_MIE_BADGES[p.stato] === 'success' ? 'success'
    : (STATI_MIE_BADGES[p.stato] === 'warning' ? 'warning' : 'neutral'));
  const badgeVariant = (p.stato === 'rifiutata' || p.stato === 'respinta_votazione') ? 'destructive'
    : (STATI_MIE_BADGES[p.stato] || 'secondary');
  return `
    <div class="card card-elevate">
      <div class="prop-head prop-head-${headTone}" style="padding-top:5px;padding-bottom:0;height:40.8px;">
        <span class="text-sm text-secondary">Stato proposta</span>
        <span style="flex-shrink:0;">${Badge({ variant: badgeVariant, children: STATI_MIE_LABELS[p.stato] || p.stato })}</span>
      </div>
      <div class="card-content" style="padding-top:10px;padding-bottom:0;">
        <div style="display:flex;gap:var(--space-3);">
          <div class="order-thumb order-thumb-empty prop-thumb" style="flex-shrink:0;">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
          </div>
          <div style="flex:1;min-width:0;height:100px;">
            <h3 class="prop-body-title">${p.nome_prodotto}</h3>
            ${p.descrizione
              ? `<p class="text-sm text-secondary prop-slot-desc" style="height:25px;">${p.descrizione}</p>`
              : `<p class="text-sm text-secondary prop-slot-desc prop-slot-empty" style="height:25px;" aria-hidden="true">&nbsp;</p>`}
            ${p.link_riferimento
              ? `<a href="${p.link_riferimento}" target="_blank" rel="noopener" class="text-xs prop-slot-link" style="display:inline-block;margin-bottom:var(--space-1);">Link di riferimento &nearr;</a>`
              : `<span class="text-xs prop-slot-link" style="display:inline-block;margin-bottom:var(--space-1);visibility:hidden;" aria-hidden="true;">&nbsp;</span>`}
            <div style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:var(--space-1);">
              ${Avatar({ fallback: inizialiProponente(p), size: 'sm' })}
              <span class="text-xs text-secondary">Proposto da ${nomeProponente(p)}</span>
            </div>
            ${p.motivo
              ? `<div class="text-xs prop-slot-motivo" style="padding:var(--space-2);background:var(--color-bg);border-radius:var(--radius-sm);">Motivo: ${p.motivo}</div>`
              : `<div class="text-xs prop-slot-motivo" style="padding:var(--space-2);visibility:hidden;" aria-hidden="true;">&nbsp;</div>`}
          </div>
          ${votabile ? `<div class="prop-vote-side">${votato ? `
          <button class="btn btn-default btn-sm" onclick="handleNonVotare(${p.id_proposta})">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Votato
          </button>` : `
          <button class="btn btn-outline btn-sm" onclick="handleVota(${p.id_proposta})">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/></svg>
            Vota
          </button>`}</div>` : ''}
        </div>
      </div>
      <div class="prop-progress">
        <div style="display:flex;justify-content:space-between;margin-bottom:var(--space-1);">
          <span class="text-xs text-secondary">${voti} di ${SOGLIA_VOTI_PROPOSTA} voti per essere considerata</span>
          <span class="text-xs font-medium">${pct}%</span>
        </div>
        ${Progress({ value: voti, max: SOGLIA_VOTI_PROPOSTA, successOnly: true })}
      </div>
    </div>`;
}

function aggiornaLocale(idProposta, mioVoto, delta) {
  const p = allProposte.find(x => x.id_proposta === idProposta);
  if (!p) return;
  p.mio_voto = mioVoto;
  p.tot_voti = Math.max(0, (parseInt(p.tot_voti) || 0) + delta);
  disegnaTab();
}

window.handleVota = async function(idProposta) {
  try {
    await apiPost(`/proposte/${idProposta}/vota`, { valore_voto: 'favore' });
    aggiornaLocale(idProposta, 'favore', 1);
  } catch (err) {
    alert(err.message);
  }
};

window.handleNonVotare = async function(idProposta) {
  try {
    await apiDelete(`/proposte/${idProposta}/vota`);
    aggiornaLocale(idProposta, null, -1);
  } catch (err) {
    alert(err.message);
  }
};

let currentTab = 'votazione';
let allProposte = [];

const EMPTY_TITLES = {
  votazione: 'Nessuna proposta in votazione',
  mie: 'Non hai ancora proposto nulla'
};

function proposteFiltrate() {
  const { user: me } = getState();
  if (currentTab === 'mie') {
    return allProposte.filter(p => me && p.proponente_id === me.id);
  }
  return allProposte.filter(p => p.stato === 'in_votazione');
}

function disegnaTab() {
  const content = document.getElementById('proposte-tab-content');
  if (!content) return;
  const proposte = proposteFiltrate();
  content.innerHTML = proposte.length > 0 ? `<div class="cards-grid">` + proposte.map(p => renderPropostaCard(p)).join('') + `</div>`
    : `<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">${EMPTY_TITLES[currentTab]}</h2></div>`;
}

async function renderTabContent() {
  const content = document.getElementById('proposte-tab-content');
  if (!content) return;
  try {
    const res = await apiGet('/proposte');
    allProposte = res.dati || [];
  } catch (e) { allProposte = []; }
  disegnaTab();
}

window.switchProposteTab = function(tab) {
  currentTab = tab;
  document.querySelectorAll('.wishlist-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
  renderTabContent();
};

window.apriPropostaModal = function() {
  document.getElementById('proposta-modal')?.remove();
  const modalHtml = `
    <div id="proposta-modal" class="modal-overlay" onclick="if(event.target===this)document.getElementById('proposta-modal').remove()">
      <div class="modal-content card" style="max-width:520px;width:92%;max-height:90vh;overflow-y:auto;">
        <div class="card-content">
          <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-1);">
            <h3 style="font-size:var(--text-lg);font-weight:var(--font-semibold);">Nuova Proposta</h3>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('proposta-modal').remove()" aria-label="Chiudi">&#10005;</button>
          </div>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-4);">Servono almeno ${SOGLIA_VOTI_PROPOSTA} voti dalla community perché venga considerata.</p>
          <div id="proposta-modal-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
          <form id="proposta-modal-form" style="display:flex;flex-direction:column;gap:var(--space-3);">
            <div class="input-group">
              <label class="input-label">Nome prodotto</label>
              <input type="text" name="nome_prodotto" class="input" placeholder="Es: Smartwatch XYZ" maxlength="80" required>
            </div>
            <div class="input-group">
              <label class="input-label">Descrizione</label>
              <textarea name="descrizione" class="input" rows="3" placeholder="Descrivi il prodotto..." style="height:auto;"></textarea>
            </div>
            <div class="input-group">
              <label class="input-label">Link di riferimento (opzionale)</label>
              <input type="url" name="link_riferimento" class="input" placeholder="https://...">
            </div>
            <div style="display:flex;gap:var(--space-2);">
              <button type="submit" class="btn btn-default" style="flex:1;">Invia Proposta</button>
              <button type="button" class="btn btn-outline" style="flex:1;" onclick="document.getElementById('proposta-modal').remove()">Annulla</button>
            </div>
          </form>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
  document.getElementById('proposta-modal-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const errorEl = document.getElementById('proposta-modal-error');
    errorEl.style.display = 'none';
    const nome = form.nome_prodotto.value.trim();
    if (!nome) { errorEl.textContent = 'Il nome è obbligatorio.'; errorEl.style.display = 'block'; return; }
    try {
      await apiPost('/proposte', {
        nome_prodotto: nome,
        descrizione: form.descrizione.value.trim(),
        link_riferimento: form.link_riferimento.value.trim()
      });
      document.getElementById('proposta-modal')?.remove();
      switchProposteTab('mie');
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.style.display = 'block';
    }
  });
};

export async function PropostePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <div>
          
          <p class="text-sm text-secondary" style="margin-top:var(--space-1);">Proponi un prodotto che vorresti in una campagna, o vota le proposte di altri utenti per farle partire prima.</p>
        </div>
        <button class="btn btn-default" id="proposte-add-btn" onclick="apriPropostaModal()">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Nuova Proposta
        </button>
      </div>
      <div class="wishlist-tabs">
        <button class="wishlist-tab active" data-tab="votazione" onclick="switchProposteTab('votazione')">In votazione</button>
        <button class="wishlist-tab" data-tab="mie" onclick="switchProposteTab('mie')">Le mie proposte</button>
      </div>
      <div id="proposte-tab-content"><div class="loading-spinner">Caricamento...</div></div>
    </div>`;
  renderTabContent();
}
