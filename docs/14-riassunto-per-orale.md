# 14 – Riassunto per l'orale (5 minuti)

## A cosa serve
Una pagina per spiegare BuyPool **a voce**: cos'è, come è fatto, il ciclo di un gruppo, i soldi, le email, il database,
tre scelte progettuali e dieci domande difficili con risposta. Ogni affermazione rimanda ai documenti 01–13, dove
c'è il `file:riga`. Dove la motivazione di una scelta non è scritta nel codice è indicato "motivazione presunta".

---

## Cos'è (30 secondi)
BuyPool è una piattaforma di **acquisti di gruppo**: un admin (o una proposta votata dagli utenti) apre un *gruppo* su un prodotto con una scadenza, un *minimo* e degli *scaglioni di prezzo*. I clienti aderiscono pagando un **acconto**; se il gruppo raggiunge il minimo, alla scadenza si addebita automaticamente il **saldo** al prezzo finale; altrimenti si **rimborsa**. Poi l'ordine va al fornitore e il cliente ritira con un QR o riceve la spedizione.

## Architettura in 6 punti
1. **SPA** in JavaScript vanilla (moduli ES, router a hash, nessun build) → `07-frontend.md`.
2. **API PHP 8.3 senza framework**: un front controller (`api/index.php`) con 101 rotte e 20 file di endpoint → `03`.
3. **MySQL** (23 tabelle, accesso solo con PDO e query preparate) → `02`.
4. **Logica di business in funzioni pure** (`api/lib/prezzi.php`, `stato.php`, `aumento.php`, `acquisti.php`), testate con script PHP/Node → `01`.
5. **Servizi esterni**: Stripe (pagamenti e webhook), Google/Microsoft (login), SMTP/PHPMailer (email) → `05`, `06`, `08`.
6. **Processi periodici**: chiusura dei gruppi, saldi scaduti (24 h/48 h), scadenza proposte, pulizia foto (cron/CLI) → `04`, `00`.

## Il ciclo di un gruppo
Creazione (admin o proposta con 5 voti) → `in_corso` → minimo raggiunto (`riuscita`, le adesioni diventano *confermate*) → scadenza → **chiusura**: se il minimo c'è, addebito dei saldi e `ordine_pronto`; se non c'è, rimborso (*"Non attivato"*) → ordine al fornitore → `consegnata` → ritiro con QR. Stati e transizioni: `04-logica-gruppi.md` §9.

## I soldi
| Voce | Regola | Dove |
|---|---|---|
| Acconto | 20 % × prezzo del **primo scaglione** × quantità, minimo €0,50, addebitato subito (non è una preautorizzazione) | `pagamento.php:685` |
| Saldo | `merce al prezzo finale + commissione + spedizione − acconto`, ogni voce arrotondata al centesimo | `prezzi.php:17-28` |
| Commissione | % del gruppo (default 10) sul totale merce | `campagne.php:679` |
| Rimborso | totale degli acconti, con chiave di idempotenza; se il gruppo non parte, se il saldo non è pagato entro 48 h o se l'utente annulla | `pagamento.php:943` |
| Carta | salvata su Stripe con `setup_future_usage=off_session` e riusata per il saldo (`off_session:true`) | `pagamento.php:775`, `campagne.php:454` |
Esempio: 3 pezzi, scaglioni 10→€25 e 15→€22, commissione 10 %: acconto €15,00; a 16 pezzi saldo **€57,60** (€62,50 con spedizione); sotto i 10 pezzi rimborso di €15,00 → `05-pagamenti.md` §5.

## Le email
Tutte passano da `invia_email()` (PHPMailer, Mailpit in sviluppo). Gli 11 eventi (adesione, aumento, minimo, saldo, saldo fallito, promemoria, rimborso, annullamenti, ritiro pronto, ordine inoltrato) sono **idempotenti** grazie alla tabella `email_inviate` con chiave UNIQUE e partono dopo la transazione → `06`.

## Il database in 8 tabelle
`utenti` · `fornitori` · `prodotti` · `collette` (= gruppi) · `scaglioni_prezzo` · `prenotazioni` (= adesioni) · `pagamenti` · `notifiche` (più `consegne`, `qr_codes`, `ordini_fornitore`, `proposte_prodotti`, `voti_proposte`, `eventi_stripe`, `email_inviate`…). Vincoli chiave: una adesione per utente e gruppo (UNIQUE), un pagamento per PaymentIntent (UNIQUE) → `02`.

## Tre scelte progettuali
1. **PHP e JavaScript senza framework né build.** *Motivazione presunta:* semplicità, nessuna dipendenza da installare, controllo totale; costo: routing, validazione e sicurezza fatti a mano (e alcune lacune, `08`).
2. **Adesione creata dal webhook, non dal browser.** Il codice lo dichiara (`partecipazioni.php:4-12`): la verità sul pagamento arriva da Stripe; `GET /pagamento/stato` ricrea l'adesione se il webhook è in ritardo. *Motivazione:* non fidarsi del client.
3. **Funzioni pure per formule e stati, con test.** `saldo_dettaglio` è l'unica formula del saldo; il frontend ne ha una copia solo per le stime. *Motivazione presunta:* evitare incoerenze fra importi mostrati e addebitati (il commento di `prezzi.php:10-13` lo dice per le voci).

## Dieci domande difficili
1. **Perché non una preautorizzazione?** Dura pochi giorni e i gruppi durano settimane; si addebita un acconto vero e si salva la carta.
2. **E se la carta fallisce al saldo?** 48 ore per aggiornarla (`azione_richiesta`), promemoria a 24 h, poi rimborso e adesione annullata.
3. **Come evitate doppi pagamenti/adesioni?** `eventi_stripe`, UNIQUE sul PaymentIntent e su (gruppo, utente), lock `FOR UPDATE`; sulla chiusura manca però una chiave di idempotenza Stripe (rischio A3 in `13`).
4. **Cosa succede se il webhook arriva due volte o in ritardo?** Il secondo è ignorato; se in ritardo oltre la scadenza l'acconto viene rimborsato.
5. **Quanto guadagna BuyPool?** La commissione % sul totale merce; i soldi restano sul conto Stripe di BuyPool (nessun Connect nel codice).
6. **Cosa non è sicuro oggi?** Il login Microsoft non verifica la firma, l'XSS nelle pagine admin, l'assenza di rate limiting (`13` A1, A2).
7. **Perché due nomi (gruppo/colletta)?** Rinomina di linguaggio senza toccare schema e rotte; glossario in `10`.
8. **Come si chiude un gruppo?** Cron/admin su `POST /campagne/chiusura`: addebita i saldi o rimborsa; `chiusura_data` evita la doppia chiusura (con un limite di concorrenza).
9. **Come testate?** Test standalone su funzioni pure (saldo, stati, aumento, formati) e qualche prova su DB; mancano test di concorrenza e di sicurezza.
10. **Cosa migliorereste per primo?** Verifica JWT Microsoft, escape XSS centralizzato, idempotenza della chiusura, stato `annullata` al posto del `DELETE`, pulizia dei file morti (`12`).

---

## Comportamenti da replicare nel porting
Esiste un **piano di riscrittura** verso Symfony 7 + Azure (`_private/legacy/05-rewrite-plan.md`, ignorato da git); **nel repository non c'è codice Symfony**. Se il porting segue questo progetto, la versione legacy ha questi comportamenti non ovvi, da replicare (o da decidere consapevolmente di cambiare):
- **Arrotondamenti:** ogni voce del saldo è arrotondata a 2 decimali *prima* di sommare (`prezzi.php:19-26`); l'acconto è `round(qtà × primo prezzo × %/100, 2)` sul totale, non per pezzo; gli importi a Stripe sono `(int)round(x*100)`.
- **Acconto sul primo scaglione**, non sul prezzo corrente o sul listino; l'aumento usa la stessa formula; minimo €0,50 sia per acconto sia per il saldo (riprova: `max(0.50, saldo)`).
- **Commissione sulla merce totale**, non sul saldo; spedizione nel saldo, **non** nell'acconto.
- **Minimo = soglia del primo scaglione** (se esistono scaglioni), altrimenti `quantita_minima`; ricalcolo dello stato "da zero" a ogni modifica, con `FOR UPDATE`.
- **Stati:** `prenotata → confermata` quando il minimo è raggiunto; la chiusura addebita solo le `confermata`; adesioni create dopo il minimo nascono `confermata`; sotto il minimo (annullamento) le `confermata` tornano `prenotata`.
- **Ordine delle operazioni:** adesione creata *solo* dal webhook; nel webhook controllo idempotenza → controlli di validità (gruppo aperto, non scaduto, non già iscritto) → creazione; se non valido, **rimborso automatico**; email e notifica nella stessa transazione, invio dopo il commit.
- **Idempotenza:** `eventi_stripe` (evento), UNIQUE su `stripe_payment_intent_id`, UNIQUE su (gruppo, utente), `email_inviate` UNIQUE (evento, tipo rif., id rif., utente), `idempotency_key = rimborso_<PI>`, `chiusura_data`.
- **Saldo non riuscito:** 48 ore (`SALDO_TERMINE_ORE`), promemoria alle ultime 24 h, poi rimborso e rimozione dei pezzi dal gruppo; email "Adesione annullata".
- **Rimborsi:** totali, su tutti gli acconti di un'adesione (acconto iniziale + aumenti); "already refunded" è considerato successo.
- **Display dei gruppi** non salvato: `in_corso | in_chiusura | conclusa | non_riuscita` derivati da stato, scadenza, minimo e `chiusura_data`.
- **Sede predefinita** = la prima sede; `ritiro in sede` sempre disponibile; la spedizione costa `costo_spedizione` salvato in `consegne.importo_consegna`.
- **Da NON replicare** (difetti noti): firma JWT non verificata, annullamento via `DELETE` che cancella lo storico pagamenti, `prezzo_corrente` stantio negli importi dell'ordine, regola "non si annulla dopo il minimo" solo lato UI (`13`).
