import { setPageInterval, clearPageTimers } from './page-timers.js';

/**
 * Formatta il tempo residuo fino a `target` come GG:HH:MM:SS.
 * Ritorna 'Terminata' se scaduto, null se la data non è valida.
 */
export function formatCountdown(target) {
  const t = new Date(target).getTime();
  if (!Number.isFinite(t)) return null;
  const diff = t - Date.now();
  if (diff <= 0) return 'Terminata';
  const s = Math.floor(diff / 1000);
  const gg = String(Math.floor(s / 86400)).padStart(2, '0');
  const hh = String(Math.floor((s % 86400) / 3600)).padStart(2, '0');
  const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
  const ss = String(s % 60).padStart(2, '0');
  return `${gg}:${hh}:${mm}:${ss}`;
}

/**
 * Livello di urgenza in base al tempo residuo:
 * 'now' (<= 24h), 'soon' (<= 3 giorni), '' oltre. Null se data non valida o scaduta.
 */
export function urgencyLevel(target) {
  const t = new Date(target).getTime();
  if (!Number.isFinite(t)) return null;
  const diff = t - Date.now();
  if (diff <= 0) return null;
  if (diff <= 24 * 3600 * 1000) return 'now';
  if (diff <= 3 * 24 * 3600 * 1000) return 'soon';
  return '';
}

/**
 * Aggiorna tutti gli span `.countdown-live` presenti nel DOM
 * leggendo la scadenza da `data-scadenza`, e il livello di urgenza
 * del box genitore `[data-urgency]` (se presente).
 */
export function tickCountdowns() {
  document.querySelectorAll('.countdown-live').forEach(el => {
    const txt = formatCountdown(el.dataset.scadenza);
    el.textContent = txt === null ? '—' : txt;
    const box = el.closest('[data-urgency]');
    if (box) {
      const lvl = urgencyLevel(el.dataset.scadenza);
      box.classList.toggle('countdown-soon', lvl === 'soon');
      box.classList.toggle('countdown-now', lvl === 'now');
    }
  });
}

/**
 * Avvia il tick ogni secondo (cancella prima eventuali timer di pagina).
 * `onTick`, se passato, viene eseguito dopo ogni aggiornamento.
 */
export function startCountdowns(onTick) {
  clearPageTimers();
  tickCountdowns();
  setPageInterval(() => {
    tickCountdowns();
    if (typeof onTick === 'function') onTick();
  }, 1000);
}
