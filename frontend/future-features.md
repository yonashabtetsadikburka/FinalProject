# Future Features - Funzionalità Rimosse

Questo file documenta le funzionalità rimosse dal frontend perché non presenti nello schema SQL del database (14 tabelle vs 20 tabelle esistenti).

## Tabella Riepilogativa

| Funzionalità | Tabelle DB coinvolte | Stato |
|-------------|---------------------|-------|
| Scaglioni Prezzo | `scaglioni_prezzo` | ❌ Rimossa |
| Assegnazioni/Token QR | `assegnazioni` | ❌ Rimossa |
| Wallet Movimenti | `wallet_movimenti` | ❌ Rimossa |
| Recensioni Fornitore | `recensioni_fornitore` | ❌ Rimossa |
| Badge Utente | `badge_utente` | ❌ Rimossa |
| Resi/Rimborsi | `resi` | ❌ Rimossa |
| Audit Log | `audit_log` | ❌ Rimossa |

---

## File da ELIMINARE (3 file)

| File | Funzione |
|------|----------|
| `js/pages/resi.js` | Pagina resi utente |
| `js/pages/admin/resi.js` | Pagina resi admin |
| `js/pages/admin/audit.js` | Pagina audit log admin |

---

## File da MODIFICARE (15 file)

### 1. `js/mock.js`
**Rimuovere:**
- `export const SCAGLIONI = [...]` (righe 164-172)
- `export const WALLET_MOVIMENTI = [...]` (righe 315-334)
- `export const BADGES = [...]` (righe 336-349)
- `export const RESI = [...]` (righe 411-423)
- `export const AUDIT_LOG = [...]` (righe 425-448)
- `export function getMovimentiByWallet()` (righe 503-504)
- `export function getBadgesByUtente()` (righe 507-508)
- `export function getResiByUtente()` (righe 515-519)

**Modificare:**
- Funzione `getCollettaById()`: rimuovere filtro scaglioni e proprietà `scaglioni`
- Oggetti FORNITORI: rimuovere `rating_mediano` e `num_recensioni`
- Notifiche: rimuovere tipo `NUOVO_SCAGLIONE` dal messaggio

### 2. `js/constants.js`
**Rimuovere:**
- `export const STATI_RESO = {...}` (righe 51-57)
- `export const BADGE_TYPES = {...}` (righe 59-64)
- `export const BADGE_LABELS = {...}` (righe 66-71)
- `export const BADGE_COLORS = {...}` (righe 73-78)
- `NUOVO_SCAGLIONE` da NOTIFICA_ICONS (riga 81)
- `RESO_APPROVATO` da NOTIFICA_ICONS (riga 90)
- `RESO_RIFIUTATO` da NOTIFICA_ICONS (riga 91)

### 3. `js/pages/dettaglio.js`
**Rimuovere:**
- Variabile `tiersHtml` (riga 30)
- Logica `reached` e `isCurrent` (righe 31-32)
- HTML tier-item (riga 34)
- Intestazione "Scaglioni Prezzo" (riga 75)
- Container `.tier-list` (riga 76)

### 4. `js/pages/home.js`
**Rimuovere:**
- `SCAGLIONI` dall'import (riga 1)

### 5. `js/pages/wallet.js`
**Rimuovere:**
- Import `getMovimentiByWallet` (riga 2)
- Variabile `movimenti` (riga 11)
- Variabile `movementsHtml` (righe 13-31)
- Sezione "Movimenti" HTML (righe 48-49)

**Mantenere:**
- Card saldo wallet

### 6. `js/pages/dashboard.js`
**Rimuovere:**
- Import `getBadgesByUtente` (riga 2)
- Import `BADGE_LABELS, BADGE_COLORS` (riga 4)
- Variabile `badges` (riga 12)
- Variabile `badgesHtml` (righe 23-27)
- Sezione "Badge Ottenuti" (righe 83-84)
- Statistica "Recensioni lasciate" (righe 104-105)
- Statistica "Resi effettuati" (righe 100-101)

### 7. `js/pages/fornitori.js`
**Rimuovere:**
- Rating stelle (righe 15-16)
- Conteggio recensioni (riga 17)

### 8. `js/pages/fornitori-dettaglio.js`
**Rimuovere:**
- Display rating (riga 55)
- Pulsante "Lascia recensione" (riga 80)

### 9. `js/pages/notifiche.js`
**Rimuovere:**
- Icona `NUOVO_SCAGLIONE` (riga 5)
- Icona `RESO_APPROVATO` (riga 14)
- Icona `RESO_RIFIUTATO` (riga 15)
- Fallback a `NUOVO_SCAGLIONE` (riga 29)

### 10. `js/pages/admin/notifiche.js`
**Rimuovere:**
- Option `NUOVO_SCAGLIONE` dal dropdown (riga 29)

### 11. `js/components/header.js`
**Rimuovere:**
- NAV_ITEMS: `{ route: '/resi', label: 'Resi', icon: '...' }` (riga 13)
- ADMIN_ITEMS: `{ route: '/admin/resi', label: 'Resi' }` (riga 22)
- ADMIN_ITEMS: `{ route: '/admin/audit', label: 'Audit' }` (riga 24)

### 12. `app.html`
**Rimuovere:**
- `import { ResiPage }` (riga 38)
- `import { AdminResiPage }` (riga 44)
- `import { AdminAuditPage }` (riga 46)
- Route `/resi` (riga 61)
- Route `/admin/resi` (riga 66)
- Route `/admin/audit` (riga 68)

### 13. `css/pages.css`
**Rimuovere:**
- `.tier-list` (righe 306-310)
- `.tier-item` (righe 312-320)
- `.tier-item.reached` (righe 322-325)
- `.tier-item.current` (righe 327-330)
- `.badge-list` (righe 385-389)
- `.badge-card` (righe 391-399)
- `.badge-card-top-buyer` (righe 401-404)
- `.badge-card-campione-risparmio` (righe 406-409)
- `.badge-card-fornitore-verificato` (righe 411-414)
- `.badge-card-pioniere` (righe 416-419)
- `.rating-stars` (righe 464-468)
- `.rating-stars svg` (righe 470-473)
- `.movement-list` (righe 518-521)
- `.movement-item` (righe 523-529)
- `.movement-icon` (righe 531-538)
- `.movement-icon-credito` (righe 540-543)
- `.movement-icon-rimborso` (righe 545-548)
- `.movement-icon-debito` (righe 550-553)
- `.movement-info` (righe 555-557)
- `.movement-desc` (righe 559-562)
- `.movement-date` (righe 564-567)
- `.movement-amount` (righe 569-572)
- `.movement-amount-credito` (righe 574-576)
- `.movement-amount-debito` (righe 578-580)

### 14. `css/responsive.css`
**Rimuovere:**
- `.tier-item` responsive (righe 122-126)

---

## Note Importanti

### Badge Component da MANTENERE
Il file `js/components/badge.js` e le sue classi CSS (`badge`, `badge-success`, ecc.) sono un **componente UI generico** usato in tutta l'app per etichette di stato (ordini, ritiri, utenti). NON vanno rimossi.

Solo le seguenti funzionalità badge vanno rimosse:
- Dati `BADGES` in mock.js
- Costanti `BADGE_TYPES`, `BADGE_LABELS`, `BADGE_COLORS` in constants.js
- Stili `.badge-card-*` in pages.css
- Sezione "Badge Ottenuti" in dashboard.js

### Tabelle che restano nel Frontend
Le seguenti tabelle dello schema SQL continuano ad essere supportate:
- `utenti`
- `fornitori_dettagli` / `amministratori_dettagli`
- `sedi`
- `proposte_prodotti`
- `voti_proposte`
- `prodotti`
- `collette`
- `prenotazioni`
- `pagamenti`
- `eventi_stripe`
- `consegne`
- `ordini_fornitore`
- `notifiche`
