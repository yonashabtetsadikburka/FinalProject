/**
 * Stato ordine unificato (condiviso tra "I miei ordini" e "Le mie partecipazioni").
 * Priorità: fallita > finale > pronto > fornitore > pagato > attesa.
 */
export const STATI_ORDINE = {
  attesa: { label: 'In attesa', variant: 'warning' },
  pagato: { label: 'Pagato', variant: 'info' },
  fornitore: { label: 'Ordine al fornitore', variant: 'info' },
  pronto: { label: 'Pronto per il ritiro', variant: 'default' },
  finale: { label: 'Ritirato', variant: 'success' },
  fallita: { label: 'Non riuscita', variant: 'secondary' }
};

export const STATI_COLLETTA_AVANZATI = ['ordine_pronto', 'ordine_fornitore', 'consegnata'];

export function deliveryType(p) {
  if (p.consegna_modalita === 'consegna_domicilio') return 'spedizione';
  if (p.consegna_modalita === 'ritiro_sede') return 'ritiro';
  return null;
}

/** Restituisce la chiave di stato unificata per una partecipazione/ordine. */
export function statoOrdine(p) {
  if (['annullata', 'rimborsata'].includes(p.stato)) return 'fallita';
  if (['fallita', 'annullata'].includes(p.stato_colletta)) return 'fallita';
  if (p.stato_qr === 'scansionato' || ['ritirata', 'consegnata'].includes(p.consegna_stato)) return 'finale';
  if (p.stato !== 'pagata') return 'attesa';
  if (deliveryType(p) === 'ritiro') return 'pronto';
  if (STATI_COLLETTA_AVANZATI.includes(p.stato_colletta) || ['pronta', 'spedita'].includes(p.consegna_stato)) return 'fornitore';
  return 'pagato';
}

/** Etichetta: stesso stato interno, "Consegnato" per la spedizione al passo finale. */
export function etichettaStato(p) {
  const key = statoOrdine(p);
  if (key === 'finale' && deliveryType(p) === 'spedizione') return 'Consegnato';
  return STATI_ORDINE[key].label;
}
