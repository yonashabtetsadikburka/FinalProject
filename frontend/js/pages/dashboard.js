import { getState } from '../state.js';
import { getPrenotazioniByUtente, COLLETTE, UTENTI, PRENOTAZIONI } from '../mock.js';
import { Card, CardHeader, CardTitle, CardContent } from '../components/card.js';

export function DashboardPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const { user } = getState();

  const campagneAttive = COLLETTE.filter(c => c.stato === 'in_corso').length;
  const campagneCompletate = COLLETTE.filter(c => ['riuscita', 'consegnata'].includes(c.stato)).length;

  const fondiGestiti = PRENOTAZIONI.reduce((acc, p) => acc + (p.importo_acconto || 0), 0);

  const utentiTotali = UTENTI.length;

  const utentiConPrenotazioni = new Map();
  PRENOTAZIONI.forEach(p => {
    if (!utentiConPrenotazioni.has(p.id_utente)) {
      utentiConPrenotazioni.set(p.id_utente, { count: 0, totale: 0 });
    }
    const data = utentiConPrenotazioni.get(p.id_utente);
    data.count++;
    data.totale += p.importo_acconto || 0;
  });

  let risparmioMedio = 0;
  if (utentiConPrenotazioni.size > 0) {
    let totaleRisparmiato = 0;
    utentiConPrenotazioni.forEach((data, idUtente) => {
      const prenotazioniUtente = PRENOTAZIONI.filter(p => p.id_utente === idUtente);
      prenotazioniUtente.forEach(p => {
        const colletta = COLLETTE.find(c => c.id === p.id_colletta);
        if (colletta) {
          totaleRisparmiato += (colletta.prezzo_base - colletta.prezzo_corrente) * p.quantita;
        }
      });
    });
    risparmioMedio = totaleRisparmiato / utentiConPrenotazioni.size;
  }

  const classifica = Array.from(utentiConPrenotazioni.entries())
    .map(([idUtente, data]) => {
      const utente = UTENTI.find(u => u.id === idUtente);
      return {
        id: idUtente,
        nome: utente ? `${utente.nome} ${utente.cognome}` : 'Sconosciuto',
        adesioni: data.count,
        totaleSpeso: data.totale
      };
    })
    .sort((a, b) => b.adesioni - a.adesioni);

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Dashboard</h1>
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
              <div class="kpi-label">Campagne Completate</div>
              <div class="kpi-value">${campagneCompletate}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-warning">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </div>
              <div class="kpi-label">Fondi Gestiti</div>
              <div class="kpi-value">&euro;${fondiGestiti.toFixed(2)}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-error">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
              </div>
              <div class="kpi-label">Utenti Totali</div>
              <div class="kpi-value">${utentiTotali}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="dashboard-grid" style="grid-template-columns: 1fr;">
        <div class="card">
          <div class="card-content">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-primary">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
              </div>
              <div class="kpi-label">Risparmio Medio per Utente</div>
              <div class="kpi-value">&euro;${risparmioMedio.toFixed(2)}</div>
            </div>
          </div>
        </div>
      </div>

      ${Card({ children: `
        <div class="card-content">
          <h3 style="margin-bottom: var(--space-4); font-size: var(--text-lg); font-weight: var(--font-semibold);">Classifica Utenti per Adesioni</h3>
          ${classifica.length > 0 ? `
            <div class="table-container">
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Utente</th>
                    <th>Adesioni</th>
                    <th>Totale Speso</th>
                  </tr>
                </thead>
                <tbody>
                  ${classifica.map((u, i) => `
                    <tr>
                      <td><span class="rank-badge rank-${i < 3 ? i + 1 : 'default'}">${i + 1}</span></td>
                      <td><span class="font-medium">${u.nome}</span></td>
                      <td>${u.adesioni}</td>
                      <td>&euro;${u.totaleSpeso.toFixed(2)}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          ` : `
            <div class="empty-state" style="padding: var(--space-6);">
              <p class="text-sm text-secondary">Nessuna adesione ancora.</p>
            </div>
          `}
        </div>
      ` })}
    </div>
  `;
}
