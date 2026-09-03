# Memory.md - BuyPool Frontend

## Sessione Corrente (1 Settembre 2026)

### Cosa è Stato Fatto

#### 1. Rimozione 7 Funzionalità Non Supportate dal DB
Rimosse perché non presenti nello schema SQL (14 tabelle vs 20 originali):

| Funzionalità | Tabelle DB |
|-------------|------------|
| Scaglioni Prezzo | `scaglioni_prezzo` |
| Assegnazioni/Token QR | `assegnazioni` |
| Wallet Movimenti | `wallet_movimenti` |
| Recensioni Fornitore | `recensioni_fornitore` |
| Badge Utente | `badge_utente` |
| Resi/Rimborsi | `resi` |
| Audit Log | `audit_log` |

#### 2. File Eliminati (3)
- `js/pages/resi.js`
- `js/pages/admin/resi.js`
- `js/pages/admin/audit.js`

#### 3. File Modificati (15)
- `js/mock.js` - Rimossi dati SCAGLIONI, WALLET_MOVIMENTI, BADGES, RESI, AUDIT_LOG
- `js/constants.js` - Rimossi STATI_RESO, BADGE_*, icone correlate
- `js/pages/dettaglio.js` - Rimossa sezione scaglioni
- `js/pages/home.js` - Rimosso import SCAGLIONI
- `js/pages/wallet.js` - Rimossa sezione movimenti
- `js/pages/dashboard.js` - Rimossi badge, recensioni, resi
- `js/pages/fornitori.js` - Rimossi rating stelle
- `js/pages/fornitori-dettaglio.js` - Rimossi rating e recensioni
- `js/pages/notifiche.js` - Rimosse icone correlate
- `js/pages/admin/notifiche.js` - Rimossa option scaglione
- `js/components/header.js` - Rimossi nav items Resi/Audit + Ristrutturato layout
- `app.html` - Rimossi import e route
- `css/pages.css` - Rimossi stili .tier-*, .badge-card-*, .rating-stars, .movement-*
- `css/responsive.css` - Rimosso stile .tier-item

#### 4. Ristrutturazione Header
- **Desktop**: Logo sinistra, Nav centro, User menu destra
- **Mobile**: Hamburger sinistra, Logo centro, User menu destra
- Dropdown con Profilo/Esci si apre al click

---

### Stato Attuale Frontend

#### Pagine Utente (10)
| Route | Pagina | Funzione |
|-------|--------|----------|
| `#/` | home.js | Lista campagne |
| `#/campagne/:id` | dettaglio.js | Dettaglio campagna |
| `#/dashboard` | dashboard.js | Dashboard utente |
| `#/fornitori` | fornitori.js | Lista fornitori |
| `#/fornitori/:id` | fornitori-dettaglio.js | Dettaglio fornitore |
| `#/wallet` | wallet.js | Wallet (solo saldo) |
| `#/wishlist` | wishlist.js | Lista desideri |
| `#/notifiche` | notifiche.js | Notifiche |
| `#/profilo` | profilo.js | Profilo utente |
| `#/ritiro` | ritiro.js | Gestione ritiri |

#### Pagine Admin (5)
| Route | Pagina | Funzione |
|-------|--------|----------|
| `#/admin/campagne` | admin/campagne.js | Gestione campagne |
| `#/admin/utenti` | admin/utenti.js | Gestione utenti |
| `#/admin/ordini` | admin/ordini.js | Gestione ordini |
| `#/admin/ritiri` | admin/ritiri.js | Gestione ritiri |
| `#/admin/notifiche` | admin/notifiche.js | Gestione notifiche |

#### Componenti (14)
auth-guard.js, badge.js, button.js, card.js, dropdown.js, header.js, input.js, mobile-nav.js, modal.js, progress.js, sidebar.js, table.js, tabs.js, toast.js

#### CSS (6)
variables.css, base.css, layout.css, components.css, pages.css, responsive.css

---

### Tabelle DB Supportate (20)
`utenti`, `fornitori`, `sedi`, `prodotti`, `proposte_prodotti`, `voti_proposte`, `collette`, `scaglioni_prezzo`, `prenotazioni`, `pagamenti`, `eventi_stripe`, `assegnazioni`, `consegne`, `ordini_fornitore`, `notifiche`, `wallet_movimenti`, `recensioni_fornitore`, `badge_utente`, `resi`, `audit_log`

---

## Cosa Manca da Fare (Prossime Sessioni)

### Priorità Alta
- [x] Collegare frontend a backend PHP (sostituire mock data con API reali)
- [x] Implementare autenticazione reale (JWT/token)
- [x] Implementare Google OAuth Login
- [ ] Testare tutti gli endpoint API

### Priorità Media
- [ ] Completare pagina Wishlist (attualmente placeholder)
- [ ] Completare pagina Ritiro (attualmente placeholder)
- [ ] Aggiungere validazione form lato client
- [ ] Implementare error handling API

### Priorità Bassa
- [ ] Animazioni e transizioni
- [ ] PWA (Progressive Web App)
- [ ] Ottimizzazione performance
- [ ] Accessibilità (a11y)

---

## Archivio Sessioni Precedenti

### Sessione 1 - Creazione Frontend Iniziale
- Creata struttura directory frontend
- Implementati 6 file CSS (variables, base, layout, components, pages, responsive)
- Implementati 5 file JS core (api, auth, constants, mock, state)
- Implementati 13 componenti UI
- Implementate 20 pagine (10 utente + 10 admin)
- Creati 2 entry point HTML (index.html, app.html)
- Rimosso sidebar, implementato header con nav orizzontale
- Aggiunto menu hamburger mobile

### Sessione 2 - Pulizia Schema DB
- Confrontato schema SQL esistente (20 tabelle) con schema fornito (14 tabelle)
- Identificate 7 tabelle mancanti
- Rimosse 7 funzionalità non supportate
- Eliminati 3 file, modificati 15 file
- Creato future-features.md con documentazione

### Sessione 3 - Ristrutturazione Header
- Modificato layout header per desktop (logo sx, nav centro, menu dx)
- Modificato layout header per mobile (hamburger sx, logo centro, menu dx)
- Dropdown Profilo/Esci rimasto al click

### Sessione 4 - Autenticazione Google OAuth (1 Settembre 2026)

#### Cosa è Stato Fatto

##### Backend PHP (FinalProject-main/api/)
1. **Database** - Modificato `schema.sql`:
   - Aggiunta colonna `google_id VARCHAR(50) NULL UNIQUE` a tabella `utenti`
   - Resa colonna `password_hash` nullable (utenti Google non hanno password)

2. **Composer** - Creato `api/composer.json` con dipendenza `google/apiclient ^2.15`
   - Eseguito `composer install` (16 pacchetti installati)
   - Nota: OpenSSL non funzionava in CLI MAMP → risolto con `disable-tls: true` in composer.json

3. **Config** - Creato `api/config.php` da `config.example.php`
   - Aggiunto campo `google_client_id`

4. **Endpoint** - Aggiunta funzione `auth_google_login()` in `api/endpoints/auth.php`
   - Usa `Google\Client::verifyIdToken()` per verificare il token JWT di Google
   - `$client->setHttpClient(new \GuzzleHttp\Client(['verify' => false]))` per bypassare problema SSL di MAMP
   - Cerca utente esistente per `google_id` o `email`
   - Se non esiste, crea automaticamente l'account (senza password)
   - Crea sessione PHP come il login normale

5. **Router** - Aggiunta rotta `POST /auth/google` in `api/index.php`
   - Aggiunto `require __DIR__ . '/vendor/autoload.php'`

#### Frontend
1. **auth.js** - Riscritto completamente:
   - `login()` ora chiama il backend reale `POST /login` (non più mock)
   - `register()` ora chiama `POST /registrazione`
   - Nuova funzione `loginWithGoogle(googleToken)` → `POST /auth/google`

2. **login.js** - Bottone Google collegato:
   - `renderGoogleButton()` usa `google.accounts.id.initialize()` + `renderButton()` programmaticamente
   - Retry automatico se lo script Google non è ancora caricato
   - Rimosso il markup HTML dei data-attributes (non affidabile con innerHTML)

3. **constants.js** - `API_URL` aggiornato per MAMP: `http://localhost:8888/buypool/api`

4. **index.html** - Aggiunto `<script src="https://accounts.google.com/gsi/client" async defer>`

#### Problemi Risolti
- **400 malformed**: Client ID duplicato (`.apps.googleusercontent.com` ripetuto due volte)
- **400 invalid_request**: File aperto con `file://` → risolto servendo il frontend via MAMP Apache
- **500 Errore interno**:
  - `config.php` mancante nella cartella MAMP (`C:\Users\39329\Desktop\MAMP\buypool\api\`)
  - MAMP serve da directory diversa rispetto a quella del progetto
  - Mancava `use Google\Client;` + SSL verify da disabilitare
- **Pulsante Google non visibile**: Race condition con `async defer` → risolto con rendering programmatico

#### Struttura File Importante
- Il progetto ha **2 copie**: `MyFinal/FinalProject-main/` e `MAMP/buypool/`
- MAMP serve da `C:\Users\39329\Desktop\MAMP\buypool\`
- I file modificati vanno sincronizzati entrambe le cartelle

### Configurazione MAMP
- Apache: porta **8888** (frontend + API)
- MySQL: porta **8889**, utente `root`, password `root`
- Host DB: `127.0.0.1` (non `localhost`)
- PHP: 8.3.1 (MAMP)
- URL frontend: `http://localhost:8888/buypool/frontend/index.html`
- URL API: `http://localhost:8888/buypool/api/`

### Dipendenze Backend
- `google/apiclient ^2.15` (via Composer)
- `firebase/php-jwt`, `guzzlehttp/guzzle` (dipendenze indirette)
