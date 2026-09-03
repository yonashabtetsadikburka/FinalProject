export const API_URL = 'http://localhost:8888/buypool/api';

export const STATI_CAMPAGNA_LABELS = {
  in_corso: 'In Corso',
  riuscita: 'Soglia Raggiunta',
  fallita: 'Fallita',
  ordine_fornitore: 'Ordine al Fornitore',
  consegnata: 'Consegnata',
  annullata: 'Annullata'
};

export const STATI_CAMPAGNA_BADGES = {
  in_corso: 'badge-default',
  riuscita: 'badge-success',
  fallita: 'badge-destructive',
  ordine_fornitore: 'badge-warning',
  consegnata: 'badge-success',
  annullata: 'badge-secondary'
};

export const STATI_PRENOTAZIONE_LABELS = {
  prenotata: 'In Attesa',
  confermata: 'Pagata',
  annullata: 'Annullata',
  rimborsata: 'Rimborsata'
};

export const STATI_PRENOTAZIONE_BADGES = {
  prenotata: 'badge-warning',
  confermata: 'badge-success',
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
