# 10 – Glossario

## A cosa serve
Traduce i **termini del sito** (quelli che vede l'utente) nei **nomi interni** del codice e del database
(spesso più vecchi: "colletta", "campagna", "prenotazione"), con gli stati collegati. Utile per leggere il codice
senza perdersi e per parlare con precisione all'orale. Regola del progetto: nel testo visibile si dice "gruppo" e
"adesione", mai "campagna" e "prenotazione" (`tests/glossario_test.mjs`).

---

## 1. Termini del dominio
| Termine sul sito | Significato | Nome interno (codice/DB) | Dove |
|---|---|---|---|
| **Gruppo** (d'acquisto) | acquisto collettivo di un prodotto con scadenza, minimo e scaglioni di prezzo | `collette` (tabella), "campagna" (rotte `/campagne`, funzioni `campagne_*`, file `campagne.php`, `admin/campagne.js`), `id_colletta` | `02-database.md` |
| **Adesione** | partecipazione di un cliente a un gruppo (quantità + consegna); una per utente per gruppo | `prenotazioni`, `id_prenotazione`, "partecipazione" (`partecipazioni.php`, `/mie/partecipazioni`), "ordine" nelle vecchie pagine | `prenotazioni.stato` |
| **Acconto** | primo pagamento, addebitato subito: 20 % del prezzo del **primo scaglione** × quantità (min € 0,50) | `pagamenti.tipo_pagamento='acconto'`, `prenotazioni.importo_acconto`, config `acconto_percent` | `05-pagamenti.md` §3.1 |
| **Saldo** | secondo pagamento, alla chiusura: merce al prezzo finale + commissione + spedizione − acconto | `pagamenti.tipo_pagamento='saldo'`, `prenotazioni.importo_saldo`, `saldo_dettaglio()` | §3.2 |
| **Minimo** | quantità di pezzi che il gruppo deve raggiungere per attivarsi (soglia del primo scaglione) | `collette.quantita_minima` (colonna), `minimo_colletta()`, MOQ (`moq_richiesto` nelle proposte) | `stato.php:7` |
| **Scaglione** | gradino di prezzo: da N pezzi in su il prezzo unitario scende | `scaglioni_prezzo` (`soglia_partecipanti`, `prezzo_unitario`), `proposta_scaglioni` | `prezzi.php` |
| **Listino** | prezzo pieno di riferimento (barrato) | `collette.prezzo_base`, `prodotti.prezzo_base` | `home.js:311-314`, `dettaglio.js:503` |
| **Prezzo di gruppo / prezzo finale** | prezzo dello scaglione più alto raggiunto | `prezzo_scaglione_raggiunto()`; la colonna `collette.prezzo_corrente` è solo un valore iniziale | `prezzi.php:51` |
| **Commissione** (di servizio) | % di BuyPool sul totale merce (default 10 %) | `collette.percentuale_commissione`, `pagamenti.commissione_agenzia`, `prenotazioni.importo_commissione` | `05-pagamenti.md` §3.3 |
| **Sede di ritiro** | punto dove si ritira la merce; sede predefinita = la prima | `sedi` (`id_sede`), `collette.id_sede`, `consegne.id_sede`, `sede_predefinita_id()` | `api/lib/sede.php` |
| **Ritiro in sede / Spedizione** | modalità di consegna scelta all'adesione | `consegne.modalita` = `ritiro_sede` / `consegna_domicilio`; costo spedizione in config `costo_spedizione` | `consegne` |
| **QR di ritiro** | codice da mostrare allo sportello | `qr_codes.token`, `qr_codes.stato` | `ritiro.php` |
| **Scadenza** | data/ora limite per aderire | `collette.data_limite` | – |
| **Chiusura** | elaborazione automatica alla scadenza (addebito saldi o rimborsi) | `chiusura_colletta()`, `collette.chiusura_data` | `campagne.php:323` |
| **Rimborso** | restituzione degli acconti (totale) | `refunds->create`, `pagamenti.stato='rimborsato'`, `prenotazioni.stato='rimborsata'` | – |
| **Ordine al fornitore** | ordine cumulativo inviato dopo la chiusura | `ordini_fornitore` (`inviato → ricevuto → in_preparazione → evaso`) | `fornitori.php:525` |
| **Fornitore** | azienda che vende il prodotto; ha un account collegato | `fornitori`, `utenti.ruolo='fornitore'`, `inviti_fornitore` | – |
| **Proposta** | idea di nuovo gruppo avanzata da un cliente o fornitore, che va al voto | `proposte_prodotti`, `voti_proposte`, `proposta_scaglioni` | `04-logica-gruppi.md` §11 |
| **Voto** | "mi interessa" a una proposta; 5 voti → valutazione | `voti_proposte.valore_voto='favore'`, `PROPOSTE_SOGLIA_VOTI=5` | – |
| **Notifica** | avviso dentro il sito | `notifiche` | `06-email-e-notifiche.md` |
| **Wallet** | storico dei pagamenti dell'utente (non è un credito) | `GET /wallet` → `pagamenti` | `pagamenti.php` |
| **I miei acquisti** | pagina con le adesioni dell'utente in quattro schede | `GET /mie/acquisti`, `lib/acquisti.php` | `acquisti.js` |
| **Pagamento non riuscito** / "Azione richiesta" | il saldo non è stato addebitato; 48 ore per aggiornare la carta | `prenotazioni.stato='azione_richiesta'`, `saldo_scadenza` | – |

## 2. Gli stati e le loro etichette
### Gruppo
| Interno (`collette.stato`) | Sul sito (card) | Note |
|---|---|---|
| `in_corso` | "Aperto" / "Gruppo attivo ✓" / "Hai aderito · N pezzi" | `statoCard`, `stato-card.js:46-58` |
| `riuscita` | "Gruppo attivo" (minimo raggiunto, ancora aperto) | in `constants.js`: "Il gruppo raggiunge il minimo" |
| `fallita` | "Non attivato" | scaduto senza minimo |
| `ordine_pronto` | "Concluso" | chiusura eseguita |
| `ordine_fornitore` | "Concluso" | ordine inviato |
| `consegnata` | "Concluso" | fornitore ha evaso |
| `annullata` | "Non attivato" | non scritto dal codice |
Display calcolato (PHP `stato_campagna_display`): `in_corso`, `in_chiusura`, `conclusa`, `non_riuscita` (vedi `04-logica-gruppi.md` §9.2).

### Adesione (`prenotazioni.stato`) e vista in "I miei acquisti" (`lib/acquisti.php:14-24`)
| Interno | Vista | Etichetta | Scheda |
|---|---|---|---|
| `prenotata` | `in_corso` | "Gruppo in corso" | In corso |
| `confermata` | `attivo` | "Gruppo attivo" | In corso |
| `azione_richiesta` | `pagamento` | "Pagamento non riuscito" | In corso |
| `pagata` | `inviato` | "Ordine inviato al fornitore" | In arrivo |
| `pagata` + QR `generato` + gruppo `consegnata` + ritiro in sede | `pronto` | "Pronto per il ritiro" | In arrivo |
| `pagata` + QR `scansionato` o consegna `ritirata/consegnata` | `ritirato` | "Ritirato" ("Consegnato" se spedizione) | Conclusi |
| `rimborsata` / gruppo fallito | `rimborsato` | "Rimborsato" | Annullati |
| `annullata` (mai scritta) | `annullato` | "Annullato" | Annullati |
⚠ `frontend/js/constants.js:26-33` ha etichette **vecchie** per `prenotata` ("In Attesa") e `confermata` ("Da Pagare"), usate dalle pagine admin: non corrispondono più al significato attuale (`confermata` = minimo raggiunto, saldo non ancora addebitato).

### Altri
| Entità | Valori e significato |
|---|---|
| `consegne.stato` | `in_attesa`, `spedita`, `consegnata` (`pronta` e `ritirata` mai scritti) |
| `ordini_fornitore.stato` | `inviato`, `ricevuto`, `in_preparazione`, `evaso` |
| `qr_codes.stato` | `generato`, `scansionato` |
| `proposte_prodotti.stato` | `in_attesa`, `in_votazione`, `in_valutazione`, `pubblicata`, `rifiutata`, `scaduta` (+ legacy `approvata_admin`, `respinta_votazione`) |
| `pagamenti.stato` | `confermato`, `rimborsato` (`in_attesa`, `fallito` mai scritti) |
| `utenti.stato` | `attivo`, `sospeso` (sola lettura) |
| `email_inviate.stato` | `in_coda`, `inviata`, `solo_notifica`, `scartata` |

## 3. Termini tecnici
| Termine | Significato |
|---|---|
| **Idempotenza** | ripetere la stessa operazione (webhook, cron, email) non produce effetti doppi |
| **Webhook** | chiamata di Stripe al server (`POST /pagamento/webhook`) per comunicare un pagamento |
| **Off-session** | addebito con la carta salvata senza che l'utente sia presente |
| **SPA / hash router** | applicazione a pagina singola con URL tipo `app.html#/acquisti` |
| **Self-healing** | `GET /pagamento/stato` crea l'adesione se il webhook non è ancora arrivato |

## Domande che potrebbero farmi
1. **Perché nel codice si dice "colletta" e sul sito "gruppo"?** Rinomina del linguaggio di prodotto senza rinominare tabelle e rotte (motivazione presunta: evitare migrazioni rischiose); il test del glossario controlla solo i testi.
2. **Che differenza c'è tra "prenotata" e "confermata"?** Prima/dopo il raggiungimento del minimo: solo le `confermata` vengono addebitate alla chiusura.
3. **Cos'è il "listino"?** Il prezzo pieno barrato (`prezzo_base`), usato per mostrare il risparmio.
4. **Cos'è l'"acconto" e su quale prezzo si calcola?** 20 % del prezzo del primo scaglione × quantità.
5. **"Concluso" e "Non attivato" cosa significano?** Concluso: il minimo è stato raggiunto e il gruppo è chiuso (o in chiusura); Non attivato: scaduto senza minimo, acconti rimborsati.
6. **Il wallet è un portafoglio?** No: è lo storico dei pagamenti.
7. **Chi è il "referente" di un gruppo?** `collette.id_referente`, sempre uguale a `id_aperta_da` (l'admin che lo ha creato); non ha un uso distinto.
8. **Cosa vuol dire "azione richiesta"?** Il saldo non è stato addebitato e l'utente deve aggiornare il pagamento entro 48 ore.
