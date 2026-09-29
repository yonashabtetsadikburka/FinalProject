# BuyPool — Frontend

Vanilla HTML / CSS / JavaScript single-page app (no framework, no build step): ES modules, a hash
router, and a small set of components. It talks to the PHP API in `../api`.

Open `index.html` (login / register / password reset) and, once logged in, `app.html` (the application).

## How it finds the API

`js/constants.js` derives the API address from where the page is served
(`<project>/frontend/…` → `<project>/api`), so it works with any folder name and on any machine.
To point at a different server define `window.BUYPOOL_API_URL` before the modules load.

## Structure

```
frontend/
├── index.html · app.html · fornitore-attiva.html    entry pages (the router lives in app.html)
├── css/                                            variables, base, layout, components, pages, responsive
└── js/
    ├── api.js          the ONLY place that calls the API; escapes every response (see "Security rules")
    ├── escape.js       esc() / unesc() / escapeDeep() / sanificaDeep()
    ├── state.js        session (user + token) kept in localStorage
    ├── auth.js         login, register, Google / Microsoft sign-in, role helpers
    ├── constants.js    API_URL, labels and badge colours for the states
    ├── countdown.js · page-timers.js · stato-ordine.js · qr-modal.js
    ├── components/     header, modal, card, table, badge, progress, toast, dropdown, button
    ├── lib/qrcode.js   third-party QR generator (MIT), draws the pickup QR in the browser
    └── pages/          one module per page; `admin/` for the admin pages
```

## Pages and routes

Access is decided by the guard in `app.html` (only to choose what to *show*: the real permission
check is always done again by the API).

| Route | Page | Who | API calls (main) |
|---|---|---|---|
| `#/` | `home.js` | any logged-in user ¹ | `GET /campagne` |
| `#/campagne/:id` | `dettaglio.js` | any logged-in user ¹ | `DELETE /campagne/{id}/partecipazioni`, `GET /campagne/{id}`, `GET /fornitori/{id}/recensioni`, `POST /campagne/{id}/partecipazioni` |
| `#/dashboard` | `dashboard.js` | any logged-in user | `GET /campagne`, `GET /mie/partecipazioni`, `GET /mie/statistiche`, `GET /notifiche` … |
| `#/fornitori` | `fornitori.js` | any logged-in user | `GET /fornitori` |
| `#/fornitori/:id` | `fornitori-dettaglio.js` | any logged-in user | `DELETE /fornitori/recensioni/{id}`, `GET /fornitori`, `GET /fornitori/{id}/recensioni`, `GET /prodotti` … |
| `#/prodotti` | `prodotti.js` | any logged-in user | `DELETE /prodotti/{id}/like`, `GET /prodotti`, `POST /prodotti/{id}/like` |
| `#/wallet` | `wallet.js` | any logged-in user | `GET /wallet`, `GET /wallet/movimenti` |
| `#/proposte` | `proposte.js` | any logged-in user | `DELETE /proposte/{id}/vota`, `GET /proposte`, `POST /proposte`, `POST /proposte/{id}/vota` |
| `#/notifiche` | `notifiche.js` | any logged-in user | `GET /notifiche`, `POST /notifiche/marca-tutte-lette`, `PUT /notifiche/{id}/letta` |
| `#/profilo` | `profilo.js` | any logged-in user | `GET /fornitore/io`, `GET /profilo`, `GET /profilo/export`, `POST /profilo/cancellazione` … |
| `#/privacy` | `privacy.js` | any logged-in user | — |
| `#/ordini` | `ordini.js` | any logged-in user | `GET /consegne/costo`, `GET /fornitori/{id}/recensioni`, `GET /mie/partecipazioni`, `POST /consegne/scelta` … |
| `#/partecipazioni` | `partecipazioni.js` | any logged-in user | `GET /mie/partecipazioni` |
| `#/admin` | `admin/dashboard.js` | admin only | `GET /campagne`, `GET /utenti`, `GET /wallet/statistiche` |
| `#/admin/campagne` | `admin/campagne.js` | admin only | `DELETE /admin/immagini/{id}`, `DELETE /campagne/{id}`, `GET /admin/fornitori`, `GET /campagne` … |
| `#/admin/utenti` | `admin/utenti.js` | admin only | `DELETE /utenti/{id}`, `GET /utenti`, `GET /utenti/{id}`, `GET /utenti/{id}/storico` … |
| `#/admin/fornitori` | `admin/fornitori.js` | admin only | `GET /admin/fornitori`, `GET /utenti`, `POST /admin/fornitori`, `POST /admin/fornitori/{id}/collega` … |
| `#/admin/prodotti` | `admin/prodotti.js` | admin only | `DELETE /admin/prodotti/{id}`, `GET /admin/fornitori`, `GET /prodotti`, `POST /admin/prodotti` … |
| `#/admin/proposte` | `admin/proposte.js` | admin only | `GET /admin/fornitori`, `GET /proposte`, `PUT /proposte/{id}/stato` |
| `#/admin/ordini` | `admin/ordini.js` | admin only | `GET /admin/consegne`, `GET /admin/ordini`, `POST /campagne/{id}/invia-fornitore`, `POST /campagne/{id}/ripartisci` … |
| `#/admin/ritiri` | `admin/ritiri.js` | admin only | `GET /admin/ritiri`, `GET /sedi`, `POST /ritiro/{id}` |
| `#/admin/notifiche` | `admin/notifiche.js` | admin only | `GET /utenti`, `POST /notifiche` |
| `#/pagamento/successo` | `pagamento-successo.js` | any logged-in user | `GET /pagamento/stato` |
| `#/pagamento/annullato` | `pagamento-successo.js` | any logged-in user | `GET /pagamento/stato` |
| `#/fornitore` | `fornitore.js` | suppliers (own area) | `GET /fornitore/campagne`, `GET /fornitore/io`, `GET /fornitore/ordini`, `GET /fornitore/proposte` … |
| `#/fornitore/:sezione` | `fornitore.js` | suppliers (own area) | `GET /fornitore/campagne`, `GET /fornitore/io`, `GET /fornitore/ordini`, `GET /fornitore/proposte` … |

¹ **Guest browsing is half-built.** The router lets guests open these two routes (`public: true`), and the
pages have a guest header, a guest banner and a "pending join" flow. But the API answers `401` to
`GET /campagne` and `GET /campagne/{id}`, so a logged-out visitor ends up on the login page. To offer
guest browsing, make those two read-only endpoints public (and rate-limit them: each list request
recomputes campaign states); otherwise remove the guest code.

Pages outside the router: `index.html` shows `login.js`, `register.js` and `password-dimenticata.js`;
`fornitore-attiva.html` shows `fornitore-attiva.js` (supplier account activation from an invite link).

After login the role picks the landing page: admin → `#/admin`, supplier → `#/fornitore`, customer → `#/`.

## Session

`state.js` keeps `{ user, token }` in `localStorage` (`buypool_user`, `buypool_token`). The API
identifies you by its **session cookie**, not by this token: the `Authorization: Bearer session_<id>`
header `api.js` still sends is ignored by the API and must never be trusted. A `401` clears the
stored session and returns to the login page (except on the login/register calls themselves).

## Security rules (read before adding a page)

The pages build HTML with template strings and write it with `innerHTML`, so user-written text must
never reach the page as HTML. It is enforced in two layers, both checked by tests:

1. **`api.js` escapes every string of every response** (`escapeDeep`). Data that reaches a page is
   already safe to put in a template, in text or in a double-quoted attribute.
2. **Never put text inside an inline handler.** `onclick="f('${nome}')"` is *not* safe even when
   escaped, because the browser decodes the entities before running the JavaScript. Inside handlers
   only ids and constants: `onclick="f({id}, this.dataset.nome)"` with `data-nome="${nome}"`.
   `this.dataset.nome` is the original text: escape it again with `esc()` if you put it back in HTML.
3. Server error messages printed into HTML go through `esc()`. `href="${url}"` needs a `http(s)://` check.
4. Everything read from `localStorage` is passed through `sanificaDeep()` (it is not trusted).

Checks (no browser needed): `node tests/test_escape.mjs` and `node tests/test_frontend_sicurezza.mjs`.
For a check in a real browser: `php tests/xss_manuale.php <api-url>`.
