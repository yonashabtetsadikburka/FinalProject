import { getState } from '../state.js';
import { PROPOSTE_PRODOTTI, FORNITORI, getUtenteById } from '../mock.js';
import { Badge } from '../components/badge.js';

const STATI_PROPOSTA_LABELS = {
  in_attesa: 'In Attesa',
  approvata_admin: 'Approvata',
  rifiutata: 'Rifiutata',
  in_votazione: 'In Votazione',
  pubblicata: 'Pubblicata',
  respinta_votazione: 'Respinta'
};

function renderPropostaCard(p, showVote = false) {
  const proponente = p.proponente_tipo === 'cliente' ? getUtenteById(p.proponente_id) : { nome: 'Admin', cognome: 'BuyPool' };
  const { user } = getState();
  const badgeVariant = p.stato === 'in_votazione' ? 'default' : p.stato === 'in_attesa' ? 'warning' : 'secondary';
  const haVotato = user && p.votatoDa && p.votatoDa.includes(user.id);

  return `
    <div class="card" style="margin-bottom: var(--space-3);">
      <div class="card-content">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-2);">
          <h3 style="font-size: var(--text-base); font-weight: var(--font-semibold);">${p.nome_prodotto}</h3>
          ${Badge({ variant: badgeVariant, children: STATI_PROPOSTA_LABELS[p.stato] || p.stato })}
        </div>
        <p class="text-sm text-secondary" style="margin-bottom: var(--space-3);">${p.descrizione || ''}</p>
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <span class="text-xs text-secondary">Proposto da: ${proponente?.nome || ''} ${proponente?.cognome || ''}</span>
          <div style="display: flex; align-items: center; gap: var(--space-2);">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/></svg>
            <span class="text-sm font-medium">${p.tot_voti} voti</span>
          </div>
        </div>
        ${showVote ? `
          <div style="margin-top: var(--space-3); display: flex; gap: var(--space-2);">
            ${haVotato ? `
              <button class="btn btn-secondary btn-sm" disabled style="opacity: 1;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Votato
              </button>
              <button class="btn btn-outline btn-sm" onclick="handleAnnullaVoto(${p.id_proposta})">
                Annulla voto
              </button>
            ` : `
              <button class="btn btn-outline btn-sm" onclick="handleVota(${p.id_proposta})">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/></svg>
                Vota
              </button>
            `}
          </div>
        ` : ''}
      </div>
    </div>
  `;
}

function renderEmptyState(message) {
  return `
    <div class="empty-state" style="padding: var(--space-8);">
      <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
      <h2 class="empty-state-title">${message}</h2>
    </div>
  `;
}

let currentTab = 'votazione';

function renderTabContent() {
  const content = document.getElementById('proposte-tab-content');
  if (!content) return;

  if (currentTab === 'votazione') {
    const proposte = PROPOSTE_PRODOTTI.filter(p => p.stato === 'in_votazione');
    content.innerHTML = proposte.length > 0
      ? proposte.map(p => renderPropostaCard(p, true)).join('')
      : renderEmptyState('Nessuna proposta in votazione');
  } else if (currentTab === 'approvate') {
    const proposte = PROPOSTE_PRODOTTI.filter(p => ['approvata_admin', 'pubblicata'].includes(p.stato));
    content.innerHTML = proposte.length > 0
      ? proposte.map(p => renderPropostaCard(p, false)).join('')
      : renderEmptyState('Nessuna proposta approvata');
  } else if (currentTab === 'nuova') {
    content.innerHTML = renderNuovaPropostaForm();
    bindFormEvents();
  }
}

function renderNuovaPropostaForm() {
  const fornitoriOptions = FORNITORI.map(f =>
    `<option value="${f.id}">${f.nome_azienda}</option>`
  ).join('');

  return `
    <div class="card">
      <div class="card-content">
        <h3 style="font-size: var(--text-lg); font-weight: var(--font-semibold); margin-bottom: var(--space-4);">Nuova Proposta</h3>
        <form class="proposta-form" id="proposta-form">
          <div class="input-group">
            <label class="input-label">Nome Prodotto *</label>
            <input type="text" name="nome_prodotto" class="input" placeholder="Es: Smartwatch XYZ" required>
          </div>
          <div class="input-group">
            <label class="input-label">Descrizione</label>
            <textarea name="descrizione" class="input" placeholder="Descrivi il prodotto che vorresti vedere in piattaforma..."></textarea>
          </div>
          <div class="input-group">
            <label class="input-label">Fornitore Suggerito</label>
            <select name="id_fornitore" class="input">
              <option value="">Seleziona un fornitore (opzionale)</option>
              ${fornitoriOptions}
            </select>
          </div>
          <div id="proposta-error" style="color: var(--color-error); font-size: var(--text-sm); display: none;"></div>
          <div id="proposta-success" style="color: var(--color-success); font-size: var(--text-sm); display: none;"></div>
          <div style="display: flex; gap: var(--space-3);">
            <button type="submit" class="btn btn-default">Invia Proposta</button>
            <button type="button" class="btn btn-outline" onclick="switchProposteTab('votazione')">Torna alle proposte</button>
          </div>
        </form>
      </div>
    </div>
  `;
}

function bindFormEvents() {
  const form = document.getElementById('proposta-form');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('proposta-error');
    const successEl = document.getElementById('proposta-success');
    errorEl.style.display = 'none';
    successEl.style.display = 'none';

    const nomeProdotto = form.nome_prodotto.value.trim();
    if (!nomeProdotto) {
      errorEl.textContent = 'Il nome del prodotto e obbligatorio.';
      errorEl.style.display = 'block';
      return;
    }

    const { user } = getState();
    const newProposta = {
      id_proposta: PROPOSTE_PRODOTTI.length + 1,
      proponente_tipo: 'cliente',
      proponente_id: user?.id || 1,
      nome_prodotto: nomeProdotto,
      descrizione: form.descrizione.value.trim(),
      image_url: null,
      id_fornitore_suggerito: form.id_fornitore.value ? parseInt(form.id_fornitore.value) : null,
      stato: 'in_attesa',
      id_admin_gestione: null,
      tot_voti: 0,
      data_proposta: new Date().toISOString().split('T')[0],
      data_gestione: null,
      utente: null,
      voti: [],
      votatoDa: []
    };

    PROPOSTE_PRODOTTI.push(newProposta);

    successEl.textContent = 'Proposta inviata con successo! Sara esaminata da un amministratore.';
    successEl.style.display = 'block';
    form.reset();

    setTimeout(() => {
      switchProposteTab('votazione');
    }, 1500);
  });
}

window.switchProposteTab = function(tab) {
  currentTab = tab;
  document.querySelectorAll('.wishlist-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === tab);
  });
  renderTabContent();
  updateHeaderButton();
};

function updateHeaderButton() {
  const btn = document.getElementById('proposte-add-btn');
  if (!btn) return;
  btn.style.display = currentTab === 'nuova' ? 'none' : 'inline-flex';
}

window.handleVota = function(idProposta) {
  const proposta = PROPOSTE_PRODOTTI.find(p => p.id_proposta === idProposta);
  const { user } = getState();
  if (proposta && user) {
    if (!proposta.votatoDa) proposta.votatoDa = [];
    if (!proposta.votatoDa.includes(user.id)) {
      proposta.votatoDa.push(user.id);
      proposta.tot_voti++;
      renderTabContent();
    }
  }
};

window.handleAnnullaVoto = function(idProposta) {
  const proposta = PROPOSTE_PRODOTTI.find(p => p.id_proposta === idProposta);
  const { user } = getState();
  if (proposta && user && proposta.votatoDa) {
    const idx = proposta.votatoDa.indexOf(user.id);
    if (idx !== -1) {
      proposta.votatoDa.splice(idx, 1);
      proposta.tot_voti = Math.max(0, proposta.tot_voti - 1);
      renderTabContent();
    }
  }
};

export function PropostePage() {
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
        <button class="wishlist-tab active" data-tab="votazione" onclick="switchProposteTab('votazione')">
          In Votazione
        </button>
        <button class="wishlist-tab" data-tab="approvate" onclick="switchProposteTab('approvate')">
          Approvate
        </button>
      </div>

      <div id="proposte-tab-content"></div>
    </div>
  `;

  renderTabContent();
}
