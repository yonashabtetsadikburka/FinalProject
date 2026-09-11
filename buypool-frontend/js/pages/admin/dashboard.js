import { apiGet } from '../../api.js';

export async function AdminDashboardPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';

  let utenti = [];
  let prenotazioni = [];
  try {
    const [uRes, pRes] = await Promise.all([
      apiGet('/utenti'),
      apiGet('/prenotazioni')
    ]);
    utenti = uRes.dati || [];
    prenotazioni = pRes.dati || [];
  } catch (e) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p></div>`;
    return;
  }

  const statiCollette = new Set();
  prenotazioni.forEach(p => {
    if (p.colletta && p.colletta.stato) statiCollette.add(p.colletta.stato);
  });
  const campagneAttive = Array.from(statiCollette).filter(s => s === 'in_corso').length;
  const ordiniDaConfermare = Array.from(statiCollette).filter(s => s === 'riuscita').length;

  const confermate = prenotazioni.filter(p => p.stato === 'confermata');
  const totaleCommissioni = confermate.reduce((acc, p) => acc + Number(p.importo_commissione || 0), 0);
  const today = new Date();
  const commissioneMese = confermate
    .filter(p => new Date(p.data_pagamento || p.data_prenotazione).getMonth() === today.getMonth() && new Date(p.data_pagamento || p.data_prenotazione).getFullYear() === today.getFullYear())
    .reduce((acc, p) => acc + Number(p.importo_commissione || 0), 0);
  const totaleAddebiti = confermate.reduce((acc, p) => acc + Number(p.importo_saldo || 0), 0);

  const quickLinks = [
    { route: '/admin/campagne', label: 'Gestione Campagne', icon: '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>' },
    { route: '/admin/utenti', label: 'Gestione Utenti', icon: '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>' },
    { route: '/admin/ordini', label: 'Gestione Ordini', icon: '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>' },
    { route: '/admin/ritiri', label: 'Gestione Ritiri', icon: '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>' },
    { route: '/admin/notifiche', label: 'Invio Notifiche', icon: '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>' }
  ];

  const ultimeAttivita = confermate.slice(-5).reverse();
  const attivitaHtml = ultimeAttivita.length > 0 ? `
    <div class="table-container">
      <table class="table">
        <thead>
          <tr>
            <th>Stato</th>
            <th>Prenotazione</th>
            <th>Importo</th>
            <th>Commissione</th>
            <th>Data</th>
          </tr>
        </thead>
        <tbody>
          ${ultimeAttivita.map(p => `
            <tr>
              <td><span class="badge badge-success">confermata</span></td>
              <td class="text-sm">Prenotazione #${p.id}</td>
              <td class="text-sm text-success">&euro;${Number(p.importo_saldo || 0).toFixed(2)}</td>
              <td class="text-sm text-secondary">&euro;${Number(p.importo_commissione || 0).toFixed(2)}</td>
              <td class="text-sm text-secondary">${new Date(p.data_pagamento || p.data_prenotazione).toLocaleDateString('it-IT')}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  ` : `
    <div class="empty-state" style="padding: var(--space-6);">
      <p class="text-sm text-secondary">Nessuna attivita recente.</p>
    </div>
  `;

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Dashboard Admin</h1>
      </div>

      <div class="dashboard-grid">
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-primary"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div>
              <div class="kpi-label">Utenti Totali</div>
              <div class="kpi-value">${utenti.length}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-success"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg></div>
              <div class="kpi-label">Campagne Attive</div>
              <div class="kpi-value">${campagneAttive}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-warning"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg></div>
              <div class="kpi-label">Ordini da Confermare</div>
              <div class="kpi-value">${ordiniDaConfermare}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-error"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
              <div class="kpi-label">Totale Commissioni</div>
              <div class="kpi-value">&euro;${totaleCommissioni.toFixed(2)}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-success"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
              <div class="kpi-label">Commissioni Mese</div>
              <div class="kpi-value">&euro;${commissioneMese.toFixed(2)}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-warning"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg></div>
              <div class="kpi-label">Totale Addebiti</div>
              <div class="kpi-value">&euro;${totaleAddebiti.toFixed(2)}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-content">
          <h3 style="margin-bottom: var(--space-4); font-size: var(--text-lg); font-weight: var(--font-semibold);">Accesso Rapido</h3>
          <div class="admin-quick-links">
            ${quickLinks.map(link => `
              <a href="#${link.route}" class="admin-quick-link">
                <div class="admin-quick-link-icon">${link.icon}</div>
                <span>${link.label}</span>
              </a>
            `).join('')}
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-content">
          <h3 style="margin-bottom: var(--space-4); font-size: var(--text-lg); font-weight: var(--font-semibold);">Ultime Attivita</h3>
          ${attivitaHtml}
        </div>
      </div>
    </div>
  `;
}
