/**
 * Difesa contro l'XSS: i testi che arrivano dall'API vanno trattati come DATI, mai come HTML.
 *
 * Le pagine costruiscono l'HTML con template string ("`<h3>${p.nome}</h3>`") e lo scrivono in
 * innerHTML. Se p.nome fosse "<img src=x onerror=...>", il browser eseguirebbe lo script, anche
 * nella pagina di un admin. Per questo api.js passa OGNI risposta da escapeDeep() prima che le
 * pagine la vedano: da li' in poi tutte le stringhe hanno & < > " ' sostituiti da entita',
 * quindi sono sicure sia come testo sia dentro un attributo tra virgolette.
 *
 * Due regole per chi scrive le pagine:
 *  1. I dati che arrivano da api.js sono GIA' sicuri: metterli nei template va bene.
 *  2. Non mettere MAI una stringa dentro un handler inline (onclick="f('${x}')"): il browser
 *     decodifica le entita' PRIMA di eseguire il JavaScript, quindi l'escape non basta.
 *     Usare un attributo dati:  data-nome="${x}" onclick="f(this.dataset.nome)".
 *     this.dataset.nome e' il testo originale (decodificato): se lo si rimette in un template
 *     o in un attributo, va ripassato da esc().
 *  (tests/test_frontend_sicurezza.mjs controlla queste regole a ogni esecuzione.)
 */

const ENTITA = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const INVERSE = { amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'" };

/** Escapa una stringa per l'HTML (testo e attributi tra virgolette). null/undefined -> ''. */
export function esc(valore) {
  return String(valore ?? '').replace(/[&<>"']/g, c => ENTITA[c]);
}

/** Il contrario di esc(): utile per accorciare un testo gia' escapato o per un confirm()/alert(). */
export function unesc(valore) {
  return String(valore ?? '').replace(/&(amp|lt|gt|quot|#39);/g, (_, k) => INVERSE[k]);
}

/**
 * Escapa ricorsivamente ogni stringa di una risposta JSON (le chiavi e gli altri tipi restano uguali).
 * $grezzi: nomi di campo che contengono URL generati dal SERVER e non vanno toccati
 * (un & in un URL diventerebbe &amp; e romperebbe il redirect).
 */
export function escapeDeep(valore, grezzi = ['checkout_url']) {
  if (typeof valore === 'string') return esc(valore);
  if (Array.isArray(valore)) return valore.map(v => escapeDeep(v, grezzi));
  if (valore && typeof valore === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(valore)) {
      out[k] = (grezzi.includes(k) && typeof v === 'string') ? v : escapeDeep(v, grezzi);
    }
    return out;
  }
  return valore;
}

/**
 * Come escapeDeep() ma IDEMPOTENTE: si puo' applicare anche a testi gia' escapati (li lascia uguali).
 * Serve per cio' che non passa da api.js, cioe' l'utente salvato nel localStorage: una sessione
 * vecchia (creata prima di questa difesa) o un valore modificato a mano non deve poter iniettare HTML.
 */
export function sanificaDeep(valore) {
  if (typeof valore === 'string') return esc(unesc(valore));
  if (Array.isArray(valore)) return valore.map(sanificaDeep);
  if (valore && typeof valore === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(valore)) out[k] = sanificaDeep(v);
    return out;
  }
  return valore;
}
