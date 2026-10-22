# Proposte: checklist test manuale

Prerequisito: applicata `api/migrations/2026-10-19_proposte_votazione.sql`.
Utenti: **Cliente A**, **Cliente B** (+ almeno 4 altri clienti per arrivare a 5 voti), **Fornitore F**, **Admin**.
Per comodità puoi usare due browser (o una finestra privata) per le sessioni diverse.

## 1. Accesso
1. Accedi come **Fornitore F**: nel menu non c'è "Proposte". Apri a mano `#/proposte`: compare "Questa pagina è riservata ai clienti".
2. Con F, `GET /api/proposte/bacheca` risponde 403 `SOLO_CLIENTI`; da non loggato 401.

## 2. Creare e ammettere
1. **A**: "+ Nuova proposta" (nome, descrizione, link https) → scheda "Le mie proposte", stato "In attesa".
2. **Admin** → Gestione proposte → "Approva" (ammetti al voto). Il proponente riceve la notifica.

## 3. Votare / togliere il voto
1. **B**: scheda "In votazione" → la card di A mostra "0 / 5 voti", "mancano 5 voti", "Scade tra 30 giorni".
2. **B**: "Voto anch'io" → senza ricaricare: "1 / 5", "manca 4", pulsante "Hai votato" (aria-pressed). Il messaggio è annunciato (aria-live).
3. **B**: clic su "Hai votato" → voto tolto, torna "0 / 5".
4. **A** (proponente): sulla propria card non c'è il pulsante di voto, c'è "È la tua proposta: non puoi votarla." e "Chiedi voti ai tuoi amici".
5. Via API come A: `POST /api/proposte/{id}/vota` → 403 `VOTO_PROPRIO`. Senza header `X-CSRF-Token` → 403 `CSRF_NON_VALIDO`.

## 4. Raggiungere i 5 voti
1. 5 clienti diversi da A votano. Alla 5ª: la card passa a "In valutazione" (nota ambra), pillole aggiornate (In votazione −1, In valutazione +1).
2. A e i 5 votanti ricevono la notifica "Proposta in valutazione" (badge Notifiche nell'header).
3. Un 6° cliente non può votare (422 `PROPOSTA_NON_VOTABILE`). Chi aveva votato non può più togliere il voto (422 `VOTO_NON_RITIRABILE`).
4. Due voti simultanei sulla 4ª/5ª posizione non devono mai superare 5 né duplicarsi (prova con due schede).

## 5. Gruppo aperto
1. **Admin** → "Pubblica" sulla proposta in valutazione, compila fornitore/prezzi → campagna creata.
2. A e i votanti ricevono la notifica "La proposta è diventata un gruppo". La card diventa "Gruppo aperto" (verde) con "Vedi il gruppo →" verso la campagna; compare nella scheda "Diventate gruppi".
3. **Admin** rifiuta un'altra proposta con motivo: il proponente la vede in "Le mie proposte" come "Non accolta" con il motivo.

## 6. Scadenza
1. Simula: `UPDATE proposte_prodotti SET data_proposta = NOW() - INTERVAL 31 DAY WHERE id_proposta = <id>;` (proposta in_votazione).
2. Ricarica: per gli altri clienti la proposta sparisce; per il proponente appare "Scaduta" con "Nuova proposta"; il voto è rifiutato (422 `PROPOSTA_SCADUTA`).
3. Limite: con `NOW() - INTERVAL 30 DAY + INTERVAL 1 HOUR` è ancora votabile.
4. Script: `php scripts/scadenza_proposte.php` → "N proposte segnate come scadute"; rilanciato subito dopo → 0 (idempotente).

## 7. UI / accessibilità
- Filtri nell'URL: cambia scheda e ordine, ricarica la pagina (`#/proposte?tab=...&ordina=...`): lo stato è conservato.
- Condividi: popover con WhatsApp, Telegram, Copia link, Altro; Esc chiude; il link `#/proposte?p=<id>` apre la scheda giusta evidenziando la card.
- Tastiera: tab sui pulsanti, focus visibile; aree di tocco ≥ 44px.
- ≤ 1000px: 2 colonne; ≤ 640px: 1 colonna, pillole scorrevoli, "Filtri" apre il pannello con "Ordina", passi in lista verticale.
- Errore (spegni MySQL o usa un token errato): toast chiaro, stato del pulsante ripristinato.
