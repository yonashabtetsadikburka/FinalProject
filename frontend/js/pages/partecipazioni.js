import { getState } from '../state.js';
import { getPrenotazioniByUtente, getCollettaById, QR_CODES } from '../mock.js';
import { Badge } from '../components/badge.js';
import { STATI_PRENOTAZIONE_BADGES, STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../constants.js';

const STATI_LABELS = {
  prenotata: 'Prenotata',
  confermata: 'Pagata',
  annullata: 'Annullata',
  rimborsata: 'Rimborsata'
};

function renderQrModal(prenotazione, qr) {
  const modalHtml = `
    <div id="qr-modal-partecipazioni" class="modal-overlay" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;">
      <div class="modal-content card" style="max-width: 400px; width: 90%;">
        <div class="card-content" style="text-align: center;">
          <h3 style="margin-bottom: var(--space-4);">Il tuo QR Code</h3>
          <p class="text-sm text-secondary" style="margin-bottom: var(--space-4);">
            Mostra questo codice al referente del punto ritiro per ritirare il tuo ordine.
          </p>
          <div style="background: var(--color-bg); border-radius: var(--radius-md); padding: var(--space-6); margin-bottom: var(--space-4);">
            <svg id="qr-svg-p" width="200" height="200" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"></svg>
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
              <div class="font-medium">${qr.stato === 'scansionato' ? 'Ritirato' : qr.stato}</div>
            </div>
          </div>
          <button class="btn btn-outline w-full" onclick="closeQrModalP()">Chiudi</button>
        </div>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
  setTimeout(() => generateQrVisualP(qr.token), 50);
}

function generateQrVisualP(token) {
  const svg = document.getElementById('qr-svg-p');
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

window.closeQrModalP = function() {
  const modal = document.getElementById('qr-modal-partecipazioni');
  if (modal) modal.remove();
};

window.showQrP = function(prenotazioneId) {
  const qr = QR_CODES.find(q => q.id_prenotazione === prenotazioneId);
  if (!qr) return;
  renderQrModal(null, qr);
};

export function PartecipazioniPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const { user } = getState();
  const prenotazioni = user ? getPrenotazioniByUtente(user.id) : [];

  const partecipazioniHtml = prenotazioni.length > 0 ? prenotazioni.map(p => {
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
              <h3 style="font-size: var(--text-base); font-weight: var(--font-semibold); margin-bottom: var(--space-1);">
                <a href="#/campagne/${p.id_colletta}" style="color: inherit; text-decoration: none;">${nomeProdotto}</a>
              </h3>
              ${nomeFornitore ? `<span class="text-xs text-secondary">${nomeFornitore}</span>` : ''}
            </div>
            <div style="display: flex; gap: var(--space-2); flex-wrap: wrap;">
              ${Badge({ variant: STATI_CAMPAGNA_BADGES[statoColletta] || 'secondary', children: STATI_CAMPAGNA_LABELS[statoColletta] || statoColletta })}
              ${Badge({ variant: STATI_PRENOTAZIONE_BADGES[p.stato] || 'secondary', children: STATI_LABELS[p.stato] || p.stato })}
            </div>
          </div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-top: var(--space-3); padding-top: var(--space-3); border-top: 1px solid var(--color-border);">
            <div style="display: flex; gap: var(--space-4);">
              <div>
                <div class="text-xs text-secondary">Quantita</div>
                <div class="font-medium text-sm">${p.quantita} pezzi</div>
              </div>
              <div>
                <div class="text-xs text-secondary">Data</div>
                <div class="font-medium text-sm">${new Date(p.data_prenotazione).toLocaleDateString('it-IT')}</div>
              </div>
            </div>
            <div style="display: flex; align-items: center; gap: var(--space-2);">
              ${isRitirato ? Badge({ variant: 'success', children: 'Ritirato' }) : ''}
              ${showQrButton ? `
                <button class="btn btn-default btn-sm" onclick="showQrP(${p.id})">
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                  Mostra QR
                </button>
              ` : ''}
            </div>
          </div>
        </div>
      </div>
    `;
  }).join('') : `
    <div class="empty-state" style="padding: var(--space-8);">
      <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <h2 class="empty-state-title">Nessuna partecipazione</h2>
      <p class="empty-state-description">Non partecipi ancora a nessuna campagna.</p>
      <a href="#/" class="btn btn-default">Esplora le campagne</a>
    </div>
  `;

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Le mie partecipazioni</h1>
      </div>
      ${partecipazioniHtml}
    </div>
  `;
}
