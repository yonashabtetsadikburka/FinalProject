# Test e QA — BuyPool

Panoramica della strategia di testing: suite automatiche standalone (PHP e Node) e checklist di verifica manuale.

## 1. Panoramica

| Tipo | Stack | Runner | Numero |
|---|---|---|---|
| Test unitari / logica pura | PHP 8.3 | `php tests/<file>.php` | 9 |
| Test DB (integrazione dev) | PHP + MySQL | `php tests/<file>.php --esegui` | 3 |
| Test modulo frontend | Node.js (zero dipendenze) | `node tests/<file>.mjs` | 10 |
| Checklist manuali | Markdown | revisione umana | 4 |

**22 suite automatiche** + 4 checklist. Nessun framework di test (PHPUnit/Jest): ogni test è uno **script standalone** che stampa `[OK]`/`[FAIL]` e termina con **exit code 1** se ci sono fallimenti — pronto per CI o pre-commit.

## 2. Test PHP — logica pura (no database)

Si lanciano da root del progetto. Richiedono PHP 8.3+ (alcuni richiedono `gd`).

| File | Cosa verifica |
|---|---|
| `tests/saldo_test.php` | Formule acconto/saldo: 20% sul primo scaglione, arrotondamento sul totale, commissione, spedizione |
| `tests/aumento_test.php` | Regole "aumenta quantità": acconto pezzi extra, tetto 99, minimo €0,50 |
| `tests/acquisti_test.php` | Classificazione "I miei acquisti" nelle 4 schede e label di stato |
| `tests/dashboard_cliente_test.php` | Costruzione payload dashboard (gruppi, scaglioni, stime) |
| `tests/eventi_test.php` | Registro eventi email/notifiche: idempotenza e payload |
| `tests/immagini_test.php` | Pipeline upload GD: resize, WebP, rifiuti (richiede estensione `gd`) |
| `tests/proposte_regole_test.php` | Regole proposte: soglia 5 voti, 30 giorni, max 5 attive |
| `tests/proposte_form_test.php` | Validazione campi del form proposta |
| `tests/sede_test.php` | Sede di ritiro effettiva (`lib/sede.php`) |

```bash
php tests/saldo_test.php
php tests/eventi_test.php
# … ripetere per ciascun file, oppure:
Get-ChildItem tests\*_test.php | ForEach-Object { php $_.FullName }
```

## 3. Test PHP — integrazione database

**Scrivono dati di test** sul database di sviluppo: richiedono la bandiera esplicita `--esegui` (senza: si fermano chiedendo conferma) e un `api/config.php` valido.

| File | Cosa verifica |
|---|---|
| `tests/aumento_db_test.php` | Flusso aumento quantità con persistence (prenotazioni, pagamenti) |
| `tests/proposte_db_test.php` | Ciclo vita proposte su DB (creazione, voti, cambio stato, auto-creazione) |
| `tests/aumento_integrazione_test.php` | "Aumenta quantità" end-to-end su DB dev: PaymentIntent finti, Stripe mock, dati temporanei puliti a fine test |

```bash
php tests/aumento_db_test.php --esegui
php tests/proposte_db_test.php --esegui
```

> Usare **solo** su database di sviluppo: non esiste un rollback automatico dei dati inseriti.

## 4. Test Node — moduli frontend

Zero dipendenze (`node:test` non usato: assertion manuali come nei test PHP).

| File | Cosa verifica |
|---|---|
| `tests/format_test.mjs` | Utility di formattazione (valute, date `it-IT`, quantità) |
| `tests/stato_percorso_test.mjs` | Stato ordine unificato (`stato-ordine.js`): priorità e derivazioni |
| `tests/home_stato_test.mjs` | Logica stato/filtri della home |
| `tests/notifiche_tipo_test.mjs` | Mappatura tipi notifica → icona/colore |
| `tests/acquisti_front_test.mjs` | Logica schede "I miei acquisti" lato client |
| `tests/saldo_stima_test.mjs` | Stime saldo in arrivo (dashboard) |
| `tests/glossario_test.mjs` | Coerenza del glossario dei termini di stato |
| `tests/banner_fine_test.mjs` | Banner del dettaglio di un gruppo terminato |
| `tests/consegna_metodi_test.mjs` | Modalità di consegna offerte nella finestra di adesione |
| `tests/dashboard_testi_test.mjs` | Frase di riepilogo dashboard e blocco "Da fare" |

```bash
node tests/format_test.mjs
# … oppure tutti:
Get-ChildItem tests\*.mjs | ForEach-Object { node $_.FullName }
```

## 5. Checklist manuali (`docs/`)

Verifiche funzionali end-to-end non automatizzate, organizzate per feature:

| File | Copertura |
|---|---|
| `acquisti-checklist.md` | Menu "Acquisti", redirect legacy, evidenziazione scheda, link di ritorno |
| `dashboard-cliente-checklist.md` | Dashboard utente nuovo (stati vuoti, KPI €0) e con storico |
| `proposte-checklist-test.md` | Votazione proposte: permessi, creazione, ammissione admin, soglia 5 voti |
| `proposte-form-checklist.md` | Form proposta con upload foto (validazioni, anteprima, rimozione) |

Da eseguire **prima di ogni release** significativa, insieme alla suite automatica.

## 6. Copertura e limiti

**Coperto**: regole pure di prezzo/saldo/aumento, classificazione stati, validazioni form, pipeline immagini, idempotenza eventi email, alcuni moduli frontend.

**Non coperto** (limiti noti):
- flussi Stripe reali (checkout, webhook, rimborsi) — testabili solo manualmente con carte di prova + Stripe CLI;
- OAuth Google/Microsoft end-to-end;
- test E2E browser (nessun Playwright/Cypress);
- performance/load;
- nessuna **CI**: i test vanno lanciati a mano.

## 7. Protocollo di release QA

1. Esegui tutta la suite PHP: `Get-ChildItem tests\*_test.php | % { php $_.FullName }` (i tre test DB con `--esegui`).
2. Esegui tutta la suite Node: `Get-ChildItem tests\*.mjs | % { node $_.FullName }`.
3. Esegui le 4 checklist manuali in `docs/`.
4. Smoke test pagamenti con carte di prova Stripe + `stripe listen` per il webhook.
5. Verifica email su Mailpit (flussi reset, inviti, notifiche).

Documentazione collegata: [05-api-reference.md](05-api-reference.md) · [03-architettura.md](03-architettura.md) · [10-runbook.md](../ops/10-runbook.md)
