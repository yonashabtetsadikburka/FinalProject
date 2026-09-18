import { apiGet, apiPost } from '../../api.js';
import { setPageInterval } from '../../page-timers.js';
import { Badge } from '../../components/badge.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES, STATI_PRENOTAZIONE_LABELS } from '../../constants.js';

window.confermaOrdine = async function(collettaId) {
  try {
    await apiPost(`/campagne/${collettaId}/ripartisci`, {});
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Ordine confermato!</div><div class="toast-description">Gli utenti possono ora procedere al pagamento.</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
    renderAdminOrdini();
  } catch (err) {
    alert(err.message);
  }
};

window.inviaAlFornitore = async function(collettaId) {
  try {
    await apiPost(`/campagne/${collettaId}/invia-fornitore`, {});
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.innerHTML = '<div class="toast-content"><div class="toast-title">Ordine inviato al fornitore!</div><div class="toast-description">Gli utenti saranno notificati.</div></div>';
    document.querySelector('.toast-container')?.appendChild(toast) || document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
    renderAdminOrdini();
  } catch (err) {
    alert(err.message);
  }
};

window.segnaSpedito = async function(consegnaId) {
  if (!confirm('Segnare questa spedizione come spedita? Il cliente sara\' notificato.')) return;
  try {
    await apiPost(`/consegne/${consegnaId}/spedisci`, {});
    renderAdminOrdini();
  } catch (err) {
    alert(err.message);
  }
};

async function renderAdminOrdini() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const [res, consRes] = await Promise.all([
      apiGet('/admin/ordini'),
      apiGet('/admin/consegne').catch(() => ({ dati: [] }))
    ]);
    const campagne = res.dati || [];
    const spedizioni = (consRes.dati || []).filter(c => c.modalita === 'consegna_domicilio');

    const spedHtml = spedizioni.length > 0 ? spedizioni.map(s => `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        <div>
          <div class="text-sm font-medium">${s.prodotto} — ${s.quantita} pezzi (${s.nome || ''} ${s.cognome || ''})</div>
          <div class="text-xs text-secondary">${s.indirizzo_consegna || ''} &middot; &euro;${parseFloat(s.importo_consegna).toFixed(2)}</div>
        </div>
        <div style="display:flex;gap:var(--space-2);align-items:center;">
          ${Badge({ variant: s.stato_consegna === 'consegnata' ? 'success' : s.stato_consegna === 'spedita' ? 'success' : 'warning', children: s.stato_consegna === 'consegnata' ? 'Ricevuto' : s.stato_consegna === 'spedita' ? 'Spedito' : 'Da spedire' })}
          ${s.stato_consegna === 'in_attesa' || s.stato_consegna === 'pronta' ? `<button class="btn btn-default btn-sm" onclick="segnaSpedito(${s.id})">Segna spedito</button>` : ''}
        </div>
      </div>`).join('') : '<div class="empty-state"><p>Nessuna spedizione.</p></div>';

    let ordiniHtml = '';
    for (const c of campagne) {
      const partecipazioni = c.partecipazioni || [];
      if (partecipazioni.length === 0) continue;

      const pagati = partecipazioni.filter(p => p.stato === 'pagata').length;
      const totali = partecipazioni.length;
      const tuttiPagati = pagati === totali;

      const prenotazioniHtml = partecipazioni.map(p => {
        const badgeVariant = p.stato === 'pagata' ? 'success' : p.stato === 'confermata' ? 'info' : 'warning';
        return `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div><div class="text-sm font-medium">${p.nome || 'Utente #' + p.id_utente}</div><div class="text-xs text-secondary">${p.quantita} pezzi</div></div>
          ${Badge({ variant: badgeVariant, children: STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato })}
        </div>`;
      }).join('');

      let azioneHtml = '';
      if (c.stato === 'riuscita') {
        azioneHtml = `<button class="btn btn-default btn-sm" onclick="confermaOrdine(${c.id})">Conferma Ordine</button>`;
      } else if (c.stato === 'ordine_pronto') {
        if (tuttiPagati) {
          azioneHtml = `<button class="btn btn-default btn-sm" onclick="inviaAlFornitore(${c.id})">Invia al Fornitore</button>`;
        } else {
          azioneHtml = `<span class="text-xs text-secondary">In attesa pagamenti (${pagati}/${totali})</span>`;
        }
      }

      const percentuale = c.stato === 'ordine_pronto' ? Math.min(100, Math.round((pagati / totali) * 100)) : null;

      ordiniHtml += `
        <div class="card" style="margin-bottom:var(--space-3);">
          <div class="card-content">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-3);">
              <div><h3 style="font-size:var(--text-base);font-weight:var(--font-semibold);">${c.prodotto || 'Campagna #' + c.id}</h3><div class="text-sm text-secondary">Soglia: ${c.quantita_attuale || 0}/${c.quantita_minima} pezzi</div></div>
              <div style="display:flex;gap:var(--space-2);align-items:center;">
                ${Badge({ variant: STATI_CAMPAGNA_BADGES[c.stato] || 'default', children: STATI_CAMPAGNA_LABELS[c.stato] || c.stato })}
                ${azioneHtml}
              </div>
            </div>
            ${percentuale !== null ? `
            <div style="margin-bottom:var(--space-3);">
              <div style="display:flex;justify-content:space-between;margin-bottom:var(--space-1);">
                <span class="text-xs text-secondary">Pagamenti</span>
                <span class="text-xs font-medium">${pagati}/${totali} (${percentuale}%)</span>
              </div>
              <div style="height:6px;background:var(--color-border);border-radius:3px;overflow:hidden;">
                <div style="height:100%;width:${percentuale}%;background:${tuttiPagati ? 'var(--color-success)' : 'var(--color-info)'};border-radius:3px;transition:width 0.3s;"></div>
              </div>
            </div>` : ''}
            <div style="font-size:var(--text-sm);font-weight:var(--font-medium);margin-bottom:var(--space-2);">Partecipazioni (${totali}):</div>
            ${prenotazioniHtml}
          </div></div>`;
    }

    content.innerHTML = `
      <div class="content-area">
        <div class="admin-page-header"><h1>Gestione Ordini</h1></div>
        ${ordiniHtml || '<div class="empty-state"><p>Nessuna campagna con partecipazioni.</p></div>'}
        <div class="card" style="margin-top:var(--space-4);"><div class="card-content">
          <h3 style="margin-bottom:var(--space-3);">Spedizioni a domicilio</h3>
          ${spedHtml}
        </div></div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}

let adminOrdiniPoll = null;

export async function AdminOrdiniPage() {
  if (adminOrdiniPoll) clearInterval(adminOrdiniPoll);
  await renderAdminOrdini();

  adminOrdiniPoll = setPageInterval(async () => {
    try {
      const res = await apiGet('/admin/ordini');
      const campagne = res.dati || [];
      const hasUnpaid = campagne.some(c =>
        c.stato === 'ordine_pronto' &&
        c.partecipazioni?.some(p => p.stato !== 'pagata')
      );
      await renderAdminOrdini();
      if (!hasUnpaid) clearInterval(adminOrdiniPoll);
    } catch (_) {}
  }, 5000);
}
