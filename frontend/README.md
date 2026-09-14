# BuyPool — Frontend

Frontend vanilla HTML/CSS/JS per la piattaforma di group-buying **BuyPool**. SPA client-side con routing basato su hash, integrazione backend PHP via API REST.

## Features

- **Buyer flow**: browse campagne → partecipa con quantità → MOQ raggiunto → admin conferma → scelta consegna (ritiro/spedizione) → pagamento Stripe → QR code o spedizione → conferma ricezione
- **Admin flow**: gestione campagne, ordini, utenti, proposte, prodotti, notifiche, ritiri, fornitori
- **Supplier area**: proposta prodotti con foto e scaglioni, monitoraggio campagne, 3-step order fulfillment (ricevuto → in preparazione → evaso)
- **Proposte prodotti**: votazione pubblica, soglia like → auto-creazione campagna
- **Pagamenti Stripe**: receipt 2 righe (prodotti + spedizione), QR per ritiro, shipping flow
- **Password reset**: admin-initiated link flow
- **GDPR**: export dati, richiesta cancellazione, privacy accettata
- **Prodotto likes**: soglia like → auto-campagna, prodotto rimosso permanentemente dalla votazione

## Struttura

```
buypool/
├── .gitignore
├── README.md
├── api/                                # Backend PHP (non nel detalle)
│   ├── index.php                       # Router principale (82 endpoint)
│   ├── config.example.php              # Template config (copiare in config.php)
│   ├── schema.sql                      # Database schema (25+ tabelle)
│   ├── seed_data.sql                   # Dati seed (categorie)
│   ├── composer.json                   # Dipendenze PHP
│   ├── lib/                            # Librerie core
│   │   ├── auth.php                    # Autenticazione e sessioni
│   │   ├── db.php                      # Connessione database
│   │   ├── risposta.php                # Helper risposte JSON
│   │   ├── stato.php                   # Calcolo stato campagne
│   │   └── ripartizione.php            # Logica ripartizione ordini
│   ├── endpoints/                      # Endpoint API (16 file)
│   │   ├── auth.php                    # Login, registrazione, OAuth
│   │   ├── campagne.php                # CRUD campagne + dettaglio
│   │   ├── catalogo.php                # Catalogo prodotti e fornitori
│   │   ├── consegne.php                # Scelta consegna, spedizione, conferma
│   │   ├── fornitore.php               # Area fornitore (proposte, ordini)
│   │   ├── notifiche.php               # Centro notifiche
│   │   ├── pagamento.php               # Checkout Stripe, webhook
│   │   ├── pagamenti.php               # Statistiche pagamenti
│   │   ├── partecipazioni.php          # Adesione e ritiro campagne
│   │   ├── password.php                # Password reset
│   │   ├── prodotti.php                # CRUD prodotti admin
│   │   ├── profilo.php                 # Profilo utente, export, GDPR
│   │   ├── proposte.php                # Votazione e pubblicazione proposte
│   │   ├── ritiro.php                  # Conferma ritiro fisico
│   │   ├── utenti.php                  # Gestione utenti admin
│   │   └── assegnazioni.php            # Assegnazione ordini
│   └── uploads/proposte/               # Foto caricate (gitignored)
└── frontend/
    ├── index.html                      # Entry point — Login/Register
    ├── app.html                        # Applicazione principale (dopo login)
    ├── fornitore-attiva.html           # Attivazione account fornitore (standalone)
    ├── css/
    │   ├── variables.css               # Variabili CSS (colori, font, spacing)
    │   ├── base.css                    # Reset e stili base
    │   ├── layout.css                  # Layout header, main, sidebar
    │   ├── components.css              # Stili componenti UI riutilizzabili
    │   ├── pages.css                   # Stili specifici per pagina
    │   └── responsive.css              # Media query mobile/tablet
    └── js/
        ├── api.js                      # Client HTTP (apiGet, apiPost, apiPut, apiDelete, apiPostForm)
        ├── auth.js                     # Login, register, Google/Microsoft OAuth, sessione
        ├── constants.js                # URL API, stati campagna/prenotazione, label, badge
        ├── mock.js                     # Dati di test (fallback sviluppo)
        ├── state.js                    # Gestione stato globale e sessione
        ├── page-timers.js              # Timer管理
        ├── qr-modal.js                 # Modale QR code
        ├── components/
        │   ├── badge.js                # Badge di stato
        │   ├── card.js                 # Card contenitore
        │   ├── dropdown.js             # Menu a tendina
        │   ├── header.js               # Header con navigazione e sidebar
        │   ├── progress.js             # Barre di progresso
        │   ├── table.js                # Tabelle dati
        │   └── toast.js                # Notifiche toast
        ├── lib/
        │   └── qrcode.js               # Libreria generazione QR code
        └── pages/
            ├── home.js                 # Lista campagne attive (card con prezzo, sconto, progresso)
            ├── dettaglio.js            # Dettaglio campagna, partecipazione, pagamento, QR
            ├── dashboard.js            # Dashboard personale (KPI, ordini, proposte)
            ├── prodotti.js             # Catalogo prodotti votabili (like → campagna)
            ├── proposte.js             # Proposte prodotti con votazione pubblica
            ├── fornitori.js            # Lista fornitori
            ├── fornitori-dettaglio.js  # Dettaglio singolo fornitore
            ├── fornitore.js            # Area fornitore (4 tab: area, campagne, proposte, ordini)
            ├── fornitore-attiva.js     # Attivazione account fornitore da invito
            ├── ordini.js               # Storico ordini con stati e conferma ricezione
            ├── notifiche.js            # Centro notifiche
            ├── profilo.js              # Gestione profilo, password, GDPR
            ├── wallet.js               # Saldo e movimenti
            ├── partecipazioni.js       # Campagne a cui partecipi
            ├── pagamento-successo.js   # Pagina successo pagamento
            ├── password-dimenticata.js # Password reset (link via email)
            ├── privacy.js              # Informativa privacy
            └── admin/
                ├── dashboard.js        # Dashboard admin (KPI, campagne recenti)
                ├── campagne.js         # Gestione campagne (modifica, elimina)
                ├── prodotti.js         # CRUD prodotti votabili
                ├── proposte.js         # Gestione proposte (approva, pubblica, rifiuta)
                ├── utenti.js           # Gestione utenti (stato, ruolo, storico)
                ├── ordini.js           # Ordini al fornitore, gestione spedizione
                ├── ritiri.js           # Conferma ritiro fisico (QR scan)
                ├── fornitori.js        # CRUD fornitori + inviti
                └── notifiche.js        # Invio notifiche admin
```

## Prerequisiti

- **MAMP** (o equivalente Apache + PHP + MySQL)
  - Apache: porta `8888`
  - MySQL: porta `8889`, utente `root`, password `root`
  - PHP: 8.3+ con estensioni: `gd`, `mbstring`, `json`, `pdo_mysql`
- **Composer** (per le dipendenze PHP backend): https://getcomposer.org

## Installazione

```bash
# 1. Clone
git clone https://github.com/UTENTE/buypool.git
cd buypool

# 2. Installa dipendenze backend
cd api
composer install
cd ..

# 3. Configura database
#    Copiare il file di esempio e modificare i valori se necessario
cp api/config.example.php api/config.php

# 4. Importa schema nel database
#    (MAMP deve essere attivo con MySQL sulla porta 8889)
mysql -h 127.0.0.1 -P 8889 -u root -proot < api/schema.sql
mysql -h 127.0.0.1 -P 8889 -u root -proot buypool < api/seed_data.sql

# 5. Configura Apache
#    Impostare DocumentRoot su: /percorso/a/buypool/frontend
#    Oppure: C:\MAMP\buypool\frontend

# 6. Avvia MAMP (Apache + MySQL)

# 7. Apri il browser
http://localhost:8888/buypool/frontend/index.html
```

## Credenziali test

| Ruolo | Email | Password |
|-------|-------|----------|
| Admin | `admin@buypool.it` | `Admin123!` |
| Cliente | `test@test.it` | `Test123!` |
| Cliente ( voter) | `voter@test.it` | `Voter123!` |

## API Backend

Il frontend comunica con il backend PHP tramite API REST. URL base definito in `js/constants.js`:

```javascript
export const API_URL = 'http://localhost:8888/buypool/api';
```

### Endpoint principali

| Metodo | Endpoint | Funzione |
|--------|----------|----------|
| POST | `/login` | Login tradizionale |
| POST | `/registrazione` | Registrazione |
| POST | `/auth/google` | Login Google OAuth |
| POST | `/auth/microsoft` | Login Microsoft |
| GET | `/io` | Utente corrente |
| GET | `/campagne` | Elenco campagne |
| GET | `/campagne/{id}` | Dettaglio campagna |
| POST | `/campagne/{id}/partecipazioni` | Partecipa alla campagna |
| DELETE | `/campagne/{id}/partecipazioni` | Annulla partecipazione |
| GET | `/prodotti` | Catalogo prodotti votabili |
| POST | `/prodotti/{id}/like` | Like a prodotto |
| DELETE | `/prodotti/{id}/like` | Rimuovi like |
| GET | `/proposte` | Elenco proposte |
| POST | `/proposte` | Crea proposta |
| POST | `/proposte/{id}/vota` | Vota proposta |
| POST | `/consegne/scelta` | Scegli ritiro o spedizione |
| POST | `/consegne/{id}/spedisci` | Admin: segna spedito |
| POST | `/consegne/{id}/conferma-ricezione` | Client: conferma ricezione |
| POST | `/pagamento/checkout` | Crea sessione Stripe |
| POST | `/pagamento/webhook` | Stripe webhook |
| GET | `/notifiche` | Elenco notifiche |
| PUT | `/notifiche/{id}/letta` | Segna come letta |
| GET | `/profilo` | Leggi profilo |
| PUT | `/profilo` | Aggiorna profilo |
| POST | `/profilo/password` | Cambia password |
| GET | `/profilo/export` | Export dati GDPR |
| POST | `/profilo/cancellazione` | Richiesta cancellazione |
| GET | `/fornitore/proposte` | Proposte fornitore |
| GET | `/fornitore/campagne` | Campagne fornitore |
| GET | `/fornitore/ordini` | Ordini fornitore |
| POST | `/fornitore/ordini/{id}/avanza` | Avanza stato ordine |
| POST | `/admin/prodotti` | Crea prodotto |
| POST | `/admin/prodotti/{id}/modifica` | Modifica prodotto |
| DELETE | `/admin/prodotti/{id}` | Elimina prodotto |
| POST | `/admin/fornitori` | Crea fornitore |
| POST | `/admin/fornitori/{id}/invito` | Genera invito |
| PUT | `/proposte/{id}/stato` | Pubblica/rifiuta proposta |
| PUT | `/campagne/{id}/modifica` | Modifica campagna |
| DELETE | `/campagne/{id}` | Elimina campagna |
| GET | `/admin/ordini` | Ordini admin |
| GET | `/admin/consegne` | Consegne admin |
| GET | `/admin/ritiri` | Ritiri admin |
| POST | `/notifiche` | Invia notifica |
| GET | `/utenti` | Elenco utenti |
| PUT | `/utenti/{id}/stato` | Attiva/sospendi utente |
| POST | `/password/richiesta` | Richiedi reset password |

## Pagine

### Utente (19)

| Route | Pagina | Funzione |
|-------|--------|----------|
| `#/` | Home | Lista campagne attive con card, prezzo, sconto, countdown |
| `#/campagne/:id` | Dettaglio | Dettaglio campagna, partecipazione, pagamento, QR |
| `#/dashboard` | Dashboard | KPI personali, ordini recenti, proposte |
| `#/prodotti` | Prodotti | Catalogo prodotti votabili con like |
| `#/proposte` | Proposte | Votazione proposte prodotti |
| `#/fornitori` | Fornitori | Lista fornitori partner |
| `#/fornitori/:id` | Dettaglio Fornitore | Info fornitore e campagne |
| `#/ordini` | Ordini | Storico ordini con stato e conferma ricezione |
| `#/notifiche` | Notifiche | Centro notifiche |
| `#/profilo` | Profilo | Gestione profilo, password, GDPR |
| `#/wallet` | Wallet | Saldo e movimenti |
| `#/partecipazioni` | Partecipazioni | Campagne a cui partecipi |
| `#/pagamento/successo` | Pagamento | Pagina successo pagamento |
| `#/password/dimenticata` | Password | Richiesta reset password |
| `#/privacy` | Privacy | Informativa privacy |

### Fornitore (4 tab)

| Tab | Funzione |
|-----|----------|
| Area | Dashboard fornitore con info azienda |
| Campagne | Campagne attive sui propri prodotti con % adesione |
| Proposte | Form proposta prodotto + le mie proposte |
| Ordini | Ordini ricevuti con 3-step fulfillment |

### Admin (9)

| Route | Pagina | Funzione |
|-------|--------|----------|
| `#/admin` | Dashboard | KPI, metriche, campagne recenti |
| `#/admin/campagne` | Campagne | Modifica/elimina campagne |
| `#/admin/prodotti` | Prodotti | CRUD prodotti votabili |
| `#/admin/proposte` | Proposte | Approva, pubblica, rifiuta proposte |
| `#/admin/utenti` | Utenti | Gestione utenti, stato, ruolo, storico |
| `#/admin/ordini` | Ordini | Ordini al fornitore, gestione spedizione |
| `#/admin/ritiri` | Ritiri | Conferma ritiro fisico (QR scan) |
| `#/admin/fornitori` | Fornitori | CRUD fornitori + generazione inviti |
| `#/admin/notifiche` | Notifiche | Invio notifiche a utenti |

## Flussi principali

### Buyer
Home → Dettaglio campagna → Partecipa (quantità) → MOQ raggiunto → Admin conferma → Scegli consegna (ritiro gratis / spedizione €4.90) → Paga con Stripe → QR code (ritiro) o "In attesa di spedizione" → Conferma ricezione

### Admin
Conferma ordine → Pagamenti ricevuti → Invia al fornitore → Fornitore evaso → Segna spedito → Client conferma ricezione

### Fornitore
Proposta prodotto (foto, prezzi, scaglioni) → Admin approva → Votazione pubblica → Admin pubblica → Auto-creazione campagna → Monitoraggio ordini → 3-step: ricevuto → in preparazione → evaso

### Proposte
Fornitore/Cliente propone prodotto → Admin approva → Votazione pubblica → Soglia like raggiunta → Admin pubblica → Auto-creazione prodotto + campagna → Broadcast a tutti gli utenti

## Tecnologie

- **Frontend**: HTML5, CSS3, JavaScript ES6+ (moduli nativi)
- **Nessun framework** — vanilla JS con routing client-side basato su hash
- **Backend**: PHP 8.3, MySQL (MAMP)
- **Pagamenti**: Stripe (checkout session + webhook)
- **Auth**: Google Identity Services, Microsoft MSAL, sessioni PHP
- **QR Code**: libreria `qrcode.js` (generazione client-side)
- **Image handling**: `object-fit:contain` per campagne/dettaglio, `object-fit:cover` per catalogo prodotti
