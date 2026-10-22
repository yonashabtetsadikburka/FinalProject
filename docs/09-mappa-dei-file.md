# 09 – Mappa dei file

## A cosa serve
Per **ogni file sorgente**: cosa fa (1–3 righe), da chi è usato (require/import/rotta/cron/test) e da cosa dipende.
I file con **un solo riferimento** sono segnati 🟡, quelli **senza riferimenti** 🔴 (candidati inutilizzati: certezza e
rischio sono nell'audit `12-audit-ridondanze.md`). "Usato da" per gli endpoint: tutti sono caricati da
`api/index.php` con `require` (righe 20-55) e raggiunti dalla tabella delle rotte (righe 68-167); qui si
indicano solo gli altri riferimenti. Le dipendenze PHP sono ricavate con una ricerca automatica delle chiamate fra
file (funzioni definite in un file e usate in un altro).

**Indice:** 1 Backend – radice · 2 Endpoint · 3 Librerie · 4 Script · 5 SQL · 6 Frontend – radice · 7 Moduli JS · 8 Componenti · 9 Pagine · 10 Admin · 11 CSS/asset · 12 Test

Legenda: 🟡 un solo riferimento · 🔴 nessun riferimento · (nessun simbolo) più riferimenti.

---

## 1. Backend – radice
| File | Cosa fa | Usato da | Dipende da | |
|---|---|---|---|---|
| `api/index.php` | front controller: sessione, timezone, `require`, tabella rotte, errori | Apache (`.htaccess`) | tutte le lib e gli endpoint | |
| `api/.htaccess` | riscrive ogni URL verso `index.php` | Apache | – | |
| `api/config.php` | configurazione reale (non versionata, non letta) | quasi tutti gli endpoint, `db.php`, `mail.php`, `prezzi.php` | – | |
| `api/config.example.php` | modello senza credenziali | documentazione | – | 🔴 (solo documentazione) |

## 2. Endpoint (`api/endpoints/`)
| File | Cosa fa | Usato da (oltre a router) | Dipende da | |
|---|---|---|---|---|
| `auth.php` | registrazione, login password/Google/Microsoft, logout, `/io` | test DB (creazione utenti) | `db`, `risposta`, `auth` | |
| `profilo.php` | profilo, password, export GDPR, richiesta cancellazione | – | `db`, `auth`, `risposta` | |
| `utenti.php` | elenco/dettaglio/stato/ruolo/eliminazione utenti (admin) | – | `db`, `auth` | |
| `password.php` | richiesta e reset password, link admin | – | `mail`, `db`, `auth` | |
| `catalogo.php` | fornitori pubblici, prodotti, sedi | – | `db`, `auth` | |
| `prodotti.php` | immagini prodotto (admin) | – | `fornitori.php` (`fornitore_salva_foto`) | |
| `campagne.php` | gruppi: lista/dettaglio/crea/modifica/elimina, chiusura, rimborsi, ordine fornitore | `pagamento.php`, `fornitori.php`, `scripts/saldi_scaduti.php` | `stato`, `prezzi`, `sede`, `notifiche_eventi`, `pagamento` | |
| `partecipazioni.php` | annullamento adesione, "le mie partecipazioni" | test DB | `pagamento` (`rimborsa_acconti`), `stato`, `notifiche_eventi` | |
| `assegnazioni.php` | ripartizione admin (legacy) e elenco QR | – | `ripartizione`, `stato` | |
| `acquisti.php` | `GET /mie/acquisti` | – | `lib/acquisti` | |
| `dashboard.php` | `GET /mie/dashboard`, consigliati | `acquisti.php` (query condivisa) | `lib/acquisti`, `consigliati`, `prezzi` | |
| `pagamento.php` | tutto Stripe: acconto, aumento, webhook, saldo, riprova, rimborsi | `campagne.php`, `partecipazioni.php`, `notifiche_eventi.php`, `scripts/saldi_scaduti.php`, test DB | `aumento`, `prezzi`, `stato`, `sede`, `notifiche_eventi`, `campagne` | |
| `pagamenti.php` | wallet e statistiche | `dashboard.php` | `db`, `auth` | |
| `consegne.php` | scelta consegna, spedizione, conferma ricezione | – | `db`, `auth` | |
| `ritiro.php` | QR del cliente, elenco e conferma ritiri | – | `db`, `auth` | |
| `notifiche.php` | lista/lette/invio notifiche | – | `mail`, `db` | |
| `proposte.php` | proposte: lista, bacheca, crea, voti, stato, auto-creazione gruppo | – | `proposte_regole`, `immagini`, `sede` | |
| `fornitori.php` | admin fornitori, inviti, area fornitore, upload foto, avanzamento ordini | `campagne.php`, `prodotti.php` | `mail`, `notifiche_eventi`, `stato` | |
| `recensioni.php` | recensioni fornitore e gruppo | – | `db`, `auth` | |
| `dev.php` | prova invio email | – | `mail` | 🟡 |

## 3. Librerie (`api/lib/`)
| File | Cosa fa | Usato da | Dipende da | |
|---|---|---|---|---|
| `db.php` | connessione PDO condivisa | quasi tutto, script, test DB | `risposta`, `config.php` | |
| `risposta.php` | `json_ok`, `json_errore`, `AppError`, `corpo`, `campo` | tutti gli endpoint, test | – | |
| `auth.php` | `richiedi_*`, `sono_admin`, CSRF | 19 endpoint, test DB | `db` | |
| `stato.php` | `minimo_colletta`, `stato_campagna_display`, `ricalcola_stato` | `acquisti`, `assegnazioni`, `campagne`, `fornitori`, `pagamento`, `partecipazioni` | `db` | |
| `prezzi.php` | `saldo_dettaglio`, scaglioni, obiettivo, sconto | `acquisti`, `campagne`, `dashboard`, `notifiche_eventi`, `pagamento`, test | – | |
| `aumento.php` | regole di "Aumenta la quantità" e acconto aggiuntivo | `lib/acquisti`, `pagamento` | – | |
| `acquisti.php` | classificazione acquisti, query unica, conteggi, "Da fare" | `endpoints/acquisti`, `endpoints/dashboard` | `prezzi`, `aumento`, `stato` | |
| `sede.php` | sede predefinita ed effettiva | `campagne`, `pagamento`, `proposte` | – | |
| `consigliati.php` | punteggio dei gruppi consigliati | `dashboard.php` | – | 🟡 |
| `ripartizione.php` | ripartizione a resti maggiori | `assegnazioni.php` | – | 🟡 (flusso legacy) |
| `proposte_regole.php` | validazione, voti, notifiche delle proposte | `proposte.php`, `scripts/scadenza_proposte.php` | – | |
| `immagini.php` | normalizzazione sicura delle foto | `proposte.php`, `scripts/pulisci_foto_proposte.php` | – | |
| `mail.php` | invio SMTP e template | `dev`, `fornitori`, `notifiche`, `password`, `notifiche_eventi` | PHPMailer | |
| `notifiche_eventi.php` | eventi email + notifica idempotenti, fasi del saldo | `campagne`, `fornitori`, `pagamento`, `partecipazioni`, `scripts/saldi_scaduti.php`, test | `mail`, `prezzi`, `saldo_prenotazione` (in `pagamento.php`) | |
| `PHPMailer/Exception.php`, `SMTP.php`, `PHPMailer.php` | libreria di invio | `mail.php` | – | 🟡 ciascuno |

## 4. Script (`scripts/`)
| File | Cosa fa | Usato da | Dipende da | |
|---|---|---|---|---|
| `saldi_scaduti.php` | promemoria, annullamento e rimborso a 48 h, flush email | cron (nessun riferimento nel repo) | `campagne`, `pagamento`, `notifiche_eventi`, `stato`, `prezzi`, `mail` | 🔴 nel codice (usato da cron esterno) |
| `scadenza_proposte.php` | scadenza proposte oltre 30 giorni | cron | `db`, `proposte_regole` | 🔴 (cron) |
| `pulisci_foto_proposte.php` | elimina foto orfane (`--applica`) | uso manuale | `db`, `immagini` | 🔴 (manuale) |

## 5. SQL
| File | Cosa fa | Note |
|---|---|---|
| `api/schema.sql` | schema completo | bug d'ordine `recensioni_campagna` |
| `api/seed_data.sql` | seed minimale | non eseguibile |
| `api/buypool.sql` | dump con dati | non versionato, non ignorato; ridondante |
| `api/migrations/*.sql` (6) | 3 migrazioni + rollback | applicate a mano |

## 6. Frontend – radice
| File | Cosa fa | Usato da | Dipende da | |
|---|---|---|---|---|
| `frontend/app.html` | SPA autenticata e router | browser, redirect da `index.html` | tutte le pagine, `state`, `api`, `header`, `toast`, `acquisti-redirect` | |
| `frontend/index.html` | login/registrazione/password/privacy | browser | `login`, `register`, `password-dimenticata`, `privacy`, `fornitore-attiva` | |
| `frontend/fornitore-attiva.html` | attivazione fornitore (link d'invito) | link generato da `fornitori.php:133` | `pages/fornitore-attiva.js` | 🟡 (riferimento solo dal backend) |
| `frontend/.htaccess` | cache | Apache | – | |

## 7. Moduli JS (`frontend/js/`)
| File | Cosa fa | Usato da | |
|---|---|---|---|
| `api.js` | `apiGet/Post/Put/Delete/PostForm` | quasi tutte le pagine | |
| `state.js` | store utente/token | `api`, `auth`, `header`, pagine, `app.html` | |
| `auth.js` | login/registrazione/ruoli | `header`, `login`, `register`, `dettaglio`, `proposte`, `fornitori-dettaglio`, `app.html` | |
| `constants.js` | `API_URL`, etichette stati, `ACCONTO_PERCENT` | `api`, `home`, `dettaglio`, `acquisti`, `dashboard`, `proposte`, admin | |
| `format.js` | prezzi, plurali, date | `banner-fine`, `dashboard-testi`, `aumenta-dialog`, `home`, `dettaglio`, `acquisti`, `dashboard`, `wallet`, `fornitori-dettaglio` | |
| `utils.js` | `esc`, tempo relativo | `acquisti`, `dashboard`, `proposte`, `aumenta-dialog`, `recensioni-popup` | |
| `page-timers.js` | timer per pagina | `app.html`, `countdown`, `home`, `admin/ordini` | |
| `countdown.js` | countdown g:h:m | `dettaglio.js` | 🟡 (4 funzioni su 8 mai usate) |
| `stato-card.js` | stato unico della card | `home.js` | 🟡 |
| `stato-ordine.js` | passi dell'ordine | `dashboard.js` | 🟡 |
| `saldo-stima.js` | stima acconto/saldo | `dettaglio.js` | 🟡 |
| `consegna-metodi.js` | scelta ritiro/spedizione | `dettaglio.js` | 🟡 |
| `banner-fine.js` | banner dei gruppi terminati | `dettaglio.js` | 🟡 |
| `dashboard-testi.js` | frase riepilogo, "Da fare" | `dashboard.js` | 🟡 |
| `notifiche-tipo.js` | etichette notifiche | `dashboard.js` | 🟡 |
| `acquisti-redirect.js` | redirect vecchie rotte | `app.html` | 🟡 |
| `stars.js` | stelle di valutazione | `dettaglio`, `home` | |
| `qr-modal.js` | modale del QR | `acquisti`, `dashboard` | |
| `lib/qrcode.js` | libreria QR (globale) | `app.html` (script classico), `qr-modal.js` | 🟡 |
| `mock.js` | dati finti di sviluppo | – | 🔴 |

(Le 🟡 sui moduli puri indicano moduli nati per essere testati con Node: ognuno ha il proprio test in `tests/*.mjs`.)

## 8. Componenti (`frontend/js/components/`)
| File | Cosa fa | Usato da | |
|---|---|---|---|
| `header.js` | header per ruolo, ricerca, menu mobile, badge notifiche | `app.html`, `notifiche.js` | |
| `toast.js` | notifiche a comparsa | `app.html`, `acquisti`, `dashboard`, `proposte`, `register`, `recensioni-popup`, `share` | |
| `aumenta-dialog.js` | modale "Aumenta la quantità" | `acquisti`, `dettaglio` | |
| `recensioni-popup.js` | modale recensioni | `acquisti.js` | 🟡 |
| `share.js` | condivisione | `acquisti`, `dashboard`, `proposte` | |
| `badge.js`, `card.js`, `table.js` | template HTML minimi | pagine admin/fornitore | |
| `dropdown.js` | esporta solo `Avatar` | `header`, `dettaglio` | |
| `progress.js` | barra di avanzamento | – | 🔴 |

## 9. Pagine (`frontend/js/pages/`)
| File | Cosa fa | Usato da | |
|---|---|---|---|
| `home.js` | elenco gruppi, filtri, card | `app.html` | 🟡 |
| `dettaglio.js` | dettaglio gruppo, modale adesione, banner di fine | `app.html` | 🟡 |
| `dashboard.js` | dashboard cliente | `app.html` | 🟡 |
| `acquisti.js` | "I miei acquisti" | `app.html` | 🟡 |
| `proposte.js` | bacheca e form proposte | `app.html` | 🟡 |
| `notifiche.js`, `wallet.js`, `profilo.js` | notifiche, storico pagamenti, profilo | `app.html` | 🟡 ciascuno |
| `privacy.js` | informativa | `app.html`, `index.html` | |
| `fornitori-dettaglio.js` | scheda fornitore | `app.html` | 🟡 |
| `fornitore.js` | area fornitore (4 sezioni) | `app.html` | 🟡 |
| `pagamento-successo.js` | esito pagamento | `app.html` | 🟡 |
| `login.js`, `register.js`, `password-dimenticata.js` | accesso | `index.html` | 🟡 ciascuno |
| `fornitore-attiva.js` | attivazione fornitore | `index.html`, `fornitore-attiva.html` | |
| `ordini.js` | pagina "Ordini" vecchia | – | 🔴 |
| `partecipazioni.js` | pagina "Partecipazioni" vecchia | – | 🔴 |

## 10. Pagine admin (`frontend/js/pages/admin/`)
`dashboard.js`, `campagne.js`, `utenti.js`, `fornitori.js`, `proposte.js`, `ordini.js`, `ritiri.js`, `notifiche.js`: ciascuna è importata solo da `app.html` (🟡) e corrisponde alla rotta `/admin/<nome>`. Importano `api`, `components/{table,badge,card}` e, a seconda dei casi, `constants`, `page-timers`. `campagne.js` gestisce creazione/modifica/chiusura dei gruppi; `ordini.js` ripartizione, invio al fornitore e spedizioni; `ritiri.js` scansione dei QR.

## 11. CSS e asset
| File | Cosa fa | Usato da |
|---|---|---|
| `css/variables.css`, `base.css`, `layout.css`, `components.css`, `pages.css`, `responsive.css` | tema, reset+font, shell, componenti, pagine, responsive | tutte e tre le entrate HTML |
| `fonts/manrope-latin.woff2`, `manrope-latin-ext.woff2` | font | `base.css` |
| `img/logo_buypool.webp`, `favicon_buypool.webp` | logo, favicon | `header.js`, entrate HTML |

## 12. Test (`tests/`)
Ciascun test è lanciato a mano (nessun riferimento nel codice: tutti 🔴 per definizione) e verifica il modulo omonimo; vedi `00-inventario.md` §5.4. Rotto: `aumento_integrazione_test.php`.

## Domande che potrebbero farmi
1. **Qual è il file più importante?** `api/index.php` (ingresso) e `api/endpoints/pagamento.php` (soldi).
2. **Quale libreria è la più usata?** `risposta.php`, `db.php` e `auth.php`, richiamate da quasi tutti gli endpoint.
3. **Quali file non sono usati da nessuno?** `mock.js`, `progress.js`, `pages/ordini.js`, `pages/partecipazioni.js` (🔴), più gli script da cron che sono usati dall'esterno.
4. **Perché tanti moduli con un solo riferimento?** Sono funzioni pure estratte per poterle testare con Node.
5. **Chi usa `lib/ripartizione.php`?** Solo `assegnazioni.php`, un flusso admin ormai legacy.
6. **Come si capisce da dove arriva una funzione PHP?** Dalle `require` di `api/index.php`: l'ordine è documentato in `01-architettura.md`.
7. **Dov'è il codice per le email?** `lib/mail.php` (invio e template) e `lib/notifiche_eventi.php` (eventi).
8. **Dove sono le regole dei prezzi?** `lib/prezzi.php` e `lib/aumento.php`.
