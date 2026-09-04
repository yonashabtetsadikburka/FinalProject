# BuyPool — Backend ⇄ Frontend Integration Plan

> Working notes for aligning the backend (`api/`) with Yonas's front end (`frontend/`, on the `Yonas` branch).
> Decision taken: **reshape the backend to the front-end vocabulary, without weakening the current security model.**
> **Blocked on:** Abdu's database. His schema is the source of truth for the new field names — the front end was built from it.

---

## 0. 🙋 What I need from the team to move forward (please read)

I've analysed the front end against the backend. The two currently use **different data models**, so before I can wire them together I need the items below. These are blocking my backend work — the sooner I get them, the sooner the app becomes workable end-to-end.

### From Abdu (database) — this is the main blocker
Please **export your BuyPool database schema and commit it to the repo** (a plain `.sql` file):
- In phpMyAdmin: select the `buypool` DB → **Export → SQL** → include table structure (and sample data if you have it) → save as a `.sql` file and push it. Tell me which branch.
- **Do NOT commit** `config.php`, passwords, or the raw MySQL data files — just the `.sql`.
- Along with it, please confirm:
  1. The `ruolo` enum values (is it `cliente` / `fornitore` / `admin`?).
  2. Which of these tables exist: **wallet, pagamenti (Stripe), notifiche, ordini_fornitore, scaglioni_prezzo, proposte/voti**.
  3. Is **Stripe** actually in scope now (real checkout + keys), or should I stub it for the demo?
  4. Are there `google_id` / `microsoft_id` columns — i.e. is Google/Microsoft login in scope now?

### From Yonas (front end) — needed once Abdu's schema is locked
1. We need to agree on **one shared data dictionary** (field names + campaign states). Right now the front end uses `quantita_minima` / `data_limite` / states like `in_corso`, `riuscita`; I'll align the backend to whatever we lock in.
2. `register` sends a `cognome` field — fine once the DB has that column.
3. Role labels in `constants.js` expect `cliente` / `fornitore` — align these with the DB enum.
4. Auth: the front end sends a fake Bearer token but actually relies on the session cookie (works same-origin only). We should decide: keep sessions, or move to real tokens.
5. Most pages still read `js/mock.js` — they'll need to switch to `apiGet(...)` as I ship each endpoint.

Full technical detail for each of these is in the sections below.

---

## 1. Direction (decided)

- The backend adopts the front end's **data dictionary** (field names, states, entities).
- Abdu's DB is the canonical schema. We do **not** invent new tables now — we wait for his and adjust.
- The existing `schema.sql` (boxes `scatole` / cans `latte` model) is treated as legacy; expect it to be replaced/merged with Abdu's.

## 2. Security invariants — DO NOT compromise (carry these into every rewritten endpoint)

These are the parts of the current backend that must survive the reshape unchanged:

1. **PDO prepared statements everywhere**, `ATTR_EMULATE_PREPARES => false` (`lib/db.php`). No string-built SQL, ever.
2. **Server-side sessions** with `session_regenerate_id(true)` on login, `httponly` + `samesite=Lax` cookies (`index.php`, `auth.php`).
3. **`password_hash` / `password_verify`**, and the same generic error for bad email vs bad password (no user enumeration).
4. **`richiedi_login()` on every protected endpoint**; `sono_admin()` gate on every admin endpoint.
5. **Errors to the log, never to the client** (`display_errors=0`; single `{ok:false,errore:{codice,messaggio}}` envelope via `risposta.php`).
6. **Input validation** via `campo()` / `campo_int()` / `corpo()` — keep validating on the reshaped fields too.
7. Response envelope stays `{ ok, dati }` / `{ ok, errore }` — this already matches `frontend/js/api.js`, don't change it.

## 3. What I need from Abdu before the real work

- The **`.sql` schema dump** (tables + columns + enums + FKs), and sample data if any.
- Confirmation of the **`ruolo` enum** values (`cliente` / `fornitore` / `admin`?) — front end assumes these.
- Whether the DB includes: **wallet, pagamenti (Stripe), notifiche, ordini_fornitore, scaglioni_prezzo, proposte/voti** — the front end assumes all of them.
- Whether **Stripe** is actually in scope (keys, checkout) or a stub for the demo.
- OAuth: are `google_id` / `microsoft_id` columns present? Is Google/Microsoft login in scope now?

## 4. Endpoint build queue (execute once Abdu's DB is in)

Ordered by "front end already calls it" → "front end will call it after Yonas wires the mock pages."

### A. Front end calls these TODAY (highest priority)
| FE call | Action | Needs from Abdu |
|---|---|---|
| `POST /registrazione` | accept `cognome` (FE sends it) | `cognome` column |
| `POST /login`, `GET /io` | keep; return FE-shaped user (`ruolo` values, `cognome`) | role enum |
| `POST /auth/google`, `POST /auth/microsoft` | verify provider token, upsert user, open session | `google_id`/`microsoft_id`, scope decision |
| `GET /wallet`, `GET /wallet/movimenti` | balance + movements | wallet table |
| `POST /pagamento/checkout`, `GET /pagamento/stato` | Stripe checkout session + status | pagamenti table, Stripe keys |

### B. Buildable as soon as DB lands (FE pages exist, Yonas must wire them)
| FE page | Endpoint to build |
|---|---|
| `home.js`, `dettaglio.js` | `GET /campagne`, `GET /campagne/{id}` reshaped to FE fields |
| `dashboard.js`, `partecipazioni.js` | `GET /mie/partecipazioni` (or `/mie/prenotazioni`) |
| `ordini.js` | `GET /mie/ordini` + QR per reservation |
| `fornitori.js`, `fornitori-dettaglio.js` | `GET /fornitori`, `GET /fornitori/{id}` (+ products) |
| `profilo.js` | `PATCH /io` (profile update) |
| `proposte.js` | `GET/POST /proposte`, `POST /proposte/{id}/voto` |
| `notifiche.js` + header badge | `GET /notifiche`, `POST /notifiche/{id}/letta` |
| `admin/*` | `GET /admin/statistiche`, `GET /admin/utenti`, campaign edit/delete/confirm, `GET/POST /ordini-fornitore`, `POST /notifiche` |

## 5. Backend TODOs still owed regardless of the reshape (your role B work)

These exist in your code today and must be finished, adapted to whatever the final "campaign/reservation" model is:
- `lib/stato.php` → `ricalcola_stato()` (campaign state machine)
- `endpoints/assegnazioni.php` → `assegnazioni_ripartisci()` (or its equivalent under the new model)
- `endpoints/ritiro.php` → `ritiro_conferma()` (pickup confirmation — the double-pickup guard is the key check)
- swap the provisional `lib/ripartizione.php` for Role A's real algorithm (if the boxes/cans model survives in Abdu's schema)

## 6. Hand to Yonas (front-end changes only he can make)

1. Reconcile field names/states to the final dictionary (once Abdu's DB is locked).
2. `register` sends `cognome` — fine once the column exists.
3. Role labels: FE `constants.js` expects `cliente`/`fornitore` — align with the DB enum.
4. Auth: FE fakes a Bearer token but relies on the session cookie (works same-origin only). Decide: keep sessions (drop the fake token) or move backend to real JWT.
5. 15 of ~16 pages still read `js/mock.js` — they need to be switched to `apiGet(...)` once the endpoints exist.

## 7. Sequencing

1. **Now:** this plan + preserve security invariants. No throwaway code.
2. **When Abdu sends the DB:** import it, confirm names, then execute the build queue (Section 4) in your existing secure style.
3. **Then:** finish the Section 5 TODOs and make it hostable.
4. **Iterate** with Yonas as pages get wired.
