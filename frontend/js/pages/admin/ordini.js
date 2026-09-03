import { getState } from '../../state.js';
import { PRENOTAZIONI, PRODOTTI, COLLETTE, QR_CODES, ORDINI_FORNITORE, UTENTI } from '../../mock.js';
import { Table } from '../../components/table.js';
import { Badge } from '../../components/badge.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES, STATI_PRENOTAZIONE_LABELS } from '../../constants.js';

function handleConfermaOrdine(collettaId) {
  const colletta = COLLETTE.find(c => c.id === collettaId);
  if (!colletta || colletta.stato !== 'riuscita') return;

  // Find all prenotazioni for this colletta
  const prenotazioni = PRENOTAZIONI.filter(p => p.id_colletta === collettaId && p.stato === 'prenotata');
  if (prenotazioni.length === 0) return;

  // Generate QR codes for each prenotazione
  prenotazioni.forEach(p => {
    const existingQr = QR_CODES.find(q => q.id_prenotazione === p.id);
    if (!existingQr) {
      const token = Array.from({ length: 48 }, () => '0123456789abcdef'[Math.floor(Math.random() * 16)]).join('');
      QR_CODES.push({
        id: QR_CODES.length + 1,
        id_prenotazione: p.id,
        token,
        quantita_assegnata: p.quantita,
        stato: 'generato',
        data_generazione: new Date().toISOString(),
        data_scansione: null
      });
    }
    p.stato = 'confermata';
  });

  // Create ordine fornitore
  const prezzoTotale = prenotazioni.reduce((acc, p) => acc + (p.quantita * (colletta.prezzo_corrente || 0)), 0);
  ORDINI_FORNITORE.push({
    id: ORDINI_FORNITORE.length + 1,
    id_colletta: collettaId,
    id_fornitore: colletta.id_fornitore || 1,
    importo_totale: prezzoTotale,
    stato: 'inviato',
    data_ordine: new Date().toISOString(),
    data_consegna: null
  });

  // Update colletta state
  colletta.stato = 'ordine_fornitore';
  colletta.id_admin_conferma = getState().user?.id;
  colletta.data_conferma = new Date().toISOString();

  renderAdminOrdini();
}

function handleConfermaConsegna(collettaId) {
  const colletta = COLLETTE.find(c => c.id === collettaId);
  if (!colletta || colletta.stato !== 'ordine_fornitore') return;

  colletta.stato = 'consegnata';
  renderAdminOrdini();
}

function renderAdminOrdini() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  // Group prenotazioni by colletta
  const ordiniByColletta = {};
  COLLETTE.forEach(c => {
    ordiniByColletta[c.id] = {
      colletta: c,
      prenotazioni: PRENOTAZIONI.filter(p => p.id_colletta === c.id),
      ordineFornitore: ORDINI_FORNITORE.find(o => o.id_colletta === c.id)
    };
  });

  let ordiniHtml = '';

  Object.values(ordiniByColletta).forEach(({ colletta, prenotazioni, ordineFornitore }) => {
    if (prenotazioni.length === 0) return;

    const prodotto = PRODOTTI.find(p => p.id === colletta.id_prodotto);
    const nomeProdotto = prodotto?.nome || 'Campagna #' + colletta.id;

    const prenotazioniHtml = prenotazioni.map(p => {
      const utente = UTENTI.find(u => u.id === p.id_utente);
      return `
        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border);">
          <div>
            <div class="text-sm font-medium">${utente ? utente.nome + ' ' + utente.cognome : 'Utente #' + p.id_utente}</div>
            <div class="text-xs text-secondary">${p.quantita} pezzi</div>
          </div>
          ${Badge({ variant: p.stato === 'confermata' ? 'success' : p.stato === 'prenotata' ? 'warning' : 'default', children: STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato })}
        </div>
      `;
    }).join('');

    let azioneHtml = '';
    if (colletta.stato === 'riuscita') {
      azioneHtml = `<button class="btn btn-default btn-sm" onclick="confermaOrdine(${colletta.id})">Conferma Ordine</button>`;
    } else if (colletta.stato === 'ordine_fornitore') {
      azioneHtml = `
        <button class="btn btn-outline btn-sm" onclick="confermaConsegna(${colletta.id})">Conferma Consegna</button>
      `;
    }

    ordiniHtml += `
      <div class="card" style="margin-bottom: var(--space-3);">
        <div class="card-content">
          <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-3);">
            <div>
              <h3 style="font-size: var(--text-base); font-weight: var(--font-semibold);">${nomeProdotto}</h3>
              <div class="text-sm text-secondary">Soglia: ${colletta.quantita_attuale}/${colletta.quantita_minima} pezzi</div>
            </div>
            <div style="display: flex; gap: var(--space-2); align-items: center;">
              ${Badge({ variant: STATI_CAMPAGNA_BADGES[colletta.stato] || 'default', children: STATI_CAMPAGNA_LABELS[colletta.stato] || colletta.stato })}
              ${azioneHtml}
            </div>
          </div>
          ${ordineFornitore ? `
            <div style="background: var(--color-bg); border-radius: var(--radius-sm); padding: var(--space-3); margin-bottom: var(--space-3); font-size: var(--text-sm);">
              <div style="display: flex; justify-content: space-between;">
                <span class="text-secondary">Ordine Fornitore:</span>
                <span class="font-medium">&euro;${ordineFornitore.importo_totale.toFixed(2)}</span>
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

window.confermaOrdine = handleConfermaOrdine;
window.confermaConsegna = handleConfermaConsegna;

export function AdminOrdiniPage() {
  renderAdminOrdini();
}
