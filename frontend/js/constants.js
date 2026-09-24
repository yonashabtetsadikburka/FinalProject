// Ton frontend est à http://localhost:8888/Mnayekkk/FinalProject/frontend/
// Backend Symfony via MAMP Apache (ton screenshot montre que ça répond 401, donc routing OK)
export const API_URL = 'http://localhost:8888/Mnayekkk/FinalProject/backend/public/api';
// Si tu préfères php -S : 'http://localhost:8000/api' (et lance backend sur 8000)

export const STATI_CAMPAGNA_LABELS = {
  in_corso: 'In Corso',
  riuscita: 'Soglia Raggiunta',
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
