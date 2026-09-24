import { apiGet, apiPost } from '../api.js';
import { setPageInterval } from '../page-timers.js';
import { Badge } from '../components/badge.js';
import { STATI_PRENOTAZIONE_LABELS, STATI_PRENOTAZIONE_BADGES, STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../constants.js';
import { showQrModal } from '../qr-modal.js';

window.showQr = function(prenotazioneId) {
  showQrModal(prenotazioneId);
};

window.handlePagaOrdine = async function(prenotazioneId) {
  try {
    const res = await apiPost('/pagamento/checkout', { prenotazione_id: prenotazioneId });
    const dati = res.dati;
    if (dati?.checkout_url) {
      window.location.href = dati.checkout_url;
    }
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore pagamento</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

window.scegliConsegna = async function(prenotazioneId, modalita) {
  try {
    await apiPost('/consegne/scelta', { prenotazione_id: prenotazioneId, modalita });
    OrdiniPage();
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

window.confermaRicezioneOrdine = async function(consegnaId) {
  if (!confirm('Confermi di aver ricevuto il pacco?')) return;
  try {
    await apiPost(`/consegne/${consegnaId}/conferma-ricezione`, {});
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Ricezione confermata</div><div class="toast-description">Grazie per il tuo acquisto!</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
    OrdiniPage();
  } catch (err) {
    const toast = document.createElement('div');
    toast.className = 'toast toast-error';
    toast.innerHTML = `<div class="toast-content"><div class="toast-title">Errore</div><div class="toast-description">${err.message}</div></div>`;
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }
};

export async function OrdiniPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const [partRes, sediRes, costoRes] = await Promise.all([
      apiGet('/mie/partecipazioni'),
      apiGet('/sedi'),
      apiGet('/consegne/costo').catch(() => ({ dati: { costo_spedizione: 0 } }))
    ]);
    const miePrenotazioni = partRes.dati || [];
    const sedi = sediRes.dati || [];
    const costoSpedizione = parseFloat(costoRes.dati?.costo_spedizione) || 0;

    renderOrdiniPage(miePrenotazioni, sedi, costoSpedizione);

    const hasConfermata = miePrenotazioni.some(p => p.stato === 'confermata');
    if (hasConfermata) {
      let attempts = 0;
      const maxAttempts = 5;
      const pollInterval = setPageInterval(async () => {
        attempts++;
        if (attempts >= maxAttempts) { clearInterval(pollInterval); return; }
        try {
          const res = await apiGet('/mie/partecipazioni');
          const dati = res.dati || [];
          const stillConfermata = dati.some(p => p.stato === 'confermata');
          if (!stillConfermata || dati.every(p => p.stato === 'pagata')) {
            clearInterval(pollInterval);
          }
          renderOrdiniPage(dati, sedi, costoSpedizione);
        } catch (_) {}
      }, 2000);
    }
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}

function renderOrdiniPage(miePrenotazioni, sedi, costoSpedizione = 0) {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const ordiniHtml = miePrenotazioni.length > 0 ? miePrenotazioni.map(p => {
    const ritirato = p.stato_qr === 'scansionato';
    const spedizione = p.consegna_modalita === 'consegna_domicilio';
    const haScelta = !!p.consegna_modalita;
    const spedita = p.consegna_stato === 'spedita';
    const consegnataSpedizione = p.consegna_stato === 'consegnata' && spedizione;
    const showConfermaRicezione = spedita && spedizione;
    const showPaga = p.stato === 'confermata' && haScelta;
    const showQr = p.stato === 'pagata' && !ritirato && !spedizione;
    return `
    <div class="card" style="margin-bottom:var(--space-3);">
      <div class="card-content">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-2);">
          <div>
            <h3 style="font-size:var(--text-base);font-weight:var(--font-semibold);margin-bottom:var(--space-1);">${p.prodotto || 'Campagna #' + p.id_colletta}</h3>
            ${p.fornitore ? `<span class="text-xs text-secondary">${p.fornitore}</span>` : ''}
          </div>
          <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
            ${Badge({ variant: STATI_CAMPAGNA_BADGES[p.stato_colletta] || 'secondary', children: STATI_CAMPAGNA_LABELS[p.stato_colletta] || p.stato_colletta })}
            ${Badge({ variant: STATI_PRENOTAZIONE_BADGES[p.stato] || 'secondary', children: STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato })}
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--color-border);">
          <div style="display:flex;gap:var(--space-4);">
            <div><div class="text-xs text-secondary">Quantita</div><div class="font-medium text-sm">${p.quantita} pezzi</div></div>
            <div><div class="text-xs text-secondary">Data</div><div class="font-medium text-sm">${new Date(p.data_prenotazione).toLocaleDateString('it-IT')}</div></div>
            <div><div class="text-xs text-secondary">Consegna</div><div class="font-medium text-sm">${!haScelta ? '-' : (spedizione ? `Spedizione (+&euro;${parseFloat(p.importo_consegna || costoSpedizione).toFixed(2)})` : 'Ritiro in sede (gratis)')}</div></div>
          </div>
            <div style="display:flex;align-items:center;gap:var(--space-2);">
              ${ritirato ? `<span class="badge badge-success">
                Articolo ritirato
              </span>` : ''}
              ${spedita ? `<span class="badge badge-info">Spedito</span>` : ''}
              ${consegnataSpedizione ? `<span class="badge badge-success">
                Ricevuto
              </span>` : ''}
              ${(!ritirato && !spedita && !consegnataSpedizione && p.stato === 'pagata' && spedizione) ? `<span class="badge badge-warning">In attesa di spedizione</span>` : ''}
            ${showPaga ? `<button class="btn btn-default btn-sm" onclick="handlePagaOrdine(${p.id})">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
              Paga ora
            </button>` : ''}
            ${showQr ? `<button class="btn btn-default btn-sm" onclick="showQr(${p.id})">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
              Mostra QR
            </button>` : ''}
            ${showConfermaRicezione ? `<button class="btn btn-default btn-sm" onclick="confermaRicezioneOrdine(${p.id_consegna})">
              Conferma ricezione
            </button>` : ''}
            </div>
          </div>
          ${p.stato === 'confermata' ? `
          <div style="margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--color-border);">
            <div class="text-xs text-secondary" style="margin-bottom:var(--space-2);">Come vuoi ricevere l'articolo?</div>
            <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
              <button class="btn ${!spedizione ? 'btn-default' : 'btn-outline'} btn-sm" onclick="scegliConsegna(${p.id}, 'ritiro_sede')">Ritiro in sede (gratis)</button>
              <button class="btn ${spedizione ? 'btn-default' : 'btn-outline'} btn-sm" onclick="scegliConsegna(${p.id}, 'consegna_domicilio')">Spedizione (+&euro;${costoSpedizione.toFixed(2)})</button>
            </div>
            ${spedizione ? `<div class="text-xs text-secondary" style="margin-top:var(--space-1);">L'articolo verrà spedito all'indirizzo del tuo Profilo.</div>` : ''}
          </div>` : ''}
        </div>
      </div>`;
  }).join('') : '<div class="empty-state" style="padding:var(--space-8);"><h2 class="empty-state-title">Nessun ordine</h2><p class="empty-state-description">Non hai ancora partecipato a nessuna campagna.</p><a href="#/" class="btn btn-default">Esplora le campagne</a></div>';

  const sediHtml = sedi.map(s => `
    <div class="card" style="margin-bottom:var(--space-3);">
      <div class="card-content">
        <div class="font-medium" style="margin-bottom:var(--space-2);">${s.nome}</div>
        <div class="text-sm text-secondary">${s.indirizzo}, ${s.citta}</div>
        ${s.orari ? `<div class="text-sm text-secondary" style="margin-top:var(--space-1);">${s.orari}</div>` : ''}
      </div>
    </div>`).join('');

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header"><h1>I miei ordini</h1></div>
      <div style="margin-bottom:var(--space-6);"><h2 style="font-size:var(--text-lg);font-weight:var(--font-semibold);margin-bottom:var(--space-3);">Ordini</h2>${ordiniHtml}</div>
      <div style="margin-bottom:var(--space-6);"><h2 style="font-size:var(--text-lg);font-weight:var(--font-semibold);margin-bottom:var(--space-3);">Punti di Ritiro</h2><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:var(--space-3);">${sediHtml}</div></div>
    </div>`;
}
