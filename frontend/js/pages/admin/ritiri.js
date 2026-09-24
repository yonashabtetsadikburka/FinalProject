import { apiGet, apiPost } from '../../api.js';
import { Table } from '../../components/table.js';
import { Badge } from '../../components/badge.js';

let filtroRitiri = 'tutti';
let ritiriDati = [];

window.setFiltroRitiri = function(f) {
  filtroRitiri = f;
  document.querySelectorAll('.ritiri-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === f));
  renderRitiriTabella();
};

function renderRitiriTabella() {
  const wrap = document.getElementById('ritiri-tabella');
  if (!wrap) return;
  const lista = ritiriDati.filter(r => {
    if (filtroRitiri === 'attesa') return r.stato_qr !== 'scansionato';
    if (filtroRitiri === 'ritirati') return r.stato_qr === 'scansionato';
    return true;
  });
  const rows = lista.map(r => ({
    utente: `${r.nome || ''} ${r.cognome || ''}`,
    prodotto: r.prodotto || '-',
    quantita: `${r.quantita_assegnata} pezzi`,
    stato: Badge({
      variant: r.stato_qr === 'scansionato' ? 'success' : 'warning',
      children: r.stato_qr === 'scansionato' ? 'Ritirato' : 'Da ritirare'
    }),
    data: r.data_scansione ? new Date(r.data_scansione).toLocaleString('it-IT') : '-'
  }));
  wrap.innerHTML = Table({ columns: [
    { key: 'utente', label: 'Utente' },
    { key: 'prodotto', label: 'Prodotto' },
    { key: 'quantita', label: 'Quantita' },
    { key: 'stato', label: 'Stato' },
    { key: 'data', label: 'Data ritiro' }
  ], rows });
}

window.confermaRitiroAdmin = async function() {
  const input = document.getElementById('ritiro-token-input');
  const resultDiv = document.getElementById('ritiro-result');
  if (!input || !resultDiv) return;
  const token = input.value.trim();
  if (!token) {
    resultDiv.innerHTML = '<div class="text-sm" style="color:var(--color-error);">Incolla il token letto dal QR del cliente.</div>';
    return;
  }
  resultDiv.innerHTML = '<div class="loading-spinner">Verifica...</div>';
  try {
    const res = await apiPost(`/ritiro/${encodeURIComponent(token)}`, {});
    const d = res.dati || {};
    resultDiv.innerHTML = `<div class="card" style="border-left:4px solid var(--color-success);"><div class="card-content">
      <div class="font-medium" style="color:var(--color-success);margin-bottom:var(--space-1);">Ritiro confermato</div>
      <div class="text-sm">${d.utente || ''} &middot; ${d.quantita || 0} pezzi &middot; &euro;${(d.importo_totale || 0).toFixed(2)}</div>
      <div class="text-xs text-secondary">L'utente e\' stato notificato.</div>
    </div></div>`;
    input.value = '';
    await ricaricaRitiri();
  } catch (err) {
    resultDiv.innerHTML = `<div class="text-sm" style="color:var(--color-error);">${err.message}</div>`;
  }
};

async function ricaricaRitiri() {
  try {
    const res = await apiGet('/admin/ritiri');
    ritiriDati = res.dati || [];
    renderRitiriTabella();
    const cont = document.getElementById('ritiri-contatori');
    if (cont) {
      const attesa = ritiriDati.filter(r => r.stato_qr !== 'scansionato').length;
      const fatti = ritiriDati.length - attesa;
      cont.innerHTML = `<span class="text-sm text-secondary">${attesa} da ritirare &middot; ${fatti} ritirati</span>`;
    }
  } catch (_) {}
}

export async function AdminRitiriPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const sediRes = await apiGet('/sedi');
    const sedi = sediRes.dati || [];
    const sediRows = sedi.map(s => ({
      nome: s.nome,
      indirizzo: `${s.indirizzo}, ${s.citta}`,
      telefono: s.telefono || 'N/A',
      orari: s.orari || 'N/A'
    }));

    content.innerHTML = `
      <div class="content-area">
        <div class="admin-page-header"><h1>Gestione Ritiri</h1><span id="ritiri-contatori"></span></div>
        <div class="card" style="margin-bottom:var(--space-3);"><div class="card-content">
          <h3 style="margin-bottom:var(--space-3);">Verifica QR e conferma ritiro</h3>
          <div style="display:flex;gap:var(--space-2);">
            <input type="text" id="ritiro-token-input" class="input" placeholder="Incolla token QR qui..." style="flex:1;font-family:monospace;font-size:var(--text-sm);" />
            <button class="btn btn-default" onclick="confermaRitiroAdmin()">Conferma Ritiro</button>
          </div>
          <div id="ritiro-result" style="margin-top:var(--space-3);"></div>
        </div></div>
        <div class="card" style="margin-bottom:var(--space-3);"><div class="card-content">
          <h3 style="margin-bottom:var(--space-3);">Storico ritiri</h3>
          <div class="wishlist-tabs" style="margin-bottom:var(--space-3);">
            <button class="wishlist-tab ritiri-tab active" data-tab="tutti" onclick="setFiltroRitiri('tutti')">Tutti</button>
            <button class="wishlist-tab ritiri-tab" data-tab="attesa" onclick="setFiltroRitiri('attesa')">Da ritirare</button>
            <button class="wishlist-tab ritiri-tab" data-tab="ritirati" onclick="setFiltroRitiri('ritirati')">Ritirati</button>
          </div>
          <div id="ritiri-tabella"></div>
        </div></div>
        <div class="card"><div class="card-content">
          <h3 style="margin-bottom:var(--space-3);">Sedi di Ritiro</h3>
          ${Table({ columns: [
            { key: 'nome', label: 'Nome' },
            { key: 'indirizzo', label: 'Indirizzo' },
            { key: 'telefono', label: 'Telefono' },
            { key: 'orari', label: 'Orari' }
          ], rows: sediRows })}
        </div></div>
      </div>`;

    filtroRitiri = 'tutti';
    await ricaricaRitiri();
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
