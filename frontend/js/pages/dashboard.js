import { getState } from '../state.js';
import { apiGet, apiPost } from '../api.js';
import { Card } from '../components/card.js';
import { Badge } from '../components/badge.js';
import { STATI_PRENOTAZIONE_LABELS } from '../constants.js';

window.dashPaga = async function(prenotazioneId) {
  try {
    const res = await apiPost('/pagamento/checkout', { prenotazione_id: prenotazioneId });
    if (res.dati?.checkout_url) window.location.href = res.dati.checkout_url;
  } catch (err) {
    alert(err.message);
  }
};

function kpi(icon, iconClass, label, value) {
  return `
    <div class="card"><div class="card-content"><div class="kpi-card">
      <div class="kpi-icon kpi-icon-${iconClass}">${icon}</div>
      <div class="kpi-label">${label}</div><div class="kpi-value">${value}</div>
    </div></div></div>`;
}

const ICON_EURO = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
const ICON_BAG = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>';
const ICON_CHECK = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
const ICON_QR = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>';

const STATI_PROPOSTA_LABELS = {
  in_attesa: 'In Attesa', approvata_admin: 'Approvata', rifiutata: 'Rifiutata',
  in_votazione: 'In Votazione', pubblicata: 'Pubblicata', respinta_votazione: 'Respinta'
};

function statoOrdine(p) {
  if (p.stato_qr === 'scansionato') return { label: 'Ritirato', variant: 'success' };
  const spedizione = p.consegna_modalita === 'consegna_domicilio';
  if (spedizione) {
    if (p.consegna_stato === 'consegnata') return { label: 'Ricevuto', variant: 'success' };
    if (p.consegna_stato === 'spedita') return { label: 'Spedito', variant: 'info' };
    if (p.stato === 'pagata') return { label: 'In attesa di spedizione', variant: 'warning' };
  }
  return { label: STATI_PRENOTAZIONE_LABELS[p.stato] || p.stato, variant: 'secondary' };
}

function importoConfermata(p, campagne) {
  const c = campagne.find(x => x.id === p.id_colletta);
  const prezzo = parseFloat(c?.prezzo_corrente) || 0;
  const comm = parseFloat(c?.percentuale_commissione) || 0;
  return prezzo * (p.quantita || 0) * (1 + comm / 100);
}

export async function DashboardPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const { user } = getState();
    const [mieRes, statRes, campRes, notRes, propRes] = await Promise.all([
      apiGet('/mie/partecipazioni'),
      apiGet('/mie/statistiche').catch(() => ({ dati: {} })),
      apiGet('/campagne').catch(() => ({ dati: [] })),
      apiGet('/notifiche').catch(() => ({ dati: [] })),
      apiGet('/proposte').catch(() => ({ dati: [] }))
    ]);
    const mie = mieRes.dati || [];
    const st = statRes.dati || {};
    const campagne = campRes.dati || [];
    const notifiche = (notRes.dati || []).filter(n => !n.letta).slice(0, 3);
    const mieProposte = (propRes.dati || []).filter(p => user && p.proponente_id === user.id);

    // --- Richiede attenzione ---
    const daPagare = mie.filter(p => p.stato === 'confermata');
    const daRitirare = mie.filter(p => p.stato === 'pagata' && p.stato_qr === 'generato' && p.consegna_modalita !== 'consegna_domicilio');
    const quasiMoq = mie.filter(p => {
      if (p.stato !== 'prenotata') return false;
      const min = parseInt(p.quantita_minima) || 1;
      const att = parseInt(p.quantita_attuale) || 0;
      return min > 0 && att / min >= 0.8 && att < min;
    });
    const attenzione = [
      ...daPagare.map(p => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div><div class="text-sm font-medium">${p.prodotto}</div><div class="text-xs text-secondary">Da pagare &middot; ${p.quantita} pezzi</div></div>
          <button class="btn btn-default btn-sm" onclick="dashPaga(${p.id})">Paga &euro;${importoConfermata(p, campagne).toFixed(2)}</button>
        </div>`),
      ...daRitirare.map(p => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div><div class="text-sm font-medium">${p.prodotto}</div><div class="text-xs text-secondary">Pronto al ritiro</div></div>
          <a href="#/ordini" class="btn btn-outline btn-sm">Mostra QR</a>
        </div>`),
      ...quasiMoq.map(p => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div><div class="text-sm font-medium">${p.prodotto}</div><div class="text-xs text-secondary">Manca poco all'obiettivo (${p.quantita_attuale}/${p.quantita_minima})</div></div>
          <a href="#/campagne/${p.id_colletta}" class="btn btn-ghost btn-sm">Vedi</a>
        </div>`)
    ].join('');

    // --- Ordini recenti ---
    const recenti = mie.slice(0, 4).map(p => {
      const s = statoOrdine(p);
      return `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        <div><div class="text-sm font-medium">${p.prodotto}</div><div class="text-xs text-secondary">${p.quantita} pezzi</div></div>
        ${Badge({ variant: s.variant, children: s.label })}
      </div>`;
    }).join('') || '<p class="text-sm text-secondary">Nessun ordine. <a href="#/">Esplora le campagne</a></p>';

    const notHtml = notifiche.map(n => `
      <div style="padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        <div class="text-sm font-medium">${n.titolo}</div>
        <div class="text-xs text-secondary">${n.messaggio?.slice(0, 90)}${(n.messaggio || '').length > 90 ? '…' : ''}</div>
      </div>`).join('') || '<p class="text-sm text-secondary">Nessuna notifica non letta.</p>';

    const propHtml = mieProposte.length > 0
      ? mieProposte.slice(0, 4).map(p => {
        const badgeVariant = p.stato === 'in_votazione' ? 'default' : p.stato === 'in_attesa' ? 'warning' : 'secondary';
        return `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
          <div><div class="text-sm font-medium">${p.nome_prodotto}</div><div class="text-xs text-secondary">${p.tot_voti || 0} voti</div></div>
          ${Badge({ variant: badgeVariant, children: STATI_PROPOSTA_LABELS[p.stato] || p.stato })}
        </div>`;
      }).join('')
      : '<p class="text-sm text-secondary">Nessuna proposta. <a href="#/proposte">Proponi un prodotto</a></p>';

    content.innerHTML = `
      <div class="content-area">
        <div class="page-header"><h1>Ciao ${user?.nome || ''}</h1></div>

        <div class="dashboard-grid">
          ${kpi(ICON_EURO, 'error', 'Speso finora', '&euro;' + (st.spesa_totale ?? 0).toFixed(2))}
          ${kpi(ICON_CHECK, 'success', 'Risparmiato', '&euro;' + (st.risparmio_stimato ?? 0).toFixed(2))}
          ${kpi(ICON_BAG, 'primary', 'Da pagare', '&euro;' + (st.da_pagare ?? 0).toFixed(2))}
          ${kpi(ICON_QR, 'warning', 'Da ritirare', st.n_ritirare ?? 0)}
        </div>

        ${Card({ children: `<div class="card-content">
          <h3 style="margin-bottom:var(--space-2);font-size:var(--text-lg);font-weight:var(--font-semibold);">Richiede attenzione</h3>
          ${attenzione || '<p class="text-sm text-secondary">Tutto a posto! Nessuna azione richiesta.</p>'}
        </div>` })}

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:var(--space-3);margin-top:var(--space-3);">
          ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">I miei ordini recenti</h3>${recenti}<div style="margin-top:var(--space-2);"><a href="#/ordini" class="btn btn-ghost btn-sm">Tutti gli ordini</a></div></div>` })}
          ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Ultime notifiche</h3>${notHtml}<div style="margin-top:var(--space-2);"><a href="#/notifiche" class="btn btn-ghost btn-sm">Tutte le notifiche</a></div></div>` })}
          ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Le mie proposte</h3>${propHtml}</div>` })}
        </div>
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
