import { apiGet, apiPost, apiPostForm } from '../api.js';
import { getState } from '../state.js';
import { Card } from '../components/card.js';
import { Badge } from '../components/badge.js';

const STATI_PROP = {
  in_attesa: 'In attesa di validazione', in_votazione: 'In votazione',
  approvata_admin: 'Approvata', rifiutata: 'Rifiutata',
  pubblicata: 'Pubblicata', respinta_votazione: 'Respinta'
};

const STATI_ORD = { da_negoziare: 'Da negoziare', inviato: 'Inviato', ricevuto: 'Ricevuto', in_preparazione: 'In preparazione', evaso: 'Evaso', consegnato: 'Consegnato', annullato: 'Annullato' };

const SEZIONI_VALIDE = ['area', 'campagne', 'proposte', 'ordini'];

let fData = null;

function titoloSezione(sezione, nomeAzienda) {
  const titoli = {
    area: ['Area Fornitore', nomeAzienda],
    campagne: ['Le mie campagne', 'Solo campagne con i tuoi prodotti'],
    proposte: ['Le mie proposte', 'Proponi e segui i tuoi prodotti'],
    ordini: ['Ordini ricevuti', 'Conferma gli ordini da evadere']
  };
  return titoli[sezione] || titoli.area;
}

window.fornitoreAggiungiScaglione = function() {
  const wrap = document.getElementById('scaglioni-wrap');
  if (!wrap) return;
  const div = document.createElement('div');
  div.className = 'scaglione-row';
  div.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;';
  div.innerHTML = `
    <input type="number" name="soglia" placeholder="Soglia pezzi" min="1" required style="flex:1;" />
    <input type="number" name="prezzo" placeholder="Prezzo &euro;" min="0.01" step="0.01" required style="flex:1;" />
    <button type="button" class="btn btn-ghost btn-sm" onclick="this.parentElement.remove()">✕</button>`;
  wrap.appendChild(div);
};

window.fornitoreAnteprimaFoto = function(input) {
  const file = input.files[0];
  const nome = document.getElementById('prop-foto-nome');
  const rimuovi = document.getElementById('prop-foto-rimuovi');
  if (!file) return;
  if (nome) nome.textContent = file.name;
  if (rimuovi) rimuovi.style.display = 'inline-block';
};

window.fornitoreRimuoviFoto = function() {
  const input = document.getElementById('prop-foto-input');
  const nome = document.getElementById('prop-foto-nome');
  const rimuovi = document.getElementById('prop-foto-rimuovi');
  if (input) input.value = '';
  if (nome) nome.textContent = 'Nessun file selezionato';
  if (rimuovi) rimuovi.style.display = 'none';
};

window.fornitoreInviaProposta = async function(e) {
  e.preventDefault();
  const f = e.target;
  const errorEl = document.getElementById('prop-error');
  errorEl.style.display = 'none';
  try {
    const scaglioni = [...document.querySelectorAll('#scaglioni-wrap .scaglione-row')].map(r => ({
      soglia: parseInt(r.querySelector('[name=soglia]').value),
      prezzo: parseFloat(r.querySelector('[name=prezzo]').value)
    }));
    const fd = new FormData();
    fd.append('nome_prodotto', f.nome_prodotto.value.trim());
    fd.append('descrizione', f.descrizione.value.trim());
    fd.append('moq_richiesto', f.moq_richiesto.value);
    fd.append('prezzo_base', f.prezzo_base.value);
    if (f.prezzo_corrente && f.prezzo_corrente.value) fd.append('prezzo_corrente', f.prezzo_corrente.value);
    if (f.tempi_consegna) fd.append('tempi_consegna', f.tempi_consegna.value.trim());
    fd.append('scaglioni', JSON.stringify(scaglioni));
    const fotoInput = document.getElementById('prop-foto-input');
    if (fotoInput && fotoInput.files[0]) fd.append('foto', fotoInput.files[0]);
    await apiPostForm('/fornitore/proposte', fd);
    const ok = document.createElement('div');
    ok.className = 'toast toast-success';
    ok.innerHTML = '<div class="toast-content"><div class="toast-title">Proposta inviata! Passera\' alla votazione pubblica.</div></div>';
    document.body.appendChild(ok);
    setTimeout(() => ok.remove(), 3000);
    FornitorePage();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

window.fornitoreAvanzaOrdine = async function(ordineId, azione) {
  if (!confirm(`Confermare "${azione}" per questo ordine?`)) return;
  try {
    await apiPost(`/fornitore/ordini/${ordineId}/avanza`, {});
    FornitorePage();
  } catch (err) {
    alert(err.message);
  }
};

function propostaCard(p) {
  const bv = p.stato === 'rifiutata' || p.stato === 'respinta_votazione' ? 'destructive'
    : (p.stato === 'approvata_admin' || p.stato === 'pubblicata') ? 'success'
    : p.stato === 'in_votazione' ? 'default' : 'warning';
  return `
  <div class="card" style="margin-bottom:var(--space-2);">
    <div class="card-content">
      <div style="display:flex;justify-content:space-between;align-items:start;">
        <div>
          <div class="font-medium">${p.nome_prodotto}</div>
          <div class="text-xs text-secondary">${p.voti_favore || 0} favorevoli${p.moq_richiesto ? ` &middot; MOQ ${p.moq_richiesto}` : ''}${p.prezzo_base ? ` &middot; &euro;${parseFloat(p.prezzo_base).toFixed(2)}${p.prezzo_corrente && p.prezzo_corrente !== p.prezzo_base ? ` → &euro;${parseFloat(p.prezzo_corrente).toFixed(2)}` : ''}` : ''}</div>
          ${p.motivo ? `<div class="text-xs" style="margin-top:4px;">Motivo: ${p.motivo}</div>` : ''}
        </div>
        ${Badge({ variant: bv, children: STATI_PROP[p.stato] || p.stato })}
      </div>
    </div>
  </div>`;
}

function renderFornitoreSezione(sezione) {
  const wrap = document.getElementById('fornitore-sezione-content');
  if (!wrap || !fData) return;
  const { f, proposte, campagne, ordini, notifiche } = fData;
  const [titolo, sottotitolo] = titoloSezione(sezione, f.nome_azienda);
  let html = '';

  if (sezione === 'campagne') {
    html = campagne.length > 0 ? campagne.map(c => `
      <div style="padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
          <span class="text-sm font-medium">${c.prodotto}</span>
          <span class="text-xs font-medium">${c.quantita_attuale}/${c.quantita_minima} (${Math.min(100, c.percentuale_adesione)}%)</span>
        </div>
        <div style="height:6px;background:var(--color-border);border-radius:3px;overflow:hidden;">
          <div style="height:100%;width:${Math.min(100, c.percentuale_adesione)}%;background:var(--color-success);border-radius:3px;"></div>
        </div>
      </div>`).join('') : '<p class="text-sm text-secondary">Nessuna campagna attiva sui tuoi prodotti.</p>';
    html = Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Campagne con i tuoi prodotti</h3>${html}</div>` });
  } else if (sezione === 'proposte') {
    const lista = proposte.length > 0 ? proposte.map(propostaCard).join('') : '<p class="text-sm text-secondary">Nessuna proposta ancora.</p>';
    html = `
      ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-3);">Proponi prodotto</h3>
        <div id="prop-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
        <form onsubmit="fornitoreInviaProposta(event)" style="display:flex;flex-direction:column;gap:var(--space-2);">
          <input type="text" name="nome_prodotto" class="input" placeholder="Nome prodotto* (max 80 caratteri)" maxlength="80" required />
          <textarea name="descrizione" class="input" placeholder="Descrizione" rows="2"></textarea>
          <div style="display:flex;gap:var(--space-2);">
            <input type="number" name="moq_richiesto" class="input" placeholder="MOQ*" min="1" required style="flex:1;" />
            <input type="number" name="prezzo_base" class="input" placeholder="Prezzo base &euro;*" min="0.01" step="0.01" required style="flex:1;" />
            <input type="number" name="prezzo_corrente" class="input" placeholder="Prezzo attuale &euro;" min="0.01" step="0.01" style="flex:1;" />
          </div>
          <label class="btn btn-outline btn-sm" for="prop-foto-input" style="cursor:pointer;">Scegli immagine</label>
          <input type="file" id="prop-foto-input" name="foto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="fornitoreAnteprimaFoto(this)" />
          <span id="prop-foto-nome" class="text-xs text-secondary" style="margin-left:var(--space-2);">Nessun file selezionato</span>
          <button type="button" id="prop-foto-rimuovi" class="btn btn-ghost btn-sm" style="display:none;margin-left:var(--space-1);" onclick="fornitoreRimuoviFoto()">&#10005;</button>
          <div>
            <div class="text-xs text-secondary" style="margin-bottom:4px;">Prezzi a scaglioni (opzionali)</div>
            <div id="scaglioni-wrap"></div>
            <button type="button" class="btn btn-ghost btn-sm" onclick="fornitoreAggiungiScaglione()">+ Aggiungi scaglione</button>
          </div>
          <button type="submit" class="btn btn-default">Invia proposta</button>
        </form>
      </div>` })}
      <div style="margin-top:var(--space-3);">
        ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Le mie proposte</h3>${lista}</div>` })}
      </div>`;
  } else if (sezione === 'ordini') {
    const righe = ordini.length > 0 ? ordini.map(o => {
      const azione = o.stato === 'inviato'
        ? `<button class="btn btn-default btn-sm" onclick="fornitoreAvanzaOrdine(${o.id}, 'Ordine ricevuto')">Ordine ricevuto</button>`
        : o.stato === 'ricevuto'
          ? `<button class="btn btn-default btn-sm" onclick="fornitoreAvanzaOrdine(${o.id}, 'In preparazione')">In preparazione</button>`
          : o.stato === 'in_preparazione'
            ? `<button class="btn btn-default btn-sm" onclick="fornitoreAvanzaOrdine(${o.id}, 'Ordine evaso')">Ordine evaso</button>` : '';
      return `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        <div>
          <div class="text-sm font-medium">${o.prodotto} — ${o.quantita_ordinata} pezzi</div>
          <div class="text-xs text-secondary">&euro;${o.importo_totale.toFixed(2)} &middot; ${STATI_ORD[o.stato] || o.stato}</div>
        </div>
        ${azione}
      </div>`;
    }).join('') : '<p class="text-sm text-secondary">Nessun ordine ricevuto.</p>';
    html = Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Ordini ricevuti</h3>${righe}</div>` });
  } else {
    // Area Fornitore (dashboard generale)
  const moqRaggiunti = campagne.filter(c => c.stato === 'riuscita');
  const ordiniAttesa = ordini.filter(o => o.stato === 'inviato' || o.stato === 'ricevuto' || o.stato === 'in_preparazione');
  const avvisiHtml = (moqRaggiunti.length === 0 && ordiniAttesa.length === 0)
    ? '<p class="text-sm text-secondary">Nessun avviso al momento.</p>'
    : moqRaggiunti.map(c => `
      <div style="display:flex;gap:var(--space-2);align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        ${Badge({ variant: 'warning', children: 'MOQ raggiunto' })}
        <span class="text-sm"><strong>${c.prodotto}</strong> — soglia raggiunta, preparati a evadere l'ordine cumulativo.</span>
      </div>`).join('') + ordiniAttesa.map(o => `
      <div style="display:flex;gap:var(--space-2);align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        ${Badge({ variant: 'info', children: 'Ordine da gestire' })}
        <span class="text-sm"><strong>${o.prodotto}</strong> — ${o.quantita_ordinata} pezzi (${STATI_ORD[o.stato] || o.stato}).</span>
      </div>`).join('');

  const ordiniRecenti = ordini.slice(0, 4).map(o => `
    <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
      <div class="text-sm font-medium">${o.prodotto} — ${o.quantita_ordinata} pezzi</div>
      <span class="text-xs text-secondary">${STATI_ORD[o.stato] || o.stato}</span>
    </div>`).join('') || '<p class="text-sm text-secondary">Nessun ordine.</p>';

  const notRecenti = notifiche.filter(n => !n.letta).slice(0, 3).map(n => `
    <div style="padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
      <div class="text-sm font-medium">${n.titolo || ''}</div>
      <div class="text-xs text-secondary">${(n.messaggio || '').slice(0, 90)}${(n.messaggio || '').length > 90 ? '…' : ''}</div>
    </div>`).join('') || '<p class="text-sm text-secondary">Nessuna notifica non letta.</p>';

  const propRiepilogo = proposte.slice(0, 4).map(p => `
    <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
      <div class="text-sm font-medium">${p.nome_prodotto}</div>
      <span class="text-xs text-secondary">${STATI_PROP[p.stato] || p.stato}</span>
    </div>`).join('') || '<p class="text-sm text-secondary">Nessuna proposta.</p>';

  html = `
    ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Richiede attenzione</h3>${avvisiHtml}</div>` })}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:var(--space-3);margin-top:var(--space-3);">
      ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">I miei ordini recenti</h3>${ordiniRecenti}</div>` })}
      ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Ultime notifiche</h3>${notRecenti}</div>` })}
      ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Le mie proposte</h3>${propRiepilogo}</div>` })}
    </div>`;
  }

  wrap.innerHTML = html;

  const headerEl = document.getElementById('fornitore-titolo');
  if (headerEl) headerEl.innerHTML = `<h1>${titolo}</h1><p class="text-secondary">${sottotitolo}</p>`;
}

export async function FornitorePage(params = {}) {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  const { user } = getState();
  if (!user || user.ruolo !== 'fornitore') {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2 class="empty-state-title">Accesso non autorizzato</h2><p class="empty-state-description">Area riservata ai fornitori.</p><a href="#/" class="btn btn-default">Torna alla Home</a></div></div>`;
    return;
  }

  let sezione = params.sezione || 'area';
  if (!SEZIONI_VALIDE.includes(sezione)) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2 class="empty-state-title">Pagina non trovata</h2><a href="#/fornitore" class="btn btn-default">Torna all'Area Fornitore</a></div></div>`;
    return;
  }

  try {
    const [ioRes, propRes, campRes, ordRes, notRes] = await Promise.all([
      apiGet('/fornitore/io'),
      apiGet('/fornitore/proposte').catch(() => ({ dati: [] })),
      apiGet('/fornitore/campagne').catch(() => ({ dati: [] })),
      apiGet('/fornitore/ordini').catch(() => ({ dati: [] })),
      apiGet('/notifiche').catch(() => ({ dati: [] }))
    ]);
    const f = ioRes.dati;
    if (!f) throw new Error('Anagrafica non trovata');

    fData = {
      f,
      proposte: propRes.dati || [],
      campagne: campRes.dati || [],
      ordini: ordRes.dati || [],
      notifiche: notRes.dati || []
    };

    content.innerHTML = `
      <div class="content-area">
        <div class="page-header" id="fornitore-titolo"></div>
        <div id="fornitore-sezione-content"></div>
      </div>`;
    renderFornitoreSezione(sezione);
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Accesso non disponibile</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}
