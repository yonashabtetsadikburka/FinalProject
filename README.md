# BuyPool

**BuyPool** is a group-buying (crowd-buying) platform. Customers pool their orders
together so a product reaches a minimum quantity threshold; when the threshold is
met the *colletta* (group purchase) succeeds and an order is placed with the
supplier. Suppliers manage their catalogue, admins run the campaigns, and users
pay, pick up their goods (via QR code) and vote on which products to offer next.

Domain vocabulary (Italian, used throughout the code and API):

| Term | Meaning |
|---|---|
| **colletta** | a group-purchase campaign for one product |
| **prenotazione** | a user's reservation/participation in a colletta |
| **fornitore** | supplier |
| **proposta** | a product proposal users can vote on (wishlist) |
| **consegna** / **ritiro** | delivery / pickup |
| **sede** | pickup location |

---

## Architecture

```
buypool-frontend/            buypool-backend/                 MySQL
┌────────────────────┐       ┌──────────────────────────┐    ┌──────────────┐
│ Vanilla JS SPA     │  JWT  │ Laravel REST API         │    │  buypool DB  │
│ (hash routing)     │──────▶│ /api/*  (routes/api.php) │───▶│  19 tables   │
│ fetch() + Bearer   │◀──────│ Eloquent models          │    └──────────────┘
└────────────────────┘ JSON  │ JWT auth (tymon/jwt-auth)│
                             │ Stripe (checkout+webhook)│───▶ Stripe
                             └──────────────────────────┘
```

- **Frontend** — no framework. Hash-based router, ES modules, a small component
  library, and an `apiRequest()` HTTP client that attaches the JWT as a
  `Bearer` token. Session (`token` + `user`) is kept in `localStorage`.
- **Backend** — Laravel (PHP 8.3+). Stateless JSON API under `/api`, authenticated
  with JWT. 18 controllers, 18 Eloquent models, 19 migrations. Stripe is wired for
  checkout and webhooks. Roles: `utente`, `fornitore`, `admin`, enforced by
  `auth:api`, `role:admin` and `richiedi_fornitore` middleware.
- **Database** — MySQL. The schema is defined entirely by the Laravel migrations
  (there is no separate `schema.sql`).

### Response envelope

Every endpoint returns the same shape:

```json
{ "successo": true,  "dati": { } }
{ "successo": false, "errore": "messaggio" }
{ "successo": false, "errori": { "campo": ["messaggio di validazione"] } }
```

---

## Repository layout

```
FinalProject/
├── buypool-frontend/        # Vanilla JS single-page app
│   ├── index.html           #   login / register
│   ├── app.html             #   main app shell (after login)
│   ├── css/                 #   design tokens + component/page styles
│   └── js/
│       ├── api.js           #   HTTP client (Bearer token)
│       ├── auth.js          #   login / register / OAuth
│       ├── constants.js     #   API_URL + labels  ← set the API URL here
│       ├── state.js         #   session + global state
│       ├── components/      #   header, modal, card, table, toast, …
│       └── pages/           #   user, admin/ and fornitore/ pages
│
└── buypool-backend/         # Laravel API
    ├── app/Http/Controllers # 18 controllers
    ├── app/Models           # 18 Eloquent models
    ├── database/migrations  # 19 migrations = the DB schema
    ├── routes/api.php        # all API routes
    └── config/              # auth (jwt guard), jwt, services (Stripe)…
```

---

## Running locally (MAMP)

Tested on macOS + MAMP with **PHP 8.3** and **MySQL on port 8889**. The project
folder lives in `MAMP/htdocs/FinalProject`, so Apache serves it at
`http://localhost:8888/FinalProject/…`.

### 1. Database

Start MAMP (Apache + MySQL), then create the database:

```bash
/Applications/MAMP/Library/bin/mysql80/bin/mysql -u root -proot -h 127.0.0.1 -P 8889 \
  -e "CREATE DATABASE IF NOT EXISTS buypool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Backend

From `buypool-backend/` (using MAMP's PHP + Composer):

```bash
PHP=/Applications/MAMP/bin/php/php8.3.14/bin/php
$PHP /Applications/MAMP/bin/php/composer install
cp .env.example .env
$PHP artisan key:generate
$PHP artisan jwt:secret
$PHP artisan migrate
```

Then set the database + driver values in `.env` (this project ships no framework
migrations for DB-backed sessions/cache/queue, so use file/sync):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=8889
DB_DATABASE=buypool
DB_USERNAME=root
DB_PASSWORD=root

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

> **Apache note:** the API uses clean URLs (`/api/...`), which need Apache's
> `mod_rewrite`. If a request to `/api/...` returns 404, enable
> `LoadModule rewrite_module modules/mod_rewrite.so` in
> `/Applications/MAMP/conf/apache/httpd.conf` and restart Apache.

### 3. Frontend

The API base URL is in [`buypool-frontend/js/constants.js`](buypool-frontend/js/constants.js):

```js
export const API_URL = 'http://localhost:8888/FinalProject/buypool-backend/public/api';
```

Open the app at:

```
http://localhost:8888/FinalProject/buypool-frontend/index.html
```

### 4. Smoke test

```bash
curl http://localhost:8888/FinalProject/buypool-backend/public/api/collette
# → {"successo":true,"dati":[]}
```

To test admin features, promote a registered user:

```sql
UPDATE utenti SET ruolo='admin' WHERE email='you@example.com';
```

---

## API overview

Base path: `/api`. Public routes are open; the rest require `Authorization: Bearer <jwt>`.

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` |
| Profile | `GET/PUT /profilo`, `GET /session/io` |
| Catalogue | `GET /fornitori`, `/fornitori/{id}`, `/prodotti`, `/prodotti/{id}`, `/categorie`, `/sedi` |
| Collette | `GET /collette`, `/collette/{id}`, `POST /collette/{id}/partecipa` |
| Reservations | `GET /prenotazioni/mie`, `/prenotazioni/{id}`, `POST /prenotazioni/{id}/annulla` |
| Proposals | `GET /proposte`, `POST /proposte`, `POST /proposte/{id}/vota`, `DELETE /proposte/{id}/voto` |
| Notifications | `GET /notifiche`, `/notifiche/non-lette`, `PATCH /notifiche/{id}/letta` |
| Wallet / payments | `GET /wallet`, `/wallet/movimenti`, `POST /pagamento/checkout`, `GET /pagamento/stato` |
| Supplier area | `GET/POST /fornitore/prodotti`, `/fornitore/ordini`, `/fornitore/collette` (role: fornitore) |
| Admin | `POST /collette`, `GET /utenti`, `/ordini`, `POST /prenotazioni/ritiro`, supplier invites, … (role: admin) |
| Stripe | `POST /pagamento/webhook` (public, signature-verified) |

Full route list: [`buypool-backend/routes/api.php`](buypool-backend/routes/api.php).

---

## Authors

- Yonas Habtetsadik Burka
- Abdelhamid Limem
- Ali Taher
