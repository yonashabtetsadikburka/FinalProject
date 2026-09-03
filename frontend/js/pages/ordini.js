import { getState } from '../state.js';
import { getPrenotazioniByUtente, getCollettaById, QR_CODES, PRENOTAZIONI, SEDI, PRODOTTI, UTENTI } from '../mock.js';
import { Badge } from '../components/badge.js';
import { STATI_PRENOTAZIONE_BADGES } from '../constants.js';

const STATI_LABELS = {
  prenotata: 'Prenotata',
  confermata: 'In Attesa di Ritiro',
  annullata: 'Annullata',
  rimborsata: 'Rimborsata'
};

function renderQrModal(prenotazione, qr) {
  const modalHtml = `
    <div id="qr-modal" class="modal-overlay" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="modal-content card" style="max-width: 400px; width: 90%;">
        <div class="card-content" style="text-align: center;">
          <h3 style="margin-bottom: var(--space-4);">Il tuo QR Code</h3>
          <p class="text-sm text-secondary" style="margin-bottom: var(--space-4);">
            Mostra questo codice al referente del punto ritiro per ritirare il tuo ordine.
          </p>
          <div style="background: var(--color-bg); border-radius: var(--radius-md); padding: var(--space-6); margin-bottom: var(--space-4);">
            <svg id="qr-svg" width="200" height="200" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"></svg>
          </div>
          <div style="background: var(--color-primary-light); border-radius: var(--radius-sm); padding: var(--space-3); margin-bottom: var(--space-4); font-family: monospace; font-size: var(--text-sm); word-break: break-all;">
            ${qr.token}
          </div>
          <div style="display: flex; justify-content: space-between; margin-bottom: var(--space-4); font-size: var(--text-sm);">
            <div>
              <div class="text-secondary">Quantita</div>
              <div class="font-medium">${qr.quantita_assegnata} pezzi</div>
            </div>
            <div>
              <div class="text-secondary">Stato</div>
              <div class="font-medium">${qr.scansionato ? 'Ritirato' : qr.stato}</div>
            </div>
          </div>
          <button class="btn btn-outline w-full" onclick="closeQrModal()">Chiudi</button>
        </div>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
  setTimeout(() => generateQrVisual(qr.token), 50);
}

function generateQrVisual(token) {
  const svg = document.getElementById('qr-svg');
  if (!svg) return;
  const size = 200;
  const cellSize = 8;
  const grid = Math.floor(size / cellSize);
  let html = '';
  let seed = 0;
  for (let i = 0; i < token.length; i++) seed = ((seed << 5) - seed + token.charCodeAt(i)) | 0;
  function nextRand() { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed; }
  for (let y = 0; y < grid; y++) {
    for (let x = 0; x < grid; x++) {
      const isFinder = (x < 7 && y < 7) || (x >= grid - 7 && y < 7) || (x < 7 && y >= grid - 7);
      if (isFinder) {
        const localX = x < 7 ? x : x - (grid - 7);
        const localY = y < 7 ? y : y - (grid - 7);
        const isBorder = localX === 0 || localX === 6 || localY === 0 || localY === 6;
        const isInner = localX >= 2 && localX <= 4 && localY >= 2 && localY <= 4;
        if (isBorder || isInner) html += `<rect x="${x * cellSize}" y="${y * cellSize}" width="${cellSize}" height="${cellSize}" fill="#000"/>`;
      } else {
        if ((nextRand() & 3) === 0) html += `<rect x="${x * cellSize}" y="${y * cellSize}" width="${cellSize}" height="${cellSize}" fill="#000"/>`;
      }
    }
  }
  for (let i = 8; i < grid - 8; i++) {
    if (i & 1) {
      html += `<rect x="${i * cellSize}" y="${6 * cellSize}" width="${cellSize}" height="${cellSize}" fill="#000"/>`;
      html += `<rect x="${6 * cellSize}" y="${i * cellSize}" width="${cellSize}" height="${cellSize}" fill="#000"/>`;
    }
  }
  svg.innerHTML = html;
}

window.closeQrModal = function() {
  const modal = document.getElementById('qr-modal');
  if (modal) modal.remove();
};

window.showQr = function(prenotazioneId) {
  const qr = QR_CODES.find(q => q.id_prenotazione === prenotazioneId);
  if (!qr) return;
  const prenotazione = PRENOTAZIONI.find(p => p.id === prenotazioneId);
  renderQrModal(prenotazione, qr);
};

function renderScannerSection() {
  return `
    <div class="card" style="border: 2px solid var(--color-primary); border-radius: var(--radius-lg);">
      <div class="card-content">
        <h3 style="margin-bottom: var(--space-3); display: flex; align-items: center; gap: var(--space-2);">
          <svg width="20" height="20" fill="none" stroke="var(--color-primary)" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
          Scanner QR Code
        </h3>
        <p class="text-sm text-secondary" style="margin-bottom: var(--space-4);">
          Incolla il codice QR del partecipante per confermare il ritiro.
        </p>
        <div style="display: flex; gap: var(--space-2);">
          <input type="text" id="qr-token-input" class="form-input" placeholder="Incolla token QR qui..."
                 style="flex: 1; font-family: monospace; font-size: var(--text-sm);" />
          <button class="btn btn-default" onclick="handleScanToken()">Conferma Ritiro</button>
        </div>
        <div id="scan-result" style="margin-top: var(--space-3);"></div>
      </div>
    </div>
  `;
}

window.handleScanToken = function() {
  const input = document.getElementById('qr-token-input');
  const resultDiv = document.getElementById('scan-result');
  if (!input || !resultDiv) return;

  const token = input.value.trim();
  if (!token) {
    resultDiv.innerHTML = `<div style="color: var(--color-error); font-size: var(--text-sm);">Inserisci un token valido.</div>`;
    return;
  }

  const qr = QR_CODES.find(q => q.token === token);
  if (!qr) {
    resultDiv.innerHTML = `<div style="color: var(--color-error); font-size: var(--text-sm);">Token non valido. Nessun QR code trovato.</div>`;
    return;
  }
  if (qr.stato === 'scansionato') {
    resultDiv.innerHTML = `<div style="color: var(--color-error); font-size: var(--text-sm);">Questo articolo e' gia' stato ritirato.</div>`;
    return;
  }
  if (qr.stato === 'annullato') {
    resultDiv.innerHTML = `<div style="color: var(--color-error); font-size: var(--text-sm);">Questo QR code e' stato annullato.</div>`;
    return;
  }

  const prenotazione = PRENOTAZIONI.find(p => p.id === qr.id_prenotazione);
  if (!prenotazione) {
    resultDiv.innerHTML = `<div style="color: var(--color-error); font-size: var(--text-sm);">Prenotazione non trovata.</div>`;
    return;
  }

  const prodotto = getCollettaById(prenotazione.id_colletta)?.prodotto?.nome || 'Prodotto';
  const utente = UTENTI.find(u => u.id === prenotazione.id_utente);

  resultDiv.innerHTML = `
    <div style="background: var(--color-success-light); border-radius: var(--radius-md); padding: var(--space-4);">
      <div style="font-weight: var(--font-semibold); margin-bottom: var(--space-2); color: var(--color-success);">Token valido!</div>
      <div style="font-size: var(--text-sm); margin-bottom: var(--space-2);">Prodotto: <strong>${prodotto}</strong></div>
      <div style="font-size: var(--text-sm); margin-bottom: var(--space-2);">Quantita: <strong>${qr.quantita_assegnata} pezzi</strong></div>
      <div style="font-size: var(--text-sm); margin-bottom: var(--space-4);">Partecipante: <strong>${utente ? utente.nome + ' ' + utente.cognome : 'Sconosciuto'}</strong></div>
      <button class="btn btn-success" onclick="confirmScanRitiro('${token}')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        Conferma Ritiro
      </button>
    </div>
  `;
};

window.confirmScanRitiro = function(token) {
  const qr = QR_CODES.find(q => q.token === token);
  if (!qr) return;
  qr.stato = 'scansionato';
  qr.data_scansione = new Date().toISOString();
  const resultDiv = document.getElementById('scan-result');
  if (resultDiv) {
    resultDiv.innerHTML = `
      <div style="background: var(--color-success-light); border-radius: var(--radius-md); padding: var(--space-4); text-align: center;">
        <svg width="48" height="48" fill="none" stroke="var(--color-success)" viewBox="0 0 24 24" style="margin-bottom: var(--space-2);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <div style="font-weight: var(--font-bold); color: var(--color-success);">Ritiro Confermato!</div>
      </div>
    `;
  }
  const input = document.getElementById('qr-token-input');
  if (input) input.value = '';
  setTimeout(() => {
    const resultDiv = document.getElementById('scan-result');
    if (resultDiv) resultDiv.innerHTML = '';
  }, 3000);
};

export function OrdiniPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const { user } = getState();
  const userIsAdmin = user && user.ruolo === 'admin';
  const prenotazioni = user ? getPrenotazioniByUtente(user.id) : [];

  // --- Sezione 1: I tuoi ordini ---
  const ordiniHtml = prenotazioni.length > 0 ? prenotazioni.map(p => {
    const colletta = getCollettaById(p.id_colletta);
    const nomeProdotto = colletta?.prodotto?.nome || 'Campagna #' + p.id_colletta;
    const nomeFornitore = colletta?.fornitore?.nome_azienda || '';
    const statoColletta = colletta?.stato || 'in_corso';
    const qr = QR_CODES.find(q => q.id_prenotazione === p.id);
    const showQrButton = statoColletta === 'ordine_fornitore' && qr && qr.stato === 'generato';
    const isRitirato = qr && qr.stato === 'scansionato';

    return `
      <div class="card" style="margin-bottom: var(--space-3);">
        <div class="card-content">
          <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-2);">
            <div>
              <h3 style="font-size: var(--text-base); font-weight: var(--font-semibold); margin-bottom: var(--space-1);">${nomeProdotto}</h3>
              ${nomeFornitore ? `<span class="text-xs text-secondary">${nomeFornitore}</span>` : ''}
            </div>
            ${Badge({ variant: STATI_PRENOTAZIONE_BADGES[p.stato] || 'secondary', children: STATI_LABELS[p.stato] || p.stato })}
          </div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--color-border);">
            <div style="display: flex; gap: var(--space-4);">
              <div>
                <div class="text-xs text-secondary">Quantita</div>
                <div class="font-medium text-sm">${p.quantita} pezzi</div>
              </div>
              <div>
                <div class="text-xs text-secondary">Stato Ordine</div>
                <div class="font-medium text-sm">${statoColletta === 'ordine_fornitore' ? 'Ordinato' : statoColletta === 'consegnata' ? 'Consegnato' : 'In attesa'}</div>
              </div>
            </div>
            <div style="display: flex; align-items: center; gap: var(--space-2);">
              ${isRitirato ? Badge({ variant: 'success', children: 'Ritirato' }) : ''}
              ${showQrButton ? `
                <button class="btn btn-default btn-sm" onclick="showQr(${p.id})">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                  Mostra QR
                </button>
              ` : ''}
              <div class="text-xs text-secondary">${new Date(p.data_prenotazione).toLocaleDateString('it-IT')}</div>
            </div>
          </div>
        </div>
      </div>
    `;
  }).join('') : `
    <div class="empty-state" style="padding: var(--space-8);">
      <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
      <h2 class="empty-state-title">Nessun ordine</h2>
      <p class="empty-state-description">Non hai ancora partecipato a nessuna campagna.</p>
      <a href="#/" class="btn btn-default">Esplora le campagne</a>
    </div>
  `;

  // --- Sezione 2: Punti di ritiro ---
  const sediHtml = SEDI.map(s => `
    <div class="card" style="margin-bottom: var(--space-3);">
      <div class="card-content">
        <div class="font-medium" style="margin-bottom: var(--space-2);">${s.nome}</div>
        <div class="text-sm text-secondary" style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-1);">
          <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          ${s.indirizzo}, ${s.citta}
        </div>
        ${s.telefono ? `<div class="text-sm text-secondary" style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-1);"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>${s.telefono}</div>` : ''}
        ${s.orari ? `<div class="text-sm text-secondary" style="display: flex; align-items: center; gap: var(--space-2);"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>${s.orari}</div>` : ''}
      </div>
    </div>
  `).join('');

  // --- Sezione 3: Ordini pronti per ritiro ---
  const ordiniPronti = PRENOTAZIONI.filter(p => {
    const qr = QR_CODES.find(q => q.id_prenotazione === p.id);
    return qr && qr.stato === 'generato';
  });

  const ordiniProntiHtml = ordiniPronti.length > 0 ? ordiniPronti.map(p => {
    const colletta = getCollettaById(p.id_colletta);
    const prodotto = colletta ? PRODOTTI.find(pr => pr.id === colletta.id_prodotto) : null;
    const qr = QR_CODES.find(q => q.id_prenotazione === p.id);
    return `
      <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border);">
        <div>
          <div class="font-medium">${prodotto?.nome || 'Prodotto'}</div>
          <div class="text-sm text-secondary">${qr.quantita_assegnata} pezzi | Token: ${qr.token.substring(0, 8)}...</div>
        </div>
        ${Badge({ variant: 'warning', children: 'In Attesa Ritiro' })}
      </div>
    `;
  }).join('') : '<p class="text-secondary text-center" style="padding: var(--space-4);">Nessun ordine pronto per il ritiro.</p>';

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>I miei ordini</h1>
      </div>

      <div style="margin-bottom: var(--space-6);">
        <h2 style="font-size: var(--text-lg); font-weight: var(--font-semibold); margin-bottom: var(--space-3);">Ordini</h2>
        ${ordiniHtml}
      </div>

      <div style="margin-bottom: var(--space-6);">
        <h2 style="font-size: var(--text-lg); font-weight: var(--font-semibold); margin-bottom: var(--space-3);">Punti di Ritiro</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--space-3);">
          ${sediHtml}
        </div>
      </div>

      ${ordiniPronti.length > 0 ? `
        <div style="margin-bottom: var(--space-6);">
          <h2 style="font-size: var(--text-lg); font-weight: var(--font-semibold); margin-bottom: var(--space-3);">Ordini Pronti per Ritiro</h2>
          <div class="card">
            <div class="card-content">${ordiniProntiHtml}</div>
          </div>
        </div>
      ` : ''}

      ${userIsAdmin ? `
        <div>
          <h2 style="font-size: var(--text-lg); font-weight: var(--font-semibold); margin-bottom: var(--space-3);">Scanner QR Code</h2>
          ${renderScannerSection()}
        </div>
      ` : ''}
    </div>
  `;
}
