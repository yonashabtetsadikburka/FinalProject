# API Reference — BuyPool

Riferimento completo delle API REST di BuyPool. Front controller: `api/index.php` — **98 rotte** + webhook Stripe (bypass).

## 1. Convenzioni

- **URL base dev**: `http://localhost:8888/buypool/api/` (in prod: `https://dominio/api/`). Il frontend usa `API_URL` in `frontend/js/constants.js`.
- **Autenticazione**: sessione PHP via cookie (`httponly`, `SameSite=Lax`), stabilita da `POST /login` / `/registrazione` / OAuth. Il frontend invia anche header `Authorization: Bearer <token>`, ma è solo un flag client-side — la sicurezza reale è il cookie di sessione.
- **Contenuto**: richieste con corpo JSON (`Content-Type: application/json`) oppure `multipart/form-data` (upload). Risposte sempre JSON.
- **CSRF**: header `X-CSRF-Token` confrontato con il token di sessione. **Oggi richiesto solo** su `POST|DELETE /proposte/{id}/vota`.

### Involucro risposte

| Esito | Forma |
|---|---|
| Successo | `json_ok($dati, $http)` → payload con i dati (struttura per endpoint, vedi note) |
| Errore | `{"errore": {"codice": "…", "messaggio": "…", "campi": {…}?}}` con status HTTP |

### Codici errore (`AppError`)

| Codice | HTTP | Significato |
|---|---|---|
| `NON_AUTENTICATO` | 401 | Nessuna sessione valida |
| `UTENTE_SOSPESO` | 403 | Account sospeso: sola consultazione |
| `NON_AUTORIZZATO` / `NON_ADMIN` | 403 | Ruolo insufficiente |
| `SOLO_CLIENTI` | 403 | Funzione riservata ai clienti |
| `FORNITORE_NON_COLLEGATO` | 403 | Account fornitore senza anagrafica |
| `CSRF_NON_VALIDO` | 403 | Token CSRF mancante/non valido |
| `VALIDAZIONE` | 422 | Campi non validi (dettaglio in `campi`) |
| `ROTTA_INESISTENTE` | 404 | Endpoint inesistente |
| `METODO_NON_AMMESSO` | 405 | Metodo errato sull'endpoint |
| `FLUSSO_DISABILITATO` | 410 | Endpoint decommissionato (es. adesione diretta) |
| `ERRORE_INTERNO` | 500 | Errore server (dettagli solo in log) |

### Legenda colonne Auth

`Pubblico` = senza sessione · `Login` = qualsiasi sessione valida · `Cliente` = solo ruolo cliente attivo · `Fornitore` = ruolo fornitore con anagrafica collegata · `Admin` = solo ruolo admin.

## 2. Sistema e autenticazione

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/salute` | Pubblico | Health check: `{"stato":"ok","php":"8.x"}` |
| POST | `/registrazione` | Pubblico | Crea account cliente. Body: `nome`, `cognome`, `email`, `password` (≥8). → 201 |
| POST | `/login` | Pubblico | `email` + `password` → utente; rigenera ID sessione |
| POST | `/auth/google` | Pubblico | Body `credential` (ID token GSI) → verifica + login/creazione utente |
| POST | `/auth/microsoft` | Pubblico | Body `credential` — **JWT non verificato (placeholder)** |
| POST | `/logout` | — | Distrugge la sessione → `{"uscito": true}` |
| GET | `/io` | Login | Utente corrente: `id`, `nome`, `email`, `ruolo`, `stato` |

## 3. Catalogo (pubblico/login)

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/fornitori` | Login | Fornitori partner con rating e recensioni |
| GET | `/prodotti` | Login | Catalogo prodotti |
| GET | `/sedi` | Login | Sedi di ritiro |
| GET | `/fornitori/{id}/recensioni` | — | Elenco recensioni (flag `verificata` per l'utente corrente) |
| POST | `/fornitori/{id}/recensioni` | Cliente | Crea recensione (1–5 stelle) — solo chi ha acquistato |
| DELETE | `/fornitori/recensioni/{id}` | Login | Elimina propria recensione (o admin) |
| GET | `/categorie` | Login | Elenco categorie |

## 4. Campagne (collette)

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/campagne` | Pubblico | Elenco campagne con scaglioni, rating fornitore, stato ricalcolato |
| GET | `/campagne/{id}` | Pubblico | Dettaglio campagna |
| POST | `/campagne` | Admin | Crea campagna (`multipart`: nome, prezzi, scaglioni, immagini) |
| POST | `/campagne/{id}/modifica` | Admin | Modifica campagna |
| DELETE | `/campagne/{id}` | Admin | Elimina campagna |
| POST | `/campagne/{id}/chiusura` | Admin | Chiude la campagna (calcola esito, rimborsi se fallita) |
| POST | `/campagne/chiusura` | Admin | Chiusura con id nel body (usata dal cron con `?secret=`) |
| POST | `/campagne/{id}/invia-fornitore` | Admin | Invia l'ordine al fornitore → stato `ordine_fornitore` |
| GET | `/admin/ordini` | Admin | Ordini al fornitore con dettaglio campagna |
| GET | `/campagne/{id}/recensioni` | Pubblico | Recensioni della campagna |
| POST | `/campagne/{id}/recensioni` | Cliente | Recensisce la campagna (solo dopo consegna/ritiro) |
| DELETE | `/campagne/recensioni/{id}` | Login | Elimina recensione campagna (propria o admin) |

## 5. Partecipazioni e acquisti

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| POST | `/campagne/{id}/partecipazioni` | — | **Disabilitato (410)**: le adesioni nascono dal webhook Stripe dell'acconto |
| DELETE | `/campagne/{id}/partecipazioni` | Login | Ritira l'adesione (ribalta gli stati se si scende sotto il MOQ) |
| GET | `/mie/partecipazioni` | Login | Le mie adesioni |
| GET | `/mie/statistiche` | Login | Statistiche personali |
| GET | `/mie/dashboard` | Cliente | Dashboard unificata: gruppi in corso, scaglioni, consigliati |
| GET | `/mie/acquisti` | Cliente | "I miei acquisti" in 4 schede (in corso / in arrivo / conclusi / annullati) |
| POST | `/campagne/{id}/ripartisci` | Admin | Ripartisce i pezzi (metodo dei resti) e conferma le adesioni |
| GET | `/campagne/{id}/assegnazioni` | Login | Esito ripartizione |

## 6. Pagamenti (Stripe)

Tutti i flussi monetari passano da Stripe; i webhook sono la fonte di verità (vedi §13).

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| POST | `/pagamento/checkout` | Login attivo | Checkout **saldo** (prenotazioni `confermata`): totale + commissione + eventuale spedizione → `checkout_url` |
| POST | `/pagamento/acconto-checkout` | Login attivo | Checkout **acconto** (20%): crea prenotazione al webhook |
| POST | `/pagamento/acconto-intent` | Login attivo | Variante Payment Element per l'acconto |
| POST | `/pagamento/aumenta-checkout` | Cliente | Checkout per aumentare la quantità di un'adesione |
| POST | `/pagamento/riprova` | Login attivo | Nuovo tentativo di pagamento del saldo (`azione_richiesta`) |
| GET | `/pagamento/stato` | Login | Stato pagamenti di una prenotazione (self-healing webhook persi) |
| GET | `/pagamento/config` | Login | Configurazione pubblica per il frontend |
| POST | `/pagamento/webhook` | Firma Stripe | **Webhook** — vedi §13 |

## 7. Consegne e ritiri

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/consegne/costo` | Login | Costo spedizione configurato |
| POST | `/consegne/scelta` | Cliente | Sceglie `ritiro_sede` (gratuito) o `consegna_domicilio` (+costo) |
| GET | `/admin/consegne` | Admin | Elenco consegne per la gestione spedizioni |
| POST | `/consegne/{id}/spedisci` | Admin | Segna come spedita (con tracking) |
| POST | `/consegne/{id}/conferma-ricezione` | Login | Il cliente conferma di aver ricevuto la merce |
| GET | `/mie/assegnazioni/{id}/qr` | Login | Token QR della propria assegnazione |
| GET | `/admin/ritiri` | Admin | Ritiri in attesa in sede |
| POST | `/ritiro/{token}` | Admin | Conferma scansione QR in sede (token monouso) |

## 8. Wallet

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/wallet` | Login | Saldo del wallet corrente |
| GET | `/wallet/movimenti` | Login | Movimenti del wallet corrente |
| GET | `/wallet/{id}` | Admin | Wallet di un utente |
| GET | `/wallet/statistiche` | Admin | Statistiche commissioni (admin) |

## 9. Proposte

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/proposte` | Login | Elenco proposte con stato |
| GET | `/proposte/bacheca` | Cliente | Bacheca votabile |
| GET | `/proposte/simili` | Cliente | Proposte simili (anti-duplicati) |
| POST | `/proposte` | Cliente | Crea proposta (multipart: nome, descrizione, foto ≤5MB, scaglioni JSON). Max 5 attive |
| POST | `/proposte/{id}/vota` | Cliente + **CSRF** | Voto `favore`/`contrario` (uno per utente) |
| DELETE | `/proposte/{id}/vota` | Cliente + **CSRF** | Ritira il proprio voto |
| PUT | `/proposte/{id}/stato` | Admin | Cambio stato: `in_votazione`, `pubblicata` (auto-crea prodotto+campagna), `rifiutata` (con `motivo`) |
| GET | `/fornitore/proposte` | Fornitore | Le mie proposte |
| POST | `/fornitore/proposte` | Fornitore | Crea proposta come fornitore (multipart) |

## 10. Notifiche

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/notifiche` | Login | Elenco notifiche |
| GET | `/notifiche/conta` | Login | Conteggio non lette (badge header) |
| PUT | `/notifiche/{id}/letta` | Login | Segna come letta |
| POST | `/notifiche/marca-tutte-lette` | Login | Segna tutte come lette |
| POST | `/notifiche` | Admin | Invia notifica a un utente o broadcast |

## 11. Area fornitore

| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/fornitore/io` | Fornitore | Il mio profilo azienda |
| PUT | `/fornitore/io` | Fornitore | Aggiorna profilo azienda |
| GET | `/fornitore/campagne` | Fornitore | Campagne sui miei prodotti (% adesione) |
| GET | `/fornitore/ordini` | Fornitore | I miei ordini |
| POST | `/fornitore/ordini/{id}/avanza` | Fornitore | Avanza stato: `inviato→ricevuto→in_preparazione→evaso` |

## 12. Amministrazione

### Utenti
| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/utenti` | Admin | Elenco utenti (filtri, ricerca) |
| GET | `/utenti/{id}` | Admin | Dettaglio utente |
| PUT | `/utenti/{id}/stato` | Admin | Attiva/sospendi |
| PUT | `/utenti/{id}/ruolo` | Admin | Cambia ruolo |
| DELETE | `/utenti/{id}` | Admin | Elimina utente |
| GET | `/utenti/{id}/storico` | Admin | Storico acquisti dell'utente |

### Fornitori
| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/admin/fornitori` | Admin | Elenco fornitori |
| POST | `/admin/fornitori` | Admin | Crea fornitore |
| PUT | `/admin/fornitori/{id}` | Admin | Aggiorna fornitore |
| POST | `/admin/fornitori/{id}/invito` | Admin | Genera invito single-use (7 giorni) |
| POST | `/admin/fornitori/{id}/collega` | Admin | Collega un utente esistente all'anagrafica |
| GET | `/fornitore/invito/{token}` | Pubblico | Verifica invito (token) |
| POST | `/fornitore/attiva` | Pubblico | Attiva account fornitore da invito |

### Prodotti e immagini
| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/admin/prodotti/{id}/immagini` | Admin | Galleria immagini |
| POST | `/admin/prodotti/{id}/immagini` | Admin | Aggiungi immagine (multipart) |
| DELETE | `/admin/immagini/{id}` | Admin | Elimina immagine |

### Password
| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| POST | `/password/richiesta` | Pubblico | Richiedi reset (risposta identica se email inesistente) |
| POST | `/admin/utenti/{id}/reset-link` | Admin | Genera link monouso (l'admin lo inoltra) |
| GET | `/password/reset/{token}` | Pubblico | Verifica token di reset |
| POST | `/password/reset` | Pubblico | Esegue il reset (`token` + nuova password) |

### Profilo e GDPR
| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/profilo` | Login | Legge il profilo |
| PUT | `/profilo` | Login | Aggiorna profilo |
| POST | `/profilo/password` | Login | Cambia password (attuale + nuova) |
| GET | `/profilo/export` | Login | **Export GDPR** dei propri dati (JSON) |
| POST | `/profilo/cancellazione` | Login | **Richiesta cancellazione** dati (GDPR art. 17) |

### Dev
| Metodo | Endpoint | Auth | Descrizione |
|---|---|---|---|
| GET | `/_dev/mail-test` | Solo 127.0.0.1 | Invia email di test (404 se non in locale) |

## 13. Webhook Stripe — `POST /pagamento/webhook`

Endpoint pubblico autenticato dalla **firma** del payload (`Stripe\Webhook::constructEvent` con `stripe_webhook_secret`). Bypassa il router normale (gestito in `index.php`).

- **Eventi gestiti**: `payment_intent.succeeded` e `checkout.session.completed` con metadata `tipo`:
  - `acconto` → **crea la prenotazione** (`prenotata`) e il pagamento;
  - `saldo` → prenotazione → `pagata`;
  - `aumenta` → incrementa la quantità dell'adesione;
  - `saldo-retry` → recupero tentativo saldo.
- **Idempotenza**: ogni evento registrato in `eventi_stripe` (`stripe_event_id` UNIQUE); la riesecuzione non duplica effetti.
- **Rimborsi**: `refunds->create` con `idempotency_key` (`rimborso_{payment_intent_id}`) — gestiti dall'applicazione (chiusura campagne fallite, saldo scaduto), non da webhook.
- **Risposta**: 2xx solo dopo elaborazione riuscita; Stripe ritenta in caso di 5xx/timeout.

## 14. Limiti e vincoli

| Vincolo | Valore |
|---|---|
| Upload immagini | ≤5 MB; JPEG/PNG/WEBP; 300–6000 px; riscrittura totale via GD (no EXIF) |
| Proposte attive per utente | Max 5; durata votazione 30 giorni; soglia pubblicazione 5 voti |
| Aumenta quantità | Tetto 99 pezzi |
| Inviti fornitore | Token single-use, scadenza 7 giorni |
| Password | Minimo 8 caratteri |
| Reset password | Token monouso con scadenza |
| Fuso orario server | `Europe/Rome` (date DB wall-clock) |

Documentazione collegata: [03-architettura.md](03-architettura.md) · [04-modello-dati.md](04-modello-dati.md) · [06-sicurezza-gdpr.md](06-sicurezza-gdpr.md)
