import { apiGet } from '../api.js';

export async function FornitoriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';

  let fornitori = [];
  try {
    const res = await apiGet('/fornitori');
    fornitori = res.dati || [];
  } catch (e) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${e.message || 'Errore nel caricamento dei fornitori'}</p></div>`;
    return;
  }

  const suppliersHtml = fornitori.map((f, i) => {
    const position = i === 0 ? '🥇' : i === 1 ? '🥈' : '🥉';
    return `
      <div class="card supplier-card" style="cursor: pointer;" onclick="window.location.hash='#/fornitori/${f.id}'">
        <div class="supplier-logo">${(f.nome_azienda || 'F')[0]}</div>
        <div class="supplier-info">
          <div class="supplier-name">${position} ${f.nome_azienda}</div>
          <div class="supplier-meta">
            <span class="text-sm text-secondary">${f.num_campagne || 0} campagne</span>
            <div class="trust-score">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
              Trust: ${f.trust_score || 0}/100
            </div>
          </div>
        </div>
      </div>
    `;
  }).join('');

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Fornitori</h1>
      </div>
      <div class="supplier-list">${suppliersHtml}</div>
    </div>
  `;
}
