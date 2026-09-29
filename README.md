# BuyPool

**BuyPool** is a group-buying platform. Customers reserve a product together; when the
group reaches the minimum quantity the *colletta* (group purchase) succeeds, everyone pays,
one combined order goes to the supplier, and each customer collects their share with a QR code.

| Term | Meaning |
|---|---|
| **colletta / campagna** | a group-purchase campaign for one product |
| **prenotazione / partecipazione** | one customer's reservation in a campaign |
| **fornitore** | supplier (has a company record, and optionally a login) |
| **scaglione** | price tier: "once N pieces are reserved, the price drops to X" |
| **proposta** | a product idea customers can vote on |
| **sede** | pickup point |

---

## How this version was put together

The repository had three separate implementations. This branch (`ali/mixed-version`) combines
the best of each, and **does not touch `main`** (where the Laravel version still lives).

| Part | Taken from | Why |
|---|---|---|
| Frontend (`frontend/`) | Yonas's branch (newest) | Most complete: 28 pages, supplier area, reviews, delivery choice, password reset |
| API (`api/`) | Yonas's plain-PHP API | Real server-side sessions, careful role checks, correct payment maths |
| Database design (`database/schema.sql`) | Laravel migrations on `main` | Clean keys and constraints, then extended for what the API really uses |
| Login rate limiting | Laravel version (request throttling) | The plain-PHP API had none |
| Repo hygiene (`.gitignore`, this README) | `main` cleanup | Secrets and `vendor/` never committed |

The Symfony branch was a line-for-line port of Yonas's API (with an older copy of his frontend), so
nothing was taken from it: see *Security fixes* below for why that matters.

### Fixed while combining (all covered by tests)

- **Microsoft login accepted forged tokens.** It read the token without checking the signature, so
  anyone could log in as anyone (including the admin). It now verifies signature, audience, issuer and
  expiry against Microsoft's published keys, and is disabled until `microsoft_client_id` is set.
- **Google login** no longer switches off TLS verification, and requires a verified email.
  Social logins can never take over an admin or supplier account, only plain customers.
- **Stripe webhook accepted forged payments when no secret was configured** (an empty key is
  computable by anyone). It now refuses to run unconfigured.
- **Payment bookkeeping bug:** the webhook read the wrong row id after creating the QR code, so a
  real payment would have rolled back and never marked the customer as paid.
- **No brute-force protection on login:** now 5 failures per email / 20 per IP per 15 minutes.
- **5 endpoints the frontend called did not exist** (admin create/edit/delete product, like/unlike).
- **Six fields the pages read were never sent by the API** (product description on the campaign page,
  supplier info, reviewer initial, pickup-point details, trust score, like/campaign flags).
- Config, scripts and tests are no longer downloadable over the web; the upload folder cannot run code.
- **Stored XSS: any customer could run script in an admin's browser.** No page escaped user text, so a
  proposal named `<img src=x onerror=...>` executed when the admin opened the proposals page (and the same
  through user names, supplier and product names, reviews and notifications). Every API response is now
  escaped once in `api.js`, no text goes inside an inline handler any more (`data-*` attributes instead),
  stored session data is sanitised, and two Node checks guard the rules. Verified in a real browser on 17
  admin pages and 10 customer pages with hostile text in every field type.
- **Any customer could read every participant's QR pickup token** (`GET /campagne/{id}/assegnazioni`),
  and so collect someone else's goods. Admin only now; the campaign page also stopped listing other
  people's names and delivery choices to customers.
- **A demoted or deleted admin kept their powers until they logged out**, because the role was cached
  in the session. Role and status are now read from the database on every request, so changes apply at once.
- **Price tiers never did anything.** They were stored but never applied, never sent to the page, and
  the tiers typed into the admin forms were silently dropped. Now the price follows the tiers as people
  join or withdraw (ported from the Laravel version and completed), it freezes once the admin confirms the
  order, participants are notified when a tier unlocks, and the admin forms create and edit tiers.
- **The session cookie was the shared default `PHPSESSID` at path `/`**, so every PHP app on the same
  host (e.g. all your MAMP projects) shared one session: logging out of one logged you out of the others.
  It now has its own name (`BUYPOOL_SESSION`) and is valid only under this installation's `api/` folder.
- **The agency's commission was recorded ~10% too high** (calculated on a total that already included it).
- Smaller: the wallet's "this month" figure was always the all-time total (misspelt variable); setting a
  user's role/status to the value it already had answered "not found"; password-reset requests could flood
  every admin with notifications; change-password crashed for Google/Microsoft-only accounts;
  a stray "." in the tier boxes; the admin dashboard mixed people with pieces.

---

## Architecture

```
frontend/  (vanilla JS, hash router)   ──  fetch + session cookie  ──▶  api/  (PHP 8, no framework)  ──▶  MySQL
  index.html   login / register                                          index.php   router (90 routes)
  app.html     the application                                           lib/        db, auth, state machine, pricing, oauth
  js/pages/    28 pages: customer,                                       endpoints/  one file per area
               admin/, supplier                                          uploads/    product photos
                                                                         Stripe (checkout + webhook), Google / Microsoft sign-in
```

- **Sessions, not tokens.** Login sets a PHP session cookie (`BUYPOOL_SESSION`, `HttpOnly`, `SameSite=Lax`, scoped to the API folder). The
  frontend and API are served from the same origin, so no CORS is needed.
- **One response shape:** `{"ok": true, "dati": ...}` or `{"ok": false, "errore": {"codice", "messaggio"}}`.
- **Three roles:** `cliente`, `fornitore`, `admin`. Admin routes answer 401 if you are not logged in
  and 403 if you are logged in but not an admin.
- **Suppliers are records first.** A supplier (`fornitori.id`) exists before anyone logs in; an admin
  invites them and they activate an account. Products, orders and proposals point at `fornitori.id`.

```
frontend/   pages, components, CSS         api/          index.php, lib/, endpoints/, uploads/
database/   schema.sql, seed.php           tests/        test_api.php, test_ciclo_campagna.php, test_oauth.php
```

### Campaign lifecycle

```
in_corso ─(goal reached)─▶ riuscita ─(admin: ripartisci)─▶ ordine_pronto ─(everyone paid)─▶ ordine_fornitore ─(supplier ships)─▶ consegnata
    └─(deadline passes without the goal)─▶ fallita
```

Reservation: `prenotata` → `confermata` (admin confirmed, awaiting payment) → `pagata` (Stripe webhook).
The QR code is created on payment; an admin scans it at the pickup point.

**Pricing.** A campaign has a list price (`prezzo_base`, shown struck through), a starting group price
(`prezzo_iniziale`) and tiers (pieces reserved → price per piece). The live price (`prezzo_corrente`) is the
price of the highest tier reached, or the starting price if none. It is recalculated on every join,
withdrawal and edit while the campaign is open, and **frozen once the admin confirms the order** so nobody
sees the amount change while paying. The customer pays pieces + commission (+ delivery if chosen);
the agency's commission is the percentage of the pieces only.

---

## Run it (macOS + MAMP)

Needs MAMP with PHP 8.1+ (tested on 8.3) and MySQL. Put the project in `MAMP/htdocs/`.

```bash
cd api
cp config.example.php config.php        # then edit: database name, frontend_url (folder name!)
composer install                        # MAMP's composer: php /Applications/MAMP/bin/php/composer install
cd ..

# create the database and load demo data
/Applications/MAMP/Library/bin/mysql80/bin/mysql -u root -proot -h 127.0.0.1 -P 8889 < database/schema.sql
php database/seed.php
```

Enable `mod_rewrite` in MAMP's `httpd.conf` (`LoadModule rewrite_module ...`) and restart Apache.
Then open `http://localhost:8888/<folder>/frontend/index.html`. The frontend finds the API by itself.
Check the API at `http://localhost:8888/<folder>/api/salute`.

MAMP notes: MySQL is on port **8889** with password **root**; use `127.0.0.1`, not `localhost`.

### Demo accounts (development only, password `Demo1234!`)

| Role | Email |
|---|---|
| admin | `admin@buypool.test` |
| customers | `mario.rossi@`, `giulia.bianchi@`, `luca.verdi@`, `sara.neri@` `buypool.test` |
| suppliers | `tech@buypool.test` (TechWorld), `casa@buypool.test` (CasaBella) |

The seed has six campaigns in different states, two product proposals with votes, and supplier reviews.
**Change or remove these accounts before any real deployment.**

### Configuration (`api/config.php`)

| Key | Purpose |
|---|---|
| `host`, `port`, `db`, `user`, `pass` | MySQL connection |
| `frontend_url` | Public URL of `frontend/` (used in Stripe return links and invite links) |
| `stripe_secret_key`, `stripe_webhook_secret` | Stripe test keys. Empty = checkout answers "not configured", webhook refuses |
| `google_client_id` | Google sign-in (the ID is public) |
| `microsoft_client_id` | Microsoft sign-in. Empty = disabled |
| `ca_bundle` | Path to a CA file if PHP can't verify TLS certificates (never disable verification) |
| `costo_spedizione` | Flat home-delivery fee in euro |

---

## Tests

They talk to the real running app over HTTP and write to the database, so **use a development database**.
`test_ciclo_campagna.php` needs `'stripe_webhook_secret' => 'whsec_local_test_secret'` and a freshly
seeded database (reset with `schema.sql` + `seed.php` between runs).

```bash
# pure unit tests: no server, no database
php tests/test_oauth.php          # 10 checks: forged / expired / wrong-audience login tokens
php tests/test_prezzi.php         # 32 checks: tier pricing rules, incl. 200,000 random cases vs a reference
php tests/test_ripartizione.php   # 12 checks: piece allocation, incl. 500,000 random cases
node tests/test_escape.mjs                 # 30 checks: the anti-XSS escaping (needs Node)
node tests/test_frontend_sicurezza.mjs     # 9 checks: scans every page for the dangerous patterns

# end to end: real HTTP, real sessions
php tests/test_api.php http://localhost:8888/<folder>/api              # 125 checks: security, customer journey, admin, tiers, privileges, contract with the frontend
php tests/test_ciclo_campagna.php http://localhost:8888/<folder>/api   # 46 checks: goal reached → paid (signed webhooks, exact money) → supplier → QR pickup
```

That is **264 checks**. Each fix above was also confirmed the other way round: temporarily reverting it
makes the matching test fail. On top of that, every SQL statement in the code is prepared against the
schema (no missing tables or columns).

---

## API overview (90 routes + the Stripe webhook)

| Area | Examples |
|---|---|
| Auth | `POST /registrazione`, `/login`, `/logout`, `/auth/google`, `/auth/microsoft`, `GET /io` |
| Campaigns | `GET /campagne`, `/campagne/{id}`, `POST /campagne`, `POST /campagne/{id}/partecipazioni`, `DELETE` to withdraw |
| Allocation & orders | `POST /campagne/{id}/ripartisci`, `/invia-fornitore`, `GET /admin/ordini` |
| Payment | `POST /pagamento/checkout`, `GET /pagamento/stato`, `POST /pagamento/webhook` |
| Pickup & delivery | `GET /mie/assegnazioni/{id}/qr`, `POST /ritiro/{token}`, `/consegne/scelta` |
| Catalogue | `GET /prodotti`, `/fornitori`, `/sedi`, `POST /admin/prodotti`, `POST /prodotti/{id}/like` |
| Community | `GET/POST /proposte`, `POST /proposte/{id}/vota`, reviews under `/fornitori/{id}/recensioni` |
| Supplier area | `/fornitore/io`, `/fornitore/campagne`, `/fornitore/ordini`, `POST /fornitore/ordini/{id}/avanza` |
| Admin | `/utenti`, `/admin/fornitori` (+ invites), `/admin/ritiri`, `/notifiche` (broadcast) |
| Profile | `/profilo`, `/profilo/password`, `/profilo/export`, `/password/richiesta` |

The full table is at the top of [`api/index.php`](api/index.php).

---

## Known limitations / next steps

- **Payments are only tested with signed simulated webhooks.** Add Stripe test keys and run one real
  test-mode checkout end to end. The webhook records the amount from our database, not Stripe's
  `amount_total`; comparing them would be a good hardening step.
- **Social logins are unit-tested only** (locally generated keys). Try one real Google and one real
  Microsoft sign-in once client IDs exist.
- **No email is sent.** Password reset and supplier invites produce links an admin passes on by hand.
- **Guest browsing is half-built** (see `frontend/README.md`): the pages support it but the API requires login.
  Decide: open `GET /campagne` and `GET /campagne/{id}` (with rate limiting) or remove the guest code.
- Ask Yonas for his local `api/schema.sql` and `seed_data.sql` (his README mentions them; they were never
  committed) and compare them with `database/schema.sql`.
- After the admin confirms an order, a customer with an *unpaid* confirmed reservation can still withdraw,
  which shrinks the order. Whether to allow that is a business decision.
- No product photos in the demo data (the pages show placeholders).
- The frontend still sends a leftover `Authorization: Bearer session_<id>` header. The API ignores it
  (**do not ever make the API trust it**: that was exactly the flaw in the Symfony version).

## Authors

- Yonas Habtetsadik Burka
- Abdelhamid Limem
- Ali Taher
