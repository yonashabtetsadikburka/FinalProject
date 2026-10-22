# 12 – Audit di ridondanze e codice non usato

## A cosa serve
Elenca **cosa nel progetto sembra non servire più o è duplicato**: file, endpoint, funzioni, tabelle e colonne, regole
implementate in più punti, CSS/JS morti, testi che contraddicono il glossario, dipendenze. Ogni voce ha un livello di
**certezza** (CERTO / PROBABILE / DA VERIFICARE) e un **rischio di rimozione** (BASSO / MEDIO / ALTO), con le
evidenze (le ricerche fatte). In fondo c'è un **piano di pulizia** in ordine di sicurezza.
**Non è stato cancellato né modificato nulla**: il piano serve a decidere.

**Indice:** 1 Metodo · 2 File · 3 Endpoint · 4 Funzioni/costanti/config · 5 DB · 6 Duplicazioni di logica · 7 CSS/JS · 8 Testi obsoleti · 9 Dipendenze · 10 Piano di pulizia

---

## 1. Metodo
1. **Inventario** dei file con `find` e conteggi (`00-inventario.md`).
2. **Grep incrociati**: per ogni file JS, ricerca di `import`/`<script>`/stringhe col nome; per ogni funzione PHP, conteggio dei riferimenti fuori dal proprio file (script Python di ricerca su `api/` e `scripts/`, con `tests/` contati a parte); per ogni rotta, ricerca del percorso in `frontend/js` (chiamate letterali `apiGet/apiPost/…`, `fetch`, template literal e costruzioni dinamiche come `recensioni-popup.js`, dove l'URL arriva dal chiamante).
3. **Riferimenti dinamici**: controllati `import()` (solo `admin/notifiche.js:71`, `pages/ordini.js:275`, un test), nomi di funzione come stringa nel router (`index.php:68-167`), `array_map('nome')`, `require` con variabili (nessuno), classi CSS composte (`acq-stato-${vista}`, `dsh-pill-`, `pp-tag-`, `camp-f-`, `ord-f-`, `prop-head-`, `rank-`).
4. **Database**: grep dei nomi di tabelle/colonne in `endpoints`, `lib`, `scripts`, e confronto con `information_schema` (solo struttura).
5. **Limiti**: non ho eseguito l'applicazione né verificato in un browser la cascata CSS; non ho letto `api/config.php` né `_private/`. Le classi CSS "non trovate" sono candidate, da confermare prima di rimuoverle.

Legenda: **C** = certezza (CERTO / PROBABILE / DA VERIFICARE) · **R** = rischio di rimozione (BASSO / MEDIO / ALTO).

## 2. File inutilizzati o ridondanti
| # | File | Evidenza | C | R |
|---|---|---|---|---|
| F1 | `frontend/js/pages/ordini.js` (22 KB) | nessun `import` in tutto il repo (grep `pages/ordini` su `frontend`, `tests`); la rotta `/ordini` è un redirect a `/acquisti` (`app.html`). È l'**unico chiamante** di `POST /pagamento/checkout`, `POST /consegne/scelta`, `GET /consegne/costo` | CERTO | BASSO |
| F2 | `frontend/js/pages/partecipazioni.js` (8 KB) | nessun import; sostituita da `/acquisti`. Contiene testi "prenotazione"/"campagna" | CERTO | BASSO |
| F3 | `frontend/js/mock.js` (16 KB) | nessun import (compare solo come esclusione in `tests/glossario_test.mjs`) | CERTO | BASSO |
| F4 | `frontend/js/components/progress.js` | nessun import | CERTO | BASSO |
| F5 | `frontend/fornitore-attiva.html` | nessun riferimento nel frontend, **ma è l'entrata usata dal link d'invito** generato dal backend (`fornitori.php:133`: `…/fornitore-attiva.html?token=`). La stessa pagina è raggiungibile anche da `index.html#/fornitore/attiva` (duplicazione di entrata, non codice morto) | CERTO (usato) | ALTO: non rimuovere |
| F6 | `tests/aumento_integrazione_test.php` | non tracciato; richiede `lib/connessione.php` che **non esiste**; è quasi identico a `aumento_db_test.php` | CERTO (rotto) | BASSO |
| F7 | `api/buypool.sql` (dump con dati) | ridondante rispetto a `schema.sql`; aggiunge `collette_backup_20261007` e `prodotti.immagine_url`; **contiene dati personali** e non è in `.gitignore` | CERTO | BASSO (spostarlo fuori dal repo) |
| F8 | `api/seed_data.sql` | non eseguibile (`trust_score` inesistente, utente 2 mancante) | CERTO (rotto) | BASSO (correggere, non cancellare) |
| F9 | `api/uploads/proposte/` vs `api/uploads/proposals/` | due cartelle di upload con pipeline diverse (vedi 08) | CERTO (duplicazione) | MEDIO |
| F10 | `docs/*-checklist.md` (4 file) e `docs/tech|ops|prod` | documentazione già presente, **non è codice**; da integrare con questi documenti | – | – |
| F11 | `api/lib/PHPMailer/` (3 file, v6.9.3) | PHPMailer è incluso a mano; `mail.php` dichiara che andrebbe sostituito da composer | PROBABILE (sostituibile) | MEDIO |
| F12 | `_private/legacy/` (ignorato da git) | note di analisi e piano di riscrittura Symfony; fuori dal repo | – | non toccare |

Nessun file `.bak`, `.old`, `*_copy`, `prova`, `tmp` trovato nel repository (il `.gitignore` li esclude comunque: `*.bak`, `*.tmp`).

## 3. Endpoint senza chiamante nel frontend
(Escluso il webhook Stripe e le rotte per cron.)
| Rotta | Funzione | Motivo | C | R |
|---|---|---|---|---|
| `GET /salute` | closure `index.php:69` | health check, utile per monitoraggio | CERTO senza chiamante | BASSO (tenere) |
| `POST /logout` | `auth_logout` | il frontend non lo chiama (**è un difetto**, non da rimuovere ma da usare) | CERTO | ALTO |
| `GET /mie/statistiche` | `mie_statistiche` | i numeri arrivano da `/mie/dashboard` | CERTO | BASSO |
| `GET /campagne/{id}/assegnazioni` | `assegnazioni_elenco` | nessun chiamante e perde dati (13-B7) | CERTO | BASSO |
| `GET /wallet/{id}` | `wallet_dettaglio_utente` | nessun chiamante | CERTO | BASSO |
| `POST /consegne/{id}/conferma-ricezione` | `consegne_conferma_ricezione` | nessun chiamante (era nella pagina morta `ordini.js`?) | PROBABILE | MEDIO |
| `GET /pagamento/config` | `pagamento_config` | serviva al Payment Element | CERTO | BASSO |
| `POST /pagamento/acconto-intent` | `pagamento_acconto_intent` | variante PaymentIntent "fallback" non usata | CERTO | MEDIO |
| `GET/POST /admin/prodotti/{id}/immagini` | `prodotti_immagini_*` | l'admin usa `/campagne/{id}/modifica` | CERTO | BASSO |
| `DELETE /campagne/recensioni/{id}` | `recensione_campagna_elimina` | solo la cancellazione di fornitore è chiamata | CERTO | BASSO |
| `POST /campagne/chiusura` (senza id) | `campagne_chiusura` | è la variante **per cron**: serve, ma nessun cron è installato nel repo | – | ALTO (tenere) |
| `GET /_dev/mail-test` | `dev_mail_test` | solo sviluppo | CERTO | BASSO |
| `POST /campagne/{id}/partecipazioni` | `partecipazioni_aderisci` | risponde sempre 410 | CERTO | BASSO |
| Solo da pagine morte: `POST /pagamento/checkout`, `POST /consegne/scelta`, `GET /consegne/costo` | `pagamento_checkout`, `consegne_scelta`, `consegne_costo` | il flusso "saldo da pagina Ordini" è sostituito dal saldo automatico; la UI viva non li chiama (verificato con grep fuori da `ordini.js`/`partecipazioni.js`) | PROBABILE (legacy) | MEDIO: `pagamento_stato` ha un ramo fallback che dipende dal flusso legacy |
| `GET /mie/partecipazioni` | `partecipazioni_mie` | **usata**: `dashboard.js:386` la legge per il riquadro "ordini" (`ordiniHtml`) e per decidere `nonPartecipaMai`. È una seconda vista degli stessi dati di `/mie/acquisti` (duplicazione di logica: ha il proprio calcolo `totale` con `prezzo_corrente`, `partecipazioni.php:133`) | CERTO (usata) | MEDIO: da unificare con `acquisti_dell_utente` in futuro |

## 4. Funzioni, costanti, configurazione
| Voce | Evidenza | C | R |
|---|---|---|---|
| `dash_stima_saldo_in_arrivo` (`dashboard.php:44`) | zero chiamanti in `api/`; usata solo da `tests/dashboard_cliente_test.php`; sostituita da `acquisti_saldo_in_arrivo` | CERTO | BASSO |
| `proposta_scaduta` (`proposte_regole.php:104`) | usata solo dai test e da un commento; la regola reale è in SQL (`proposta_blocca`, `:263`) | CERTO | BASSO |
| Countdown a secondi in `frontend/js/countdown.js`: `formatCountdown`, `urgencyLevel`, `tickCountdowns`, `startCountdowns` | nessun import | CERTO | BASSO |
| `apiRequest` esportata ma usata solo in `api.js`; `Progress`; `ALIAS_STATO`, `STATI_ORDINE`, `STATI_COLLETTA_AVANZATI` esportati ma solo interni | grep export | CERTO | BASSO |
| Import inutilizzati: `admin/dashboard.js` (`apiPost`, `apiPut`, `STATI_CAMPAGNA_BADGES`), `notifiche.js` (`getState`), `register.js` (`showToast`) | analisi automatica | CERTO | BASSO |
| `lib/ripartizione.php` (`ripartisci`) | usata solo da `assegnazioni_ripartisci`, a sua volta legacy; l'esito non cambia le quantità | PROBABILE | MEDIO (richiede decisione sul flusso admin) |
| `sono_fornitore`, `fornitore_id_corrente` | usate solo da `richiedi_fornitore` | – (helper interni) | – |
| Costanti: nessuna completamente inutilizzata | verifica automatica | – | – |
| Config letta dal codice ma assente da `config.example.php`: `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `chiusura_secret`, `frontend_url`, `microsoft_client_id` | grep `$cfg['…']` | CERTO (documentazione incompleta) | – |
| Config presente nell'esempio e usata: `acconto_percent`, `costo_spedizione`, `mail_*`, `smtp_*`, `google_client_id`, DB | – | – | – |
| `$commissioni_mese` calcolata e mai usata (`pagamenti.php:99-105`) | bug (13-C1) | CERTO | – |

## 5. Tabelle, colonne, valori, indici (dettagli in `02-database.md`)
| Voce | Evidenza | C | R |
|---|---|---|---|
| Tabella `collette_backup_20261007` | non nominata in nessun file del repo | CERTO | BASSO (fare prima un backup) |
| `prodotti.immagine_url` | solo nel DB/dump, non in `schema.sql`; 0 riferimenti nel codice | CERTO | BASSO |
| `pagamenti.valuta`, `consegne.data_prevista`, `ordini_fornitore.prezzo_negoziato` | 0 riferimenti nel codice | CERTO | BASSO |
| `fornitori.num_campagne` | letta ma mai scritta (valore stantio) | CERTO | MEDIO |
| `fornitori.partner_pubblico`, `data_partnership`, `collette.regola_arrotondamento`, `collette.data_inizio` | lette, mai scritte (default) | CERTO | MEDIO |
| `collette.id_admin_conferma` | scritta ma mai letta | CERTO | BASSO |
| Colonne gemelle: `collette.id_referente`≈`id_aperta_da`; `proposte_prodotti.foto_path`≈`foto_file`; `utenti.partita_iva`≈`fornitori.piva`; `stato`≈`chiusura_data` | vedi 02 §5 | CERTO | MEDIO/ALTO |
| ENUM mai assegnati: `prodotti.stato=archiviato`; `collette.stato=annullata`; `prenotazioni.stato=annullata`; `pagamenti.stato=in_attesa|fallito`; `pagamenti.tipo_pagamento=consegna`; `consegne.stato=pronta|ritirata`; `ordini_fornitore.stato=da_negoziare|consegnato|annullato`; `qr_codes.stato=annullato`; `proponente_tipo=admin`; `notifiche.tipo=SCADENZA|TRACCIAMENTO` | grep sulle `INSERT/UPDATE` | CERTO | MEDIO (alcuni sono riservati: `annullata` andrebbe usato) |
| Indici ridondanti: `pagamenti.idx_pagamenti_stripe_intent`, `prenotazioni.idx_prenotazioni_colletta`, `proposte_prodotti.idx_proposte_stato` | verificati su `information_schema` | CERTO | BASSO |

## 6. Duplicazioni di logica (dove sono, se divergono, fonte unica)
| Regola | Copie | Divergono? | Fonte unica consigliata |
|---|---|---|---|
| **Acconto** (`qtà × primo prezzo × % / 100`, min 0,50) | `pagamento.php:685`; `aumento.php:14` (`acconto_aumento`); `pagamento_primo_prezzo` `:972` (duplica la lettura del primo prezzo di `:675-684`); `saldo-stima.js` (`accontoStima`); `dettaglio.js:620`, `aumenta-dialog.js:18` (senza arrotondamento sul totale); `ACCONTO_PERCENT` cablata in `constants.js:4` | sì: arrotondamento per riga vs sul totale (1 centesimo); percentuale 20 cablata vs config | una funzione PHP (`acconto_aumento`) usata anche da `pagamento_prepara_acconto`; il frontend riceve percentuale e importi dall'API |
| **Saldo** (`merce + commissione + spedizione − acconto`) | `prezzi.php:17` (**fonte**); `saldo-stima.js` (copia JS); `partecipazioni.php:133` (`totale` calcolato in SQL con `prezzo_corrente`); `assegnazioni.php:95-98`; `ritiro.php:127-128` | sì: le ultime tre usano `prezzo_corrente` stantio e non la formula | far usare `saldo_dettaglio` a tutti |
| **Commissione** | `prezzi.php:20`; `assegnazioni.php:96`; `ritiro.php:128`; `pagamento.php:1203` | stessa percentuale, ma prezzi diversi (vedi sopra) | `commissione_prenotazione` |
| **Prezzo corrente / scaglione raggiunto** | PHP: `prezzo_scaglione_raggiunto`, `obiettivo_barra`, `evento_prezzo_sceso` (indice dello scaglione); JS: `home.js:48-66` `obiettivoCorrente`, `dettaglio.js:201-216` `sceltaObiettivo`, `dettaglio.js:261`; colonna `collette.prezzo_corrente` | sì: colonna stantia; fallback "senza scaglioni" diverso tra home e dettaglio | `prezzi.php` esposto via API (`obiettivo` già calcolato per dashboard/acquisti) |
| **Minimo del gruppo** | `minimo_colletta` (primo scaglione), `ricalcola_stato` (colonna `quantita_minima`), `normalizza_colletta` (`soglia_raggiunta`), `partecipazioni_ritira` (`:52`), `prodotti.quantita_minima` | sì | `minimo_colletta` ovunque |
| **Conteggio gruppi "in corso"** | `lib/acquisti.php` (`acquisti_dell_utente` + `acquisti_conteggi`) è ora la fonte per Acquisti e Dashboard; `fornitore_campagne`, admin dashboard calcolano a parte | no per il cliente | mantenere |
| **Controllo scadenza** | `stato.php:29,77` (`<`), `pagamento.php:643,832` (`<=` SQL), `partecipazioni.php:44` (`<`), `aumento.php:51` (`<=`), JS: `stato-card.js:22`, `dettaglio.js:253`, `fornitore.js:73` | sì (operatore) | una funzione/espressione SQL `data_limite <= NOW()` |
| **Formattazione euro** | PHP: `formatta_euro` (`€12,00`), ma notifiche in `pagamento.php:283-284,316-317` usano `"EUR " . number_format`; JS: `euro()` vs `toFixed(2)`/`&euro;` in admin e `fornitore.js`, `eurIt` in `fornitore.js:337` | sì: "EUR 12,00" / "€12,00" / "12.50" | `formatta_euro` e `euro()` |
| **Date e plurali** | `dataGGMM` (format.js) vs `giornoMese` (`dashboard.js:39`) vs `ggmm` (`home.js:230`); `toLocaleDateString('it-IT')` in 10+ punti; `plurale` privato in `utils.js:8`; ternari inline | sì (padding e parsing) | `format.js` |
| **Escape HTML** | `utils.esc` (completo) vs `home.js:43`, `dettaglio.js:634`, `banner-fine.js:52`, `admin/fornitori.js:21,123`, `admin/utenti.js:56` | sì (sicurezza, vedi 13-A2) | `utils.esc` |
| **Whitelist tipi notifica** | ENUM `notifiche.tipo` e array in `notifiche.php:59-62` | allineati a mano | derivare da un'unica costante |
| **Stato "display" del gruppo** | `stato_campagna_display` (PHP) vs `displayCampagna` (JS) vs fallback in `dettaglio.js:255` | sì (solo se manca `stato_campagna`) | usare solo il valore dell'API |
| **Notifiche inserite a mano** | 24 `INSERT INTO notifiche` in 12 file vs `evento_emetti` | – | passare tutti da `evento_emetti` |
| **Upload foto** | `lib/immagini.php` vs `fornitore_salva_foto` | sì (sicurezza) | `lib/immagini.php` |
| **Helper immagini JS** | `imgUrl` identica in `home.js:37` e `dettaglio.js:91`; `urlImmagine` in `acquisti.js:80` e `dashboard.js:140`; `svg()` in 3 file | – | modulo condiviso |

## 7. CSS e JS inutilizzati
(Analisi automatica sui selettori di classe; non verificata in browser.)
- **CSS morto probabile:** blocco "Come funziona" vecchio (`.how-*`, tranne `how-circle` usata da `dettaglio.js:541`), dettaglio gruppo "legacy" (`.detail-*`, `.gallery-rail`, `.scaglioni-box`, `.countdown-box`…, il commento `pages.css:1965` dice "non più usata"), card campagna vecchia (`.campaign-card-*`), filtri vecchi (`.campagne-filter-bar`, `.filter-tab`, `.search-field`), `.supplier-*`, `.sede-*`, `.rank-*`, `.trust-score`, `.danger-zone*`, resti di sidebar (`.sidebar-layout`, `.nav-dropdown*`), componenti mai usati (`.skeleton*`, `.tabs-list`, `.modal-header/body/footer`, `.btn-lg`, `.btn-icon`…). Conteggio indicativo di classi non trovate: base 96/113, layout 7/44, components 30/78, pages 128/690, responsive 11/109. **C: PROBABILE – R: BASSO/MEDIO** (alcune sono generate dinamicamente: `avatar-sm`, `toast-<variante>`).
- **Utility "Tailwind-like" in `base.css`** quasi mai usate (il codice usa stili inline). **C: PROBABILE – R: BASSO.**
- **CSS usato solo da pagine morte:** `.order-*`, `.pickup-*`, `.part-head*`, `.cards-grid-wide`… (se si rimuovono `ordini.js` e `partecipazioni.js`).
- **Variabili CSS non referenziate:** `--color-success-hover`, `--color-warning-hover`, `--color-black`, `--gray-500/700/800`, `--font-mono`, `--font-normal`, `--fs-ui/body/small`, `--fw-ui/body/small`, `--color-small`; **usate ma non definite:** `--color-gray-600` (ha fallback), `--radius` (`admin/campagne.js:165`, senza fallback). **C: CERTO – R: BASSO.**
- **Grandi blocchi commentati:** nessuno trovato (solo JSDoc).
- **Font/librerie caricati e non usati:** nessuno: i due woff2 sono usati da `base.css`; Google GSI e MSAL sono usati da `login.js` (ma MSAL non è configurato: `MICROSOFT_CLIENT_ID` segnaposto). `js/lib/qrcode.js` (2297 righe) è usato.
- **Tre sistemi di variabili CSS** coesistenti e colori `#0F1B33` ripetuti a mano.

## 8. Testi e stati obsoleti rispetto al glossario
(Visibili all'utente; i nomi interni di rotte, tabelle e funzioni sono volutamente esclusi.)
| Dove | Testo | Stato |
|---|---|---|
| `frontend/js/pages/admin/dashboard.js:41` | colonna `Campagna` | obsoleto (visibile all'admin) |
| `frontend/js/pages/admin/campagne.js:97` | "…addebitare i saldi alle **prenotazioni** confermate?" | obsoleto |
| `api/endpoints/proposte.php:426` | titolo notifica **"Nuova Campagna"** (broadcast a tutti i clienti) | obsoleto, visibile ai clienti |
| `api/endpoints/assegnazioni.php:60` | "Nessuna **prenotazione** attiva per questo gruppo" | obsoleto |
| `api/endpoints/pagamento.php:43,1299,1301` | "**Prenotazione** non trovata", "…questa **prenotazione**" | obsoleto |
| `api/endpoints/ritiro.php:21` | "QR code non trovato per questa **prenotazione**" | obsoleto |
| `frontend/js/pages/partecipazioni.js` (pagina morta) | "Annulla **prenotazione**", "**Campagna** #N", "Esplora le **campagne**" | irrilevante se si elimina il file |
| `frontend/js/pages/ordini.js:425` (pagina morta) | "Esplora le campagne" | idem |
| Notifiche in `pagamento.php:283-284,316-317` | importi scritti "EUR 12,00" invece di "€12,00" | incoerente con il formato italiano |
| "Prenota ora" | nessuna occorrenza visibile | ok |
| "Oggi" come etichetta di prezzo | nessuna occorrenza; esistono solo "oggi/ieri" per le date (`format.js:93`) e "Scade oggi" (`proposte.js:127`) | ok |

`tests/glossario_test.mjs` vieta alcune frasi intere ma **non** cerca le parole isolate "campagna"/"prenotazione", quindi le occorrenze sopra passano il test.

## 9. Dipendenze
| Dipendenza | Dove è dichiarata | Uso | Nota |
|---|---|---|---|
| `stripe/stripe-php ^14.0` | `api/composer.json` | usata ovunque nei pagamenti | ok |
| `google/apiclient ^2.15` | `api/composer.json` | usata **solo** per `verifyIdToken` in `auth.php:63-71` | pesante (porta Guzzle, Monolog, firebase/php-jwt…): potrebbe bastare `firebase/php-jwt` + JWKS, che servirebbe anche per Microsoft (13-A1) |
| PHPMailer 6.9.3 | copiato in `api/lib/PHPMailer/` (non in composer) | `mail.php` | passare a composer |
| MSAL 2.38.3, Google GSI | CDN in `index.html` | login | MSAL non utilizzabile finché manca il client id |
| npm/package.json | non esiste | – | nessuna dipendenza frontend |
Nessuna dipendenza composer risulta inutilizzata.

---

## 10. Piano di pulizia (non eseguito)
### (A) Rimozioni a rischio BASSO e CERTE – si possono fare subito
| Voce | Come verificare prima |
|---|---|
| `frontend/js/mock.js`, `components/progress.js` | `grep -rn "mock\|progress" frontend` senza risultati di import |
| `pages/ordini.js` e `pages/partecipazioni.js` (+ CSS `.order-*`, `.pickup-*`, `.part-head*`) | smoke test manuale di Acquisti e Dashboard; `node tests/acquisti_front_test.mjs` |
| `tests/aumento_integrazione_test.php` (rotto/duplicato) | confrontare con `aumento_db_test.php` |
| Countdown a secondi in `countdown.js` (4 funzioni) e relative regole `.countdown-*` | `grep` + `node tests/format_test.mjs` |
| `dash_stima_saldo_in_arrivo` + suo test; `proposta_scaduta` o usarla davvero | eseguire `php tests/dashboard_cliente_test.php` |
| Import inutilizzati (3 file) | `node --check` |
| Indici ridondanti (3) | `SHOW INDEX` + piano delle query (migrazione con `_down`) |
| Rotte senza chiamante e senza dati utili: `GET /wallet/{id}`, `GET /mie/statistiche`, `GET|POST /admin/prodotti/{id}/immagini`, `DELETE /campagne/recensioni/{id}`, `GET /pagamento/config` | ricerca di chiamate esterne (script, Postman) con te |
| Spostare `api/buypool.sql` fuori dal repo e aggiungerlo a `.gitignore` | `git status` |

### (B) Da verificare con un test o con te
| Voce | Domanda / test |
|---|---|
| Flusso legacy del saldo (`/pagamento/checkout`, ramo fallback in `pagamento_stato`, `consegne_*`) | confermare che nessun gruppo vecchio abbia adesioni `confermata` senza addebito automatico |
| `assegnazioni_ripartisci` + `ripartizione.php` | l'admin lo usa ancora? (impedisce l'addebito dei saldi, 13-B4) |
| `acconto-intent`/Payment Element | si vuole mantenere come alternativa? |
| `fornitore-attiva.html` | **da tenere**: è il link generato da `fornitori_invito_genera` (`fornitori.php:133`); si può eliminare solo la doppia entrata `index.html#/fornitore/attiva` |
| `GET /mie/partecipazioni` | unificare con `/mie/acquisti` (la dashboard la usa per il riquadro ordini) |
| Tabella `collette_backup_20261007`, `prodotti.immagine_url` | backup e `DROP` con script `_down` |
| Colonne lette ma mai scritte (`num_campagne`, `partner_pubblico`, …) | decidere se rimuovere o far scrivere |
| ENUM mai assegnati | usarli (es. `annullata`) o toglierli con migrazione |
| CSS "probabile morto" (§7) | strumento di coverage del browser (DevTools) sulle pagine principali |
| Unificare le due pipeline di upload e le cartelle `uploads/` | spostare i file esistenti e aggiornare `immagini_prodotto.url` |

### (C) Da NON toccare adesso
| Voce | Perché |
|---|---|
| `POST /campagne/chiusura` (variante cron), `scripts/saldi_scaduti.php`, `scadenza_proposte.php`, `pulisci_foto_proposte.php` | usati da cron/CLI, non dal frontend |
| `POST /pagamento/webhook`, `GET /salute` | chiamati da sistemi esterni |
| Funzioni "usate una volta sola" (`stato-card.js`, `saldo-stima.js`, `consegna-metodi.js`, `banner-fine.js`, `dashboard-testi.js`, …) | sono moduli puri nati per essere testati |
| Colonne gemelle (`stato`/`chiusura_data`, `id_referente`/`id_aperta_da`) | toccano l'idempotenza e i dati esistenti: richiedono una decisione di prodotto |
| `lib/PHPMailer` | sostituirlo va fatto insieme al deploy (composer) |
| `_private/legacy` | fuori dal repo, serve per il porting |

## Domande che potrebbero farmi
1. **Che metodo avete usato per trovare il codice morto?** Grep incrociati su funzioni, rotte, `import` e classi CSS, più controllo dei riferimenti dinamici; poi classificazione per certezza.
2. **Qual è il file inutile più grosso?** `pages/ordini.js` (22 KB) e `mock.js` (16 KB), entrambi senza nessun `import`.
3. **Qual è la duplicazione più pericolosa?** La formula del saldo/acconto, perché ha copie JS e PHP (e tre punti PHP che usano un prezzo stantio).
4. **Perché non avete cancellato niente?** Per scelta: questo è un audit; la cancellazione va decisa con un test o una conferma.
5. **Cosa rimuovereste per primo?** I file senza import e il dump SQL con dati personali.
6. **Come sapete che un endpoint è inutilizzato?** Nessuna chiamata in `frontend/js` (letterale o dinamica) e non è webhook/cron.
7. **Ci sono dipendenze inutili?** Nessuna inutilizzata; `google/apiclient` è pesante rispetto all'uso.
8. **Il glossario è rispettato?** Nel frontend vivo quasi sempre; restano 5 messaggi visibili del backend e 2 dell'admin (§8).
