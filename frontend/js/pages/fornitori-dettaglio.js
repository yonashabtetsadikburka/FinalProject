import { apiGet } from '../api.js';
import { Card } from '../components/card.js';

export async function FornitoriDettaglioPage(params) {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const [fornRes, prodRes] = await Promise.all([
      apiGet('/fornitori'),
      apiGet(`/prodotti?fornitore_id=${params.id}`)
    ]);
    const fornitori = fornRes.dati || [];
    const fornitore = fornitori.find(f => f.id === parseInt(params.id));
    const prodotti = ((prodRes.dati && prodRes.dati.prodotti) || prodRes.dati || []).filter(p => p.id_fornitore === parseInt(params.id));

    if (!fornitore) {
      content.innerHTML = '<div class="empty-state"><h2>Fornitore non trovato</h2><a href="#/fornitori" class="btn btn-default">Torna ai fornitori</a></div>';
      return;
    }

    const productsHtml = prodotti.map(p => `
      <div style="display: flex; justify-content: space-between; padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border);">
        <div>
          <div class="font-medium">${p.nome}</div>
          <div class="text-sm text-secondary">MOQ: ${p.quantita_minima} pezzi</div>
        </div>
        <div class="font-bold">&euro;${parseFloat(p.prezzo_unitario).toFixed(2)}</div>
      </div>
    `).join('') || '<p class="text-secondary">Nessun prodotto disponibile</p>';

    content.innerHTML = `
      <div class="content-area">
        <div style="margin-bottom: var(--space-4);">
          <a href="#/fornitori" class="btn btn-ghost btn-sm">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Torna ai fornitori
          </a>
        </div>
        <div class="two-columns">
          <div>
            ${Card({ children: `
              <div class="card-content">
                <div style="display: flex; align-items: center; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div class="supplier-logo" style="width: 80px; height: 80px; font-size: var(--text-3xl);">${fornitore.nome[0]}</div>
                  <div>
                    <h2 style="font-size: var(--text-xl); font-weight: var(--font-bold);">${fornitore.nome}</h2>
                    <div class="text-sm text-secondary">${fornitore.indirizzo || ''}</div>
                  </div>
                </div>
                <p class="text-secondary" style="margin-bottom: var(--space-4);">${fornitore.descrizione || ''}</p>
                ${fornitore.sito_web ? `<a href="${fornitore.sito_web}" target="_blank" rel="noopener" class="btn btn-default w-full" style="margin-bottom: var(--space-4);">Vai al sito / catalogo
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>` : ''}
                <div style="display: flex; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div class="trust-score" style="font-size: var(--text-base);">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Trust: ${fornitore.trust_score}/100
                  </div>
                </div>
                <h3 style="margin-bottom: var(--space-3);">Prodotti</h3>
                ${productsHtml}
              </div>
            ` })}
          </div>
          <div>
            ${Card({ children: `
              <div class="card-content">
                <h3 style="margin-bottom: var(--space-3);">Contatti</h3>
                <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                  <div class="text-sm">
                    <span class="text-secondary">Email:</span>
                    <span class="font-medium">${fornitore.email_contatto || 'N/A'}</span>
                  </div>
                  <div class="text-sm">
                    <span class="text-secondary">Telefono:</span>
                    <span class="font-medium">${fornitore.telefono || 'N/A'}</span>
                  </div>
                  <div class="text-sm">
                    <span class="text-secondary">Indirizzo:</span>
                    <span class="font-medium">${fornitore.indirizzo || 'N/A'}</span>
                  </div>
                </div>
              </div>
            ` })}
          </div>
        </div>
      </div>
    `;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
