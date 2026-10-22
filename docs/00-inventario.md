# 00 – Inventario del progetto

## A cosa serve
Fotografia di **cosa c'è nel repository**: struttura delle cartelle, conteggio dei file per tipo, tecnologie e versioni,
come si avvia in locale (solo NOMI delle variabili di configurazione), i punti d'ingresso (router API, pagine,
script CLI, webhook) e l'elenco di **tutti i file sorgente con una riga ciascuno**. È la base della mappa dei file
(`09-mappa-dei-file.md`). Data dell'analisi: 21/10/2026, branch `feat/hero-layout`, working tree con modifiche non committate.

---

## 1. Struttura e conteggi
Esclusi `api/vendor/` (dipendenze composer, ~52.000 file), `api/uploads/` (19 immagini caricate), `.git/` e `_private/`.
```
buypool/
├─ api/                 backend PHP (nessun framework)
│  ├─ index.php         front controller + tabella delle rotte
│  ├─ endpoints/        20 file: una funzione per rotta
│  ├─ lib/              14 file + PHPMailer/ (3): logica condivisa
│  ├─ migrations/       6 file SQL (3 migrazioni + 3 rollback)
│  ├─ schema.sql, seed_data.sql, buypool.sql (dump)
│  ├─ config.example.php (modello), config.php (NON versionato)
│  └─ uploads/, vendor/
├─ frontend/            SPA vanilla JS (56 file JS, 6 CSS, 3 HTML, 2 font, 2 immagini)
├─ scripts/             3 script PHP da riga di comando (cron)
├─ tests/               22 test (12 PHP, 10 Node)
├─ docs/                documentazione
├─ README.md, Frontend-Guide.md
└─ _private/legacy/     note di analisi (ignorate da git)
```
| Tipo | File |
|---|---|
| `.js` | 56 |
| `.php` | 55 (esclusi vendor) |
| `.md` | 17 + i nuovi in `docs/` |
| `.mjs` (test Node) | 10 |
| `.sql` | 9 |
| `.css` | 6 |
| `.html` | 4 (3 frontend + deck `docs/presentazione`) |
| `.woff2` / `.webp` / `.htaccess` | 2 / 2 / 2 |

Dimensioni indicative del backend (righe): `pagamento.php` 1340, `campagne.php` 1076, `fornitori.php` 607, `lib/notifiche_eventi.php` 534, `proposte.php` 507; totale `endpoints+lib+scripts` ≈ 8.400 righe. Frontend: `pages.css` 4203 righe.

## 2. Tecnologie e versioni
| Elemento | Valore | Fonte |
|---|---|---|
| PHP | 8.3.1 (MAMP, ZTS), `declare(strict_types=1)` ovunque | `php -v` |
| Estensioni usate | `pdo_mysql`, `curl`, `openssl`, `mbstring`, `gd` (foto), `fileinfo`, `json`, `session` | `php -m` |
| Database | MySQL 5.7.24 (MAMP, porta 8889), InnoDB, utf8mb4 | `information_schema` |
| Librerie PHP (composer, `api/composer.json`) | `stripe/stripe-php ^14.0`, `google/apiclient ^2.15` | – |
| Mail | PHPMailer 6.9.3 incluso a mano in `api/lib/PHPMailer/` | `mail.php:20` |
| Frontend | JavaScript ES modules, nessun bundler né npm; Manrope in locale; `qrcode.js` incluso; Google GSI e MSAL da CDN in `index.html` | `frontend/` |
| Server web | Apache (MAMP), `mod_rewrite` per l'API (`api/.htaccess`) | – |
| Pagamenti | Stripe (Checkout Session + PaymentIntent + webhook) | `05-pagamenti.md` |
| Email in sviluppo | Mailpit su `127.0.0.1:1025` (UI `localhost:8025`) | `config.example.php` |

## 3. Avvio in locale (MAMP)
1. Avviare Apache e MySQL di MAMP (Apache su **8888**, MySQL su **8889**); il progetto sta in `htdocs/buypool`.
2. Creare il database `buypool` con `api/schema.sql` (attenzione all'ordine di `recensioni_campagna`, vedi 02) e applicare le migrazioni a mano; il seed non è eseguibile così com'è.
3. Copiare `api/config.example.php` in `api/config.php` (non versionato) e compilarlo.
4. `composer install` in `api/` (vendor già presente nel working tree).
5. URL: frontend `http://localhost:8888/buypool/frontend/app.html` (o `index.html` per il login); API `http://localhost:8888/buypool/api/` (`API_URL` fisso in `frontend/js/constants.js:1`).
6. Per i pagamenti serve un account Stripe di prova e l'inoltro dei webhook verso `POST /pagamento/webhook` (es. `stripe listen`, DA VERIFICARE nei documenti ops).

**Variabili di configurazione** (solo nomi, da `api/config.php`; letti con `$cfg['…']`):
| Nome | Serve a | In `config.example.php`? |
|---|---|---|
| `host`, `port`, `db`, `user`, `pass` | connessione MySQL | sì |
| `google_client_id` | login Google | sì |
| `microsoft_client_id` | login Microsoft (`auth.php:126`) | **no** |
| `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret` | Stripe | **no** |
| `chiusura_secret` | segreto per la chiusura da cron | **no** |
| `frontend_url` | base dei link in email e redirect Stripe | **no** |
| `acconto_percent` (20), `costo_spedizione` (4,90) | regole economiche | sì |
| `mail_enabled`, `mail_from`, `mail_from_nome`, `smtp_host`, `smtp_port`, `smtp_auth`, `smtp_user`, `smtp_pass`, `smtp_secure` | email | sì |

## 4. Punti d'ingresso
| Tipo | Elemento | Note |
|---|---|---|
| Router API | `api/index.php` (tabella `$rotte` righe 68-167) | 100 rotte + webhook |
| Webhook | `POST /pagamento/webhook` → `pagamento_webhook` (`index.php:196-200`) | chiamato da Stripe, firma verificata |
| Pagine | `frontend/app.html` (SPA autenticata), `index.html` (login/registrazione/password), `fornitore-attiva.html` (attivazione fornitore) | |
| Script CLI | `scripts/saldi_scaduti.php` (cron **orario**), `scripts/scadenza_proposte.php` (cron periodico), `scripts/pulisci_foto_proposte.php` (manuale, `--applica`) | tutti con guardia `PHP_SAPI !== 'cli'` |
| Cron via HTTP | `POST /campagne/chiusura?secret=…` | nessun cron installato nel repo (DA VERIFICARE sul server) |
| Migrazioni | `api/migrations/*.sql` (+ `_down`) | applicate a mano |
| Hook di git/CI | nessuno trovato | – |

## 5. Elenco dei file sorgente (una riga ciascuno)
### 5.1 Backend – `api/`
| File | Cosa fa |
|---|---|
| `index.php` | front controller: sessione, `require` di libreria ed endpoint, tabella delle rotte, gestione errori |
| `.htaccess` | riscrive tutto verso `index.php` |
| `config.example.php` | modello di configurazione (senza credenziali reali) |
| `schema.sql` | schema completo (23 tabelle) |
| `seed_data.sql` | seed minimale (non eseguibile così com'è) |
| `buypool.sql` | dump del DB di sviluppo con dati (non versionato) |
| `composer.json`, `composer.lock` | dipendenze PHP |
| `migrations/2026-10-19_proposte_votazione(.sql/_down.sql)` | stati proposte `in_valutazione`/`scaduta` |
| `migrations/2026-10-20_proposte_form_foto(.sql/_down.sql)` | categoria, prezzo indicativo, foto file |
| `migrations/2026-10-21_email_notifiche(.sql/_down.sql)` | tabella `email_inviate` + `prenotazioni.saldo_scadenza` |

**`api/endpoints/`**
| File | Cosa fa |
|---|---|
| `auth.php` | registrazione, login, Google, Microsoft, logout, `/io` |
| `profilo.php` | profilo, cambio password, export dati, richiesta cancellazione |
| `utenti.php` | gestione utenti (admin) |
| `password.php` | reset password |
| `catalogo.php` | elenco fornitori, prodotti, sedi |
| `prodotti.php` | immagini prodotto (admin) |
| `campagne.php` | gruppi: elenco, dettaglio, crea, modifica, elimina, **chiusura**, rimborsi, invio ordine al fornitore |
| `partecipazioni.php` | annullamento adesione, "le mie partecipazioni" |
| `assegnazioni.php` | ripartizione legacy (admin) e elenco QR |
| `acquisti.php` | `GET /mie/acquisti` |
| `dashboard.php` | `GET /mie/dashboard` |
| `pagamento.php` | **Stripe**: acconto, aumento, webhook, saldo, riprova, rimborsi |
| `pagamenti.php` | wallet e statistiche |
| `consegne.php` | scelta consegna legacy, spedizione, conferma ricezione |
| `ritiro.php` | QR del cliente, elenco e conferma ritiri (admin) |
| `notifiche.php` | notifiche del sito |
| `proposte.php` | proposte, bacheca, voti, cambio stato, auto-creazione gruppo |
| `fornitori.php` | admin fornitori, inviti, area fornitore, upload foto |
| `recensioni.php` | recensioni fornitore e gruppo |
| `dev.php` | prova invio email (solo sviluppo) |

**`api/lib/`**
| File | Cosa fa |
|---|---|
| `db.php` | connessione PDO condivisa |
| `risposta.php` | `json_ok`, `json_errore`, `AppError`, `corpo`, `campo`, `campo_int` |
| `auth.php` | controlli di sessione e ruolo, CSRF |
| `stato.php` | minimo, stato display, `ricalcola_stato` |
| `prezzi.php` | formula del saldo, scaglioni, obiettivo, sconto |
| `aumento.php` | regole di "Aumenta la quantità" |
| `acquisti.php` | classificazione acquisti, query condivisa, conteggi, "Da fare" |
| `sede.php` | sede predefinita |
| `consigliati.php` | punteggio dei gruppi consigliati |
| `ripartizione.php` | ripartizione a resti maggiori (legacy) |
| `proposte_regole.php` | regole, voti e notifiche delle proposte |
| `immagini.php` | normalizzazione sicura delle foto |
| `mail.php` | invio SMTP e template email |
| `notifiche_eventi.php` | eventi con email + notifica idempotenti |
| `PHPMailer/{Exception,SMTP,PHPMailer}.php` | libreria di invio |

### 5.2 Script – `scripts/`
| File | Cosa fa |
|---|---|
| `saldi_scaduti.php` | promemoria a 24 h, annullamento e rimborso a 48 h, rinvio email in coda |
| `scadenza_proposte.php` | marca `scaduta` le proposte in votazione oltre 30 giorni |
| `pulisci_foto_proposte.php` | elimina i file foto orfani (anteprima di default) |

### 5.3 Frontend – `frontend/`
| File | Cosa fa |
|---|---|
| `app.html` | SPA autenticata e router |
| `index.html` | login / registrazione / password / privacy |
| `fornitore-attiva.html` | attivazione account fornitore |
| `.htaccess` | policy di cache |
| `css/{variables,base,layout,components,pages,responsive}.css` | tema, reset+font, shell, componenti, pagine, responsive |
| `fonts/manrope-latin(-ext).woff2`, `img/{favicon,logo}_buypool.webp` | font e immagini |
| `js/api.js`, `state.js`, `auth.js`, `constants.js` | chiamate API, stato e sessione, autenticazione, costanti |
| `js/format.js`, `utils.js` | prezzi/plurali/date; escape HTML |
| `js/stato-card.js`, `stato-ordine.js` | stato card gruppo, passi ordine |
| `js/saldo-stima.js`, `consegna-metodi.js`, `banner-fine.js`, `dashboard-testi.js`, `notifiche-tipo.js`, `acquisti-redirect.js` | logica pura testabile |
| `js/countdown.js`, `page-timers.js`, `stars.js`, `qr-modal.js` | countdown, timer, stelle, modale QR |
| `js/mock.js`, `lib/qrcode.js` | dati finti (inutilizzati), libreria QR |
| `js/components/{header,toast,badge,card,table,dropdown,progress,aumenta-dialog,recensioni-popup,share}.js` | componenti UI |
| `js/pages/{home,dettaglio,dashboard,acquisti,proposte,notifiche,wallet,profilo,privacy,fornitori-dettaglio,fornitore,pagamento-successo,login,register,password-dimenticata,fornitore-attiva}.js` | pagine |
| `js/pages/{ordini,partecipazioni}.js` | pagine **morte** (non importate) |
| `js/pages/admin/{dashboard,campagne,utenti,fornitori,proposte,ordini,ritiri,notifiche}.js` | pagine admin |

### 5.4 Test – `tests/`
| File | Cosa testa |
|---|---|
| `saldo_test.php`, `aumento_test.php`, `acquisti_test.php`, `dashboard_cliente_test.php`, `sede_test.php` | funzioni pure di prezzo/saldo/aumento/classificazione/dashboard/sede |
| `eventi_test.php` | email ed eventi (registro simulato) |
| `proposte_regole_test.php`, `proposte_form_test.php`, `immagini_test.php` | regole proposte, validazione form, foto |
| `aumento_db_test.php`, `proposte_db_test.php` | prove su DB di sviluppo (`--esegui`) |
| `aumento_integrazione_test.php` | **rotto** (richiede un file inesistente) |
| `format_test.mjs`, `stato_percorso_test.mjs`, `home_stato_test.mjs`, `notifiche_tipo_test.mjs`, `acquisti_front_test.mjs`, `saldo_stima_test.mjs`, `banner_fine_test.mjs`, `consegna_metodi_test.mjs`, `dashboard_testi_test.mjs`, `glossario_test.mjs` | funzioni pure del frontend e glossario |
Lancio: `php tests/<file>.php` / `node tests/<file>.mjs`; non c'è un runner unico (descritto in `docs/tech/08-test-qa.md`).

## Domande che potrebbero farmi
1. **Con che tecnologie è fatto?** PHP 8.3 senza framework, MySQL 5.7, JavaScript vanilla a moduli, Stripe per i pagamenti, PHPMailer per le email.
2. **Come si avvia?** MAMP (Apache 8888, MySQL 8889), `api/config.php` da `config.example.php`, schema e migrazioni a mano.
3. **Qual è il punto d'ingresso dell'API?** `api/index.php`: tabella di rotte e funzioni.
4. **Quali processi girano in background?** Nessun daemon: script CLI da cron (saldi, proposte) e la chiusura via HTTP.
5. **Quante rotte ha l'API?** 101 (100 nella tabella + webhook).
6. **Dove sono le dipendenze?** `api/composer.json` (Stripe, Google API); il frontend non ha npm.
7. **Cosa non è versionato?** `api/config.php`, `api/vendor`, `api/uploads/proposte|proposals` (tranne `.htaccess`), `_private/`.
8. **Come si lanciano i test?** Singolarmente con `php`/`node`, senza runner.
