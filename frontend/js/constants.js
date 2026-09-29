// L'API sta accanto al front-end:  <cartella-progetto>/frontend/...  ->  <cartella-progetto>/api
// Cosi' funziona con qualsiasi nome di cartella e su qualsiasi macchina, senza
// modificare questo file. Per un server diverso: window.BUYPOOL_API_URL = '...'
// (da definire in una <script> prima dei moduli).
export const API_URL = window.BUYPOOL_API_URL
  || (window.location.origin + window.location.pathname.replace(/\/frontend\/.*$/, '') + '/api');

export const STATI_CAMPAGNA_LABELS = {
  in_corso: 'In Corso',
  riuscita: 'Obiettivo Raggiunto',
  fallita: 'Fallita',
  ordine_pronto: 'Ordine Confermato',
  ordine_fornitore: 'Ordine al Fornitore',
  consegnata: 'Consegnata',
  annullata: 'Annullata'
};

export const STATI_CAMPAGNA_BADGES = {
  in_corso: 'badge-default',
  riuscita: 'badge-success',
  fallita: 'badge-destructive',
  ordine_pronto: 'badge-warning',
  ordine_fornitore: 'badge-info',
  consegnata: 'badge-success',
  annullata: 'badge-secondary'
};

export const STATI_PRENOTAZIONE_LABELS = {
  prenotata: 'In Attesa',
  confermata: 'Da Pagare',
  pagata: 'Pagata',
  annullata: 'Annullata',
  rimborsata: 'Rimborsata'
};

export const STATI_PRENOTAZIONE_BADGES = {
  prenotata: 'badge-warning',
  confermata: 'badge-info',
  pagata: 'badge-success',
  annullata: 'badge-destructive',
  rimborsata: 'badge-secondary'
};

export const RUOLI_LABELS = {
  cliente: 'Cliente',
  fornitore: 'Fornitore',
  admin: 'Amministratore'
};

export const RUOLI_BADGES = {
  cliente: 'badge-secondary',
  fornitore: 'badge-info',
  admin: 'badge-default'
};
