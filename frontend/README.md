# BuyPool — Frontend

Frontend vanilla HTML/CSS/JS per la piattaforma di crowdbuying **BuyPool**. Applicazione client-side con routing basato su hash e integrazione backend PHP via API REST.

## Struttura

```
frontend/
├── index.html              # Entry point — Login/Register
├── app.html                # Applicazione principale (dopo login)
├── css/
│   ├── variables.css       # Variabili CSS (colori, font, spacing)
│   ├── base.css            # Reset e stili base
│   ├── layout.css          # Layout header, main, sidebar
│   ├── components.css      # Stili componenti UI riutilizzabili
│   ├── pages.css           # Stili specifici per pagina
│   └── responsive.css      # Media query mobile/tablet
├── js/
│   ├── api.js              # Client HTTP per chiamate API
│   ├── auth.js             # Login, register, Google OAuth, sessione
│   ├── constants.js        # Costanti (URL API, stati, label)
│   ├── mock.js             # Dati di test (fallback senza backend)
│   ├── state.js            # Gestione stato globale e sessione
│   ├── components/         # Componenti UI riutilizzabili
│   │   ├── header.js       # Header con navigazione
│   │   ├── toast.js        # Notifiche toast
│   │   ├── modal.js        # Modali
│   │   ├── card.js         # Card
│   │   ├── table.js        # Tabelle
│   │   ├── badge.js        # Badge di stato
│   │   ├── input.js        # Campi form
│   │   ├── button.js       # Pulsanti
│   │   ├── tabs.js         # Tab navigation
│   │   ├── progress.js     # Barre di progresso
│   │   ├── dropdown.js     # Menu a tendina
│   │   ├── sidebar.js      # Sidebar
│   │   ├── mobile-nav.js   # Navigazione mobile
│   │   └── auth-guard.js   # Guardia autenticazione
│   └── pages/              # Pagine applicazione
│       ├── login.js
│       ├── register.js
│       ├── home.js
│       ├── dettaglio.js
│       ├── dashboard.js
│       ├── fornitori.js
│       ├── fornitori-dettaglio.js
│       ├── wallet.js
│       ├── notifiche.js
│       ├── profilo.js
│       ├── ordini.js
│       ├── partecipazioni.js
│       ├── pagamento-successo.js
│       └── admin/
│           ├── dashboard.js
│           ├── campagne.js
│           ├── utenti.js
│           ├── ordini.js
│           ├── ritiri.js
│           └── notifiche.js
├── img/                    # Immagini e icon
├── Memory.md               # Note di sviluppo
└── future-features.md      # Funzionalità rimosse (documentazione)
```

## Prerequisiti

- **MAMP** (o equivalente Apache + PHP + MySQL)
  - Apache: porta `8888`
  - MySQL: porta `8889`, utente `root`, password `root`
  - PHP: 8.3+
- Backend API BuyPool configurato in `MAMP/buypool/api/`

## Installazione

1. Copiare la cartella `frontend/` in `MAMP/buypool/frontend/`

2. Avviare MAMP (Apache + MySQL)

3. Aprire il browser:
   ```
   http://localhost:8888/buypool/frontend/index.html
   ```

## Configurazione API

L'URL delle API è definito in `js/constants.js`:

```javascript
export const API_URL = 'http://localhost:8888/buypool/api';
```

Modificare se il backend è servito su porta o percorso diverso.

## Autenticazione

- **Login tradizionale**: email + password → JWT token
- **Google OAuth**: pulsante "Continua con Google" → token Google verificato dal backend
- La sessione è salvata in `localStorage` (`buypool_user`, `buypool_token`)
- Auto-logout alla scadenza della sessione

## Pagine

### Utente (10)

| Route | Pagina | Funzione |
|-------|--------|----------|
| `#/` | Home | Lista campagne attive |
| `#/campagne/:id` | Dettaglio | Dettaglio campagna e adesione |
| `#/dashboard` | Dashboard | Statistiche personali |
| `#/fornitori` | Fornitori | Lista fornitori |
| `#/fornitori/:id` | Dettaglio Fornitore | Info fornitore |
| `#/wallet` | Wallet | Saldo e movimenti |
| `#/proposte` | Proposte | Wishlist prodotti |
| `#/notifiche` | Notifiche | Centro notifiche |
| `#/profilo` | Profilo | Gestione profilo |
| `#/ordini` | Ordini | Storico ordini |
| `#/partecipazioni` | Partecipazioni | Campagne a cui partecipi |

### Admin (5)

| Route | Pagina | Funzione |
|-------|--------|----------|
| `#/admin` | Dashboard Admin | KPI e metriche |
| `#/admin/campagne` | Gestione Campagne | CRUD campagne |
| `#/admin/utenti` | Gestione Utenti | Lista utenti |
| `#/admin/ordini` | Gestione Ordini | Ordini al fornitore |
| `#/admin/ritiri` | Gestione Ritiri | Conferma ritiro |
| `#/admin/notifiche` | Gestione Notifiche | Invio notifiche |

## Tecnologie

- HTML5, CSS3, JavaScript ES6+ (moduli)
- Nessun framework — vanilla JS con routing client-side
- Integrazione Google Identity Services
- Integrazione MSAL (Microsoft Auth Library)
