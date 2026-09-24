import { apiGet, apiPut } from '../../api.js';
import { Badge } from '../../components/badge.js';

const STATI = {
  in_attesa: 'In Attesa', in_votazione: 'In Votazione', approvata_admin: 'Approvata',
  rifiutata: 'Rifiutata', pubblicata: 'Pubblicata', respinta_votazione: 'Respinta'
};

let filtroStato = 'attesa';

window.setFiltroProposte = function(f) {
  filtroStato = f;
  renderAdminProposte();
};

window.propostaAzione = async function(id, stato, etichetta) {
  let motivo = null;
  if (stato === 'rifiutata') {
    motivo = prompt('Motivo del rifiuto (visibile al proponente):') || '';
  }
  if (stato === 'pubblicata') {
    propostaPubblica(id);
    return;
  }
  if (!confirm(`Impostare la proposta #${id} come "${etichetta || STATI[stato]}"?`)) return;
  try {
    await apiPut(`/proposte/${id}/stato`, { stato, motivo });
    alert('Stato aggiornato.');
    renderAdminProposte();
  } catch (err) {
    alert(err.message);
  }
};

window.propostaPubblica = async function(id) {
  try {
    const res = await apiGet('/admin/fornitori');
    const fornitori = res.dati || [];
    // Prova a pre-selezionare il fornitore suggerito/collegato
    const prop = (await apiGet('/proposte')).dati?.find(p => p.id_proposta === id);
    const suggerito = prop?.id_fornitore_suggerito || null;
    const options = fornitori.map(f =>
      `<option value="${f.id}" ${suggerito === f.id ? 'selected' : ''}>${f.nome_azienda}</option>`).join('');
    const prezzoBase = prop?.prezzo_base ?? '';
    const prezzoCorrente = prop?.prezzo_corrente ?? prezzoBase;
    const moq = prop?.moq_richiesto ?? 1;
    const dataDefault = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    const modalHtml = `
      <div id="pubblica-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;padding:var(--space-4);">
        <div class="modal-content card" style="max-width:520px;width:100%;max-height:90vh;overflow-y:auto;">
          <div class="card-content">
            <h3 style="margin-bottom:var(--space-3);">Pubblica campagna</h3>
            <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Verranno creati prodotto + campagna e tutti gli utenti saranno avvisati.</p>
            <div id="pubblica-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-2);">
              <label class="text-sm" style="grid-column:1/-1;">Fornitore
                <select id="pubblica-fornitore" style="width:100%;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);">${options}</select>
              </label>
              <label class="text-sm">Prezzo base (&euro;)
                <input id="pubblica-prezzo-base" type="number" min="0.01" step="0.01" value="${prezzoBase}" style="width:100%;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);" />
              </label>
              <label class="text-sm">Prezzo attuale (&euro;)
                <input id="pubblica-prezzo" type="number" min="0.01" step="0.01" value="${prezzoCorrente}" style="width:100%;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);" />
              </label>
              <label class="text-sm">MOQ (pezzi)
                <input id="pubblica-moq" type="number" min="1" step="1" value="${moq}" style="width:100%;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);" />
              </label>
              <label class="text-sm">Commissione (%)
                <input id="pubblica-commissione" type="number" min="0" max="100" step="0.5" value="10" style="width:100%;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);" />
              </label>
              <label class="text-sm" style="grid-column:1/-1;">Scadenza
                <input id="pubblica-scadenza" type="date" value="${dataDefault}" style="width:100%;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:var(--space-2);" />
              </label>
            </div>
            <div style="display:flex;gap:var(--space-2);margin-top:var(--space-3);">
              <button class="btn btn-default w-full" onclick="confermaPubblica(${id})">Pubblica</button>
              <button class="btn btn-outline w-full" onclick="document.getElementById('pubblica-modal').remove()">Annulla</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
  } catch (err) {
    alert(err.message);
  }
};

window.confermaPubblica = async function(id) {
  const errorEl = document.getElementById('pubblica-error');
  const val = (vid) => document.getElementById(vid)?.value || '';
  try {
    const res = await apiPut(`/proposte/${id}/stato`, {
      stato: 'pubblicata',
      id_fornitore: parseInt(val('pubblica-fornitore')),
      prezzo_base: val('pubblica-prezzo-base'),
      prezzo_corrente: val('pubblica-prezzo'),
      moq: val('pubblica-moq'),
      scadenza: val('pubblica-scadenza'),
      commissione: val('pubblica-commissione')
    });
    const auto = res.dati?.auto_creazione;
    document.getElementById('pubblica-modal')?.remove();
    alert(`Pubblicata! Creati prodotto #${auto.id_prodotto} e campagna #${auto.id_colletta}. Tutti gli utenti sono stati avvisati.`);
    renderAdminProposte();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

async function renderAdminProposte() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  try {
    const res = await apiGet('/proposte');
    const tutte = res.dati || [];

    const filtrate = tutte.filter(p => {
      if (filtroStato === 'attesa') return p.stato === 'in_attesa';
      if (filtroStato === 'votazione') return p.stato === 'in_votazione';
      if (filtroStato === 'gestite') return ['approvata_admin', 'rifiutata', 'pubblicata', 'respinta_votazione'].includes(p.stato);
      return true;
    });

    const cards = filtrate.length > 0 ? filtrate.map(p => {
      const badgeVariant = p.stato === 'in_attesa' ? 'warning' : p.stato === 'in_votazione' ? 'default'
        : (p.stato === 'approvata_admin' || p.stato === 'pubblicata') ? 'success' : 'destructive';
      return `
      <div class="card" style="margin-bottom:var(--space-3);">
        <div class="card-content">
          <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-2);">
            <div>
              <h3 style="font-weight:var(--font-semibold);">${p.nome_prodotto}</h3>
              <div class="text-xs text-secondary">da ${p.proponente_nome || ''} ${p.proponente_cognome || ''} (${p.proponente_tipo}) &middot; ${p.tot_voti} voti</div>
            </div>
            ${Badge({ variant: badgeVariant, children: STATI[p.stato] || p.stato })}
          </div>
          ${p.descrizione ? `<p class="text-sm" style="margin-bottom:var(--space-2);">${p.descrizione}</p>` : ''}
          <div class="text-xs text-secondary" style="margin-bottom:var(--space-3);">
            ${p.moq_richiesto ? `MOQ: ${p.moq_richiesto} &middot; ` : ''}${p.prezzo_base ? `Prezzo: &euro;${parseFloat(p.prezzo_base).toFixed(2)} &middot; ` : ''}${p.tempi_consegna ? `Consegna: ${p.tempi_consegna}` : ''}
          </div>
          ${p.motivo ? `<div class="text-xs" style="margin-bottom:var(--space-2);">Motivo: ${p.motivo}</div>` : ''}
          <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
            ${p.stato === 'in_attesa' ? `
              <button class="btn btn-default btn-sm" onclick="propostaAzione(${p.id_proposta}, 'in_votazione', 'Ammetti alla votazione')">Approva</button>
              <button class="btn btn-destructive btn-sm" onclick="propostaAzione(${p.id_proposta}, 'rifiutata')">Rifiuta</button>` : ''}
            ${['in_votazione', 'approvata_admin'].includes(p.stato) ? `
              <button class="btn btn-outline btn-sm" onclick="propostaAzione(${p.id_proposta}, 'pubblicata')">Pubblica</button>
              <button class="btn btn-destructive btn-sm" onclick="propostaAzione(${p.id_proposta}, 'rifiutata')">Rifiuta</button>` : ''}
          </div>
        </div>
      </div>`;
    }).join('') : '<div class="empty-state"><p>Nessuna proposta in questa categoria.</p></div>';

    content.innerHTML = `
      <div class="content-area">
        <div class="admin-page-header"><h1>Gestione Proposte</h1></div>
        <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-4);flex-wrap:wrap;">
          ${[['attesa', 'In attesa'], ['votazione', 'In votazione'], ['gestite', 'Gestite'], ['tutte', 'Tutte']].map(([v, l]) =>
            `<button class="btn btn-sm ${filtroStato === v ? 'btn-default' : 'btn-outline'}" onclick="setFiltroProposte('${v}')">${l}</button>`).join('')}
        </div>
        ${cards}
      </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}

export async function AdminPropostePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';
  renderAdminProposte();
}
