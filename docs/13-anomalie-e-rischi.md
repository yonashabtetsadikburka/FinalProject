# 13 – Anomalie e rischi

## A cosa serve
Elenco dei **bug e dei rischi reali** emersi leggendo il codice: sicurezza, soldi (doppi addebiti, rimborsi), concorrenza,
dati incoerenti, calcoli duplicati. Per ciascuno: gravità, dove, scenario concreto, correzione consigliata.
**Nessuna correzione è stata applicata.** Dove non ho potuto riprodurre il problema lo dichiaro ("DA VERIFICARE").
La gravità tiene conto del danno possibile (soldi, accessi, dati) e della facilità dello scenario.

**Indice:** A Alta · B Media · C Bassa · D Cosa è già gestito bene

---

## A. Gravità ALTA
### A1 – Login Microsoft senza verifica della firma: presa di controllo di qualunque account
- **Dove:** `api/endpoints/auth.php:128-149,171-173`.
- **Cosa fa il codice:** decodifica il payload del JWT con `base64_decode` (il commento dice "placeholder") e cerca l'utente per `microsoft_id` **o email**; poi scrive in sessione il `ruolo` di quell'utente.
- **Scenario:** chiunque costruisce a mano un token `x.<payload con email di un admin>.y` e fa `POST /auth/microsoft` → sessione admin. Il pulsante Microsoft oggi è un segnaposto lato client (`login.js:4`), ma l'endpoint è raggiungibile direttamente.
- **Correzione:** verificare firma, `iss`, `aud` e `exp` con le chiavi JWKS di Microsoft (libreria JWT), oppure disattivare la rotta finché non è pronta; non collegare per sola email.

### A2 – XSS persistente verso l'admin (nome utente, nome proposta)
- **Dove:** `auth.php:9` (qualsiasi `nome`), `lib/proposte_regole.php` (nome 3–80 caratteri senza ripulitura), `frontend/js/pages/admin/utenti.js:171,172,192,217`, `admin/proposte.js:43,134`, `pages/fornitore.js`.
- **Scenario:** un cliente si registra con nome `<img src=x onerror="…">`; quando l'admin apre "Utenti" lo script gira con la sessione dell'admin e può chiamare l'API (cambiare ruoli, resettare password…). Lo stesso con il nome di una proposta in "Proposte".
- **Correzione:** escape sistematico con `esc()`/`textContent`; eliminare gli `onclick` con valori interpolati; opzionale: rifiutare `<`/`>` nei nomi lato server.

### A3 – Possibile doppio addebito del saldo alla chiusura
- **Dove:** `api/endpoints/campagne.php:323-502` (addebito a `:454-468`, lock rilasciato a `:344`, `chiusura_data` scritta solo a `:500`).
- **Scenario 1 (concorrenza):** cron e admin lanciano la chiusura insieme: entrambi superano il controllo `chiusura_data IS NULL` (la transazione di controllo fa `commit` subito), entrambi leggono le adesioni ancora `confermata` e **entrambi creano il PaymentIntent**. Non c'è `idempotency_key` Stripe su `paymentIntents->create`.
- **Scenario 2 (crash):** il processo si interrompe dopo che Stripe ha addebitato ma prima di `saldo_completa`; la volta successiva l'adesione è ancora `confermata` e viene riaddebitata (mitigato solo se il webhook `payment_intent.succeeded` con `tipo=saldo` arriva prima: `pagamento.php:358-376`).
- **Correzione:** chiave di idempotenza deterministica (`saldo_<id adesione>`), stato intermedio persistente ("in addebito") scritto con `UPDATE … WHERE stato='confermata'` e controllo di `rowCount()`, e/o lock applicativo (`GET_LOCK`) per tutta la durata.

### A4 – Annullamento cliente: rimborso + `DELETE` non atomici, e perdita dello storico dei pagamenti
- **Dove:** `api/endpoints/partecipazioni.php:55-80`; FK `pagamenti.id_prenotazione … ON DELETE CASCADE` (`02-database.md`).
- **Scenario:** l'acconto è rimborsato su Stripe; poi `DELETE FROM prenotazioni` **cancella a cascata le righe `pagamenti`** (anche quelle `rimborsato`): non resta traccia contabile interna del pagamento e del rimborso (resta solo su Stripe e in `eventi_stripe`, dove `id_pagamento` diventa NULL). Se il `DELETE` fallisce dopo il rimborso, il cliente è rimborsato ma ancora iscritto. Nessuna transazione né lock con webhook/chiusura concorrenti.
- **Correzione:** usare lo stato `annullata` (già previsto dall'ENUM) invece del `DELETE`, in transazione con `FOR UPDATE`, e decrementare la quantità nella stessa transazione.

## B. Gravità MEDIA
### B1 – `saldi_scaduti.php` rimborsa prima di verificare lo stato
- **Dove:** `scripts/saldi_scaduti.php:57-73`. Il rimborso Stripe (`rimborsa_acconti`) avviene **prima** del controllo `rowCount() === 1` su `azione_richiesta`. Se l'utente ha appena pagato con "Aggiorna pagamento", l'adesione è `pagata` ma l'acconto è già rimborsato; il codice se ne accorge e scrive solo un log ("verificare").
- **Correzione:** "agganciare" l'adesione (`UPDATE … SET stato='rimborsata' WHERE stato='azione_richiesta'`) **prima** del rimborso; ripristinare in caso di errore Stripe.

### B2 – Regola "a minimo raggiunto non si annulla" solo nell'interfaccia
- **Dove:** `acquisti.js:209-212` e `lib/acquisti.php:153` (UI) contro `partecipazioni.php:19-115` (server) che accetta anche `confermata` fino alla scadenza; l'email promette il contrario (`notifiche_eventi.php:269`).
- **Scenario:** chiamata diretta `DELETE /campagne/{id}/partecipazioni` dopo il raggiungimento del minimo: l'utente esce, il gruppo può tornare sotto soglia e gli altri vengono riportati a `prenotata`.
- **Correzione:** rifiutare nel server se l'adesione è `confermata` (decidere con il prodotto la regola e applicarla in un solo punto).

### B3 – `collette.prezzo_corrente` obsoleto usato in importi reali
- **Dove:** `campagne.php:825` (importo ordine al fornitore), `ritiro.php:124-128` (importo mostrato), `assegnazioni.php:83-98` (notifica), contro `prezzo_scaglione_raggiunto` usato per il saldo (`campagne.php:407`, `pagamento.php:1198`).
- **Scenario:** gruppo con scaglioni 25 € → 22 €; il saldo usa 22 €, ma `ordini_fornitore.importo_totale` e l'importo del ritiro usano il prezzo salvato alla creazione (non aggiornato).
- **Correzione:** un'unica funzione per "prezzo finale del gruppo" e uso in tutti i punti (o salvare `prezzo_finale` alla chiusura).

### B4 – Percorso legacy `assegnazioni_ripartisci` interferisce con la chiusura
- **Dove:** `assegnazioni.php:13-114` (rotta `POST /campagne/{id}/ripartisci`, chiamata da `admin/ordini.js:8`).
- **Scenario:** sposta il gruppo in `ordine_pronto` senza addebitare i saldi, mentre la chiusura salta i gruppi in `ordine_pronto` (`campagne.php:297-301`): i saldi non vengono mai addebitati automaticamente; la notifica invita a pagare con il flusso legacy `/pagamento/checkout`. La "ripartizione" (`ripartisci`) con somma = `quantita_attuale` non cambia le quantità (di fatto è un no-op).
- **Correzione:** rimuovere/nascondere la funzione dall'admin dopo conferma con il prodotto.

### B5 – Webhook Stripe: eventi importanti non gestiti
- **Dove:** `pagamento.php:167,351`. Non gestiti `payment_intent.payment_failed`, `charge.refunded`, `charge.dispute.created`.
- **Scenario:** un rimborso fatto dalla dashboard Stripe o una contestazione non cambiano lo stato nel database; gli eventi restano `elaborato = 0` nella tabella.
- **Correzione:** gestire almeno rimborsi e dispute; marcare come elaborati gli eventi ignorati.

### B6 – Dati e stati incoerenti: `stato` ↔ `chiusura_data`
- **Dove:** `campagne_modifica` (sposta `data_limite`, `:931-940`) senza azzerare `chiusura_data`; `ricalcola_stato` (`stato.php`).
- **Scenario:** gruppi con `chiusura_data` valorizzata e scadenza futura (osservati nei dati demo: gruppi 21, 31, 32, 33): l'aumento di quantità è rifiutato (`CAMPAGNA_CHIUSA`, `aumento.php:48`), la UI li mostra "in corso". Un gruppo `fallita` con acconti già rimborsati può tornare `in_corso` allungando la scadenza (DA VERIFICARE con un test).
- **Correzione:** vietare di spostare la scadenza di un gruppo già chiuso (o azzerare coerentemente i dati), e usare un solo indicatore di "chiuso".

### B7 – Perdita di riservatezza: token QR e partecipanti visibili
- **Dove:** `GET /campagne/{id}/assegnazioni` (`assegnazioni.php:119-142`) e `campagne_dettaglio` (`campagne.php:223-247`), `proposte_elenco` (`proposte.php:12-23`).
- **Scenario:** un cliente loggato ottiene nome, cognome, quantità, stato e **token del QR** di tutti gli altri partecipanti; con il token potrebbe presentarsi al ritiro al posto di un altro.
- **Correzione:** eliminare la rotta (nessun chiamante) o limitarla all'admin; nel dettaglio mostrare solo iniziali/conteggi.

### B8 – La lettura di un gruppo scrive sul DB
- **Dove:** `campagne_elenco`/`campagne_dettaglio`/`admin_ordini` chiamano `ricalcola_stato` (transazione + `FOR UPDATE` + eventuale `UPDATE`) per ogni gruppo, anche per ospiti non autenticati (`campagne.php:78,170,764`).
- **Scenario:** la home pubblica esegue N transazioni con lock a ogni visita: contesa con webhook/chiusura e carico crescente; stato che cambia "per effetto" di una GET.
- **Correzione:** calcolare lo stato senza scrivere (come `stato_campagna_display`) e aggiornare solo nei punti di scrittura/cron.

### B9 – Session/identità
- Logout non chiama il server (`frontend/js/auth.js:32`): la sessione PHP resta valida; ruolo non riletto dal DB (`auth.php:57-58`); utente sospeso può annullare adesioni (`partecipazioni.php:21`); Google con `verify => false` (`auth.php:70`); cookie senza `secure`; nessun rate limit sul login. **Correzioni:** chiamare `POST /logout`, controllare il ruolo/stato a ogni richiesta sensibile, rimuovere `verify=false`.

### B10 – Migrazione obbligatoria non tracciata
- **Dove:** `evento_emetti` (`notifiche_eventi.php:56-78`) fallisce in silenzio se manca `email_inviate`: niente email **e niente notifica** per gli eventi che la creano (RIMBORSO, PREZZO_SCESO); `saldo_scadenza` mancante disattiva il termine di 48 h (`campagne.php:545-549`). Non esiste registro delle migrazioni applicate.
- **Correzione:** controllo all'avvio/health-check e runner di migrazioni.

### B11 – File con dati personali nel working tree
- `api/buypool.sql` (dump con utenti, pagamenti, payload Stripe, corpo email): non tracciato ma **non** in `.gitignore`. Un `git add .` lo pubblicherebbe. **Correzione:** aggiungere a `.gitignore` e spostarlo fuori dal repo.

## C. Gravità BASSA
| # | Cosa | Dove | Note / correzione |
|---|---|---|---|
| C1 | `commissioni_mese` restituisce sempre il totale: variabile `$commissione_mese` non definita (quella calcolata è `$commissioni_mese`) | `api/endpoints/pagamenti.php:99-105,130` | statistiche admin sbagliate; correggere il nome |
| C2 | Saldo negativo (acconto > totale) non rimborsa la differenza | `campagne.php:420-432`, `prezzi.php:13` | caso raro (prezzo scende molto, commissione 0) |
| C3 | Messaggi di errore Stripe restituiti al cliente | `pagamento.php:108,727,783` | log lato server e messaggio generico |
| C4 | `ACCONTO_PERCENT = 20` e `ACCONTO_MINIMO = 0.5` ripetuti nel frontend | `frontend/js/constants.js:4`, `aumenta-dialog.js:5` | se si cambia `acconto_percent` in config il testo "20 %" diventa sbagliato; esporre il valore dall'API |
| C5 | Stime acconto lato client arrotondate per riga (`aumenta-dialog.js:18`) e non sul totale (`saldo-stima.js`) | – | possibile scarto di 1 centesimo fra stima e addebito |
| C6 | Pagina del gruppo: fallback "legacy" dello stato diverso da `displayCampagna` | `dettaglio.js:255-256` vs `stato-card.js:31-38` | scatta solo se manca `stato_campagna` |
| C7 | Operatori di confronto della scadenza non uniformi (`<` / `<=`) | `pagamento.php:643`, `partecipazioni.php:44`, `stato.php:29,77`, `aumento.php:51` | a pari secondo si hanno risposte diverse |
| C8 | `proposte_cambia_stato` non verifica le transizioni | `proposte.php:318-440` | una proposta pubblicata può essere "rifiutata" |
| C9 | Pubblicazione di una proposta = una `INSERT notifiche` per ogni cliente | `proposte.php:422-431` | non scala; usare notifica per ruolo/broadcast |
| C10 | `quantita_totale` e `partecipanti` in `sql_collette` contano anche adesioni rimborsate | `campagne.php:24-25` | DA VERIFICARE dove sono mostrate |
| C11 | `proposta_scaduta()` è testata ma non usata in produzione (la regola reale è in SQL, `proposte_regole.php:263`) | `tests/proposte_regole_test.php` | falsa sicurezza dei test |
| C12 | `tests/aumento_integrazione_test.php` richiede `lib/connessione.php` (inesistente) ed è un duplicato di `aumento_db_test.php`; i test DB scrivono sul DB di sviluppo | `tests/` | rimuovere o correggere; usare un DB di test |
| C13 | `config.example.php` non elenca `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `chiusura_secret`, `frontend_url`, `microsoft_client_id` | `api/config.example.php` | un'installazione pulita fallisce |
| C14 | Schema: `recensioni_campagna` creata prima di `collette`; seed con `trust_score` inesistente; `CHECK` ignorati da MySQL 5.7 | `api/schema.sql:156`, `seed_data.sql:7` | vedi 02 |
| C15 | `API_URL` fissa a `localhost:8888` | `frontend/js/constants.js:1` | rende il deploy più fragile |
| C16 | `GET /_dev/mail-test` sempre registrata | `dev.php`, `index.php:166` | rimuovere in produzione |
| C17 | Segreto della chiusura in query string (`?secret=`) | `campagne.php:277` | finisce nei log; usare header |
| C18 | `eventi_stripe` per eventi non gestiti resta `elaborato=0`; payload mai cancellati | `pagamento.php:156-163` | retention e marcatura |
| C19 | `header.js:148` usa `user.nome[0]` senza controllo | `frontend/js/components/header.js` | errore se nome vuoto (login social senza nome) |
| C20 | `--radius` non definita in `admin/campagne.js:165` | – | solo estetico |
| C21 | Dopo la **registrazione** il frontend salva la sessione locale (`auth.js:26-31`, `register.js:81-90`) ma `auth_registrazione` non scrive `$_SESSION` (`auth.php:6-39`): il server non riconosce l'utente | DA VERIFICARE con una prova | alla prima chiamata autenticata → 401 → ritorno al login (`api.js:30-38`). Correzione: fare il login nel backend dopo la registrazione oppure rimandare al login |

## D. Già gestito bene (per bilanciare)
- Idempotenza webhook su due livelli + vincoli UNIQUE (`pagamenti.stripe_payment_intent_id`, `prenotazioni(id_colletta,id_utente)`): le richieste concorrenti dello stesso pagamento falliscono e vengono ritentate, senza adesioni doppie (`05-pagamenti.md` §7).
- Idempotenza delle email (`email_inviate` UNIQUE) e dei rimborsi (`idempotency_key = rimborso_<PI>`).
- Formula del saldo unica con arrotondamento per voce (`prezzi.php:17-28`) usata da chiusura, riprova, email e stime.
- Lock `FOR UPDATE` coerenti su gruppo e adesione negli aumenti (`pagamento.php:1068-1111`) e nei voti (`proposte_regole.php:259`).
- Rifiuto dell'adesione a gruppo scaduto sia nel checkout sia nel webhook con rimborso automatico (`pagamento.php:643-647,832-844`).

## Domande che potrebbero farmi
1. **Qual è il rischio più grave?** La verifica mancante del token Microsoft (A1): permette di entrare con l'identità di un altro.
2. **Può un utente essere addebitato due volte?** Sulla chiusura sì in teoria (A3), per mancanza di idempotency key e di lock durevole; sull'acconto no grazie ai vincoli UNIQUE.
3. **Cosa succede se il server si ferma durante la chiusura?** I saldi già addebitati sono confermati dal webhook `payment_intent.succeeded`; quelli rimasti verranno riprovati alla chiusura successiva.
4. **Come migliorereste la gestione degli annullamenti?** Stato `annullata` invece di `DELETE`, transazione unica e regola applicata nel server.
5. **Perché il dump SQL è un rischio?** Contiene dati personali e non è ignorato da git.
6. **I test coprono questi casi?** No: i test sono su funzioni pure (saldo, stati, aumento) e su un paio di scenari DB; non ci sono test di concorrenza né di sicurezza.
7. **Qual è la prima cosa che correggereste?** A1 e A2 (accesso), poi A3 (soldi), poi A4 (storico pagamenti).
8. **I problemi sono stati risolti?** No: questo documento descrive soltanto; nessun file di codice è stato modificato.
