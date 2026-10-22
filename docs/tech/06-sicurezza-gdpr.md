# Sicurezza e GDPR — BuyPool

Analisi del modello di sicurezza attuale, delle difese implementate, dei punti deboli noti (con remediation per la produzione) e del trattamento dei dati personali.

## 1. Modello di autenticazione

- **Sessioni PHP native** (`session_start()` con cookie `httponly` + `SameSite=Lax`). Lo stato utente vive esclusivamente lato server in `$_SESSION` (`utente_id`, `ruolo`).
- **`session_regenerate_id(true)`** al login rigenerato (previene session fixation).
- **Password**: `password_hash` (bcrypt/argon2 nativo PHP) + `password_verify`; minimo 8 caratteri; errore unico per email inesistente o password sbagliata (no user enumeration tramite login).
- **Token lato client**: il `localStorage` (`buypool_token`) contiene un flag fittizio — **non** è un credenziale. La sicurezza reale è il cookie di sessione. Questo è un punto di forza (niente JWT rubabili via XSS con scadenza lunga) ma va documentato per non trarre in inganno.
- **OAuth**: Google (`Google\Client::verifyIdToken`); Microsoft — *placeholder, JWT decodificato senza verifica firma* (vedi §5).

### Ruoli e guardie (server-side)

| Guardia | Effetto |
|---|---|
| `richiedi_login()` | 401 se nessuna sessione |
| `richiedi_utente_attivo()` | 403 `UTENTE_SOSPESO` se account sospeso (sola lettura) |
| `richiedi_cliente()` | 403 `SOLO_CLIENTI` se ruolo ≠ cliente |
| `richiedi_fornitore()` | 403 se ruolo ≠ fornitore o anagrafica non collegata |
| `sono_admin()` | 403 `NON_ADMIN` se ruolo ≠ admin |

Tutte le autorizzazioni sono valutate **lato server** su ogni richiesta; il frontend (guard delle route, nav per ruolo) è solo UX.

## 2. Difese implementate

### 2.1 SQL Injection
Prepared statement PDO ovunque (`db()->prepare` + parametri posizionali); casting intero nei path param (`{id}` solo cifre, `{token}` alfanumerico dal router). Nessuna concatenazione dinamica di input utente nelle query (eccezione: costruzione `IN (?,?,…)` con placeholder generati dal codice, non dai dati).

### 2.2 CSRF
Token generato in sessione (`csrf_token()`, 32 byte casuali), confrontato con header `X-CSRF-Token` via `hash_equals` (`richiedi_csrf()`).
**Limite noto**: oggi applicato **solo** al voto/ritiro voto proposte. Le altre scritture mutative (partecipazioni, pagamenti, profilo…) si affidano al SameSite=Lax del cookie. *Remediation prod*: estendere `richiedi_csrf()` a tutte le rotte POST/PUT/DELETE.

### 2.3 XSS
Render client-side via `innerHTML` + template literals (rischio strutturale da template). Mitigazioni: i dati mostrati provengono da API che restituiscono testo (non HTML) e i campi upload immagini non contengono mai HTML eseguibile; la sessione è in cookie `httponly` (non leggibile via JS). *Remediation prod*: introdurre escaping sistematico nelle utility di template (`escapeHtml`) e una CSP.

### 2.4 Upload immagini (`lib/immagini.php`)
Pipeline difensiva completa:
1. verifica MIME/extensioni reali (`finfo` + `getimagesize`);
2. **riscrittura totale del file via GD** (pixel decodificati e ricodificati) — elimina metadati EXIF e payload nascosti;
3. limiti dimensione (≤5 MB) e risoluzione (300–6000 px);
4. nome file casuale (32 hex) — no path traversal;
5. conversione in WebP + thumbnail;
6. `.htaccess` generato nella cartella uploads: PHP off, `Require all denied` tranne i file immagine, `X-Content-Type-Options: nosniff`.

### 2.5 Header e superficie
- `display_errors = 0`: gli errori PHP finiscono solo in `error_log`, mai nella risposta (`ERRORE_INTERNO` generico).
- Endpoint `/_dev/mail-test` limitato a `127.0.0.1` (404 altrove).
- Chiusura campagne via cron: `?secret=` confrontato con `chiusura_secret` di config.
- Cookie sessione `SameSite=Lax` (attenua CSRF cross-site).

### 2.6 Pagamenti
- Webhook Stripe verificato con firma HMAC (`Stripe\Webhook::constructEvent`).
- Idempotenza su `eventi_stripe` (UNIQUE su `stripe_event_id`) — nessun doppio addebito/rimborso anche a ripetute consegne dello stesso evento.
- Rimborsi con `idempotency_key` Stripe.
- I calcoli di importo (acconto, saldo, commissione) sono lato server; il browser non invia mai importi da fidarsi.

## 3. Debolezze note e piano di remediation

| # | Debolezza | Rischio | Remediation (produzione) |
|---|---|---|---|
| 1 | **JWT Microsoft non verificato** (`auth_microsoft_login`) | Autenticazione forabile se l'endpoint fosse attivo | Verifica firma + audience + nonce con `firebase/php-jwt` (già in vendor) oppure disattivare il login Microsoft |
| 2 | **Guzzle `verify => false`** nel client Google | MITM sulla verifica ID token | Rimuovere l'override TLS in produzione |
| 3 | **Cookie di sessione senza `secure`** | Transito in chiaro se servito su HTTP | `session_set_cookie_params(['secure' => true])` dietro HTTPS |
| 4 | **CSRF non globale** | Scritture mutative solo protette da SameSite | Estendere `richiedi_csrf()` a tutte le POST/PUT/DELETE |
| 5 | **Nessun header di sicurezza** (CSP, X-Frame-Options, HSTS) | Clickjacking, XSS, downgrade | `.htaccess`/reverse proxy con CSP, `X-Frame-Options: DENY`, HSTS |
| 6 | **Nessun rate limiting** su login/reset | Brute force, spam reset | Fail2ban o middleware conteggio tentativi |
| 7 | **Niente CORS esplicito** (assunto same-origin) | Se frontend e API separate, serve policy esplicita | Configurare CORS whitelist dominio |
| 8 | **Config con segreti in un solo file PHP** | Difficile rotazione, rischio commit | Separare segreti (env/secret manager), ruotare chiavi |

Nessuna delle debolezze è critica per l'ambiente di demo/sviluppo; le voci 1–4 sono **bloccanti prima del go-live** con traffico reale.

## 4. GDPR — trattamento dati

### 4.1 Dati trattati
- **Identificativi**: nome, cognome, email, telefono, indirizzo/CAP/città (per spedizione), codice fiscale/partita IVA (opzionali, B2B).
- **OAuth**: `google_id` / `microsoft_id` (identificativi presso il provider).
- **Pagamentari**: nessun dato carta in chiaro memorizzato — solo riferimenti Stripe (`stripe_customer_id`, `stripe_payment_intent_id`). I dati di pagamento passano direttamente su Stripe (PCI SAQ-A).
- **Operativi**: adesioni, pagamenti, recensioni (nome autore pubblico), token QR.

### 4.2 Diritti dell'interessato implementati

| Diritto | Implementazione |
|---|---|
| **Accesso / portabilità** | `GET /profilo/export` — export JSON completo dei propri dati |
| **Cancellazione** | `POST /profilo/cancellazione` — richiesta con workflow admin (cancellazione/anonimizzazione) |
| **Rettifica** | `PUT /profilo` (dati anagrafici), cambio password |
| **Limitazione** | Stato account `sospeso` (sola consultazione) |
| **Informativa** | Pagina `#/privacy` + accettata in registrazione (`privacy_accettata_at`) |

### 4.3 Note operative
- **Minimizzazione**: le recensioni espongono solo nome + iniziale del cognome; il QR non espone dati anagrafici.
- **Retention**: le transazioni (`pagamenti`, `eventi_stripe`) hanno valore fiscale/contabile — prevedere conservazione 10 anni anche dopo cancellazione account (anonimizzazione preferita alla hard delete dove presente obbligo).
- **Data processor**: Stripe (pagamenti), Google/Microsoft (OAuth), provider SMTP (email) — necessario valutare DPA dei singoli provider prima del go-live.
- **Cookie**: la SPA usa solo `localStorage` + cookie di sessione tecnico (no cookie di tracciamento/benchmark) — da dichiarare nell'informativa.

## 5. Checklist sicurezza pre-go-live

- [ ] Completare o disattivare Microsoft OAuth (verifica JWT)
- [ ] Riattivare TLS verification su chiamate Google
- [ ] Cookie di sessione `secure` + HTTPS obbligatorio (redirect 301 da HTTP)
- [ ] CSRF su tutte le scritture mutative
- [ ] Header sicurezza: CSP, `X-Frame-Options`, `X-Content-Type-Options`, HSTS
- [ ] Rate limiting su `/login`, `/password/richiesta`, OAuth
- [ ] Ruotare chiavi Stripe (live) e Google Client ID per la produzione
- [ ] Verificare che `config.php` non sia servibile web (sotto `api/`, PHP viene eseguito: ok; verificare comunque permessi)
- [ ] Disattivare `/_dev/*` (già 404 fuori da localhost, ma rimuovere in prod)
- [ ] Backup DB automatico + test di restore

Documentazione collegata: [05-api-reference.md](05-api-reference.md) · [07-integrazioni-esterne.md](07-integrazioni-esterne.md) · [09-guida-deploy.md](../ops/09-guida-deploy.md)
