# BuyPool — Backend Symfony 7

Backend **Symfony 7.4** qui remplace l'ancien `api/` PHP vanilla en gardant **100% la même logique métier** et le même schéma MySQL `buypool`.

## Architecture

```
backend/
├── config/               # Configuration Symfony (framework, routes, services)
├── public/
│   ├── index.php         # Front controller Symfony
│   └── uploads/proposte/ # Photos produits (même format que legacy api/uploads/proposte)
├── src/
│   ├── Controller/       # 15 controllers → 70+ routes (legacy index.php)
│   │   ├── AuthController.php
│   │   ├── CampagneController.php
│   │   ├── CatalogoController.php
│   │   ├── PartecipazioniController.php
│   │   ├── AssegnazioniController.php
│   │   ├── RitiroController.php
│   │   ├── PagamentiController.php
│   │   ├── PagamentoController.php (Stripe checkout + webhook + stato)
│   │   ├── NotificheController.php
│   │   ├── ProposteController.php
│   │   ├── UtentiController.php
│   │   ├── PasswordController.php
│   │   ├── FornitoriController.php (admin + area fornitore)
│   │   ├── RecensioniController.php
│   │   ├── ConsegneController.php
│   │   ├── ProfiloController.php
│   │   └── ProdottoImmaginiController.php
│   ├── Service/
│   │   ├── DatabaseService.php  # PDO singleton (remplace lib/db.php)
│   │   ├── AuthService.php      # Session + Bearer fallback (remplace lib/auth.php)
│   │   ├── StatoService.php     # ricalcolaStato() (remplace lib/stato.php)
│   │   ├── RipartizioneService.php # ripartisci() (remplace lib/ripartizione.php)
│   │   ├── FornitoreService.php # salvaFoto + normalizzaUrl
│   │   └── ApiResponse.php      # json_ok / json_errore + AppException
│   ├── Exception/AppException.php
│   └── EventListener/
│       ├── CorsListener.php      # CORS + OPTIONS preflight
│       └── ExceptionListener.php # {ok:false,errore:{codice,messaggio}}
└── .env                          # Config DB, Stripe, Google, FRONTEND_URL
```

**Logique conservée à l'identique :**
- Même requêtes SQL ( `FOR UPDATE`, `GROUP_CONCAT`, `ricalcola_stato` à chaque lecture )
- Même machine à états `in_corso → riuscita → ordine_pronto → ordine_fornitore → consegnata`
- Même répartition **largest-remainder** (`ripartisci`)
- Même JSON envelope `{ok:true,dati}` / `{ok:false,errore:{codice,messaggio}}`
- Même gestion Stripe (idempotence `eventi_stripe`, fallback `pagamento/stato`)
- Même upload `5MB, JPG/PNG/WEBP, uploads/proposte/`

## Prérequis

- PHP 8.3 (MAMP: `C:\MAMP\bin\php\php8.3.1\php.exe`)
- MySQL MAMP sur `127.0.0.1:8889`, DB `buypool`
- Composer 2.10

## Installation

```bash
cd FinalProject/backend
# Dépendances déjà installées (vendor présent). Si besoin:
C:\MAMP\bin\php\php8.3.1\php.exe C:\ProgramData\ComposerSetup\bin\composer.phar install

# Configurer
cp .env .env.local
# éditer .env.local: DATABASE_*, GOOGLE_CLIENT_ID, STRIPE_*, FRONTEND_URL, CORS_ALLOW_ORIGIN

# Créer uploads
mkdir -p public/uploads/proposte
# (optionnel) lier legacy pour compat Apache:
# mklink /D api\uploads backend\public\uploads  # Windows
```

### .env — variables clés

```ini
DATABASE_HOST=127.0.0.1
DATABASE_PORT=8889
DATABASE_NAME=buypool
DATABASE_USER=root
DATABASE_PASSWORD=root
GOOGLE_CLIENT_ID=22127010556-mss3ikiiugra9aqv3im8m1jenn3jj8cd.apps.googleusercontent.com
MICROSOFT_CLIENT_ID=
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
FRONTEND_URL=http://localhost:8888/buypool/frontend
CORS_ALLOW_ORIGIN=http://localhost:8888,http://localhost:8000
COSTO_SPEDIZIONE=4.90
```

## Lancer

**Option A — Serveur Symfony natif (recommandé dev) :**
```bash
C:\MAMP\bin\php\php8.3.1\php.exe -S localhost:8000 -t public
# ou avec Symfony CLI:
symfony server:start --port=8000
```
Backend dispo: `http://localhost:8000/api/salute` → `{"ok":true,"dati":{"stato":"ok"}}`

**Option B — Via MAMP Apache :**
- DocumentRoot → `.../FinalProject/backend/public`
- `.htaccess` déjà géré par Symfony (public/index.php)
- URL: `http://localhost:8888/backend/public/api/salute`

## Liaison Frontend ↔ Backend

| Fichier | Changement |
|---------|------------|
| `frontend/js/constants.js` | `API_URL = 'http://localhost:8000/api'` (était `http://localhost:8888/buypool/api`) |
| `frontend/js/api.js` | Ajout `credentials:'include'` sur tous les fetch (session cookie) + Bearer fallback |

**Switch rapide :**
```js
// frontend/js/constants.js
export const API_URL = 'http://localhost:8000/api'; // Symfony
// export const API_URL = 'http://localhost:8888/buypool/api'; // legacy PHP vanilla
```

**CORS :** `CorsListener` reflète l'`Origin` du frontend et autorise `Authorization`. Tester:
```bash
curl -i http://localhost:8000/api/salute
curl -i -H "Origin: http://localhost:8888" http://localhost:8000/api/salute
```

**Auth :** Le backend accepte deux modes (compat totale):
1. **Session cookie** (`PHPSESSID`, `httponly Lax`) — `credentials:'include'` côté frontend
2. **Bearer token** `Authorization: Bearer session_123` — lu par `AuthService::utenteCorrenteId()` pour compat avec `frontend/js/state.js` (qui stocke `session_{id}`)

## Routes — parité avec `api/index.php`

Toutes les 70+ routes sont dupliquées avec **et sans** préfixe `/api` pour compat:
```
GET  /api/salute  /salute
POST /api/login   /login
POST /api/registrazione
GET  /api/io
GET  /api/fornitori, /prodotti, /sedi
GET  /api/campagne, POST /api/campagne, GET /api/campagne/{id}, DELETE, POST /modifica
POST /api/campagne/{id}/partecipazioni  DELETE ...
GET  /api/mie/partecipazioni, /mie/statistiche
POST /api/consegne/scelta, /consegne/costo, /admin/consegne, /spedisci, /conferma-ricezione
POST /api/campagne/{id}/ripartisci, /invia-fornitore
GET  /api/campagne/{id}/assegnazioni
GET  /api/mie/assegnazioni/{id}/qr, /admin/ritiri, POST /ritiro/{token}
GET  /api/wallet, /wallet/movimenti, /wallet/{id}, /wallet/statistiche
POST /api/pagamento/checkout, GET /pagamento/stato, POST /pagamento/webhook
GET  /api/notifiche, /conta, PUT /letta, POST /marca-tutte-lette, POST /notifiche
GET  /api/proposte, POST, POST /{id}/vota, DELETE /vota, PUT /{id}/stato
GET  /api/utenti, /{id}, PUT /{id}/stato, /ruolo, DELETE, /storico
POST /api/password/richiesta, POST /admin/utenti/{id}/reset-link, GET /reset/{token}, POST /reset
GET  /api/admin/fornitori, POST, PUT /{id}, POST /{id}/invito, POST /{id}/collega, GET /invito/{token}, POST /attiva
GET  /api/fornitore/io, PUT, POST /fornitore/proposte, GET /fornitore/proposte, /campagne, /ordini, POST /ordini/{id}/avanza
GET  /api/fornitori/{id}/recensioni, POST, DELETE /recensioni/{id}, GET+POST /campagne/{id}/recensioni
GET  /api/profilo, PUT, POST /profilo/password, GET /export, POST /cancellazione
GET  /api/admin/prodotti/{id}/immagini, POST, DELETE /admin/immagini/{id}
```

Vérifier:
```bash
C:\MAMP\bin\php\php8.3.1\php.exe bin/console debug:router | grep campagne
```

## Base de données

**Aucune migration nécessaire.** Le backend Symfony utilise les mêmes 25+ tables (`utenti`, `collette`, `prodotti`, `fornitori`, `prenotazioni`, `qr_codes`, `pagamenti`, `eventi_stripe`, `notifiche`, `proposte_prodotti`, `voti_proposte`, `consegne`, `ordini_fornitore`, `inviti_fornitore`, `reset_password`, `recensioni_*`, `immagini_prodotto`, etc.)

Importer le schéma legacy si besoin:
```bash
mysql -h 127.0.0.1 -P 8889 -u root -proot buypool < ../api/schema.sql  # si fourni
```

## Tests rapides

```bash
# Santé
curl http://localhost:8000/api/salute
# Login (nécessite DB)
curl -X POST http://localhost:8000/api/login -H "Content-Type: application/json" -d '{"email":"admin@buypool.it","password":"Admin123!"}' -c cookies.txt
# Campagnes (auth)
curl http://localhost:8000/api/campagne -b cookies.txt -H "Authorization: Bearer session_1"
```

## Notes migration

- L'ancien `api/` reste fonctionnel en parallèle — il suffit de basculer `API_URL` dans `frontend/js/constants.js`
- `api/config.php` est lu en fallback si `.env` incomplet (migration douce)
- Les uploads sont dans `backend/public/uploads/proposte` (URL `uploads/proposte/...` identique côté frontend)
- Stripe webhook: configurer `STRIPE_WEBHOOK_SECRET` et exposer `POST /api/pagamento/webhook` (ngrok en dev)
