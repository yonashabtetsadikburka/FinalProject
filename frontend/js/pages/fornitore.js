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
    const evidenziaId = getEvidenziaId();
    const righe = ordini.length > 0 ? ordini.map(o => {
      const azione = o.stato === 'inviato'
        ? `<button class="btn btn-default btn-sm" onclick="fornitoreAvanzaOrdine(${o.id}, 'Ordine ricevuto')">Ordine ricevuto</button>`
        : o.stato === 'ricevuto'
          ? `<button class="btn btn-default btn-sm" onclick="fornitoreAvanzaOrdine(${o.id}, 'In preparazione')">In preparazione</button>`
          : o.stato === 'in_preparazione'
            ? `<button class="btn btn-default btn-sm" onclick="fornitoreAvanzaOrdine(${o.id}, 'Ordine evaso')">Ordine evaso</button>` : '';
      const ev = String(evidenziaId) === String(o.id) ? ' ordine-evidenziato' : '';
      return `
      <div id="ordine-${o.id}" class="ordine-riga${ev}" style="display:flex;justify-content:space-between;align-items:center;gap:var(--space-2);padding:var(--space-2) 0;border-bottom:1px solid var(--color-border);">
        <div style="min-width:0;">
          <div class="text-sm font-medium">${o.prodotto} — ${o.quantita_ordinata} pezzi</div>
          <div class="text-xs text-secondary" style="margin:2px 0 6px;">&euro;${o.importo_totale.toFixed(2)}</div>
          ${badgeOrdineFornitore(o.stato)}
        </div>
        ${azione}
      </div>`;
    }).join('') : '<p class="text-sm text-secondary">Nessun ordine ricevuto.</p>';
    html = Card({ children: `<div class="card-content"><h3 style="margin-bottom:var(--space-2);">Ordini ricevuti</h3>${righe}</div>` });
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
