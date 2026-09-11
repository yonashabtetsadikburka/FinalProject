import { apiGet } from '../../api.js';
import { Progress } from '../../components/progress.js';

export async function FornitoreDashboardPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';

  let fornitore = null;
  let statistiche = null;
  let prodotti = [];
  let ordini = [];
  try {
    const [areaRes, prodottiRes, ordiniRes] = await Promise.all([
      apiGet('/fornitore/area'),
      apiGet('/fornitore/prodotti'),
      apiGet('/fornitore/ordini')
    ]);
    fornitore = areaRes.dati.fornitore || {};
    statistiche = areaRes.dati.statistiche || {};
    prodotti = prodottiRes.dati || [];
    ordini = ordiniRes.dati || [];
  } catch (e) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p></div>`;
    return;
  }

  const quickLinks = [
    { route: '/area/prodotti', label: 'I miei prodotti', icon: '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>' },
    { route: '/area/ordini', label: 'Ordini', icon: '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>' },
    { route: '/area/collette', label: 'Campagne', icon: '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>' }
  ];

  const prodottiPreview = prodotti.slice(0, 5).map(p => `
    <tr>
      <td class="font-medium">${p.nome || '—'}</td>
      <td class="text-sm text-secondary">${p.categoria?.nome || '—'}</td>
      <td class="text-sm">&euro;${Number(p.prezzo_unitario || 0).toFixed(2)}</td>
      <td class="text-sm">${p.quantita_minima || 0}</td>
      <td><span class="badge ${p.stato === 'attivo' ? 'badge-success' : 'badge-default'}" style="text-transform: capitalize;">${p.stato || '—'}</span></td>
    </tr>
  `).join('');

  const ordiniPreview = ordini.slice(0, 5).map(o => `
    <tr>
      <td class="text-sm">${o.colletta?.prodotto?.nome || `Ordine #${o.id}`}</td>
      <td class="text-sm">${o.quantita_ordinata || 0}</td>
      <td class="text-sm">&euro;${Number(o.importo_totale || 0).toFixed(2)}</td>
      <td><span class="badge badge-secondary" style="text-transform: capitalize;">${o.stato || '—'}</span></td>
      <td class="text-sm text-secondary">${o.data_ordine ? new Date(o.data_ordine).toLocaleDateString('it-IT') : '—'}</td>
    </tr>
  `).join('');

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:var(--space-4);">
        <div>
          <h1>Area Fornitore</h1>
          <p class="text-secondary">Gestisci i tuoi prodotti, ordini e campagne</p>
        </div>
        <div class="card" style="padding: var(--space-4);">
          <div class="text-sm text-secondary">${fornitore.nome_azienda || 'Scheda fornitore'}</div>
          <div class="font-medium">${fornitore.email_contatto || ''}</div>
          <div class="text-sm text-secondary">Trust Score: <strong>${fornitore.trust_score ?? 0}</strong> &nbsp;·&nbsp; Campagne: <strong>${fornitore.num_campagne ?? 0}</strong></div>
        </div>
      </div>

      <div class="dashboard-grid">
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-primary"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg></div>
              <div class="kpi-label">Prodotti</div>
              <div class="kpi-value">${statistiche.prodotti ?? 0}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-success"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg></div>
              <div class="kpi-label">Campagne attive</div>
              <div class="kpi-value">${statistiche.collette_attive ?? 0}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-warning"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
              <div class="kpi-label">Ordini da negoziare</div>
              <div class="kpi-value">${statistiche.ordini_da_negoziare ?? 0}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-error"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
              <div class="kpi-label">Fatturato</div>
              <div class="kpi-value">&euro;${Number(statistiche.fatturato || 0).toFixed(2)}</div>
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
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom: var(--space-4);">
            <h3 style="font-size: var(--text-lg); font-weight: var(--font-semibold);">Ultimi prodotti</h3>
            <a href="#/area/prodotti" class="btn btn-ghost">Vedi tutti</a>
          </div>
          ${prodottiPreview.length ? `
            <div class="table-container"><table class="table">
              <thead><tr><th>Nome</th><th>Categoria</th><th>Prezzo</th><th>Q.ta minima</th><th>Stato</th></tr></thead>
              <tbody>${prodottiPreview}</tbody>
            </table></div>
          ` : `
            <div class="empty-state" style="padding: var(--space-6);">
              <p class="text-sm text-secondary">Nessun prodotto. <a href="#/area/prodotti">Aggiungi il primo prodotto</a>.</p>
            </div>
          `}
        </div>
      </div>

      <div class="card">
        <div class="card-content">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom: var(--space-4);">
            <h3 style="font-size: var(--text-lg); font-weight: var(--font-semibold);">Ultimi ordini</h3>
            <a href="#/area/ordini" class="btn btn-ghost">Vedi tutti</a>
          </div>
          ${ordiniPreview.length ? `
            <div class="table-container"><table class="table">
              <thead><tr><th>Campagna</th><th>Quantit&agrave;</th><th>Importo</th><th>Stato</th><th>Data</th></tr></thead>
              <tbody>${ordiniPreview}</tbody>
            </table></div>
          ` : `
            <div class="empty-state" style="padding: var(--space-6);">
              <p class="text-sm text-secondary">Nessun ordine registrato.</p>
            </div>
          `}
        </div>
      </div>
    </div>
  `;
}