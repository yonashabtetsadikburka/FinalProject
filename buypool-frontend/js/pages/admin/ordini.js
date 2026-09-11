import { getState } from '../../state.js';
import { apiGet, apiPost } from '../../api.js';
import { Badge } from '../../components/badge.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES, STATI_PRENOTAZIONE_LABELS } from '../../constants.js';

let currentData = { prenotazioni: [], ordini: [] };

function findOrdine(idColletta) {
  return currentData.ordini.find(o => Number(o.id_colletta) === Number(idColletta));
}

async function confermaOrdine(collettaId) {
  try {
    await apiPost(`/collette/${collettaId}/conferma-ordine`);
    await renderAdminOrdini();
  } catch (e) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${e.message || 'Impossibile confermare l\'ordine'}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
}

async function confermaConsegna(collettaId) {
  try {
    await apiPost(`/collette/${collettaId}/consegna`);
    await renderAdminOrdini();
  } catch (e) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${e.message || 'Impossibile confermare la consegna'}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
}

async function renderAdminOrdini() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';

  try {
    const [pRes, oRes] = await Promise.all([
      apiGet('/prenotazioni'),
      apiGet('/ordini')
    ]);
    currentData = {
      prenotazioni: pRes.dati || [],
      ordini: oRes.dati || []
    };
  } catch (e) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p></div>`;
    return;
  }

  // Group prenotazioni by colletta
  const ordiniByColletta = {};
  currentData.prenotazioni.forEach(p => {
    const idColletta = p.id_colletta;
    if (!ordiniByColletta[idColletta]) {
      ordiniByColletta[idColletta] = { colletta: p.colletta || {}, prenotazioni: [] };
    }
    ordiniByColletta[idColletta].prenotazioni.push(p);
  });

  let ordiniHtml = '';

  Object.values(ordiniByColletta).forEach(({ colletta, prenotazioni }) => {
    if (prenotazioni.length === 0) return;

    const nomeProdotto = colletta.prodotto?.nome || 'Campagna #' + (colletta.id || prenotazioni[0].id_colletta);
    const stato = colletta.stato || 'in_corso';

    const prenotazioniHtml = prenotazioni.map(p => {
      const nomeUtente = p.utente ? `${p.utente.nome || ''} ${p.utente.cognome || ''}` : 'Utente #' + p.id_utente;
      return `
        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border);">
          <div>
            <div class="text-sm font-medium">${nomeUtente}</div>
            <div class="text-xs text-secondary">${p.quantita} pezzi</div>
          </div>
          ${Badge({ variant: p.stato === 'confermata' ? 'success' : p.stato === 'prenotata' ? 'warning' : 'default', children: STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato })}
        </div>
      `;
    }).join('');

    let azioneHtml = '';
    if (stato === 'riuscita') {
      azioneHtml = `<button class="btn btn-default btn-sm" onclick="confermaOrdine(${colletta.id})">Conferma Ordine</button>`;
    } else if (stato === 'ordine_fornitore') {
      azioneHtml = `
        <button class="btn btn-outline btn-sm" onclick="confermaConsegna(${colletta.id})">Conferma Consegna</button>
      `;
    }

    const ordineFornitore = findOrdine(colletta.id || prenotazioni[0].id_colletta);

    ordiniHtml += `
      <div class="card" style="margin-bottom: var(--space-3);">
        <div class="card-content">
          <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-3);">
            <div>
              <h3 style="font-size: var(--text-base); font-weight: var(--font-semibold);">${nomeProdotto}</h3>
              <div class="text-sm text-secondary">Soglia: ${colletta.quantita_attuale}/${colletta.quantita_minima} pezzi</div>
            </div>
            <div style="display: flex; gap: var(--space-2); align-items: center;">
              ${Badge({ variant: STATI_CAMPAGNA_BADGES[stato] || 'default', children: STATI_CAMPAGNA_LABELS[stato] || stato })}
              ${azioneHtml}
            </div>
          </div>
          ${ordineFornitore ? `
            <div style="background: var(--color-bg); border-radius: var(--radius-sm); padding: var(--space-3); margin-bottom: var(--space-3); font-size: var(--text-sm);">
              <div style="display: flex; justify-content: space-between;">
                <span class="text-secondary">Ordine Fornitore:</span>
                <span class="font-medium">&euro;${Number(ordineFornitore.importo_totale || 0).toFixed(2)}</span>
              </div>
            </div>
          ` : ''}
          <div style="font-size: var(--text-sm); font-weight: var(--font-medium); margin-bottom: var(--space-2);">Partecipazioni (${prenotazioni.length}):</div>
          ${prenotazioniHtml}
        </div>
      </div>
    `;
  });

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Gestione Ordini</h1>
      </div>
      ${ordiniHtml || '<div class="empty-state"><p>Nessuna campagna con partecipazioni.</p></div>'}
    </div>
  `;
}

window.confermaOrdine = confermaOrdine;
window.confermaConsegna = confermaConsegna;

export function AdminOrdiniPage() {
  renderAdminOrdini();
}
