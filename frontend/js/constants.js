export const API_URL = 'http://localhost:8888/buypool/api';

/** Percentuale di acconto sul prezzo primo scaglione (solo presentazione: la logica di pagamento non la usa). */
export const ACCONTO_PERCENT = 20;

/** Voti minimi perché una proposta venga considerata dall'admin (solo presentazione). */
export const SOGLIA_VOTI_PROPOSTA = 5;

export const STATI_CAMPAGNA_LABELS = {
  in_corso: 'In Corso',
  riuscita: 'Il gruppo raggiunge il minimo',
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
  rimborsata: 'Rimborsata',
  azione_richiesta: 'Azione richiesta'
};

export const STATI_PRENOTAZIONE_BADGES = {
  prenotata: 'badge-warning',
  confermata: 'badge-info',
  pagata: 'badge-success',
  annullata: 'badge-destructive',
  rimborsata: 'badge-secondary',
  azione_richiesta: 'badge-destructive'
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
