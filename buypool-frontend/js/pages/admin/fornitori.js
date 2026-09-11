import { apiGet, apiPost } from '../../api.js';
import { Table } from '../../components/table.js';
import { showToast } from '../../components/toast.js';

const STATI_LABELS = {
  libero: 'Libero',
  collegato: 'Collegato',
  in_attesa: 'In attesa'
};

const STATI_BADGES = {
  libero: 'badge-default',
  collegato: 'badge-success',
  in_attesa: 'badge-warning'
};

function frontendBase() {
  const path = window.location.pathname;
  const idx = path.lastIndexOf('/');
  return window.location.origin + (idx >= 0 ? path.substring(0, idx + 1) : '/');
}

function statoBadge(stato) {
  return `<span class="badge ${STATI_BADGES[stato] || 'badge-secondary'}">${STATI_LABELS[stato] || stato}</span>`;
}

export async function AdminFornitoriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="content-area">
      <div class="admin-page-header">
        <h1>Gestione Fornitori</h1>
        <div style="display:flex;align-items:center;gap:var(--space-3);">
          <div class="search-field">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" class="input" placeholder="Cerca fornitori..." oninput="filterFornitori(this.value)">
          </div>
          <button class="btn btn-default" onclick="nuovoFornitore()">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span>Nuovo fornitore</span>
          </button>
        </div>
      </div>
      <div class="card">
        <div class="card-content">
          <p class="text-secondary" style="margin-bottom: var(--space-4);">
            Le schede fornitore libere possono essere invitate o collegate a un account esistente.
            L'invito permette al fornitore di attivare il proprio account scegliendo una password.
          </p>
          <div id="fornitori-table">
            <p class="text-secondary">Caricamento...</p>
          </div>
        </div>
      </div>
    </div>
  `;

  window.filterFornitori = function(value) {
    const filter = value.trim().toLowerCase();
    const rows = stateFornitori
      .filter(f => {
        if (!filter) return true;
        return (f.nome_azienda || '').toLowerCase().includes(filter)
          || (f.email_contatto || '').toLowerCase().includes(filter);
      })
      .map(rowFornitore);
    document.getElementById('fornitori-table').innerHTML = renderTable(rows);
  };

  const stateFornitori = [];

  function rowFornitore(f) {
    const libero = f.stato !== 'collegato';
    let azioni = '<span class="text-secondary text-sm">—</span>';
    if (libero) {
      azioni = `
        <button class="btn btn-outline btn-sm" onclick="invitaFornitore(${f.id})">Invita</button>
        <button class="btn btn-ghost btn-sm" onclick="collegaFornitore(${f.id}, '${(f.nome_azienda || '').replace(/'/g, "\\'")}')">Collega</button>
      `;
    }
    return {
      azienda: `<span class="font-medium">${f.nome_azienda || '—'}</span>`,
      email: f.email_contatto || '<span class="text-secondary">N/A</span>',
      stato: statoBadge(f.stato),
      azioni
    };
  }

  function renderTable(rows) {
    return Table({
      columns: [
        { key: 'azienda', label: 'Azienda' },
        { key: 'email', label: 'Email contatto' },
        { key: 'stato', label: 'Stato' },
        { key: 'azioni', label: 'Azioni' }
      ],
      rows
    });
  }

  async function caricamentoFornitori() {
    const res = await apiGet('/admin/fornitori');
    return res.dati || [];
  }

  async function caricamentoUtenti() {
    const res = await apiGet('/utenti');
    return res.dati || [];
  }

  async function refresh() {
    const [fornitori, utenti] = await Promise.all([caricamentoFornitori(), caricamentoUtenti()]);
    stateFornitori.length = 0;
    stateFornitori.push(...fornitori);
    window._utentiCollega = utenti.filter(u => !u.fornitore);
    document.getElementById('fornitori-table').innerHTML = renderTable(fornitori.map(rowFornitore));
  }

  try {
    await refresh();
  } catch (e) {
    document.getElementById('fornitori-table').innerHTML =
      `<p style="color:var(--color-error)">${e.message || 'Errore nel caricamento'}</p>`;
  }
}

window.invitaFornitore = async function(id) {
  try {
    const res = await apiPost(`/admin/fornitori/${id}/invito`);
    mostraLinkInvito(res.dati);
  } catch (e) {
    showToast({ title: e.message || 'Errore durante la generazione dell\'invito', variant: 'error' });
  }
};

window.mostraLinkInvito = function({ token, email, scadenza }) {
  const link = `${frontendBase()}fornitore-attiva.html?token=${encodeURIComponent(token)}`;

  document.body.insertAdjacentHTML('beforeend', `
    <div id="invito-modal" class="modal-overlay" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;"
         onclick="if(event.target===this) closeInvitoModal()">
      <div class="modal-content card" style="max-width: 460px; width: 90%;">
        <div class="card-content">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4);">
            <h3 style="margin: 0;">Invito generato</h3>
            <button class="modal-close" onclick="closeInvitoModal()">
              <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <p class="text-sm text-secondary" style="margin-bottom: var(--space-3);">
            Invia questo link al fornitore (scadenza: ${new Date(scadenza).toLocaleString('it-IT')}).
            Generato per <strong>${email}</strong>.
          </p>
          <div style="display: flex; gap: var(--space-2); margin-bottom: var(--space-4);">
            <input type="text" class="input" id="invito-link" value="${link}" readonly
                   style="font-family: monospace; font-size: var(--text-sm);">
            <button class="btn btn-default" onclick="copyInvitoLink()">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </button>
          </div>
          <button class="btn btn-outline w-full" onclick="closeInvitoModal()">Chiudi</button>
        </div>
      </div>
    </div>
  `);
};

window.closeInvitoModal = function() {
  document.getElementById('invito-modal')?.remove();
};

window.copyInvitoLink = async function() {
  const input = document.getElementById('invito-link');
  if (!input) return;
  try {
    await navigator.clipboard.writeText(input.value);
    showToast({ title: 'Link copiato negli appunti', variant: 'success' });
  } catch {
    input.select();
    document.execCommand('copy');
    showToast({ title: 'Link copiato negli appunti', variant: 'success' });
  }
};

window.collegaFornitore = function(id, nomeAzienda) {
  const candidati = (window._utentiCollega || []).filter(u => u.ruolo !== 'admin');
  const options = candidati.length > 0
    ? candidati.map(u => {
        const label = `${u.nome} ${u.cognome || ''} — ${u.email}${u.ruolo === 'fornitore' ? ' (fornitore)' : ''}`.trim();
        return `<option value="${u.id}">${label}</option>`;
      }).join('')
    : '<option value="">Nessun account disponibile</option>';

  document.body.insertAdjacentHTML('beforeend', `
    <div id="collega-modal" class="modal-overlay" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;"
         onclick="if(event.target===this) closeCollegaModal()">
      <div class="modal-content card" style="max-width: 460px; width: 90%;">
        <div class="card-content">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4);">
            <h3 style="margin: 0;">Collega account</h3>
            <button class="modal-close" onclick="closeCollegaModal()">
              <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <p class="text-sm text-secondary" style="margin-bottom: var(--space-3);">
            Scegli un account esistente da collegare alla scheda <strong>${nomeAzienda || ''}</strong>.
          </p>
          <div class="input-group" style="margin-bottom: var(--space-4);">
            <label class="input-label">Account</label>
            <select class="input" id="collega-utente">
              ${options}
            </select>
          </div>
          <div id="collega-error" style="color: var(--color-error); font-size: var(--text-sm); display: none; margin-bottom: var(--space-3);"></div>
          <button class="btn btn-default w-full" onclick="confermaCollega(${id}, this)">Collega</button>
        </div>
      </div>
    </div>
  `);
};

window.closeCollegaModal = function() {
  document.getElementById('collega-modal')?.remove();
};

window.confermaCollega = async function(id, btn) {
  const select = document.getElementById('collega-utente');
  const errorEl = document.getElementById('collega-error');
  errorEl.style.display = 'none';

  if (!select.value) {
    errorEl.textContent = 'Seleziona un account da collegare.';
    errorEl.style.display = 'block';
    return;
  }

  try {
    btn.disabled = true;
    const res = await apiPost(`/admin/fornitori/${id}/collega`, { id_utente: Number(select.value) });
    closeCollegaModal();
    showToast({ title: res.dati?.messaggio || 'Scheda collegata con successo', variant: 'success' });
    AdminFornitoriPage();
  } catch (e) {
    btn.disabled = false;
    errorEl.textContent = e.message || 'Errore durante il collegamento.';
    errorEl.style.display = 'block';
  }
};

// === Creazione nuova scheda fornitore + link di attivazione ===
window.nuovoFornitore = function() {
  document.body.insertAdjacentHTML('beforeend', `
    <div id="nuovo-fornitore-modal" class="modal-overlay" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000;"
         onclick="if(event.target===this) chiudiNuovoFornitore()">
      <div class="modal-content card" style="max-width: 560px; width: 90%;">
        <div class="card-content">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4);">
            <h3 style="margin: 0;">Nuovo fornitore</h3>
            <button class="modal-close" onclick="chiudiNuovoFornitore()">
              <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <p class="text-sm text-secondary" style="margin-bottom: var(--space-4);">
            Crea la scheda fornitore e genera il link di attivazione.
            Il fornitore aprirà il link per scegliere la propria password e attivare l'account.
          </p>
          <form id="nuovo-fornitore-form">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3);">
              <div class="input-group">
                <label class="input-label">Nome azienda *</label>
                <input type="text" class="input" name="nome_azienda" required maxlength="200">
              </div>
              <div class="input-group">
                <label class="input-label">Email di contatto *</label>
                <input type="email" class="input" name="email_contatto" required maxlength="255">
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3);">
              <div class="input-group">
                <label class="input-label">Telefono</label>
                <input type="text" class="input" name="telefono" maxlength="30">
              </div>
              <div class="input-group">
                <label class="input-label">Logo (URL)</label>
                <input type="url" class="input" name="logo_url" maxlength="500">
              </div>
            </div>
            <div class="input-group">
              <label class="input-label">Indirizzo</label>
              <input type="text" class="input" name="indirizzo">
            </div>
            <div class="input-group">
              <label class="input-label">Descrizione</label>
              <textarea class="input" name="descrizione" rows="2"></textarea>
            </div>
            <label style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:var(--space-3);">
              <input type="checkbox" name="partner_pubblico" checked>
              <span class="text-sm">Visibile nel marketplace pubblico</span>
            </label>
            <label style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:var(--space-4);">
              <input type="checkbox" name="genera_invito" checked>
              <span class="text-sm">Genera subito il link di attivazione</span>
            </label>
            <div id="nuovo-fornitore-error" style="color: var(--color-error); font-size: var(--text-sm); display: none; margin-bottom: var(--space-3);"></div>
            <button type="submit" class="btn btn-default w-full">Crea e genera link</button>
          </form>
        </div>
      </div>
    </div>
  `);

  document.getElementById('nuovo-fornitore-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('nuovo-fornitore-error');
    errorEl.style.display = 'none';

    const form = e.target;
    const payload = {
      nome_azienda: form.nome_azienda.value.trim(),
      email_contatto: form.email_contatto.value.trim(),
      telefono: form.telefono.value.trim() || null,
      indirizzo: form.indirizzo.value.trim() || null,
      descrizione: form.descrizione.value.trim() || null,
      logo_url: form.logo_url.value.trim() || null,
      partner_pubblico: form.partner_pubblico.checked,
      genera_invito: form.genera_invito.checked
    };

    try {
      const res = await apiPost('/admin/fornitori', payload);
      chiudiNuovoFornitore();
      showToast({ title: 'Scheda fornitore creata', variant: 'success' });
      if (res.dati?.invito) {
        mostraLinkInvito(res.dati.invito);
      }
      AdminFornitoriPage();
    } catch (err) {
      errorEl.textContent = err.message || 'Errore durante la creazione.';
      errorEl.style.display = 'block';
    }
  });
};

window.chiudiNuovoFornitore = function() {
  document.getElementById('nuovo-fornitore-modal')?.remove();
};