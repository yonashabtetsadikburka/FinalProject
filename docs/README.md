# Documentazione BuyPool

Indice della documentazione del progetto **BuyPool** — piattaforma di group buying (PHP 8.3 + MySQL + SPA vanilla JS, pagamenti Stripe).

## Per chi sei?

| Se sei… | Leggi |
|---|---|
| **Stakeholder / commissione — quadro generale** | [prod/01-panoramica-prodotto.md](prod/01-panoramica-prodotto.md) · [presentazione/index.html](presentazione/index.html) |
| **Valutatore tecnico / commissione** | [tech/03-architettura.md](tech/03-architettura.md) e i documenti tech/ seguenti |
| **Utente / formazione** | [prod/02-manuale-operativo.md](prod/02-manuale-operativo.md) |
| **Responsabile deploy / ops** | [ops/09-guida-deploy.md](ops/09-guida-deploy.md) · [ops/10-runbook.md](ops/10-runbook.md) |
| **Sviluppatore** | [../README.md](../README.md) · [../Frontend-Guide.md](../Frontend-Guide.md) · [tech/05-api-reference.md](tech/05-api-reference.md) · [tech/08-test-qa.md](tech/08-test-qa.md) |

## prod/ — Prodotto (stakeholder)

| Documento | Contenuto |
|---|---|
| [01-panoramica-prodotto.md](prod/01-panoramica-prodotto.md) | Problema, soluzione, ruoli, flussi, funzioni distintive, KPI di progetto |
| [02-manuale-operativo.md](prod/02-manuale-operativo.md) | Manuale passo-passo per cliente, fornitore e admin; tavola degli stati; FAQ |

## tech/ — Tecnico (commissione)

| Documento | Contenuto |
|---|---|
| [03-architettura.md](tech/03-architettura.md) | Stack, diagramma componenti, sequenze (pagamento, QR, cron), scelte progettuali |
| [04-modello-dati.md](tech/04-modello-dati.md) | ER delle 23 tabelle, macchine a stati, migrazioni |
| [05-api-reference.md](tech/05-api-reference.md) | Tutti gli 98 endpoint: auth, richieste/risposte, errori, webhook Stripe |
| [06-sicurezza-gdpr.md](tech/06-sicurezza-gdpr.md) | Auth, difese, punti deboli noti + remediation, diritti GDPR |
| [07-integrazioni-esterne.md](tech/07-integrazioni-esterne.md) | Stripe, Google/Microsoft OAuth, Mailpit (dev) vs SMTP prod, QR |
| [08-test-qa.md](tech/08-test-qa.md) | 22 suite automatiche, checklist manuali, protocollo di release QA |

## ops/ — Operativo (deploy)

| Documento | Contenuto |
|---|---|
| [09-guida-deploy.md](ops/09-guida-deploy.md) | Requisiti, rilascio, config completa, cron, migrazioni, checklist pre-go-live |
| [10-runbook.md](ops/10-runbook.md) | Monitoraggio, backup, coda email, troubleshooting per sintomo |

## presentazione/

| File | Contenuto |
|---|---|
| [index.html](presentazione/index.html) | Deck di presentazione (~20 slide, reveal.js + Mermaid) — apri nel browser, stampa in PDF con `Ctrl+P` |

## Checklist di test manuale (in questa cartella)

- [acquisti-checklist.md](acquisti-checklist.md) — menu Acquisti e redirect legacy
- [dashboard-cliente-checklist.md](dashboard-cliente-checklist.md) — dashboard cliente
- [proposte-checklist-test.md](proposte-checklist-test.md) — flusso votazione proposte
- [proposte-form-checklist.md](proposte-form-checklist.md) — form proposta con upload foto

## Documentazione di analisi e per l'orale (00–14) — aggiunta del 21/10/2026

Serie di documenti **verificati sul codice**, scritti in italiano e pensati per spiegare il progetto a voce, dal database all'interfaccia. Sono in questa cartella (`docs/NN-nome.md`) e affiancano — senza sostituirli — i documenti `prod/`, `tech/`, `ops/` sopra. Ogni documento inizia con "A cosa serve" e finisce con "Domande che potrebbero farmi".

**Ordine di lettura consigliato:** 14 (riassunto) → 01 → 10 → 04 → 05 → 06 → 02 → 03 → 07 → 08 → 11 → 13 → 12 → 09 → 00.

| Documento | Contenuto |
|---|---|
| [00-inventario.md](00-inventario.md) | struttura, conteggi, tecnologie, avvio in locale, variabili di configurazione (solo nomi), punti d'ingresso, elenco di tutti i file |
| [01-architettura.md](01-architettura.md) | componenti, flusso richiesta → router → endpoint → libreria → DB (Mermaid), convenzioni |
| [02-database.md](02-database.md) | le 23 tabelle (+ un backup), colonne, vincoli, stati, chi legge/scrive, ER, incoerenze schema ↔ codice |
| [03-endpoint-api.md](03-endpoint-api.md) | tutte le 101 rotte: file, auth, parametri, tabelle, errori, chiamante nel frontend |
| [04-logica-gruppi.md](04-logica-gruppi.md) | ciclo di vita del gruppo, tabelle degli stati, chiusura, proposte e voti |
| [05-pagamenti.md](05-pagamenti.md) | Stripe: acconto, saldo, commissione, rimborsi, webhook, esempio numerico calcolato |
| [06-email-e-notifiche.md](06-email-e-notifiche.md) | invio email, tabella evento → email → notifica → idempotenza |
| [07-frontend.md](07-frontend.md) | router, chiamate API, stato, moduli condivisi, CSS, mappa pagina → JS → endpoint |
| [08-sicurezza-e-ruoli.md](08-sicurezza-e-ruoli.md) | autenticazione, ruoli, XSS/CSRF/SQL injection, upload, cosa manca |
| [09-mappa-dei-file.md](09-mappa-dei-file.md) | per ogni file: cosa fa, chi lo usa, da cosa dipende (🟡 un riferimento, 🔴 nessuno) |
| [10-glossario.md](10-glossario.md) | termini del sito ↔ nomi interni (gruppo = colletta, adesione = prenotazione…) e stati |
| [11-percorsi-utente.md](11-percorsi-utente.md) | otto percorsi passo-passo con `file:riga` e diagrammi di sequenza |
| [12-audit-ridondanze.md](12-audit-ridondanze.md) | file, endpoint, funzioni, tabelle e logiche duplicate o inutilizzate, con certezza/rischio e piano di pulizia |
| [13-anomalie-e-rischi.md](13-anomalie-e-rischi.md) | bug e rischi (alti/medi/bassi) con scenario e correzione consigliata, nessuna applicata |
| [14-riassunto-per-orale.md](14-riassunto-per-orale.md) | pagina di 5 minuti, 10 domande difficili, comportamenti da replicare nel porting |

### Cosa è verificato e cosa no
- **Verificato sul codice** (lettura diretta delle funzioni e dei `file:riga` citati, più prove con le funzioni pure di `prezzi.php`/`aumento.php` per l'esempio numerico): formule di acconto, saldo e commissione; flusso Stripe e webhook; chiusura e rimborsi; stati del gruppo e dell'adesione; eventi email e idempotenza; routing e controlli di accesso; vincoli del DB (anche su `information_schema`, solo struttura).
- **Verificato con ricerche automatiche** (grep/script, ma non eseguendo l'applicazione): rotte senza chiamante, funzioni e file non usati, classi CSS non trovate, testi obsoleti.
- **DA VERIFICARE** (serve eseguire l'app o un'infrastruttura che qui non c'è): cron installato sul server; comportamento dopo la registrazione (sessione assente, 13-C21); riapertura di un gruppo `fallita` con scadenza allungata; tabella dei token (hash o chiaro) in reset/inviti; copertura reale delle classi CSS nel browser; hosting e variabili in produzione; discrepanze di conteggio con i documenti precedenti (`tech/05-api-reference.md` parla di 98 endpoint, qui ne risultano 101 inclusi `salute` e il webhook).
- **Non letti di proposito:** `api/config.php` e `_private/` (eccetto la conferma dell'esistenza di un piano di riscrittura Symfony). Nessun dato personale reale è riportato.
- **Controllo a campione:** circa 30 riferimenti `file:riga` sono stati riaperti a verifica; ne sono stati corretti 3 (righe di `lib/acquisti.php` e `fornitori.php`).

---

*Documentazione generata a partire dal codice sorgente (ottobre 2026). Aggiornare i documenti a ogni modifica significativa di API, schema o flussi.*
