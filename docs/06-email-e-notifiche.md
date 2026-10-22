# 06 – Email e notifiche

## A cosa serve
Spiega **come e quando BuyPool avvisa l'utente**: le email transazionali (SMTP via PHPMailer), le notifiche
dentro il sito (tabella `notifiche`) e il meccanismo che impedisce di inviare due volte lo stesso messaggio
(tabella `email_inviate`). Ogni evento è associato al file e alla funzione che lo produce. Serve a rispondere
a domande tipo "cosa succede se il webhook arriva due volte? parte due volte l'email?".
I riferimenti `file:riga` si riferiscono al working tree attuale.

---

## 1. Come si inviano le email
| Aspetto | Valore | Dove |
|---|---|---|
| Libreria | **PHPMailer 6.9.3 incluso a mano** in `api/lib/PHPMailer/` (non via composer) | `api/lib/mail.php:20-29` |
| Punto unico di invio | `invia_email($to, $oggetto, $html, $testo='')` – non lancia mai eccezioni, ritorna `true/false` | `mail.php:95-128` |
| Regola di progetto | "TUTTE le email devono passare da `invia_email()`" (mai `mail()`) | `mail.php:9-11` |
| Interruttore | `mail_enabled` (se `false`: nessun tentativo SMTP, ritorna `false`) | `mail.php:44,59-62,102` |
| Variabili di configurazione (solo nomi) | `mail_enabled, mail_from, mail_from_nome, smtp_host, smtp_port, smtp_auth, smtp_user, smtp_pass, smtp_secure, frontend_url` | `mail.php:35-56`, modello `api/config.example.php:29-37` |
| Ambiente di sviluppo | SMTP locale **Mailpit** su `127.0.0.1:1025`, senza TLS | `mail.php:6,47-49`, `config.example.php:32-37` |
| Produzione | `smtp_secure = 'tls'` (esempio nel commento: Brevo su 587) | `mail.php:52` |
| Timeout | 5 secondi | `mail.php:85` |
| Destinatario non valido / oggetto vuoto | log + `false` | `mail.php:97-101,104-107` |
| Testo alternativo | ricavato dall'HTML (`html_para_testo`) | `mail.php:108-110,131-138` |

### Template (tutti in `api/lib/mail.php`)
| Funzione | Uso | Riga |
|---|---|---|
| `mail_template_layout` | cornice comune: barra verde "BuyPool", titolo, piè di pagina "Email automatica…" | 141 |
| `mail_template_invito_fornitore` | invito ad attivare l'account fornitore (link valido 7 giorni, monouso) | 164 |
| `mail_template_reset_password` | reset password (24 ore, monouso) | 183 |
| `mail_template_notifica` | replica via email di una notifica scritta da un admin | 203 |
| `mail_template_evento` | paragrafi + tabella chiave/valore + pulsante: usato da **tutte** le email di evento (pagamenti, rimborsi, gruppi) | 214 |

Le email di evento sono costruite in `api/lib/notifiche_eventi.php` (funzione `evento_email`, `:196`) e hanno
l'input utente **escapato** in `mail_template_evento` (`mail.php:216`). Le email non sono tradotte: sono in italiano nel codice.

> Osservazione di stile (non un errore): il colore del pulsante nelle email di evento è `#0262BB` (`mail.php:227`), mentre
> il layout e le email di invito/reset usano il verde `#1a7a4a` (`mail.php:152,172,191`): due identità visive diverse.

## 2. Il meccanismo anti-duplicati (`email_inviate`)
Il cuore è `evento_emetti()` (`notifiche_eventi.php:53-79`):

1. Inserisce una riga con `INSERT IGNORE INTO email_inviate (evento, riferimento_tipo, riferimento_id, id_utente, …)`.
2. La tabella ha una **chiave UNIQUE** `(evento, riferimento_tipo, riferimento_id, id_utente)` (`api/schema.sql` sezione "EMAIL_INVIATE"; stessa definizione in `api/migrations/2026-10-21_email_notifiche.sql:18`).
3. Se la riga esiste già, `rowCount() !== 1` → la funzione ritorna `false` e **non succede nulla** (né email, né notifica) – `:63`.
4. Se è nuova: crea l'eventuale notifica (`:66-72`) e, se c'è un'email, la mette **in coda** (`stato = 'in_coda'`) e pianifica l'invio.
5. La riga di registro e la notifica nascono **nella stessa transazione** dell'evento di business: se la transazione fa rollback, spariscono entrambe (`notifiche_eventi.php:11-12`).
6. L'invio vero parte **a transazione conclusa**: `register_shutdown_function` → `email_flush()` (`:82-96`), oppure dal cron (`email_flush($pdo)` in `scripts/saldi_scaduti.php:101`).
7. `email_flush` (`:103-135`): marca `scartata` le email in coda da più di **72 ore** (`:107`); se `mail_enabled` è falso non invia (`:108`); per ogni email chiama `invia_email`; se va bene `stato='inviata'`, altrimenti incrementa `tentativi` e riprova al giro successivo.
8. Nessuna eccezione esce da questo modulo: errori → `error_log` (`:75-78`, `:131-133`).

**Conseguenza:** webhook ripetuti, cron doppi o richieste ripetute **non duplicano le email di evento**. Stati possibili di `email_inviate.stato`: `in_coda`, `inviata`, `solo_notifica`, `scartata`.

**Limiti noti** (dettagli in `13-anomalie-e-rischi.md`):
- Se la tabella `email_inviate` non esiste (migrazione non applicata) `evento_emetti` fallisce in silenzio **prima** di creare la notifica: per gli eventi con notifica propria (es. `RIMBORSO`) non esce né l'email né la notifica (`:56-78`). La migrazione `2026-10-21_email_notifiche.sql` va applicata a mano (`:2-4` del file).
- Le email di **invito fornitore**, **reset password**, **notifica admin** e **prova** (`/_dev/mail-test`) **non passano dal registro**: sono inviate subito e una sola volta per richiesta (vedi §4).
- Se `mail_enabled=false` le email restano in coda e dopo 72 ore diventano `scartata`: nessuna email "recuperabile" a posteriori.

## 3. Tabella eventi → email → notifica → idempotenza
Chiave di idempotenza = `(evento, riferimento_tipo, riferimento_id, utente)`. "Notifica" = riga in `notifiche`.

| # | Evento (codice `evento`) | Oggetto email | Destinatario | Quando | Chi lo emette (file:riga) | Notifica sul sito | Idempotenza |
|---|---|---|---|---|---|---|---|
| 1 | **Adesione confermata** (`ADESIONE`) | "Adesione confermata: *prodotto*" | l'aderente | dopo il webhook dell'acconto | `evento_adesione` (`notifiche_eventi.php:204`), chiamata da `pagamento.php:884` | `ORDINE_CONFERMATO` "Acconto addebitato" creata direttamente (`pagamento.php:880-883`) | UNIQUE su `(ADESIONE, prenotazione, id)` |
| 2 | **Quantità aumentata** (`AUMENTO`) | "Quantità aumentata: *prodotto*" | l'aderente | dopo il webhook dell'aumento | `evento_aumento` (`:230`), da `pagamento.php:1164` | `ORDINE_CONFERMATO` "Quantità aumentata" (`pagamento.php:1160-1163`) | UNIQUE su `(AUMENTO, pagamento, id riga acconto)` – una per ogni aumento |
| 3 | **Minimo raggiunto** (`MINIMO_RAGGIUNTO`) | "Il gruppo è attivo: *prodotto*" | tutti gli aderenti | quando una nuova adesione/aumento porta il gruppo al minimo | `evento_minimo_raggiunto` (`:252`), da `gruppo_applica_minimo_raggiunto` (`pagamento.php:935`) | `MOQ_RAGGIUNTO` "Minimo raggiunto" (`pagamento.php:910-921`); al fornitore la stessa notifica con testo diverso (`:930`) | UNIQUE su `(MINIMO_RAGGIUNTO, colletta, id)` per utente |
| 4 | **Saldo addebitato** (`SALDO_ADDEBITATO`) | "Saldo addebitato: *prodotto*" (con righe: prezzo, commissione, spedizione, acconto, totale, risparmio) | l'aderente | quando il saldo va a buon fine (chiusura, webhook saldo, riprova) | `evento_saldo_addebitato` (`:282`), da `saldo_completa` (`pagamento.php:1263`), webhook legacy (`:293`), `pagamento_stato` (`:522`) | `PAGAMENTO_RIUSCITO` "Saldo addebitato" (`pagamento.php:1259-1262`) | UNIQUE su `(SALDO_ADDEBITATO, prenotazione, id)` |
| 5 | **Saldo non riuscito** (`SALDO_FALLITO`) | "Pagamento del saldo non riuscito: *prodotto*" | l'aderente | quando l'addebito off_session fallisce | `evento_saldo_fallito` (`:318`), da `segna_azione_richiesta` (`campagne.php:554`) | `PAGAMENTO_FALLITO` "Azione richiesta" (`campagne.php:550-553`) | UNIQUE su `(SALDO_FALLITO, prenotazione, id)` |
| 5b | **Promemoria saldo** (`SALDO_PROMEMORIA`) | "Ultime ore per pagare il saldo" | l'aderente | negli ultimi 24 h del termine di 48 h | `evento_saldo_promemoria` (`:342`), da `scripts/saldi_scaduti.php:51` | nessuna | UNIQUE → una sola volta anche se il cron gira ogni ora |
| 6 | **Rimborso automatico** (`RIMBORSO`) | "Acconto rimborsato: *prodotto*" | l'aderente | gruppo non raggiunge il minimo / annullato | `evento_rimborso` (`:365`), da `chiusura_rimborsi` (`campagne.php:530`) | `RIMBORSO` "Acconto rimborsato" (creata dentro `evento_emetti`) | UNIQUE su `(RIMBORSO, prenotazione, id)` |
| 6b | **Adesione annullata per saldo non pagato** (`ADESIONE_ANNULLATA_SALDO`) | "Adesione annullata: pagamento del saldo non riuscito" | l'aderente | scaduto il termine di 48 h | `evento_adesione_annullata_saldo` (`:392`), da `scripts/saldi_scaduti.php:77` | `RIMBORSO` | UNIQUE |
| 7 | **Adesione annullata dal cliente** (`ADESIONE_ANNULLATA_CLIENTE`) | "Adesione annullata: *prodotto*" | l'aderente | quando annulla prima della scadenza | `evento_adesione_annullata_cliente` (`:414`), da `partecipazioni.php:77` | `RIMBORSO` | UNIQUE su `(…, prenotazione, id)`; ⚠ l'adesione viene **cancellata** (`DELETE`), l'id non verrà riusato (AUTO_INCREMENT) |
| 8 | **Pronto per il ritiro** (`RITIRO_PRONTO`) | "Pronto per il ritiro: *prodotto*" | aderenti `pagata` con ritiro in sede | quando il fornitore porta l'ordine a `evaso` | `evento_ritiro_pronto` (`:434`), da `fornitori.php:575` | `MERCE_PRONTA` "Merce Consegnata" (`fornitori.php:565-572`) | UNIQUE |
| 9 | **Ordine inoltrato al fornitore** (`ORDINE_INOLTRATO`) | "Ordine inoltrato al fornitore: *prodotto*" | tutti gli aderenti | quando l'admin (o il sistema) invia l'ordine | `evento_ordine_inoltrato` (`:463`), da `invia_ordine_fornitore_al` (`campagne.php:874`) | `ORDINE_INVIATO` "Ordine Inviato" (`campagne.php:866-873`) | UNIQUE su `(…, colletta, id)` |
| 10 | **Prezzo sceso** (`PREZZO_SCESO:<n>`) | **nessuna email** (solo notifica) | tutti gli aderenti | quando una nuova adesione/aumento fa scattare uno scaglione dal secondo in poi | `evento_prezzo_sceso` (`:487`), da `pagamento.php:889,1165` | `NUOVO_SCAGLIONE` "Il prezzo è sceso" | UNIQUE per scaglione (il numero è nel nome dell'evento) |

**Eventi attesi dalla richiesta → stato nel codice:** adesione confermata ✔ (1), quantità aumentata ✔ (2), minimo raggiunto ✔ (3),
saldo addebitato ✔ (4), saldo non riuscito ✔ (5), rimborso automatico ✔ (6, 6b), adesione annullata ✔ (6b, 7), pronto per il ritiro ✔ (8).
**Nessuno manca.** Altri eventi con email NON presenti: spedizione partita (`SPEDITO`, solo notifica – `consegne.php:177`), ordine ricevuto (`ORDINE_RICEVUTO`, solo notifica – `consegne.php:239`), ritiro confermato (`RITIRATO`, solo notifica – `ritiro.php:133`), soglia persa (`SOGLIA_PERSA`, solo notifica – `partecipazioni.php:103`).

### Email che NON passano da `email_inviate`
| Email | Dove | Quando |
|---|---|---|
| Invito attivazione fornitore | `api/endpoints/fornitori.php:140` | l'admin genera l'invito (`POST /admin/fornitori/{id}/invito`) |
| Reset password | `api/endpoints/password.php:71` | richiesta pubblica o link generato da admin |
| Notifica replicata via email | `api/endpoints/notifiche.php:77-85` | l'admin spunta "invia email" |
| Prova SMTP | `api/endpoints/dev.php:34` (`GET /_dev/mail-test`) | solo sviluppo – DA VERIFICARE se è protetta in produzione (vedi 08) |

## 4. Notifiche sul sito
### Tabella `notifiche` (`api/schema.sql`, sezione 17)
- Colonne: `id`, `id_utente` (FK `ON DELETE CASCADE`), `tipo` (ENUM), `titolo`, `messaggio`, `tipo_riferimento` (`colletta|prenotazione|proposta`), `id_riferimento`, `letta`, `data_creazione`, `data_lettura`. Indice `idx_notifiche_utente_letta (id_utente, letta)`.
- **Tipi (ENUM, 18)**: `SCADENZA, ORDINE_DISPONIBILE, ORDINE_INVIATO, RITIRATO, PROPOSTA_APPROVATA, PAGAMENTO_RIUSCITO, PAGAMENTO_RICEVUTO, PAGAMENTO_FALLITO, RIMBORSO, MOQ_RAGGIUNTO, ORDINE_CONFERMATO, MERCE_PRONTA, SPEDITO, NUOVO_SCAGLIONE, TRACCIAMENTO, SISTEMA, ORDINE_RICEVUTO, SOGLIA_PERSA`. La stessa lista è ripetuta come whitelist in `api/endpoints/notifiche.php:59-62` (duplicazione da tenere allineata).
- Tipi **mai creati** dal codice trovato: `SCADENZA`, `PROPOSTA_APPROVATA`, `TRACCIAMENTO` (DA VERIFICARE con grep in audit 12).

### Come nascono
Due modi coesistono:
1. **Inserimento diretto** `INSERT INTO notifiche …` sparso in 12 file (`pagamento.php`, `campagne.php`, `consegne.php`, `fornitori.php`, `password.php`, `profilo.php`, `proposte.php`, `ritiro.php`, `partecipazioni.php`, `assegnazioni.php`, `notifiche.php`, `proposte_regole.php`) – non idempotente da solo: è protetto dalla transazione/idempotenza dell'operazione che lo contiene.
2. **Attraverso `evento_emetti`** (solo per gli eventi nuovi con notifica propria: rimborsi, prezzo sceso) – idempotente.

Questa duplicazione (24 punti di `INSERT INTO notifiche`) è un candidato alla centralizzazione (audit 12).

### Come si leggono
| Rotta | Funzione | Cosa fa |
|---|---|---|
| `GET /notifiche` | `notifiche_elenco` (`notifiche.php:4-19`) | ultime **50** dell'utente |
| `GET /notifiche/conta` | `notifiche_conta` (`:21-27`) | numero non lette (badge nell'header) |
| `PUT /notifiche/{id}/letta` | `notifiche_segna_letta` (`:29-38`) | segna una come letta (filtra per `id_utente`) |
| `POST /notifiche/marca-tutte-lette` | `notifiche_segna_tutte_lette` (`:40-46`) | segna tutte |
| `POST /notifiche` | `notifiche_invia` (`:48-87`) | **solo admin**: invia una notifica (con email opzionale) |

Il frontend le mostra in `frontend/js/pages/notifiche.js` e nell'header; la mappa tipo→icona/etichetta è in `frontend/js/notifiche-tipo.js` (dettagli in `07-frontend.md`).

## 5. Esempio: cosa succede con un webhook doppio
1. Stripe invia `payment_intent.succeeded` due volte per lo stesso acconto.
2. Il secondo è fermato da `eventi_stripe.elaborato` (`pagamento.php:148`) oppure da `pagamenti.stripe_payment_intent_id` (`:814`): `webhook_acconto_crea` esce prima di toccare nulla.
3. Anche se per assurdo arrivasse a `evento_adesione`, l'`INSERT IGNORE` su `email_inviate` darebbe `rowCount()=0` e non partirebbe nessuna seconda email (`notifiche_eventi.php:63`).

## Domande che potrebbero farmi
1. **Come evitate di mandare due volte la stessa email?** Con la tabella `email_inviate` e la chiave UNIQUE `(evento, tipo rif., id rif., utente)`: l'`INSERT IGNORE` dice se l'evento è nuovo (`notifiche_eventi.php:58-63`).
2. **Perché l'email parte "dopo"?** L'email si accoda nella stessa transazione ma si invia a transazione conclusa (`register_shutdown_function` / cron), così un errore SMTP non può rovinare un pagamento (`:13-16,82-96`).
3. **Cosa succede se il server SMTP è spento?** `invia_email` ritorna `false`, l'email resta `in_coda` e viene ritentata; dopo 72 ore è `scartata` (`:107`).
4. **Che libreria usate?** PHPMailer incluso a mano in `api/lib/PHPMailer/` (nel commento: perché la rete di sviluppo bloccava Packagist – `mail.php:20-25`).
5. **Che differenza c'è tra notifica ed email?** La notifica è una riga in `notifiche` visibile nel sito; l'email è un canale in più. Il commento di `mail.php:17-18` dice che le notifiche DB sono la "source of truth" e l'email è best-effort.
6. **Quali eventi generano email?** 11 eventi (tabella §3); "prezzo sceso" ha solo la notifica.
7. **Come si provano le email in locale?** Con Mailpit (`127.0.0.1:1025`) e `mail_enabled=true`; esiste anche una rotta di prova `/_dev/mail-test`.
8. **Cosa serve per far funzionare le email di evento?** La migrazione `2026-10-21_email_notifiche.sql` (tabella `email_inviate` e colonna `prenotazioni.saldo_scadenza`) applicata a mano, e un cron orario su `scripts/saldi_scaduti.php`.
