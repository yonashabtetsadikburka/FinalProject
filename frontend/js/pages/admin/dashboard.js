import { apiGet, apiPost, apiPut } from '../../api.js';
import { Card } from '../../components/card.js';
import { STATI_CAMPAGNA_LABELS, STATI_CAMPAGNA_BADGES } from '../../constants.js';

export async function AdminDashboardPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const [utentiRes, campagneRes, walletRes] = await Promise.all([
      apiGet('/utenti').catch(() => ({ dati: [] })),
      apiGet('/campagne').catch(() => ({ dati: [] })),
      apiGet('/wallet/statistiche').catch(() => ({ dati: {} }))
    ]);
    const utenti = utentiRes.dati || [];
    const campagne = campagneRes.dati || [];
    const stats = walletRes.dati || {};

    const campagneAttive = campagne.filter(c => c.stato === 'in_corso').length;
    const campagneRiuscite = campagne.filter(c => c.stato === 'riuscita').length;
    const campagnePronte = campagne.filter(c => c.stato === 'ordine_pronto').length;
    const totalePartecipanti = campagne.reduce((acc, c) => acc + (parseInt(c.partecipanti) || 0), 0);
    const incassoTotale = parseFloat(stats.totale_addebiti) || 0;
    const commissioneTotale = parseFloat(stats.totale_commissioni) || 0;

    content.innerHTML = `
      <div class="content-area">
        <div class="page-header"><h1>Dashboard Admin</h1></div>
        <div class="dashboard-grid">
          <div class="card"><div class="card-content"><div class="kpi-card"><div class="kpi-icon kpi-icon-primary"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="kpi-label">Utenti Totali</div><div class="kpi-value">${utenti.length}</div></div></div></div>
          <div class="card"><div class="card-content"><div class="kpi-card"><div class="kpi-icon kpi-icon-success"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg></div><div class="kpi-label">Campagne Attive</div><div class="kpi-value">${campagneAttive}</div></div></div></div>
          <div class="card"><div class="card-content"><div class="kpi-card"><div class="kpi-icon kpi-icon-warning"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg></div><div class="kpi-label">Ordini da Confermare</div><div class="kpi-value">${campagneRiuscite}</div></div></div></div>
          <div class="card"><div class="card-content"><div class="kpi-card"><div class="kpi-icon kpi-icon-error"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="kpi-label">Totale Partecipanti</div><div class="kpi-value">${totalePartecipanti}</div></div></div></div>
        </div>
        <div class="dashboard-grid" style="margin-top:var(--space-4);">
          <div class="card"><div class="card-content"><div class="kpi-card"><div class="kpi-icon" style="background:var(--color-success-light);color:var(--color-success);"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="kpi-label">Incasso Totale</div><div class="kpi-value">&euro;${incassoTotale.toFixed(2)}</div></div></div></div>
          <div class="card"><div class="card-content"><div class="kpi-card"><div class="kpi-icon" style="background:var(--color-info-light);color:var(--color-info);"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg></div><div class="kpi-label">Commissioni Incassate</div><div class="kpi-value">&euro;${commissioneTotale.toFixed(2)}</div></div></div></div>
          <div class="card"><div class="card-content"><div class="kpi-card"><div class="kpi-icon" style="background:var(--color-warning-light);color:var(--color-warning);"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></div><div class="kpi-label">Ordini Pronti</div><div class="kpi-value">${campagnePronte}</div></div></div></div>
        </div>
        ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-4);">Campagne Recenti</h3><div class="table-container"><table class="table"><thead><tr><th>Campagna</th><th>Fornitore</th><th>Stato</th><th>Partecipanti</th></tr></thead><tbody>${campagne.slice(0, 5).map(c => `<tr><td class="font-medium">${c.prodotto || '-'}</td><td>${c.fornitore || '-'}</td><td>${STATI_CAMPAGNA_LABELS[c.stato] || c.stato}</td><td>${c.partecipanti || 0}/${c.quantita_minima}</td></tr>`).join('')}</tbody></table></div></div>` })}
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
