# 03 – Endpoint API

## A cosa serve
Elenco completo delle **101 rotte HTTP** del backend (100 voci nella tabella `$rotte` di `api/index.php:68-167` più il
webhook Stripe gestito prima del router, `index.php:196-200`). Per ogni rotta: file e funzione, chi può chiamarla,
cosa valida, quali tabelle tocca, cosa risponde, quali errori può dare e **quale pagina del frontend la usa**.
Serve a rispondere a "cosa succede quando il browser chiama X?" e a trovare le rotte senza chiamante (vedi audit 12).
Le colonne "Frontend" derivano da una ricerca incrociata (grep di `apiGet/apiPost/apiPut/apiDelete/apiPostForm`, `fetch` e template literal) in `frontend/js`.

**Indice:** 0 Convenzioni · 1 Auth · 2 Profilo · 3 Utenti (admin) · 4 Password · 5 Catalogo · 6 Gruppi · 7 Adesioni/acquisti/consegne · 8 Ritiri/QR · 9 Pagamenti · 10 Proposte · 11 Notifiche · 12 Fornitori (admin) · 13 Area fornitore · 14 Recensioni · 15 Dev · 16 Rotte senza chiamante · 17 Domande

---

## 0. Convenzioni generali
| Tema | Come funziona | Dove |
|---|---|---|
| Router | front controller senza framework: tabella `[metodo, schema, funzione]`; `{id}` accetta solo cifre, `{token}` solo alfanumerici; il percorso senza cartella base e query string | `api/index.php:57-65,68-167,169-188` |
| Webhook Stripe | `POST pagamento/webhook` gestito **prima** del router (non è in `$rotte`) | `index.php:196-200` |
| Errori di routing | stesso percorso con metodo diverso → 405 `METODO_NON_AMMESSO`; nessun percorso → 404 `ROTTA_INESISTENTE` | `index.php:210-213` |
| Forma risposte | successo `{"ok":true,"dati":…}`; errore `{"ok":false,"errore":{"codice","messaggio","campi"?}}` | `api/lib/risposta.php:27-45` |
| Eccezioni | `AppError(codice, messaggio, http, campi)` → `json_errore`; qualsiasi altra `Throwable` → log + 500 `ERRORE_INTERNO` | `risposta.php:11-25`, `index.php:215-220` |
| Input | `corpo()` legge JSON dal body (se non è array → `JSON_NON_VALIDO`); `campo()`, `campo_int()` validano | `risposta.php:47-67` |
| Sessione | cookie di sessione PHP `httponly`, `SameSite=Lax`, **senza** flag `secure`; il login scrive `utente_id` e `ruolo` in `$_SESSION` dopo `session_regenerate_id(true)` | `index.php:17-18`, `auth.php:56-58` |
| Controlli di accesso | `richiedi_login` (401), `richiedi_utente_attivo` (403 se sospeso), `richiedi_cliente`, `richiedi_fornitore`; **non esiste `richiedi_admin()`**: gli admin sono controllati inline con `sono_admin()` (403 `NON_AUTORIZZATO`/`NON_ADMIN`) | `api/lib/auth.php:18-98` |
| CSRF | reale solo su `POST/DELETE proposte/{id}/vota` (header `X-CSRF-Token`) | `auth.php:62-76`, `proposte.php:185,196` |
| CORS | nessun header: il frontend è sullo stesso origin (`http://localhost:8888/buypool/...`) | `frontend/js/constants.js:1` |
| Rate limiting | assente | grep su rate/throttle senza risultati |
| Header `Authorization` | il frontend invia `Bearer session_<id>` ma il backend non lo legge mai: conta solo il cookie | `frontend/js/api.js:13`, nessun `HTTP_AUTHORIZATION` in PHP |

⚠ Il ruolo resta quello scritto in sessione al login: un cambio ruolo (`PUT utenti/{id}/ruolo`) ha effetto solo al login successivo.

---

## 1. Auth (`api/endpoints/auth.php`)
| Rotta | Funzione | Auth | Validazione | Tabelle | Risposta / errori | Frontend |
|---|---|---|---|---|---|---|
| GET `/salute` | closure `index.php:69` | no | – | – | `{stato:'ok',php}` | nessuno trovato |
| POST `/registrazione` | `auth_registrazione` `:6` | no | nome/email/password obbligatori; email valida; password ≥ 8; `privacy` obbligatoria; `tipo` privato\|b2b | W `utenti` | 201 `{id,nome,cognome,ruolo}`; `EMAIL_NON_VALIDA`, `PASSWORD_DEBOLE`, `PRIVACY_NON_ACCETTATA`, `EMAIL_GIA_USATA` 409 | `js/auth.js:26`, `pages/register.js:80` |
| POST `/login` | `auth_login` `:41` | no | email, password | R `utenti` | `{id,nome,cognome,email,ruolo,stato}` + sessione; `CREDENZIALI_NON_VALIDE` 401 (stesso messaggio se l'email non esiste) | `auth.js:5` |
| POST `/auth/google` | `auth_google_login` `:63` | no | `token` (ID token Google) | R/W `utenti` | come login; `TOKEN_GOOGLE_NON_VALIDO` 401 | `auth.js:12` |
| POST `/auth/microsoft` | `auth_microsoft_login` `:120` | no | `token` JWT | R/W `utenti` | come login; `TOKEN_MICROSOFT_NON_VALIDO` 401 | `auth.js:19` |
| POST `/logout` | `auth_logout` `:184` | no | – | – | `{uscito:true}` | **nessuno trovato** (`auth.js:32` pulisce solo lo stato locale) |
| GET `/io` | `auth_io` `:191` | login | – | R `utenti` | `{id,nome,email,ruolo,stato}` | `app.html:205` (`validateSession`) |

Osservazioni **verificate leggendo il codice**:
- `auth.php:70` crea il client Google con `'verify' => false` (disattiva la verifica TLS).
- `auth.php:131-139`: il JWT Microsoft viene decodificato **senza verificare la firma** (commento "placeholder") e poi si cerca l'utente per `microsoft_id` **o email** (`:147`) assumendone il `ruolo` (`:173`): chi forgia un token con l'email di un admin ottiene una sessione admin. Vedi `13-anomalie-e-rischi.md` (gravità alta).
- `auth_registrazione` non crea la sessione; DA VERIFICARE che il frontend esegua poi un login (`register.js:80`).
- Il login non controlla `utenti.stato`: un utente sospeso entra in sola lettura (voluto: `UTENTE_SOSPESO` blocca le scritture).
- `microsoft_client_id` è letto (`:126`) ma assente da `api/config.example.php`.

## 2. Profilo (`profilo.php`)
| Rotta | Funzione | Auth | Validazione | Tabelle | Risposta / errori | Frontend |
|---|---|---|---|---|---|---|
| GET `/profilo` | `profilo_leggi` `:4` | login | – | R utenti | riga utente; `UTENTE_INESISTENTE` 404 | `profilo.js:23`, `dettaglio.js:694` |
| PUT `/profilo` | `profilo_aggiorna` `:19` | login | nome, cognome, telefono, indirizzo, cap ≤ 10, città ≤ 100, provincia 2 lettere, P.IVA 11 cifre, CF 16 alfanumerici | W utenti | `{aggiornato:true}`; `CAP_NON_VALIDO`, `PROVINCIA_NON_VALIDA`, `PIVA_NON_VALIDA`, `CF_NON_VALIDO`, `NESSUN_CAMPO` | `profilo.js:105` |
| POST `/profilo/password` | `profilo_cambia_password` `:71` | login | vecchia, nuova ≥ 8 | W utenti | `PASSWORD_DEBOLE`, `PASSWORD_VECCHIA_ERRATA` 401 | `profilo.js:138` |
| GET `/profilo/export` | `profilo_export` `:99` | login | – | R utenti, prenotazioni, pagamenti, notifiche, proposte, voti | JSON di esportazione dati (GDPR) | `profilo.js:152` |
| POST `/profilo/cancellazione` | `profilo_cancellazione_richiesta` `:172` | login | – | W notifiche (SISTEMA agli admin) | `{richiesta:true}` | `profilo.js:167` |

## 3. Utenti – solo admin (`utenti.php`)
Tutte con `sono_admin()` (403). Le scritture su sé stessi sono bloccate (`OPERAZIONE_NON_CONSENTITA`).
| Rotta | Funzione | Effetto | Errori | Frontend |
|---|---|---|---|---|
| GET `/utenti` | `utenti_elenco` `:4` | elenco utenti | 403 | `admin/utenti.js:12,210`, `admin/dashboard.js:12`, `admin/fornitori.js:79`, `admin/notifiche.js:9` |
| GET `/utenti/{id}` | `utenti_dettaglio` `:18` | scheda | `UTENTE_INESISTENTE` 404 | `admin/utenti.js:53,121` |
| PUT `/utenti/{id}/stato` | `utenti_aggiorna_stato` `:33` | `attivo`\|`sospeso` | `STATO_NON_VALIDO` | `admin/utenti.js:18` |
| PUT `/utenti/{id}/ruolo` | `utenti_aggiorna_ruolo` `:52` | `cliente`\|`fornitore`\|`admin` | `RUOLO_NON_VALIDO` | `admin/utenti.js:8` |
| DELETE `/utenti/{id}` | `utenti_elimina` `:71` | elimina se **senza** prenotazioni/pagamenti | `HA_STORICO` 409 | `admin/utenti.js:109` |
| GET `/utenti/{id}/storico` | `utenti_storico` `:104` | partecipazioni, pagamenti, ultime 20 notifiche, proposte | 404 | `admin/utenti.js:122` |

## 4. Reset password (`password.php`)
| Rotta | Funzione | Auth | Note | Frontend |
|---|---|---|---|---|
| POST `/password/richiesta` | `password_richiesta` `:15` | no | risposta sempre generica (anti-enumerazione); avvisa gli admin con una notifica SISTEMA | `password-dimenticata.js:35` |
| POST `/admin/utenti/{id}/reset-link` | `password_reset_genera` `:45` | admin | token 64 hex, scadenza 24 h, email facoltativa | `admin/utenti.js:91` |
| GET `/password/reset/{token}` | `password_reset_verifica` `:81` | no | `LINK_NON_VALIDO` 404, `LINK_SCADUTO` 410 | `password-dimenticata.js:62` |
| POST `/password/reset` | `password_reset_esegui` `:101` | no | token + password ≥ 8; transazione `FOR UPDATE` | `password-dimenticata.js:99` |

## 5. Catalogo (`catalogo.php`, `prodotti.php`)
| Rotta | Funzione | Auth | Note | Frontend |
|---|---|---|---|---|
| GET `/fornitori` | `catalogo_fornitori` `catalogo.php:4` | login | solo `partner_pubblico=1` | `fornitori-dettaglio.js:31` |
| GET `/prodotti?fornitore_id=` | `catalogo_prodotti` `:26` | login | solo prodotti `attivo` | `fornitori-dettaglio.js:32` |
| GET `/sedi` | `catalogo_sedi` `:61` | login | `SELECT *` su `sedi` | `admin/ritiri.js:86` |
| GET `/categorie` | `categorie_elenco` `proposte.php:171` | login | – | `proposte.js:504` |
| GET `/admin/prodotti/{id}/immagini` | `prodotti_immagini_elenco` `prodotti.php:12` | admin | – | **nessuno trovato** |
| POST `/admin/prodotti/{id}/immagini` | `prodotti_immagini_aggiungi` `:27` | admin | multipart `foto[]`, usa `fornitore_salva_foto` | **nessuno trovato** (la UI usa `/campagne/{id}/modifica` con `foto_extra[]`) |
| DELETE `/admin/immagini/{id}` | `prodotti_immagine_elimina` `:78` | admin | rifiuta l'ultima immagine (`ULTIMA_IMMAGINE` 409) | `admin/campagne.js:214` |

## 6. Gruppi (`campagne.php`, tabella `collette`)
| Rotta | Funzione | Auth | Parametri / validazione | Tabelle | Errori | Frontend |
|---|---|---|---|---|---|---|
| GET `/campagne` | `campagne_elenco` `:71` | **pubblica** | – | R collette, prodotti, fornitori, sedi, prenotazioni, scaglioni, recensioni, utenti; **scrive indirettamente** via `ricalcola_stato` per ogni gruppo | – | `home.js:858`, `fornitore.js:167,573`, `fornitori-dettaglio.js:34`, `admin/campagne.js:274,362` |
| POST `/campagne` | `campagne_crea` `:622` | admin | JSON o multipart; scadenza futura; `prodotto_id` **oppure** nome (≤ 80)+fornitore+prezzo; opzionali: `prezzo_base`, `quantita_minima`, `id_categoria`, `sede_id`, `percentuale_commissione` (0-100, default 10), `scaglioni` (max 10), `foto[]` | W prodotti, collette, scaglioni_prezzo, immagini_prodotto | `DATA_NEL_PASSATO`, `PREZZO_NON_VALIDO`, `MOQ_NON_VALIDO`, `COMMISSIONE_NON_VALIDA`, `SCAGLIONI_NON_VALIDI`, … | `admin/campagne.js:347` |
| GET `/campagne/{id}` | `campagne_dettaglio` `:159` | pubblica | – | R collette, scaglioni, recensioni, prenotazioni, utenti, qr, consegne | `CAMPAGNA_INESISTENTE` 404 | `dettaglio.js:917,941,976`, `admin/campagne.js:48,124` |
| DELETE `/campagne/{id}` | `campagne_elimina` `:561` | admin | blocca se esistono pagamenti | W collette (cascade) | `HA_PAGAMENTI` 409 | `admin/campagne.js:115` |
| POST `/campagne/{id}/modifica` | `campagne_modifica` `:893` | admin | `data_limite` futura, `quantita_minima`, prezzi, commissione, scaglioni (bloccati se ci sono adesioni), foto; bloccata se `ordine_fornitore`/`consegnata` | W collette, scaglioni, immagini | `CAMPAGNA_NON_MODIFICABILE` 409, `SCAGLIONI_BLOCCATI` 409 | `admin/campagne.js:261` |
| GET `/admin/ordini` | `admin_ordini` `:746` | admin | gruppi con adesioni | R | – | `admin/ordini.js:51,147` |
| POST `/campagne/{id}/ripartisci` | `assegnazioni_ripartisci` `assegnazioni.php:13` | admin | richiede stato `riuscita`; passa le adesioni `prenotata`→`confermata` e il gruppo a `ordine_pronto` | W prenotazioni, collette, notifiche | `CAMPAGNA_NON_RIUSCITA`, `GIA_RIPARTITA` 409 | `admin/ordini.js:8` |
| POST `/campagne/{id}/invia-fornitore` | `campagne_invia_fornitore` `:783` → `invia_ordine_fornitore_al` `:800` | admin | richiede `ordine_pronto` e tutte le adesioni `pagata`/`rimborsata` | W ordini_fornitore, collette, notifiche, email_inviate | `PAGAMENTI_INCOMPLETI` | `admin/ordini.js:22` |
| GET `/campagne/{id}/assegnazioni` | `assegnazioni_elenco` `:119` | **qualunque utente loggato** | – | R qr_codes, prenotazioni, utenti | – | **nessuno trovato** |
| POST `/campagne/{id}/chiusura` e `/campagne/chiusura` | `campagne_chiusura` `:273` | admin **oppure** `?secret=` (`chiusura_secret`, `hash_equals`) | body facoltativo `id_colletta`; con id solo se scaduta | W collette, prenotazioni, pagamenti, notifiche, qr_codes + Stripe | `CHIUSURA_ANTICIPATA` 409 | `admin/campagne.js:99` (solo la variante con id) |

## 7. Adesioni, acquisti, consegne
### Partecipazioni (`partecipazioni.php`)
| Rotta | Funzione | Auth | Note | Frontend |
|---|---|---|---|---|
| POST `/campagne/{id}/partecipazioni` | `partecipazioni_aderisci` `:9` | – | **disabilitata**: risponde sempre 410 `FLUSSO_DISABILITATO` (le adesioni nascono dal webhook) | nessuno |
| DELETE `/campagne/{id}/partecipazioni` | `partecipazioni_ritira` `:19` | `richiedi_login` | rifiuta gruppo `ordine_fornitore`/`consegnata`, scaduto, adesione già `pagata`/`rimborsata`; rimborsa gli acconti su Stripe; **cancella** la prenotazione; se si scende sotto il minimo le `confermata` tornano `prenotata` (+ notifica `SOGLIA_PERSA`) | `acquisti.js:394`, `dettaglio.js:935`, `partecipazioni.js:96` |
| GET `/mie/partecipazioni` | `partecipazioni_mie` `:120` | login | array grezzo delle adesioni dell'utente con totali, QR, consegna, sede | `dashboard.js:386`, `ordini.js:82,230,260`, `partecipazioni.js:103,120` |

### Acquisti e dashboard
| Rotta | Funzione | Auth | Note | Frontend |
|---|---|---|---|---|
| GET `/mie/acquisti?tab=&sort=&id=` | `mie_acquisti` `acquisti.php:9` (logica `lib/acquisti.php`) | `richiedi_cliente` | schede `in-corso/in-arrivo/conclusi/annullati`, ordinamento `scadenza/recenti`, `id` apre la scheda che contiene quell'acquisto | `acquisti.js:344,444`, `dettaglio.js:962` |
| GET `/mie/dashboard` | `mie_dashboard` `dashboard.php:155` | `richiedi_cliente` | `{statistiche, saldo_in_arrivo, n_gruppi_in_corso, ritiri_pronti, acquisti_conteggi, da_fare, gruppi_in_corso, consigliati}`; usa **la stessa funzione** di "I miei acquisti" per i conteggi | `dashboard.js:385` |
| GET `/mie/statistiche` | `mie_statistiche` `pagamenti.php:141` | login | spesa, risparmio, da pagare | **nessuno trovato** |

### Consegne (`consegne.php`)
| Rotta | Funzione | Auth | Note | Frontend |
|---|---|---|---|---|
| POST `/consegne/scelta` | `consegne_scelta` `:27` | utente attivo | flusso **precedente** all'acconto: scelta su adesione `confermata` | `ordini.js:81` |
| GET `/consegne/costo` | `consegne_costo` `:16` | login | `{costo_spedizione}` | `ordini.js:231` |
| GET `/admin/consegne` | `consegne_admin_elenco` `:94` | admin | – | `admin/ordini.js:52` |
| POST `/consegne/{id}/spedisci` | `consegne_spedisci` `:125` | admin | solo domicilio; l'ordine fornitore deve essere `evaso`; notifica `SPEDITO` | `admin/ordini.js:37` |
| POST `/consegne/{id}/conferma-ricezione` | `consegne_conferma_ricezione` `:198` | `richiedi_login`, proprietario | `spedita`→`consegnata`, notifica `ORDINE_RICEVUTO` | **nessuno trovato** |

## 8. Ritiri e QR (`ritiro.php`)
| Rotta | Funzione | Auth | Note | Frontend |
|---|---|---|---|---|
| GET `/mie/assegnazioni/{id}/qr` | `ritiro_qr` `:7` | login, proprietario | `{id}` = id prenotazione; restituisce il token del QR | `qr-modal.js:9` |
| GET `/admin/ritiri` | `ritiri_elenco` `:37` | admin | il token è rimosso dalla risposta | `admin/ritiri.js:68` |
| POST `/ritiro/{token}` | `ritiro_conferma` `:75` | admin | transazione `FOR UPDATE`; QR → `scansionato`; notifica `RITIRATO`. Errori `TOKEN_NON_VALIDO` 404, `GIA_RITIRATO` 409 | `admin/ritiri.js:52` |

## 9. Pagamenti
### Wallet e statistiche (`pagamenti.php` – nome al plurale)
| Rotta | Funzione | Auth | Note | Frontend |
|---|---|---|---|---|
| GET `/wallet` | `wallet_dettaglio` `:17` | login | ultimi 50 pagamenti dell'utente | `wallet.js:27` |
| GET `/wallet/movimenti` | `wallet_movimenti` `:45` | login | alias identico di `/wallet` | `wallet.js:28` |
| GET `/wallet/{id}` | `wallet_dettaglio_utente` `:53` | admin | – | **nessuno trovato** |
| GET `/wallet/statistiche` | `wallet_statistiche` `:84` | admin | commissioni, addebiti, ordini in corso | `admin/dashboard.js:14` |

⚠ `pagamenti.php:130` usa la variabile `$commissione_mese` (non definita; quella calcolata è `$commissioni_mese`): il campo `commissioni_mese` restituisce sempre il totale. Il commento in testa al file cita rotte inesistenti (`POST /wallet/ricarica`, `GET /pagamenti/stats`).

### Stripe (`pagamento.php` – singolare; non è un duplicato di `pagamenti.php`)
| Rotta | Funzione | Auth | Parametri | Note / errori | Frontend |
|---|---|---|---|---|---|
| POST `/pagamento/acconto-checkout` | `pagamento_acconto_checkout` `:740` | utente attivo | `colletta_id`, `quantita` 1–99, `delivery{tipo,id_sede \| via,cap,citta,provincia}` | `CAMPAGNA_NON_APERTA`, `CAMPAGNA_SCADUTA`, `GIA_PARTECIPI`, `SEDE_NON_VALIDA`, `INDIRIZZO_MANCANTE`, `ACCONTO_MINIMO`, `STRIPE_ERRORE` | `dettaglio.js:848` |
| POST `/pagamento/acconto-intent` | `pagamento_acconto_intent` `:701` | utente attivo | come sopra | variante PaymentIntent ("fallback") | **nessuno trovato** |
| POST `/pagamento/aumenta-checkout` | `pagamento_aumenta_checkout` `:989` | `richiedi_cliente` | `prenotazione_id`, `quantita_aggiuntiva` | regole in `lib/aumento.php:37-58` | `components/aumenta-dialog.js:78` |
| POST `/pagamento/riprova` | `pagamento_riprova` `:1283` | utente attivo | `prenotazione_id` (stato `azione_richiesta`) | `STATO_NON_VALIDO`, `NESSUN_SALDO` | `acquisti.js:413`, `dashboard.js:29`, `ordini.js:101` |
| POST `/pagamento/checkout` | `pagamento_checkout` `:24` | utente attivo | `prenotazione_id` (stato `confermata`) | **flusso legacy** del saldo | `ordini.js:92` |
| GET `/pagamento/stato?session_id=` | `pagamento_stato` `:390` | login | – | "self-healing" dopo il redirect da Stripe; `NON_AUTORIZZATO` se la sessione è di un altro utente | `acquisti.js:442`, `dettaglio.js:914`, `pagamento-successo.js:40` |
| GET `/pagamento/config` | `pagamento_config` `:602` | login | – | `{publishable_key}` | **nessuno trovato** |
| POST `/pagamento/webhook` | `pagamento_webhook` `:116` | firma Stripe | eventi `checkout.session.completed` e `payment_intent.succeeded` | risponde 200 `{received:true}`; 400 su firma/payload non validi | chiamato da Stripe |

## 10. Proposte (`proposte.php`, `lib/proposte_regole.php`)
Costanti: soglia **5** voti, validità **30** giorni, massimo **5** proposte attive per utente.
| Rotta | Funzione | Auth | Note | Frontend |
|---|---|---|---|---|
| GET `/proposte` | `proposte_elenco` `:4` | login | le rifiutate sono visibili solo a proponente e admin | `admin/proposte.js:40,116` |
| GET `/proposte/bacheca` | `proposte_bacheca` `:204` | `richiedi_cliente` | `tab` votazione\|valutazione\|gruppi\|mie; `ordina`; restituisce anche il token `csrf` | `proposte.js:263`, `dashboard.js:388` |
| GET `/proposte/simili?q=` | `proposte_simili` `:135` | `richiedi_cliente` | q ≥ 3 caratteri; max 3 risultati | `proposte.js:410` |
| POST `/proposte` | `proposte_crea` `:57` | `richiedi_cliente` | multipart con `foto` (pipeline `lib/immagini.php`); lock `FOR UPDATE` sull'utente per il limite | `proposte.js:521` |
| POST `/proposte/{id}/vota` | `proposte_vota` `:182` | cliente + **CSRF** | un voto per utente; a 5 voti → `in_valutazione` | `proposte.js:228` |
| DELETE `/proposte/{id}/vota` | `proposte_ritira_voto` `:193` | cliente + CSRF | solo in votazione | `proposte.js:227` |
| PUT `/proposte/{id}/stato` | `proposte_cambia_stato` `:318` | admin | `pubblicata` crea prodotto+gruppo+scaglioni (`proposte_auto_crea` `:446`) e invia a **tutti** i clienti attivi la notifica `ORDINE_DISPONIBILE` | `admin/proposte.js:27,92` |

## 11. Notifiche (`notifiche.php`)
| Rotta | Funzione | Auth | Frontend |
|---|---|---|---|
| GET `/notifiche` | `notifiche_elenco` `:4` (ultime 50) | login | `notifiche.js:23`, `dashboard.js:387`, `fornitore.js:629` |
| GET `/notifiche/conta` | `notifiche_conta` `:21` | login | `components/header.js:280` |
| PUT `/notifiche/{id}/letta` | `notifiche_segna_letta` `:29` | login | `notifiche.js:54` |
| POST `/notifiche/marca-tutte-lette` | `notifiche_segna_tutte_lette` `:40` | login | `notifiche.js:81` |
| POST `/notifiche` | `notifiche_invia` `:48` | admin | `admin/notifiche.js:72` |

## 12. Fornitori – amministrazione e inviti (`fornitori.php`)
| Rotta | Funzione | Auth | Frontend |
|---|---|---|---|
| GET `/admin/fornitori` | `fornitori_admin_elenco` `:14` | admin | `admin/fornitori.js:120,211` e altre |
| POST `/admin/fornitori` | `fornitori_admin_crea` `:37` | admin | `admin/fornitori.js:188` |
| PUT `/admin/fornitori/{id}` | `fornitori_admin_aggiorna` `:74` | admin | `admin/fornitori.js:159` |
| POST `/admin/fornitori/{id}/invito` | `fornitori_invito_genera` `:108` | admin (token valido 7 giorni, email facoltativa) | `admin/fornitori.js:51` |
| POST `/admin/fornitori/{id}/collega` | `fornitori_collega` `:230` | admin | `admin/fornitori.js:109` |
| GET `/fornitori/invito/{token}` | `fornitori_invito_verifica` `:146` | pubblica | `fornitore-attiva.js:42` |
| POST `/fornitori/attiva` | `fornitori_attiva` `:163` | pubblica (token) – crea l'utente `fornitore` | `fornitore-attiva.js:88` |

## 13. Area fornitore (`fornitori.php:282-607`)
| Rotta | Funzione | Auth | Frontend |
|---|---|---|---|
| GET/PUT `/fornitore/io` | `fornitore_io` `:282`, `fornitore_io_aggiorna` `:302` | `richiedi_fornitore` | `fornitore.js:625`, `profilo.js:31,117` |
| POST `/fornitore/proposte` | `fornitore_proposte_crea` `:362` | fornitore attivo | `fornitore.js:363` |
| GET `/fornitore/proposte` | `fornitore_proposte_mie` `:423` | fornitore | `fornitore.js:626` |
| GET `/fornitore/campagne` | `fornitore_campagne` `:467` | fornitore | `fornitore.js:627` |
| GET `/fornitore/ordini` | `fornitore_ordini` `:497` | fornitore | `fornitore.js:628` |
| POST `/fornitore/ordini/{id}/avanza` | `fornitore_ordine_avanza` `:525` | fornitore attivo; `inviato→ricevuto→in_preparazione→evaso`; all'ultimo passo il gruppo diventa `consegnata` e parte `MERCE_PRONTA` + email "pronto per il ritiro" | `fornitore.js:379` |

## 14. Recensioni (`recensioni.php`)
| Rotta | Funzione | Auth / regola | Frontend |
|---|---|---|---|
| GET `/fornitori/{id}/recensioni` | `recensioni_elenco` `:33` | pubblica | `fornitori-dettaglio.js:33`, `ordini.js:30,137` |
| POST `/fornitori/{id}/recensioni` | `recensioni_salva` `:83` | utente attivo con adesione `pagata` presso il fornitore; voto 1–5, testo ≤ 1000; upsert | `fornitori-dettaglio.js:172`, `ordini.js:199`, `recensioni-popup.js:84` |
| DELETE `/fornitori/recensioni/{id}` | `recensione_elimina` `:106` | autore o admin | `fornitori-dettaglio.js:183` |
| GET `/campagne/{id}/recensioni` | `recensioni_campagna_elenco` `:149` | pubblica | `dettaglio.js:981`, `ordini.js:36,138` |
| POST `/campagne/{id}/recensioni` | `recensioni_campagna_salva` `:199` | adesione `pagata` + consegna/ritiro avvenuto | `ordini.js:194`, `recensioni-popup.js:84` |
| DELETE `/campagne/recensioni/{id}` | `recensione_campagna_elimina` `:222` | autore o admin | **nessuno trovato** |

## 15. Sviluppo
`GET /_dev/mail-test` (`dev.php:19`): consentita solo da `127.0.0.1`/`::1` e con `mail_enabled`; sempre registrata, anche in produzione; il commento dice di rimuoverla prima del merge in main.

## 16. Rotte senza chiamante nel frontend
`GET /salute`, `POST /logout`, `GET /mie/statistiche`, `POST /campagne/chiusura` (variante cron), `GET /campagne/{id}/assegnazioni`, `GET /wallet/{id}`, `POST /consegne/{id}/conferma-ricezione`, `GET /pagamento/config`, `POST /pagamento/acconto-intent`, `GET|POST /admin/prodotti/{id}/immagini`, `DELETE /campagne/recensioni/{id}`, `GET /_dev/mail-test`, `POST /campagne/{id}/partecipazioni` (410) e il webhook (chiamato da Stripe). Classificazione per certezza e rischio in `12-audit-ridondanze.md`.

## Domande che potrebbero farmi
1. **Come è fatto il router?** Una tabella di rotte in `api/index.php:68-167` e una funzione `abbina` che confronta i segmenti; `{id}` solo numerico (`:177-179`). Nessun framework.
2. **Che formato hanno le risposte?** Sempre `{ok:true,dati}` o `{ok:false,errore:{codice,messaggio}}` (`risposta.php:27-45`), tranne il webhook Stripe.
3. **Come funziona l'autenticazione?** Sessione PHP con cookie; l'header `Authorization` inviato dal frontend non viene letto.
4. **Quali endpoint sono pubblici?** `GET /campagne`, `GET /campagne/{id}`, recensioni (GET), login/registrazione, reset password, inviti fornitore, `salute`.
5. **Dove si crea un'adesione?** Non in un endpoint "aderisci" (disabilitato, 410) ma nel webhook Stripe dell'acconto (`pagamento.php:793`).
6. **Perché `pagamento.php` e `pagamenti.php`?** Il primo è Stripe e chiusura saldi, il secondo wallet e statistiche: nomi quasi uguali ma contenuti diversi.
7. **Come proteggete le rotte admin?** Con `sono_admin()` inline in ogni funzione (non c'è un middleware), quindi un ospite riceve 403 e non 401.
8. **Quali rotte non servono?** Vedi §16 e l'audit.
