# BuyPool — Backend ⇄ Frontend Integration Plan

> Status of aligning the backend (`api/`) with Yonas's front end (`frontend/`, `Yonas` branch)
> and Abdu's database (`schema.sql` → `collette_acquisto_gruppo`, already on `main`).
> **Backend has been reshaped to Abdu's schema.** Remaining work + team asks are below.

---

## 0. 🙋 What I still need from the team

### From Abdu (database)
1. Confirm the DB has **no `wallet` table** on purpose (the front end's `wallet.js` calls `/wallet` + `/wallet/movimenti`). Add a table, or we drop the wallet feature.
2. **Pickup model**: front end uses `QR_CODES` (token per reservation); your DB models it as `consegne` (deliveries). We need to pick one.
3. **Prices**: `collette` has no `prezzo_base` / `prezzo_corrente` / `percentuale_commissione`; prices live only on `prodotti.prezzo_unitario`. Where should discount/commission come from?
4. **Stripe** — in scope for the demo (real keys + webhook), or leave the payment endpoints stubbed?
5. There's **no seed data** in `schema.sql`. To test admin features, one user must be promoted: `UPDATE utenti SET ruolo='admin' WHERE email='...';`

### From Yonas (front end)
1. **Wire the mock pages to the API** — most pages still read `js/mock.js`; switch them to `apiGet(...)`. Endpoint list in Section 3.
2. **Field names**: the backend now returns FE-style fields (`quantita_minima`, `quantita_attuale`, `data_limite`, `stato`, `nome_azienda`, user `id`, etc.). A campaign is `colletta`; the endpoint base is `/collette`.
3. `prezzo_corrente` / `percentuale_commissione` aren't in the DB yet — pages that show them (home, dettaglio) will need a fallback until Abdu decides (see Abdu #3).
4. Auth stays **session-cookie based** (same-origin). The fake Bearer token in `api.js` is harmless but dead — safe to drop.

---

## 1. Security invariants — preserved through the reshape ✅

Every rewritten endpoint keeps: PDO prepared statements (`EMULATE_PREPARES=false`); server-side sessions + `session_regenerate_id(true)` on login/register; `password_hash`/`password_verify` (the DB column is `password` but only ever holds a bcrypt hash — never plaintext); generic login error (no user enumeration); `richiedi_login()` on protected routes and `richiedi_admin()` on admin routes; errors to the log only; the `{ok,dati}` / `{ok,errore}` envelope.

## 2. What was built (backend now matches `collette_acquisto_gruppo`)

New `api/` structure (old boxes/cans files removed: `campagne/partecipazioni/assegnazioni/ritiro.php`, `lib/ripartizione.php`):

- `lib/stato.php` — colletta state machine: `in_corso → riuscita/fallita`, reverts to `in_corso` if reservations drop below threshold; admin-only states (`ordine_fornitore/consegnata/annullata`) left untouched; keeps `quantita_attuale` synced to the sum of active reservations, under a `FOR UPDATE` lock.
- `endpoints/auth.php` — register (now takes `cognome`, auto-login), login, logout, `GET/PUT /io` (profile), Google/Microsoft stubs (501).
- `endpoints/catalogo.php` — `/fornitori`, `/fornitori/{id}` (+products), `/prodotti`, `/sedi`.
- `endpoints/collette.php` — list, detail (with progress + participants + my reservation), create (admin), status update (admin).
- `endpoints/prenotazioni.php` — join a colletta, `/mie/prenotazioni`, cancel (with state recompute).
- `endpoints/proposte.php` — list (with vote counts), create, vote (favore/contrario, one per user).
- `endpoints/notifiche.php` — my notifications, mark-as-read.
- `endpoints/pagamenti.php` — Stripe checkout/status + wallet: **501 stubs** (see team asks).
- `endpoints/admin.php` — user list, KPI statistics.

Verified: all files pass `php -l`, and the whole thing was tested **end-to-end against Abdu's live schema** (MAMP MySQL): register → login (session) → browse fornitori/prodotti/sedi → list/detail collette → join a colletta (amount auto-computed) → `/mie/prenotazioni` → cancel → state machine keeps `quantita_attuale` in sync and flips `in_corso ↔ riuscita` at the threshold → create/vote a proposta → notifications → admin gate (403 as cliente, 200 as admin) → wallet/Stripe return clean 501s. All green.

## 2b. How this fits everyone's work

- **Doesn't touch anyone else's files.** The reshape changed only `api/**`, `INTEGRATION_PLAN.md`, and `config.example.php`. It does **not** modify Yonas's `frontend/` or Abdu's `schema.sql` — so merging it to `main` won't disturb their branches or work.
- **Fits Abdu's DB:** every query targets his `collette_acquisto_gruppo` tables/columns exactly (unified `utenti` + `fornitori_dettagli`/`amministratori_dettagli`, `collette`, `prenotazioni`, `proposte_prodotti`/`voti_proposte`, `notifiche`, `sedi`). His `password` column stores a bcrypt hash. His enum values and states are used as-is.
- **Fits Yonas's front end:** responses use the front-end's vocabulary and shapes — user objects expose `id`, campaigns expose `quantita_minima`/`quantita_attuale`/`data_limite`/`stato`, suppliers expose `nome_azienda`, etc., and everything uses the `{ok,dati}`/`{ok,errore}` envelope his `api.js` already parses. He wires each mock page to the matching endpoint in Section 3 at his own pace; nothing forces a big-bang change.
- **Note on ports:** this machine's MAMP runs MySQL on **3306**, not the usual 8889 — set the real port in `config.php` (see `config.example.php`).

## 3. Endpoint reference (for Yonas, to wire the pages)

| Method | Path | Serves |
|---|---|---|
| POST | `/registrazione`, `/login`, `/logout` | login/register |
| GET/PUT | `/io` | current user / update profile |
| GET | `/collette`, `/collette/{id}` | home, dettaglio |
| POST | `/collette/{id}/prenotazioni` | join a campaign |
| GET | `/mie/prenotazioni` | dashboard, ordini, partecipazioni |
| DELETE | `/prenotazioni/{id}` | cancel a reservation |
| GET | `/fornitori`, `/fornitori/{id}`, `/prodotti`, `/sedi` | suppliers, products, pickup points |
| GET/POST | `/proposte`, `POST /proposte/{id}/voto` | wishlist + voting |
| GET | `/notifiche`, `POST /notifiche/{id}/letta` | notifications + badge |
| POST/GET | `/collette` (POST), `PUT /collette/{id}` | admin campaign create / status |
| GET | `/admin/utenti`, `/admin/statistiche` | admin dashboard |
| — | `/pagamento/*`, `/wallet/*`, `/auth/google\|microsoft` | **501 stub** — pending team decisions |

## 4. Backend TODOs still open

- Stripe checkout + webhook (`eventi_stripe`), once Abdu confirms scope + keys.
- Wallet endpoints, once there's a table (or removal from the front end).
- Pickup/delivery endpoints on the `consegne` table, once the QR-vs-consegne question is settled.
- Supplier-order endpoints (`ordini_fornitore`) for the admin orders page.

## 5. How to run / test locally

1. In phpMyAdmin, import `schema.sql` (creates DB `collette_acquisto_gruppo`).
2. Copy `api/config.example.php` → `api/config.php` (DB name is already correct).
3. Front end: `http://localhost:8888/buypool/frontend/index.html`; API base: `http://localhost:8888/buypool/api`.
4. Quick check: `GET http://localhost:8888/buypool/api/salute` → `{"ok":true,...}`.
5. Register a user, then to test admin: `UPDATE utenti SET ruolo='admin' WHERE email='you@...';`
