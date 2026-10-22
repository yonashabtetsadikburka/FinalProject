# 08 – Sicurezza e ruoli

## A cosa serve
Riassume **come BuyPool identifica gli utenti, decide cosa possono fare e si difende** da SQL injection, XSS, CSRF,
upload malevoli e abusi, indicando cosa è fatto bene e cosa manca. Ogni punto ha un riferimento al codice.
Non è un penetration test: è una lettura del codice (nessun attacco eseguito). I problemi più gravi sono
ripresi, con gravità e correzione consigliata, in `13-anomalie-e-rischi.md`. Nessun segreto è riportato: solo i nomi delle variabili.

---

## 1. Autenticazione
| Aspetto | Cosa fa il codice | Dove |
|---|---|---|
| Password | `password_hash` (registrazione `PASSWORD_DEFAULT`; reset/attivazione fornitore `PASSWORD_BCRYPT`) e `password_verify`; minimo 8 caratteri, nessun'altra regola | `auth.php:28,52`, `password.php`, `fornitori.php` |
| Login | stesso errore se l'email non esiste o la password è sbagliata (non rivela l'esistenza dell'account); `session_regenerate_id(true)` (anti session fixation) | `auth.php:52-58` |
| Sessione | cookie PHP `httponly`, `SameSite=Lax`, **senza** `secure` (da attivare con HTTPS) | `index.php:17` |
| Google | verifica del token con la libreria Google; **ma** il client HTTP è creato con `verify => false` (TLS non verificato) | `auth.php:68-71` |
| Microsoft | il JWT è decodificato **senza verificare la firma**, poi si fa login per `microsoft_id` **o email** | `auth.php:128-149` |
| Logout | `POST /logout` distrugge la sessione, ma il frontend non lo chiama (pulisce solo `localStorage`): la sessione server resta viva fino alla scadenza | `auth.php:184`, `frontend/js/auth.js:32` |
| Reset password | token di 64 hex (`random_bytes`), scadenza 1 giorno, monouso, link precedenti invalidati; la richiesta pubblica risponde sempre allo stesso modo (anti-enumerazione) | `password.php:45-141` |
| Rate limiting | **assente** (login, registrazione, reset, ricerca) | grep |
| Token in chiaro nel DB | `reset_password.token`, `inviti_fornitore.token`, `qr_codes.token` salvati in chiaro (DA VERIFICARE che non siano hash) | `02-database.md` |

## 2. Autorizzazione per ruolo
Ruoli (`utenti.ruolo`): `cliente`, `fornitore`, `admin`. Il ruolo è copiato in `$_SESSION` al login e **non viene riletto**: un cambio ruolo ha effetto al login successivo e un admin degradato resta admin fino al logout.
| Funzione | Controllo | File |
|---|---|---|
| `richiedi_login` | 401 se non loggato | `lib/auth.php:78` |
| `richiedi_utente_attivo` | 403 `UTENTE_SOSPESO` se `stato <> 'attivo'` (letto dal DB) – usato su scritture economiche | `:89` |
| `richiedi_cliente` | attivo + ruolo `cliente` | `:52` |
| `richiedi_fornitore` | ruolo `fornitore` + anagrafica collegata, ritorna `fornitori.id` | `:42` |
| `sono_admin()` | solo sessione; usato **inline** in ogni endpoint admin (nessun `richiedi_admin`): ospite → 403 e non 401 | `:18` |

**Dati filtrati per utente (verificati a campione):** `mie_acquisti`, `mie_dashboard` (`id_utente = ?`), `notifiche_*` (filtrano `id_utente`), `ritiro_qr` (solo il proprietario), `consegne_conferma_ricezione`, `pagamento_stato` (rifiuta sessioni di altri utenti, `pagamento.php:412-414`), `pagamento_aumenta_checkout` e `riprova` (`WHERE p.id = ? AND p.id_utente = ?`).

**Eccezioni / buchi di autorizzazione:**
- `GET /campagne/{id}/assegnazioni` (`assegnazioni.php:119`): **qualsiasi utente loggato** vede nome, quantità e **token QR** di tutti i partecipanti di un gruppo.
- `GET /campagne/{id}` (`campagne.php:223-247`): ogni utente loggato (non solo gli ospiti) riceve l'elenco dei partecipanti con nome, cognome, quantità, stato e modalità di consegna.
- `GET /proposte` restituisce `pp.*` e nome/cognome del proponente a ogni utente loggato (`proposte.php:12-23`).
- `partecipazioni_ritira` e `consegne_conferma_ricezione` usano `richiedi_login` e non `richiedi_utente_attivo`: un utente sospeso può ancora annullare un'adesione.
- La regola "a minimo raggiunto non si annulla" è applicata solo dall'interfaccia (`04-logica-gruppi.md` §6).

## 3. Validazione degli input
- Helper `campo`, `campo_int` (intero ≥ min), `corpo()` (JSON obbligatorio se presente) in `lib/risposta.php:47-67`.
- Parametri GET/ID: `{id}` solo cifre e `{token}` solo alfanumerico nel router (`index.php:177-182`); `tab`, `sort`, `ordina` con whitelist (`lib/acquisti.php`, `proposte.php:265-285`); `fornitore_id` con `FILTER_VALIDATE_INT`.
- Pagamenti: tutti gli importi si ricalcolano **lato server**; il client invia solo gruppo, quantità (1–99) e modalità (`pagamento.php:627-693`). Il prezzo non è mai accettato dal browser.
- Proposte: lunghezze, URL http(s), prezzo `^\d{1,7}(\.\d{1,2})?$`, categoria esistente (`lib/proposte_regole.php`); **nessuna sanitizzazione dei tag HTML** nel nome (vedi XSS).
- Il `CHECK` 1–5 sui voti delle recensioni è ignorato da MySQL 5.7: la validazione è nel PHP (`recensioni.php`, DA VERIFICARE).

## 4. SQL injection
**Buono:** PDO con `ATTR_EMULATE_PREPARES = false` e `ERRMODE_EXCEPTION` (`lib/db.php:19-23`); tutte le query con input utente usano `prepare` + parametri. Le `->query()` senza parametri sono su SQL statico; l'unica concatenazione trovata, in `proposte_elenco` (`proposte.php:8-23`), inserisce solo `(int)$io`. Le colonne di `UPDATE` dinamici (`campagne_modifica`, `fornitori_admin_aggiorna`) vengono da whitelist fisse nel codice.
**Da tenere d'occhio:** nessuna regola automatica impedisce a una futura query di concatenare input.

## 5. XSS (il punto debole del frontend)
- **Buono:** `utils.js:esc` esegue l'escape completo e viene usato in `acquisti.js`, `dashboard.js`, `proposte.js`, nelle email (`mail_template_evento` fa `htmlspecialchars`) e nei nuovi moduli (`banner-fine.js`).
- **Problema verificato:** molte pagine costruiscono HTML con template literal **senza escape**: `admin/utenti.js:171-172,192,217` (nome, cognome, email dell'utente, anche dentro un `onclick="…('${u.nome}…')"`), `admin/proposte.js:43,134` (`nome_prodotto`, `nome_azienda`), `pages/fornitore.js`, `pages/dettaglio.js` (usa un `escHtml` locale che non escapa `"`, `dettaglio.js:634`). Poiché la **registrazione accetta qualsiasi `nome`** (`auth.php:9`) e il nome di una proposta (3–80 caratteri) non è ripulito, un cliente può inserire HTML/JS che viene eseguito nella sessione dell'admin quando apre "Utenti" o "Proposte" (**stored XSS**, gravità alta). Il cookie è `httponly`, ma lo script può chiamare l'API come admin.
- Correzione consigliata: usare sempre `esc()` (o `textContent`), centralizzare un solo helper, rimuovere gli `onclick` inline con valori interpolati.

## 6. CSRF e CORS
- CSRF applicativo **solo** sul voto alle proposte (`richiedi_csrf`, `auth.php:62-76`).
- Per tutte le altre scritture si conta su `SameSite=Lax` del cookie; `corpo()` non controlla il `Content-Type`, quindi un form cross-site `text/plain` con JSON valido non è bloccato dall'applicazione (mitigato da SameSite per le richieste cross-site POST nei browser moderni, ma non per sottodomini/stesso sito).
- CORS: nessun header; il frontend è sullo stesso origin. Se il frontend venisse servito da un dominio diverso, serviranno header e `credentials`.

## 7. Upload di immagini
Due pipeline con livelli di sicurezza diversi:
| Pipeline | Usata da | Controlli |
|---|---|---|
| `lib/immagini.php` (proposte dei clienti) | `proposte_crea` | tipo dal contenuto (`getimagesize`+`finfo`), JPEG/PNG/WebP, ≤ 5 MB, 300–6000 px, **ricodifica con GD** (rimuove metadati), nome casuale, cartella `api/uploads/proposals/` con `.htaccess` che disattiva l'esecuzione di script e consente solo `^[a-f0-9]{32}(_t)?\.(webp|jpg)$` |
| `fornitore_salva_foto` (`fornitori.php:334-356`) | gruppi admin, immagini prodotto, proposte fornitore | solo `getimagesize`, JPG/PNG/WebP, ≤ 5 MB, **nessuna ricodifica**, nome casuale, cartella `api/uploads/proposte/` **senza `.htaccess`** (verificato: la cartella contiene solo immagini) e non viene creata dal codice |
Rischio: un file con intestazione immagine valida ma contenuto PHP (polyglot) in `uploads/proposte/` potrebbe essere eseguito se Apache serve `.php` da quella cartella; la mitigazione sta nel nome (estensione scelta dal tipo rilevato: `.jpg/.png/.webp`), quindi il rischio è basso ma la difesa è debole. Consigliato riusare `lib/immagini.php` ovunque.

## 8. Stripe e webhook
- Chiavi solo in `api/config.php` (non versionato, `.gitignore`); il publishable key è esposto da `/pagamento/config`.
- Webhook: firma verificata con `\Stripe\Webhook::constructEvent` (`pagamento.php:130`), 400 se manca/invalida; idempotenza con `eventi_stripe`. Nessuna dato carta transita dal server.
- La chiusura da cron usa `?secret=` in query string (`campagne.php:277`): il segreto finisce nei log di accesso; meglio un header. Confronto con `hash_equals` (corretto).

## 9. Gestione errori e segreti
- `display_errors=0`, errori nel log, risposta generica `ERRORE_INTERNO` (`index.php:14,217-219`); i messaggi Stripe invece vengono restituiti all'utente (`'Errore Stripe: ' . $e->getMessage()`, `pagamento.php:108,727`).
- `api/config.php` non versionato; `api/buypool.sql` (dump con dati personali) **non è in `.gitignore`**.
- `GET /_dev/mail-test` è sempre registrata (limitata a localhost e con `mail_enabled`).
- Il `Authorization: Bearer session_<id>` inviato dal browser non viene letto dal server (non è una vulnerabilità, ma è fuorviante).

## 10. Privacy / GDPR
- `privacy_accettata_at` salvata in registrazione (`auth.php:30`); esportazione dati `GET /profilo/export` (`profilo.php:99`); richiesta di cancellazione che crea solo una **notifica agli admin** (`profilo.php:172`), non cancella automaticamente.
- `email_inviate.corpo_html` conserva il testo delle email senza retention; `notifiche` e `eventi_stripe.payload_json` crescono senza pulizia.

## 11. Riepilogo sintetico
**Fatto bene:** prepared statements ovunque; prezzi ricalcolati sul server; firma e idempotenza dei webhook; anti-enumerazione nel reset password; `session_regenerate_id`; pipeline foto sicura per le proposte; lock `FOR UPDATE` e chiavi UNIQUE per l'idempotenza; filtri per utente sugli acquisti.
**Manca o è debole:** verifica firma JWT Microsoft e TLS Google; escape XSS coerente; rate limiting; CSRF generalizzato; logout server-side dal frontend; `.htaccess` su `uploads/proposte`; autorizzazioni su `assegnazioni`/dettaglio gruppo; cookie `secure`; ruolo riletto dal DB; retention dei dati.

## Domande che potrebbero farmi
1. **Come autenticate gli utenti?** Sessione PHP con cookie `httponly`/`SameSite=Lax`; login con password (bcrypt) o Google/Microsoft.
2. **Come proteggete le rotte admin?** Con `sono_admin()` in ogni funzione; il ruolo è in sessione.
3. **Siete protetti da SQL injection?** Sì: PDO con prepared statement reali (`EMULATE_PREPARES=false`).
4. **E da XSS?** Parzialmente: esiste `esc()` ma non è usato ovunque, soprattutto nelle pagine admin.
5. **Come proteggete il webhook Stripe?** Verifica della firma e registro eventi idempotente.
6. **Come gestite i caricamenti di immagini?** Due pipeline; la più sicura ricodifica con GD e blocca l'esecuzione degli script.
7. **Cosa fareste per migliorare la sicurezza?** Verificare la firma Microsoft, escape centralizzato, rate limiting, CSRF per tutte le scritture, cookie `secure`.
8. **Come rispettate il GDPR?** Esportazione dati, consenso privacy salvato, richiesta di cancellazione gestita dall'admin; mancano retention e anonimizzazione automatica.
