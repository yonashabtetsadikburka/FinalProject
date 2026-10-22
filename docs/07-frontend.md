# 07 – Frontend

## A cosa serve
Spiega come è fatta l'interfaccia: **SPA in JavaScript vanilla** (moduli ES, senza build né npm) con un router a hash,
come le pagine parlano con l'API, dove stanno stato e sessione, quali moduli sono condivisi e come è organizzato il CSS.
Contiene la mappa **pagina → file JS/CSS → endpoint chiamati**. Si usa per rispondere a "cosa succede quando clicco X?".
`J` = `frontend/js/`, `F` = `frontend/`. I riferimenti derivano dalla lettura del codice e da ricerche incrociate (Metodo in 12).

---

## 1. Le tre entrate HTML
Tutte caricano gli stessi 6 CSS nello stesso ordine (`variables, base, layout, components, pages, responsive`). Nessun bundler.
| File | Ruolo |
|---|---|
| `F/app.html` (238 righe) | **SPA autenticata** con router inline e shell (`#header-container`, `#content-area`); importa staticamente tutte le pagine (righe 25-52) e la libreria QR classica `js/lib/qrcode.js` (riga 23) |
| `F/index.html` (53 righe) | login, registrazione, password dimenticata, privacy, attivazione fornitore, con un mini-router; carica Google GSI (riga 50) e MSAL 2.38.3 da CDN (riga 51) |
| `F/fornitore-attiva.html` (21 righe) | entrata per il link di attivazione fornitore (`?token=`): nessun riferimento nel frontend, ma è il link generato dal backend (`api/endpoints/fornitori.php:133`) |
| `F/.htaccess` | HTML/JS/CSS `no-cache, must-revalidate`; immagini e font con cache di 7 giorni |

## 2. Il router (`F/app.html`)
- **Tabella delle rotte** `routes` (righe 57-85): ogni voce ha `path`, `component`, `guard` (`auth`/`admin`), opzionale `public: true` (visibile agli ospiti) e `redirect`.
- **`matchRoute`** (99-117): converte `:param` in regex `([^/]+)`, match esatto in ordine di dichiarazione.
- **`handleRoute`** (121-181), in ordine: ignora se sta caricando; toglie la query dall'hash; **non ri-renderizza se il percorso è uguale all'ultimo** (così Home e Acquisti reagiscono ai filtri con `hashchange` senza rimontare, `home.js:765`, `acquisti.js:378`); se l'utente non è autenticato e la rotta non è pubblica salva `sessionStorage.postLoginHash` e va a `index.html`; gestisce i `redirect` (`/partecipazioni`, `/ordini` → `/acquisti`); ridisegna l'header; azzera i timer di pagina (`clearPageTimers`); 404 o "Accesso non autorizzato" per le guardie; monta il componente.
- **Eventi globali**: `hashchange` (riga 229); `visibilitychange` → `validateSession()` chiama `GET /io` e, se fallisce, pulisce la sessione (201-216); evento `storage` per il logout tra schede (218-223).
- Le pagine usano molti `onclick="nomeFunzione()"` inline, quindi assegnano funzioni a `window.*` (es. `window.handleAderisci`).
- Non c'è un teardown dei componenti: i vecchi listener restano finché il DOM viene sostituito.

## 3. Come il frontend chiama l'API (`J/api.js`, `J/constants.js`)
- `API_URL = 'http://localhost:8888/buypool/api'` **fisso** (`constants.js:1`): per il deploy va configurato (DA VERIFICARE nella guida deploy esistente).
- `apiRequest(method, url, body)` (`api.js:4-62`): invia JSON, `cache: 'no-store'`, header `Authorization: Bearer <token>` se presente; risposta attesa `{dati}` / `{errore:{codice,messaggio,campi}}`; in caso di errore lancia `Error(messaggio)` con `.codice` e `.campi`.
- Su **401** (non per le rotte auth) pulisce la sessione e va a `index.html` (`api.js:30-38`); su `UTENTE_SOSPESO` mostra un toast (43-49).
- Wrapper: `apiGet`, `apiPost`, `apiPut`, `apiDelete`; `apiPostForm` per i `multipart` (upload foto).
- Eccezione: `pages/fornitore-attiva.js:3-19` usa `fetch` diretto (pagina pubblica).
- ⚠ Il "token" è finto: `'session_' + id` (`auth.js:6-27`). Il backend non legge mai l'header `Authorization`; funziona solo il **cookie di sessione PHP** (stesso origin). `isAuthenticated()` (`auth.js:59`) è quindi una verifica lato client sul `localStorage`; la verità è `GET /io`.

## 4. Stato e sessione
| Cosa | Dove |
|---|---|
| Store minimale `{user, token, isLoading}` con `subscribe` | `J/state.js` |
| `localStorage`: `buypool_token`, `buypool_user` | `state.js:23-47` |
| `sessionStorage`: `postLoginHash` (ritorno dopo login), `buypool_pending_adesione` (adesione di un ospite in attesa del login), `recPopupShown` (pagina morta) | `auth.js:48`, `dettaglio.js:592,988,992` |
| Login/registrazione/OAuth, `landingPerRuolo` (admin → `#/admin`, fornitore → `#/fornitore`, cliente → `#/`) | `J/auth.js` |
| Timer per pagina (`setPageInterval/Timeout`, `clearPageTimers`) | `J/page-timers.js` |
| Login Google: client id cablato in `pages/login.js:49`; **Microsoft: `MICROSOFT_CLIENT_ID` è un segnaposto** (`login.js:4`) | – |

## 5. Moduli condivisi
**Funzioni pure (testate con Node):**
| Modulo | Cosa fa |
|---|---|
| `format.js` | `euro` (formato `€1.234,56`), `plurale` e wrapper `pezzi, voti, gruppi, ritiri, recensioni, persone`; date `dataGGMMAAAA`, `dataGGMM`, `oraHHMM`; `scadenzaTesto`, `scadenzaDaSecondi`, `motivoRimborso`, `dataRelativa` |
| `utils.js` | `esc` (escape HTML completo), `tempoRelativo`, `dominioDa` |
| `stato-card.js` | stato unico della card gruppo: `normalizzaStato`, `displayCampagna`, `statoCard`, `isTerminato`, `dataFine` |
| `stato-ordine.js` | passi del percorso di un ordine (`percorsoOrdine`, `statoOrdine`) |
| `saldo-stima.js` | `accontoStima`, `saldoStima` – stima lato client (copia della formula del server) |
| `consegna-metodi.js` | `metodiConsegna`: ritiro in sede sempre disponibile e preselezionato, spedizione disattivabile |
| `banner-fine.js` | testi e HTML del banner sottile per gruppi terminati |
| `dashboard-testi.js` | frase di riepilogo e blocco "Da fare" |
| `notifiche-tipo.js` | etichetta/icona per tipo di notifica |
| `acquisti-redirect.js` | mappa dei vecchi percorsi `/partecipazioni`, `/ordini` verso `/acquisti` |
| `countdown.js` | countdown "g : h : m" aggiornato al minuto (il vecchio countdown a secondi non è usato) |
| `stars.js`, `constants.js` | stelle di valutazione; etichette di stato, `ACCONTO_PERCENT = 20` (solo presentazione) |

**Componenti UI (`J/components/`):** `header.js` (nav per ruolo, ricerca, menu mobile, badge notifiche via `GET /notifiche/conta`), `toast.js`, `aumenta-dialog.js` (modale "Aumenta la quantità"), `recensioni-popup.js`, `share.js` (WhatsApp/Telegram), `badge.js`, `card.js`, `table.js`, `dropdown.js` (esporta solo `Avatar`), `progress.js` (**mai importato**). `J/qr-modal.js` mostra il QR di ritiro.
Non esiste una libreria di modali condivisa: ogni modale è HTML ad hoc (`modal-overlay`/`modal-content`) in `dettaglio.js` (`#prenota-modal`), `qr-modal`, `aumenta-dialog`, `recensioni-popup` e nei file admin.

## 6. CSS
Tutto globale, nessun `@import`, nessun CSS per pagina.
| File | Contenuto |
|---|---|
| `variables.css` | `:root`: colori `--color-*`/`--gray-*` (vecchio), palette `--bp-*` (nuova UI), contenitore `--container-width: 1240px`, scala tipografica `--fs-*`/`--fw-*`, font Manrope |
| `base.css` | due `@font-face` Manrope locali (woff2 in `F/fonts/`), reset, classi utility "tipo Tailwind" (in gran parte inutilizzate) |
| `layout.css` | shell, header, navigazione, menu mobile |
| `components.css` | bottoni, card, badge, input, toast, modali, tabelle |
| `pages.css` (4203 righe) | le pagine, per prefisso: `.bp-*` (home e dettaglio), `.dsh-*` (dashboard), `.acq-*` (acquisti), `.pp-*` (proposte), `.pm-*` (modale di adesione), `.bp-banner-fine*`, … |
| `responsive.css` | breakpoint 1024/768/640/480 |

Tre sistemi di variabili coesistono (vecchio, `--bp-*`, `--fs-*`); molte regole usano il colore `#0F1B33` scritto a mano.

## 7. Mappa pagina → JS → CSS → endpoint
| Rotta hash | Guard | JS | CSS principale | Endpoint chiamati |
|---|---|---|---|---|
| `/` | pubblica | `pages/home.js` | `.bp-*` (pages 2755-3750) | `GET /campagne` |
| `/campagne/:id` | pubblica | `pages/dettaglio.js` + `banner-fine`, `consegna-metodi`, `saldo-stima`, `countdown`, `aumenta-dialog` | `.bp-*`, `.pm-*`, `.bp-banner-fine*` | `GET /campagne/{id}`, `GET /campagne/{id}/recensioni`, `GET /profilo`, `POST /pagamento/acconto-checkout`, `GET /pagamento/stato`, `DELETE /campagne/{id}/partecipazioni`, `GET /mie/acquisti?id=` |
| `/dashboard` | cliente | `pages/dashboard.js` + `dashboard-testi`, `notifiche-tipo`, `stato-ordine`, `qr-modal` | `.dsh-*` | `GET /mie/dashboard`, `/mie/partecipazioni`, `/notifiche`, `/proposte/bacheca`; `POST /pagamento/riprova` |
| `/acquisti` | cliente | `pages/acquisti.js` + `recensioni-popup`, `aumenta-dialog`, `qr-modal` | `.acq-*` | `GET /mie/acquisti`; `DELETE /campagne/{id}/partecipazioni`; `POST /pagamento/riprova`; `GET /pagamento/stato` |
| `/partecipazioni`, `/ordini` | – | redirect (`acquisti-redirect.js`) → `/acquisti` | – | – |
| `/proposte` | cliente | `pages/proposte.js` + `share` | `.pp-*` | `GET /proposte/bacheca`, `/proposte/simili`, `/categorie`; `POST /proposte`; `POST/DELETE /proposte/{id}/vota` |
| `/notifiche` | login | `pages/notifiche.js` | `.notification-*` | `GET /notifiche`; `PUT /notifiche/{id}/letta`; `POST /notifiche/marca-tutte-lette` |
| `/wallet` | login | `pages/wallet.js` | `.wallet-*` | `GET /wallet`, `/wallet/movimenti` |
| `/profilo` | login | `pages/profilo.js` | `components.css` | `GET/PUT /profilo`, `/fornitore/io`; `POST /profilo/password`, `/profilo/cancellazione`; `GET /profilo/export` |
| `/fornitori/:id` | login | `pages/fornitori-dettaglio.js` | inline + `.rec-*` | `GET /fornitori`, `/prodotti`, `/fornitori/{id}/recensioni`, `/campagne`; `POST/DELETE` recensioni |
| `/pagamento/successo`, `/annullato` | login | `pages/pagamento-successo.js` | `.empty-state` | `GET /pagamento/stato` |
| `/fornitore`, `/fornitore/:sezione` | fornitore | `pages/fornitore.js` | `.camp-f-*`, `.ord-f-*`, `.prop-*` | `GET /fornitore/io`, `/fornitore/proposte`, `/fornitore/campagne`, `/fornitore/ordini`, `/notifiche`; `POST /fornitore/proposte`, `/fornitore/ordini/{id}/avanza` |
| `/admin` | admin | `pages/admin/dashboard.js` | `.kpi-*` | `GET /utenti`, `/campagne`, `/wallet/statistiche` |
| `/admin/campagne` | admin | `pages/admin/campagne.js` | `components.css` | `GET/POST /campagne`, `GET/DELETE /campagne/{id}`, `POST …/modifica`, `…/chiusura`; `DELETE /admin/immagini/{id}` |
| `/admin/utenti` | admin | `pages/admin/utenti.js` | idem | `GET /utenti…`; `PUT …/ruolo`, `…/stato`; `DELETE /utenti/{id}`; `POST /admin/utenti/{id}/reset-link` |
| `/admin/fornitori` | admin | `pages/admin/fornitori.js` | idem | `GET/POST /admin/fornitori`; `PUT /admin/fornitori/{id}`; `POST …/invito`, `…/collega` |
| `/admin/proposte` | admin | `pages/admin/proposte.js` | idem | `GET /proposte`; `PUT /proposte/{id}/stato` |
| `/admin/ordini` | admin | `pages/admin/ordini.js` | idem | `GET /admin/ordini`, `/admin/consegne`; `POST …/ripartisci`, `…/invia-fornitore`, `/consegne/{id}/spedisci` |
| `/admin/ritiri` | admin | `pages/admin/ritiri.js` | idem | `GET /admin/ritiri`, `/sedi`; `POST /ritiro/{token}` |
| `/admin/notifiche` | admin | `pages/admin/notifiche.js` | idem | `GET /utenti`; `POST /notifiche` |
| (index.html) `/login`, `/register`, `/password/*`, `/fornitore/attiva`, `/privacy` | pubbliche | `login.js`, `register.js`, `password-dimenticata.js`, `fornitore-attiva.js`, `privacy.js` | `.login-*` | `POST /login`, `/auth/google`, `/auth/microsoft`, `/registrazione`, `/password/richiesta`, `/password/reset`; `GET /password/reset/{token}`, `/fornitori/invito/{token}`; `POST /fornitori/attiva` |

Trasversali: `GET /io` (`app.html:205`), `GET /notifiche/conta` (`header.js:280`), `GET /mie/assegnazioni/{id}/qr` (`qr-modal.js:9`), `POST /pagamento/aumenta-checkout` (`aumenta-dialog.js:78`).

## 8. Pagine morte e codice duplicato (rimando all'audit)
- **Mai importati** (verificato con grep): `pages/ordini.js`, `pages/partecipazioni.js`, `js/mock.js` (solo nominato in `tests/glossario_test.mjs`), `components/progress.js`. Le due pagine vecchie sono le **uniche chiamanti** di `POST /pagamento/checkout`, `POST /consegne/scelta` e `GET /consegne/costo`.
- Divergenze di formato: le pagine admin e `fornitore.js` usano `toFixed(2)` e `toLocaleDateString('it-IT')` invece di `euro()` e `dataGGMMAAAA()`.
- Escape HTML: oltre a `utils.esc` ci sono versioni parziali locali (`dettaglio.js:634`, `home.js:43`, `admin/*`), e molte interpolazioni admin/fornitore non sono escapate: rischio XSS da verificare (vedi 08 e 13).
- Dettagli (con certezza e rischio) in `12-audit-ridondanze.md`.

## Domande che potrebbero farmi
1. **Come funziona il routing?** Hash router in `app.html:57-117`: tabella rotte, `matchRoute` con parametri `:id`, guardie `auth`/`admin`, nessun re-render se l'hash non cambia.
2. **Perché non usate un framework?** Scelta di progetto (SPA vanilla, zero build, zero npm). Motivazione presunta: semplicità e nessuna dipendenza da installare per la consegna.
3. **Come chiama l'API il frontend?** Tramite `apiGet/apiPost…` (`api.js`), con cookie di sessione; risposte `{ok,dati}`.
4. **Dove sta la sessione?** Cookie PHP lato server; nel browser solo un duplicato in `localStorage` per mostrare nome e ruolo.
5. **Come evitate di duplicare la logica dei prezzi?** Il server è la fonte (`prezzi.php`); il browser ha una copia (`saldo-stima.js`) solo per le stime, con test `saldo_stima_test.mjs`.
6. **Come gestite mobile?** Breakpoint in `responsive.css` (≤ 640 px una colonna).
7. **Come si testa il frontend?** Con test Node sulle funzioni pure (`node tests/…mjs`), non sulle pagine.
8. **Cosa succede se la sessione scade?** Alla prima risposta 401 `api.js` pulisce lo stato e rimanda al login.
