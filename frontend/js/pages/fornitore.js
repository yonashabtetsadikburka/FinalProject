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

/** Badge stato ordine fornitore: in_preparazione → warning, evaso → info (accent), consegnato → success. */
const STATI_ORD_BADGES = {
  da_negoziare: 'warning', inviato: 'info', ricevuto: 'info',
  in_preparazione: 'warning', evaso: 'info', consegnato: 'success', annullato: 'secondary'
};

const STATI_PROP_BADGES = {
  in_attesa: 'warning', in_votazione: 'default',
  approvata_admin: 'success', pubblicata: 'success',
  rifiutata: 'destructive', respinta_votazione: 'destructive'
};

const ICON_WARNING = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>';

function badgeOrdineFornitore(stato) {
  return Badge({ variant: STATI_ORD_BADGES[stato] || 'secondary', children: STATI_ORD[stato] || stato });
}

function attenzioneRow(main, sub, badgeHtml, actionHtml) {
  return `
    <div class="dash-attention-row">
      <div style="min-width:0;flex:1;">
        <div class="text-sm font-medium">${main}</div>
        <div class="text-xs text-secondary" style="margin:2px 0 6px;">${sub}</div>
        ${badgeHtml}
      </div>
      ${actionHtml}
    </div>`;
}

function getEvidenziaId() {
  try {
    const raw = window.location.hash || '';
    const q = raw.split('?')[1] || '';
    const m = q.match(/evidenzia=(\d+)/);
    return m ? m[1] : null;
  } catch (_) { return null; }
}

const SEZIONI_VALIDE = ['area', 'campagne', 'proposte', 'ordini'];

let fData = null;

/* --- "Le mie campagne": stato display, avanzamento, card, filtri (mirror home.js) --- */
let campFornQ = '';
let campFornStato = '';
let campFornSoloMiei = true;

const CAMP_FORN_LABELS = {
  in_corso: 'In corso', confermata: 'Confermata',
  conclusa: 'Conclusa', non_riuscita: 'Non riuscita'
};

/** Stato display a 4 valori: in_corso (timer attivo, MOQ non raggiunto),
 *  confermata (MOQ raggiunto, ancora aperta), conclusa, non_riuscita. */
function statoCampagnaFornitore(c) {
  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 0;
  const raggiunto = min > 0 && qty >= min;
  const t = new Date(c.data_limite).getTime();
  const scaduta = Number.isFinite(t) && t < Date.now();
  const st = c.stato || '';
  if (['fallita', 'annullata'].includes(st) || (scaduta && !raggiunto)) return 'non_riuscita';
  if (['ordine_pronto', 'ordine_fornitore', 'consegnata'].includes(st)) return 'conclusa';
  if (raggiunto) {
    if (!scaduta && ['in_corso', 'riuscita'].includes(st)) return 'confermata';
    return 'conclusa';
  }
  if (!scaduta && ['in_corso', 'riuscita'].includes(st)) return 'in_corso';
  if (!scaduta) return 'in_corso';
  return 'non_riuscita';
}

/** Avanzamento: oltre il MOQ niente frazione fuorviante ("7/3"),
 *  solo "{qty} pezzi prenotati · Obiettivo raggiunto ✓". */
function avanzamentoCampagnaFornitore(c) {
  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 0;
  if (min > 0 && qty >= min) return `${qty} pezzi prenotati &middot; Obiettivo raggiunto &#10003;`;
  const pct = min > 0 ? Math.min(100, Math.round((qty / min) * 100)) : 0;
  return `${qty}/${min} (${pct}%)`;
}

function barraCampagnaFornitore(c) {
  const qty = parseInt(c.quantita_attuale) || 0;
  const min = parseInt(c.quantita_minima) || 0;
  const raggiunto = min > 0 && qty >= min;
  const pct = min > 0 ? Math.min(100, Math.round((qty / min) * 100)) : 0;
  return `<div class="camp-f-bar"><div class="camp-f-fill${raggiunto ? ' raggiunto' : ''}" style="width:${raggiunto ? 100 : pct}%;"></div></div>`;
}

function campagnaCardFornitore(c) {
  const key = statoCampagnaFornitore(c);
  const nome = (c.prodotto || 'Prodotto').replace(/"/g, '&quot;');
  return `
    <div class="card card-elevate">
      <div class="camp-f-head camp-f-${key}">
        <span class="order-status-label">${CAMP_FORN_LABELS[key]}</span>
        ${key === 'confermata' ? '<span class="text-xs">&#10003;</span>' : ''}
      </div>
      <div class="card-content">
        <a href="#/campagne/${c.id}" class="order-product-name" title="${nome}">${c.prodotto || 'Prodotto'}</a>
        <div class="text-xs font-medium" style="margin:var(--space-2) 0;">${avanzamentoCampagnaFornitore(c)}</div>
        ${barraCampagnaFornitore(c)}
        <div style="margin-top:var(--space-3);">
          <a href="#/campagne/${c.id}" class="btn btn-ghost btn-sm">Vedi dettaglio &rarr;</a>
        </div>
      </div>
    </div>`;
}

function campFornBase() {
  if (!fData) return [];
  if (campFornSoloMiei) return fData.campagne || [];
  return fData.campagneAll || [];
}

function renderCampFornGrid() {
  const grid = document.getElementById('camp-forn-grid');
  if (!grid) return;
  const q = (campFornQ || '').toLowerCase();
  const filtrate = campFornBase().filter(c =>
    ((c.prodotto || '').toLowerCase().includes(q)) &&
    (campFornStato === '' || statoCampagnaFornitore(c) === campFornStato));
  const countEl = document.getElementById('camp-forn-count');
  if (countEl) countEl.textContent = `${filtrate.length} ${filtrate.length === 1 ? 'campagna' : 'campagne'}`;
  grid.innerHTML = filtrate.length > 0
    ? filtrate.map(campagnaCardFornitore).join('')
    : `<div class="empty-state"><p class="text-secondary">Nessuna campagna trovata.</p>${(q || campFornStato) ? '<button class="btn btn-ghost btn-sm" style="margin-top:var(--space-2);" onclick="campFornReset()">Reset filtri</button>' : ''}</div>`;
}

window.campFornFiltra = function() {
  campFornQ = document.getElementById('search-camp-forn')?.value || '';
  campFornStato = document.getElementById('filtro-stato-camp-forn')?.value || '';
  renderCampFornGrid();
};

window.campFornReset = function() {
  const q = document.getElementById('search-camp-forn');
  if (q) q.value = '';
  const fs = document.getElementById('filtro-stato-camp-forn');
  if (fs) fs.value = '';
  campFornQ = '';
  campFornStato = '';
  renderCampFornGrid();
};

window.campFornToggleMiei = async function(input) {
  campFornSoloMiei = !!input.checked;
  if (!campFornSoloMiei && !fData.campagneAll) {
    const grid = document.getElementById('camp-forn-grid');
    if (grid) grid.innerHTML = '<div class="loading-spinner">Caricamento...</div>';
    try {
      const res = await apiGet('/campagne');
      fData.campagneAll = res.dati || [];
    } catch (err) {
      if (grid) grid.innerHTML = `<div class="empty-state"><p class="text-secondary">Impossibile caricare tutte le campagne: ${err.message}</p></div>`;
      input.checked = true;
      campFornSoloMiei = true;
      return;
    }
  }
  renderCampFornGrid();
};

/* --- "Ordini ricevuti": card, azione esplicita per stato, filtri --- */
let ordFornQ = '';
let ordFornStato = '';

/** Header card: in_preparazione → warning, evaso → info (accent), consegnato → success. */
const ORD_F_HEAD = {
  in_preparazione: 'in_preparazione',
  evaso: 'evaso',
  consegnato: 'consegnato'
};

/** Bottone d'azione esplicito per stato. Solo questi 3 stati avanzano lato fornitore
 *  (inviato → ricevuto → in_preparazione → evaso via POST /fornitore/ordini/{id}/avanza);
 *  su evaso/consegnato/altri nessun bottone: l'azione successiva spetta al cliente o all'admin. */
function azioneOrdineFornitore(o) {
  if (o.stato === 'inviato') {
    return `<button class="btn btn-default w-full" onclick="fornitoreAvanzaOrdine(${o.id}, 'Ordine ricevuto')">Prendi in carico</button>`;
  }
  if (o.stato === 'ricevuto') {
    return `<button class="btn btn-default w-full" onclick="fornitoreAvanzaOrdine(${o.id}, 'In preparazione')">Metti in preparazione</button>`;
  }
  if (o.stato === 'in_preparazione') {
    return `<button class="btn btn-default w-full" onclick="fornitoreAvanzaOrdine(${o.id}, 'Ordine evaso')">Segna come evaso</button>`;
  }
  return '';
}

function ordineCardFornitore(o, evidenziaId) {
  const headKey = ORD_F_HEAD[o.stato] || 'neutro';
  const headLabel = STATI_ORD[o.stato] || o.stato;
  const ev = String(evidenziaId) === String(o.id) ? ' ordine-evidenziato' : '';
  const nomeEsc = (o.prodotto || 'Prodotto').replace(/"/g, '&quot;');
  const azione = azioneOrdineFornitore(o);
  return `
    <div id="ordine-${o.id}" class="card card-elevate ordine-riga${ev}">
      <div class="ord-f-head ord-f-${headKey}">
        <span class="order-status-label">${headLabel}</span>
      </div>
      <div class="card-content">
        <a href="#/campagne/${o.id_colletta}" class="order-product-name" title="${nomeEsc}">${o.prodotto || 'Prodotto'}</a>
        <div class="text-xs text-secondary" style="margin-top:var(--space-1);">${o.quantita_ordinata} pezzi &middot; ${eurIt(o.importo_totale)}</div>
        ${azione ? `<div style="margin-top:var(--space-3);">${azione}</div>` : ''}
      </div>
    </div>`;
}

function renderOrdFornGrid() {
  const grid = document.getElementById('ord-forn-grid');
  if (!grid || !fData) return;
  const evidenziaId = getEvidenziaId();
  const q = (ordFornQ || '').toLowerCase();
  const filtrati = (fData.ordini || []).filter(o =>
    ((o.prodotto || '').toLowerCase().includes(q)) &&
    (ordFornStato === '' || o.stato === ordFornStato));
  const countEl = document.getElementById('ord-forn-count');
  if (countEl) countEl.textContent = `${filtrati.length} ${filtrati.length === 1 ? 'ordine' : 'ordini'}`;
  grid.innerHTML = filtrati.length > 0
    ? filtrati.map(o => ordineCardFornitore(o, evidenziaId)).join('')
    : `<div class="empty-state"><p class="text-secondary">Nessun ordine trovato.</p>${(q || ordFornStato) ? '<button class="btn btn-ghost btn-sm" style="margin-top:var(--space-2);" onclick="ordFornReset()">Reset filtri</button>' : ''}</div>`;
}

window.ordFornFiltra = function() {
  ordFornQ = document.getElementById('search-ord-forn')?.value || '';
  ordFornStato = document.getElementById('filtro-stato-ord-forn')?.value || '';
  renderOrdFornGrid();
};

window.ordFornReset = function() {
  const q = document.getElementById('search-ord-forn');
  if (q) q.value = '';
  const fs = document.getElementById('filtro-stato-ord-forn');
  if (fs) fs.value = '';
  ordFornQ = '';
  ordFornStato = '';
  renderOrdFornGrid();
};

function titoloSezione(sezione, nomeAzienda) {
  const titoli = {
    area: ['Area Fornitore', nomeAzienda],
    campagne: ['Le mie campagne', 'Cerca, filtra e segui lo stato delle campagne'],
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
  const thumb = document.getElementById('prop-foto-thumb');
  if (!file) return;
  if (nome) nome.textContent = file.name;
  if (rimuovi) rimuovi.style.display = 'inline-block';
  if (thumb) {
    if (thumb.dataset.url) URL.revokeObjectURL(thumb.dataset.url);
    const url = URL.createObjectURL(file);
    thumb.dataset.url = url;
    thumb.innerHTML = `<img src="${url}" alt="Anteprima" />`;
    thumb.style.display = 'block';
  }
  document.querySelector('.upload-dropzone')?.classList.add('has-file');
};

window.fornitoreRimuoviFoto = function() {
  const input = document.getElementById('prop-foto-input');
  const nome = document.getElementById('prop-foto-nome');
  const rimuovi = document.getElementById('prop-foto-rimuovi');
  const thumb = document.getElementById('prop-foto-thumb');
  if (input) input.value = '';
  if (nome) nome.textContent = 'Nessun file selezionato';
  if (rimuovi) rimuovi.style.display = 'none';
  if (thumb) {
    if (thumb.dataset.url) URL.revokeObjectURL(thumb.dataset.url);
    delete thumb.dataset.url;
    thumb.innerHTML = '';
    thumb.style.display = 'none';
  }
  document.querySelector('.upload-dropzone')?.classList.remove('has-file');
};

window.fornitoreDropzoneDrag = function(event) {
  event.preventDefault();
  event.currentTarget.classList.add('dragover');
};

window.fornitoreDropzoneLeave = function(event) {
  event.currentTarget.classList.remove('dragover');
};

window.fornitoreDropzoneDrop = function(event) {
  event.preventDefault();
  event.currentTarget.classList.remove('dragover');
  const file = event.dataTransfer?.files?.[0];
  if (!file) return;
  const input = document.getElementById('prop-foto-input');
  if (!input) return;
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  fornitoreAnteprimaFoto(input);
};

/** Prezzo in formato italiano (€19,99): solo display card proposte fornitore. */
function eurIt(v) {
  const n = parseFloat(v);
  if (!Number.isFinite(n)) return '—';
  return '€' + n.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

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
  const tone = (p.stato === 'rifiutata' || p.stato === 'respinta_votazione') ? 'danger'
    : (p.stato === 'approvata_admin' || p.stato === 'pubblicata') ? 'success'
    : (p.stato === 'in_votazione' || p.stato === 'in_attesa') ? 'warning' : 'neutral';
  const idCampagna = parseInt(p.campagna_id) || 0;
  const linkabile = (p.stato === 'pubblicata' || p.stato === 'approvata_admin') && idCampagna > 0;
  const nomeEsc = (p.nome_prodotto || 'Prodotto').replace(/"/g, '&quot;');
  const prezzoTxt = p.prezzo_base
    ? eurIt(p.prezzo_base) + (p.prezzo_corrente && p.prezzo_corrente !== p.prezzo_base ? ` → ${eurIt(p.prezzo_corrente)}` : '')
    : '';
  return `
  <div class="card card-elevate">
    <div class="prop-head prop-head-${tone}">
      <span class="order-status-label">${STATI_PROP[p.stato] || p.stato}</span>
    </div>
    <div class="card-content">
      ${linkabile
        ? `<a href="#/campagne/${idCampagna}" class="order-product-name" title="${nomeEsc}">${p.nome_prodotto}</a>`
        : `<div class="font-medium text-sm">${p.nome_prodotto}</div>`}
      <div class="text-xs text-secondary" style="margin-top:var(--space-1);">${p.voti_favore || 0} favorevoli${p.moq_richiesto ? ` &middot; MOQ ${p.moq_richiesto}` : ''}${prezzoTxt ? ` &middot; ${prezzoTxt}` : ''}</div>
    </div>
    ${p.motivo ? `<div class="prop-motivo-row">Motivo: ${p.motivo}</div>` : ''}
    ${linkabile ? `<div class="prop-campaign-link"><a href="#/campagne/${idCampagna}" class="btn btn-ghost btn-sm w-full">Vedi campagna &rarr;</a></div>` : ''}
  </div>`;
}

function renderFornitoreSezione(sezione) {
  const wrap = document.getElementById('fornitore-sezione-content');
  if (!wrap || !fData) return;
  const { f, proposte, campagne, ordini, notifiche } = fData;
  const [titolo, sottotitolo] = titoloSezione(sezione, f.nome_azienda);
  let html = '';

  if (sezione === 'campagne') {
    const qEsc = String(campFornQ || '').replace(/"/g, '&quot;');
    const optSel = (v) => campFornStato === v ? ' selected' : '';
    html = `
      <div class="search-filter-bar">
        <div class="search-input-wrapper" style="flex:1;">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="search" autocomplete="off" aria-label="Cerca campagne" class="input" placeholder="Cerca campagne..." id="search-camp-forn" value="${qEsc}" oninput="campFornFiltra()">
        </div>
        <select id="filtro-stato-camp-forn" class="input" style="max-width:220px;" aria-label="Stato campagna" onchange="campFornFiltra()">
          <option value=""${optSel('')}>Tutti gli stati</option>
          <option value="in_corso"${optSel('in_corso')}>In corso</option>
          <option value="confermata"${optSel('confermata')}>Confermata</option>
          <option value="conclusa"${optSel('conclusa')}>Conclusa</option>
          <option value="non_riuscita"${optSel('non_riuscita')}>Non riuscita</option>
        </select>
        <label class="switch-wrap" title="Mostra solo le campagne con i tuoi prodotti">
          <span class="switch">
            <input type="checkbox" ${campFornSoloMiei ? 'checked' : ''} onchange="campFornToggleMiei(this)" aria-label="Solo campagne con i tuoi prodotti">
            <span class="switch-slider"></span>
          </span>
          <span class="text-sm">Solo miei prodotti</span>
        </label>
      </div>
      <div class="text-sm text-secondary" id="camp-forn-count" style="margin-bottom:var(--space-3);"></div>
      <div class="camp-forn-grid" id="camp-forn-grid"></div>`;
  } else if (sezione === 'proposte') {
    const lista = proposte.length > 0 ? `<div class="proposte-list">${proposte.map(propostaCard).join('')}</div>` : '<p class="text-sm text-secondary">Nessuna proposta ancora.</p>';
    html = `
      <div class="proposte-layout">
      ${Card({ class: 'fornitore-form-card', children: `<div class="card-content"><h3 style="margin-bottom:var(--space-3);">Proponi prodotto</h3>
        <div id="prop-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
        <form onsubmit="fornitoreInviaProposta(event)" style="display:flex;flex-direction:column;gap:var(--space-2);">
          <input type="text" name="nome_prodotto" class="input" placeholder="Nome prodotto* (max 80 caratteri)" maxlength="80" required />
          <textarea name="descrizione" class="input" placeholder="Descrizione" rows="2"></textarea>
          <div style="display:flex;gap:var(--space-2);">
            <div style="flex:1;">
              <input type="number" name="moq_richiesto" class="input" placeholder="MOQ*" min="1" required style="width:100%;" />
              <div class="text-xs text-secondary" style="margin-top:4px;">Quantità minima richiesta per attivare la campagna</div>
            </div>
            <div style="flex:1;">
              <input type="number" name="prezzo_base" class="input" placeholder="Prezzo base &euro;*" min="0.01" step="0.01" required style="width:100%;" />
              <div class="text-xs text-secondary" style="margin-top:4px;">Prezzo al pezzo sotto il MOQ</div>
            </div>
            <input type="number" name="prezzo_corrente" class="input" placeholder="Prezzo attuale &euro;" min="0.01" step="0.01" style="flex:1;" />
          </div>
          <label class="upload-dropzone" ondragover="fornitoreDropzoneDrag(event)" ondragleave="fornitoreDropzoneLeave(event)" ondrop="fornitoreDropzoneDrop(event)">
            <input type="file" id="prop-foto-input" name="foto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="fornitoreAnteprimaFoto(this)" />
            <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <span class="upload-dropzone-text">Trascina un'immagine o clicca per selezionarla</span>
            <span class="text-xs text-secondary">JPG, PNG, WebP &middot; max 5MB</span>
            <span id="prop-foto-thumb" class="upload-thumb" style="display:none;"></span>
            <span id="prop-foto-nome" class="text-xs text-secondary">Nessun file selezionato</span>
          </label>
          <button type="button" id="prop-foto-rimuovi" class="btn btn-ghost btn-sm" style="display:none;align-self:flex-start;" onclick="fornitoreRimuoviFoto()">&#10005; Rimuovi immagine</button>
          <div>
            <div class="text-xs text-secondary" style="margin-bottom:4px;">Prezzi a scaglioni (opzionali)</div>
            <div id="scaglioni-wrap"></div>
            <button type="button" class="btn btn-ghost btn-sm" onclick="fornitoreAggiungiScaglione()">+ Aggiungi scaglione</button>
          </div>
          <button type="submit" class="btn btn-default">Invia proposta</button>
        </form>
      </div>` })}
      <div>
        <h3 style="margin-bottom:var(--space-2);">Le mie proposte</h3>${lista}
      </div>
      </div>`;
  } else if (sezione === 'ordini') {
    const qEsc = String(ordFornQ || '').replace(/"/g, '&quot;');
    const optSel = (v) => ordFornStato === v ? ' selected' : '';
    html = `
      <div class="search-filter-bar">
        <div class="search-input-wrapper" style="flex:1;">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="search" autocomplete="off" aria-label="Cerca ordini" class="input" placeholder="Cerca per prodotto..." id="search-ord-forn" value="${qEsc}" oninput="ordFornFiltra()">
        </div>
        <select id="filtro-stato-ord-forn" class="input" style="max-width:220px;" aria-label="Stato ordine" onchange="ordFornFiltra()">
          <option value=""${optSel('')}>Tutti gli stati</option>
          <option value="in_preparazione"${optSel('in_preparazione')}>In preparazione</option>
          <option value="evaso"${optSel('evaso')}>Evaso</option>
          <option value="consegnato"${optSel('consegnato')}>Consegnato</option>
        </select>
      </div>
      <div class="text-sm text-secondary" id="ord-forn-count" style="margin-bottom:var(--space-3);"></div>
      <div class="cards-grid" id="ord-forn-grid"></div>`;
  } else {
    // Area Fornitore (dashboard generale)
  const moqRaggiunti = campagne.filter(c => c.stato === 'riuscita');
  const ordiniAttesa = ordini.filter(o => o.stato === 'inviato' || o.stato === 'ricevuto' || o.stato === 'in_preparazione');
  const totaleAttenzione = moqRaggiunti.length + ordiniAttesa.length;
  const righeAttenzione = [
    ...moqRaggiunti.map(c => attenzioneRow(
      c.prodotto,
      `Soglia raggiunta (${c.quantita_attuale}/${c.quantita_minima}) — preparati a evadere l'ordine cumulativo.`,
      Badge({ variant: 'warning', children: 'MOQ raggiunto' }),
      `<a href="#/campagne/${c.id}" class="btn btn-outline btn-sm">Vedi campagna &rarr;</a>`
    )),
    ...ordiniAttesa.map(o => attenzioneRow(
      o.prodotto,
      `${o.quantita_ordinata} pezzi &middot; &euro;${Number(o.importo_totale).toFixed(2)}`,
      badgeOrdineFornitore(o.stato),
      `<a href="#/fornitore/ordini?evidenzia=${o.id}" class="btn btn-default btn-sm">Gestisci</a>`
    ))
  ];

  const attenzioneHtml = totaleAttenzione === 0
    ? Card({ children: `<div class="card-content">
        <h3 style="margin-bottom:var(--space-2);font-size:var(--text-lg);font-weight:var(--font-semibold);">Richiede attenzione</h3>
        <p class="text-sm text-secondary">Nessun avviso al momento.</p>
      </div>` })
    : `
      <div class="dash-attention">
        <div class="dash-attention-head">
          <h3>Richiede attenzione</h3>
          ${Badge({ variant: 'default', children: String(totaleAttenzione) })}
        </div>
        <div class="dash-attention-list">${righeAttenzione.join('')}</div>
      </div>`;

  const ordiniRecenti = ordini.slice(0, 4).map(o => `
    <div class="fornitore-row-link">
      <a href="#/fornitore/ordini?evidenzia=${o.id}" class="order-product-name" title="${(o.prodotto || '').replace(/"/g, '&quot;')}">${o.prodotto} — ${o.quantita_ordinata} pezzi</a>
      ${badgeOrdineFornitore(o.stato)}
    </div>`).join('') || '<p class="text-sm text-secondary">Nessun ordine.</p>';

  const notRecenti = notifiche.filter(n => !n.letta).slice(0, 3).map(n => `
    <div class="fornitore-row-link">
      <div style="min-width:0;flex:1;">
        <a href="#/notifiche" class="order-product-name" title="${(n.titolo || '').replace(/"/g, '&quot;')}">${n.titolo || ''}</a>
        <div class="text-xs text-secondary">${(n.messaggio || '').slice(0, 90)}${(n.messaggio || '').length > 90 ? '…' : ''}</div>
      </div>
    </div>`).join('') || '<p class="text-sm text-secondary">Nessuna notifica non letta.</p>';

  const propRiepilogo = proposte.slice(0, 4).map(p => `
    <div class="fornitore-row-link">
      <a href="#/fornitore/proposte" class="order-product-name" title="${(p.nome_prodotto || '').replace(/"/g, '&quot;')}">${p.nome_prodotto}</a>
      ${Badge({ variant: STATI_PROP_BADGES[p.stato] || 'secondary', children: STATI_PROP[p.stato] || p.stato })}
    </div>`).join('') || '<p class="text-sm text-secondary">Nessuna proposta.</p>';

  html = `
    ${attenzioneHtml}
    <div class="fornitore-grid">
      ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">I miei ordini recenti</h3>${ordiniRecenti}<div style="margin-top:var(--space-2);"><a href="#/fornitore/ordini" class="btn btn-ghost btn-sm">Tutti gli ordini</a></div></div>` })}
      ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Ultime notifiche</h3>${notRecenti}<div style="margin-top:var(--space-2);"><a href="#/notifiche" class="btn btn-ghost btn-sm">Tutte le notifiche</a></div></div>` })}
      ${Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Le mie proposte</h3>${propRiepilogo}<div style="margin-top:var(--space-2);"><a href="#/fornitore/proposte" class="btn btn-ghost btn-sm">Tutte le proposte</a></div></div>` })}
    </div>`;
  }

  wrap.innerHTML = html;

  if (sezione === 'campagne') {
    if (!campFornSoloMiei && !fData.campagneAll) {
      const grid = document.getElementById('camp-forn-grid');
      if (grid) grid.innerHTML = '<div class="loading-spinner">Caricamento...</div>';
      apiGet('/campagne').then(res => {
        if (fData) fData.campagneAll = res.dati || [];
        renderCampFornGrid();
      }).catch(() => {
        campFornSoloMiei = true;
        const t = document.querySelector('.switch-wrap input');
        if (t) t.checked = true;
        renderCampFornGrid();
      });
    } else {
      renderCampFornGrid();
    }
  }

  const headerEl = document.getElementById('fornitore-titolo');
  if (headerEl) {
    headerEl.innerHTML = sezione === 'area'
      ? `<h1>${titolo}</h1>`
      : `<h1>${titolo}</h1><p class="text-secondary">${sottotitolo}</p>`;
  }

  if (sezione === 'ordini') {
    renderOrdFornGrid();
    const evId = getEvidenziaId();
    if (evId) {
      const target = document.getElementById(`ordine-${evId}`);
      if (target && target.scrollIntoView) {
        setTimeout(() => target.scrollIntoView({ block: 'center', behavior: 'smooth' }), 50);
      }
    }
  }
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

    const prevAll = fData?.campagneAll;
    fData = {
      f,
      proposte: propRes.dati || [],
      campagne: campRes.dati || [],
      ordini: ordRes.dati || [],
      notifiche: notRes.dati || []
    };
    if (prevAll) fData.campagneAll = prevAll;

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
