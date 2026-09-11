import { getState } from '../state.js';
import { apiGet } from '../api.js';
import { Card } from '../components/card.js';

export async function DashboardPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const { user } = getState();

  content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';

  let collette = [];
  let miePrenotazioni = [];
  try {
    const [cRes, pRes] = await Promise.all([
      apiGet('/collette'),
      apiGet('/prenotazioni/mie')
    ]);
    collette = cRes.dati || [];
    miePrenotazioni = pRes.dati || [];
  } catch (e) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p></div>`;
    return;
  }

  const campagneAttive = collette.filter(c => c.stato === 'in_corso').length;
  const campagneCompletate = collette.filter(c => ['riuscita', 'consegnata'].includes(c.stato)).length;
  const miePartecipazioni = miePrenotazioni.length;
  const fondiGestiti = miePrenotazioni.reduce((acc, p) => acc + Number(p.importo_saldo || 0), 0);

  let risparmioTotale = 0;
  miePrenotazioni.forEach(p => {
    const colletta = p.colletta || collette.find(c => c.id === p.id_colletta);
    if (colletta) {
      risparmioTotale += (Number(colletta.prezzo_base) - Number(colletta.prezzo_corrente)) * Number(p.quantita || 0);
    }
  });

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Ciao, ${user?.nome || ''}! 👋</h1>
        <p class="text-secondary" style="margin-top: var(--space-1);">Ecco il riepilogo della tua attività su BuyPool.</p>
      </div>

      <div class="dashboard-grid">
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-primary">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
              </div>
              <div class="kpi-label">Campagne Attive</div>
              <div class="kpi-value">${campagneAttive}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-success">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </div>
              <div class="kpi-label">Le Mie Partecipazioni</div>
              <div class="kpi-value">${miePartecipazioni}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-warning">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </div>
              <div class="kpi-label">Fondi Impiegati</div>
              <div class="kpi-value">&euro;${fondiGestiti.toFixed(2)}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-error">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
              </div>
              <div class="kpi-label">Risparmio Totale</div>
              <div class="kpi-value">&euro;${risparmioTotale.toFixed(2)}</div>
            </div>
          </div>
        </div>
      </div>

      ${Card({ children: `
        <div class="card-content">
          <h3 style="margin-bottom: var(--space-4); font-size: var(--text-lg); font-weight: var(--font-semibold);">Le mie ultime partecipazioni</h3>
          ${miePrenotazioni.length > 0 ? `
            <div class="table-container">
              <table class="table">
                <thead>
                  <tr>
                    <th>Prodotto</th>
                    <th>Quantita</th>
                    <th>Stato</th>
                    <th>Data</th>
                  </tr>
                </thead>
                <tbody>
                  ${miePrenotazioni.slice(0, 5).map(p => `
                    <tr>
                      <td><span class="font-medium">${p.colletta?.prodotto?.nome || 'Campagna #' + p.id_colletta}</span></td>
                      <td>${p.quantita} pezzi</td>
                      <td>${p.stato}</td>
                      <td class="text-secondary">${new Date(p.data_prenotazione).toLocaleDateString('it-IT')}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          ` : `
            <div class="empty-state" style="padding: var(--space-6);">
              <p class="text-sm text-secondary">Non hai ancora partecipato a nessuna campagna.</p>
            </div>
          `}
        </div>
      ` })}
    </div>
  `;
}
