import { getState } from '../state.js';
import { getCollettaById, PRENOTAZIONI } from '../mock.js';
import { apiPost } from '../api.js';
import { Progress } from '../components/progress.js';
import { Badge } from '../components/badge.js';
import { Card, CardHeader, CardTitle, CardContent, CardFooter } from '../components/card.js';
import { Modal } from '../components/modal.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../constants.js';

let currentIdColletta = null;

function isPartecipato(idColletta) {
  const { user } = getState();
  if (!user) return false;
  return PRENOTAZIONI.some(p => p.id_colletta === parseInt(idColletta) && p.id_utente === user.id);
}

function getMiaPrenotazione(idColletta) {
  const { user } = getState();
  if (!user) return null;
  return PRENOTAZIONI.find(p => p.id_colletta === parseInt(idColletta) && p.id_utente === user.id);
}

async function handlePagaOra(prenotazioneId) {
  try {
    const res = await apiPost('/pagamento/checkout', { prenotazione_id: prenotazioneId });
    if (res.url) {
      window.location.href = res.url;
    }
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore pagamento</div><div class="toast-description">${err.message || 'Impossibile avviare il pagamento'}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
}

function executePartecipa() {
  const { user } = getState();
  if (!user || !currentIdColletta) return;

  const colletta = getCollettaById(currentIdColletta);
  if (!colletta) return;

  const newPrenotazione = {
    id: PRENOTAZIONI.length + 1,
    id_colletta: parseInt(currentIdColletta),
    id_utente: user.id,
    quantita: 1,
    importo_acconto: 0,
    importo_saldo: null,
    importo_commissione: 0,
    stato: 'prenotata',
    data_prenotazione: new Date().toISOString().split('T')[0],
    data_pagamento: null,
    colletta: null
  };
  PRENOTAZIONI.push(newPrenotazione);

  // Incrementa quantita attuale della colletta
  colletta.quantita_attuale++;

  renderDettaglio();
}

function handlePartecipa() {
  const { user } = getState();
  const colletta = getCollettaById(currentIdColletta);
  if (!colletta || !user) return;

  const nomeProdotto = colletta.prodotto?.nome || 'Campagna #' + currentIdColletta;

  const modalHtml = Modal({
    id: 'partecipa-modal',
    isOpen: true,
    title: 'Conferma partecipazione',
    children: `
      <div style="display: flex; flex-direction: column; gap: var(--space-4);">
        <p style="color: var(--color-text); line-height: 1.6;">
          Stai per partecipare alla campagna <strong>${nomeProdotto}</strong>.
        </p>
        <div style="background: var(--color-primary-light); border-radius: var(--radius-md); padding: var(--space-3); display: flex; align-items: start; gap: var(--space-3);">
          <svg width="20" height="20" fill="none" stroke="var(--color-primary)" viewBox="0 0 24 24" style="flex-shrink: 0; margin-top: 2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span style="font-size: var(--text-sm); color: var(--color-text-secondary);">
            Nessun addebito ora. Pagherai solo quando l'ordine verra confermato dall'admin.
          </span>
        </div>
        <div style="display: flex; gap: var(--space-3); justify-content: flex-end; margin-top: var(--space-2);">
          <button class="btn btn-outline" onclick="closePartecipaModal()">Annulla</button>
          <button class="btn btn-default" onclick="confirmPartecipa()">Conferma</button>
        </div>
      </div>
    `
  });

  document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function handleCondividi() {
  const url = window.location.origin + '/buypool/frontend/app.html#/campagne/' + currentIdColletta;
  const testo = 'Guarda questa campagna su BuyPool!';

  const modalHtml = Modal({
    id: 'share-modal',
    isOpen: true,
    title: 'Condividi campagna',
    children: `
      <div style="display: flex; flex-direction: column; gap: var(--space-3);">
        <a href="https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}" target="_blank" rel="noopener noreferrer" class="btn btn-outline w-full" style="justify-content: flex-start; gap: var(--space-3);">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
          Facebook
        </a>
        <a href="https://twitter.com/intent/tweet?url=${encodeURIComponent(url)}&text=${encodeURIComponent(testo)}" target="_blank" rel="noopener noreferrer" class="btn btn-outline w-full" style="justify-content: flex-start; gap: var(--space-3);">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="#1DA1F2"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg>
          Twitter
        </a>
        <button class="btn btn-outline w-full" style="justify-content: flex-start; gap: var(--space-3);" onclick="alert('Apri Instagram e incolla il link!'); closeShareModal();">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#E4405F" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
          Instagram
        </button>
        <button class="btn btn-default w-full" style="justify-content: flex-start; gap: var(--space-3);" onclick="handleCopiaLink()">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
          Copia il link
        </button>
      </div>
    `
  });

  document.body.insertAdjacentHTML('beforeend', modalHtml);
}

window.closeShareModal = function() {
  const modal = document.getElementById('share-modal');
  if (modal) modal.remove();
};

window.handleCopiaLink = function() {
  const url = window.location.origin + '/buypool/frontend/app.html#/campagne/' + currentIdColletta;
  navigator.clipboard.writeText(url).then(() => {
    closeShareModal();
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Link copiato!</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  }).catch(() => {
    closeShareModal();
  });
};

function renderDettaglio() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const colletta = getCollettaById(currentIdColletta);

  if (!colletta) {
    content.innerHTML = `
      <div class="empty-state">
        <h2 class="empty-state-title">Campagna non trovata</h2>
        <a href="#/" class="btn btn-default">Torna alle campagne</a>
      </div>
    `;
    return;
  }

  const percentage = Math.round((colletta.quantita_attuale / colletta.quantita_minima) * 100);
  const discount = Math.round((1 - colletta.prezzo_corrente / colletta.prezzo_base) * 100);
  const deadline = new Date(colletta.data_limite);
  const now = new Date();
  const daysLeft = Math.max(0, Math.ceil((deadline - now) / (1000 * 60 * 60 * 24)));
  const partecipato = isPartecipato(currentIdColletta);
  const miaPrenotazione = getMiaPrenotazione(currentIdColletta);
  const devePagare = miaPrenotazione && miaPrenotazione.stato === 'confermata';
  const haPagato = miaPrenotazione && (miaPrenotazione.stato === 'pagata' || miaPrenotazione.stato === 'confermata' && miaPrenotazione.data_pagamento);

  content.innerHTML = `
    <div class="content-area">
      <div style="margin-bottom: var(--space-4);">
        <a href="#/" class="btn btn-ghost btn-sm">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          Torna alle campagne
        </a>
      </div>
      <div class="detail-layout">
        <div class="detail-main">
          <div class="detail-image" style="display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--color-primary-light), var(--gray-100));">
            <svg width="96" height="96" fill="none" stroke="var(--color-primary)" viewBox="0 0 24 24" opacity="0.4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
          </div>
          ${Card({ children: `
            <div class="card-content">
              <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-3);">
                <h2 style="font-size: var(--text-2xl); font-weight: var(--font-bold);">${colletta.prodotto?.nome || 'Prodotto'}</h2>
                <span class="discount-badge" style="font-size: var(--text-sm); padding: 4px 8px;">-${discount}%</span>
              </div>
              <p class="text-secondary" style="margin-bottom: var(--space-4);">${colletta.prodotto?.descrizione || ''}</p>
              <div style="margin-bottom: var(--space-4);">
                <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-2);">
                  <span class="text-sm text-secondary">Avanzamento</span>
                  <span class="text-sm font-medium">${colletta.quantita_attuale} / ${colletta.quantita_minima} pezzi (${percentage}%)</span>
                </div>
                ${Progress({ value: colletta.quantita_attuale, max: colletta.quantita_minima })}
              </div>
              <div class="countdown" style="font-size: var(--text-base); margin-bottom: var(--space-4);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                ${daysLeft} giorni rimanenti
              </div>
            </div>
          ` })}
        </div>
        <div class="detail-sidebar">
          ${Card({ children: `
            <div class="card-content">
              <h3 style="margin-bottom: var(--space-4);">Riepilogo</h3>
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">Prezzo attuale</span>
                <span class="font-bold text-primary" style="font-size: var(--text-xl);">&euro;${colletta.prezzo_corrente.toFixed(2)}</span>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">Prezzo base</span>
                <span class="text-sm" style="text-decoration: line-through;">&euro;${colletta.prezzo_base.toFixed(2)}</span>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">Scadenza</span>
                <span class="text-sm font-medium">${new Date(colletta.data_limite).toLocaleDateString('it-IT')}</span>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-3);">
                <span class="text-sm text-secondary">MOQ</span>
                <span class="text-sm font-medium">${colletta.quantita_minima} pezzi</span>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-4);">
                <span class="text-sm text-secondary">Commissione</span>
                <span class="text-sm font-medium">${colletta.percentuale_commissione || 10}%</span>
              </div>
              ${devePagare ? `
                <button class="btn btn-success w-full" onclick="handlePagaOra(${miaPrenotazione.id})">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                  Paga ora — &euro;${(miaPrenotazione.importo_saldo || 0).toFixed(2)}
                </button>
              ` : haPagato ? `
                <button class="btn btn-success w-full" disabled style="opacity: 1;">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                  Pagato
                </button>
              ` : partecipato ? `
                <button class="btn btn-secondary w-full" disabled style="opacity: 1;">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                  Partecipato — In attesa conferma
                </button>
              ` : `
                <button class="btn btn-default w-full" onclick="handlePartecipa()">Partecipa ora</button>
              `}
              <button class="btn btn-outline w-full" style="margin-top: var(--space-2);" onclick="handleCondividi()">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                Condividi
              </button>
            </div>
          ` })}
          ${Card({ children: `
            <div class="card-content">
              <h3 style="margin-bottom: var(--space-3);">Fornitore</h3>
              <div style="display: flex; align-items: center; gap: var(--space-3);">
                <div class="supplier-logo" style="width: 48px; height: 48px; font-size: var(--text-lg);">
                  ${(colletta.fornitore?.nome_azienda || 'F')[0]}
                </div>
                <div>
                  <div class="font-medium">${colletta.fornitore?.nome_azienda || 'Fornitore'}</div>
                  <div class="text-sm text-secondary">Trust Score: ${colletta.fornitore?.trust_score || 0}/100</div>
                </div>
              </div>
            </div>
          ` })}
        </div>
      </div>
    </div>
  `;
}

export function DettaglioPage(params) {
  currentIdColletta = params.id;
  renderDettaglio();
}

window.handlePartecipa = handlePartecipa;
window.handleCondividi = handleCondividi;
window.handlePagaOra = handlePagaOra;

window.closePartecipaModal = function() {
  const modal = document.getElementById('partecipa-modal');
  if (modal) modal.remove();
};

window.confirmPartecipa = function() {
  closePartecipaModal();
  executePartecipa();
};
