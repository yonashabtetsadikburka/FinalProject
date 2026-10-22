# I miei acquisti: checklist test manuale

Prerequisiti: Apache e MySQL avviati da MAMP; dopo ogni modifica ai CSS ricarica con `Ctrl+F5`.
Verifiche automatiche già pronte:
`php tests/acquisti_test.php`, `node tests/acquisti_front_test.mjs`, `node tests/format_test.mjs`, `node tests/stato_percorso_test.mjs`.
Utenti di prova nel DB di sviluppo: `nuovo@test.it` (nessun dato), `voter@test.it` (id 4: gruppi attivi, 1 ritiro pronto, 3 consegnati), `test@test.it`, `yonashabtetsadikburka@gmail.com`; più un fornitore e un admin.

## 1. Menu e redirect
1. Da cliente il menu ha UNA sola voce "Acquisti" (niente "Partecipazioni" né "Ordini"), evidenziata su `#/acquisti`, anche con `?tab=...`.
2. `#/partecipazioni` → `#/acquisti?tab=in-corso`; `#/ordini` → `#/acquisti?tab=in-arrivo` (senza lampeggi né loop).
3. `#/ordini?id=<numero ordine tuo>` apre la scheda che contiene l'acquisto e lo evidenzia; con l'id di un acquisto altrui apre la scheda predefinita senza errori.
4. Dettaglio gruppo (stato "Hai aderito"): link "I miei acquisti"; dashboard: "Tutti i miei acquisti" (3 punti); pagina di conferma pagamento: "I miei acquisti".
5. Da fornitore e da admin il menu e le pagine non cambiano.

## 2. Testata e schede
1. Frase: "Hai 2 gruppi in corso e 1 ritiro pronto." (omette il valore 0; senza acquisti "Non hai ancora acquisti.").
2. Pillole con contatori; clic su una scheda: l'indirizzo cambia (`?tab=`), il refresh mantiene la scheda, il link copiato in un'altra finestra si apre sulla stessa scheda.
3. Scheda predefinita: In corso; se vuota e In arrivo no, In arrivo; altrimenti la prima non vuota.
4. "Ordina": in In corso "Scadenza più vicina" (predefinito) e "Più recenti"; nelle altre schede solo "Più recenti". Valori strani in `?sort=` o `?tab=` ricadono sul predefinito.
5. Scheda vuota: messaggio breve + "Esplora i gruppi".

## 3. Schede per stato
- **Gruppo in corso**: "N / MIN pz", "mancano X pezzi per attivare il gruppo", barra a un solo colore, scadenza "Scade tra 6g 20h"; acconto versato ("20% del prezzo di gruppo") e saldo previsto con data; "Invita amici" (WhatsApp/Telegram/Copia link/Altro, secondo clic chiude); link grigio "Annulla adesione" → conferma → acconto rimborsato, la scheda sparisce.
- **Gruppo attivo** (minimo raggiunto): nessun "Annulla adesione", al suo posto la nota col lucchetto; messaggio "prezzo di gruppo sbloccato · a N pezzi scende ancora" oppure "Prezzo più basso raggiunto".
- **Pagamento non riuscito** (prenotazione `azione_richiesta`): nota ambra e "Aggiorna il pagamento" che porta al checkout Stripe.
- **Ordine inviato al fornitore**: nota, totale pagato e risparmiato (se esiste il listino), nessuna azione.
- **Pronto per il ritiro**: solo quando il gruppo è `consegnata` e il QR è generato; "Mostra QR" apre il QR; la striscia mostra "Ritiro <sede>". In cima alla scheda In arrivo.
- **Ritirato / Consegnato**: nota, totali, pulsante "Lascia una recensione sul prodotto" → finestra → invio → toast → la scheda mostra "Hai recensito" (se manca solo il fornitore: "…sul fornitore").
- **Rimborsato**: motivazione ("…entro la scadenza (12/10/2026): 3 pezzi su 6 prenotati."), "Acconto rimborsato", "Vedi altri gruppi". Se il rimborso non è ancora stato eseguito: "Acconto in rimborso" e "Rimborso in elaborazione".

Per simulare gli stati: `UPDATE prenotazioni SET stato='…' WHERE id=…` e `UPDATE collette SET stato='…'` su dati di PROVA (non su dati reali).
Rimborso: `UPDATE collette SET data_limite = NOW() - INTERVAL 1 DAY WHERE id=…` con quantità sotto il minimo, poi lancia la chiusura (`POST /campagne/chiusura?secret=…`).

## 4. Sicurezza
1. `GET /api/mie/acquisti` senza login → 401; da fornitore/admin → 403.
2. Con l'utente A, `?id=` di un acquisto dell'utente B non lo mostra e non rivela la sua scheda.
3. Testi con `<script>` o `"` nel nome prodotto compaiono come testo (nessuna esecuzione).

## 5. Mobile e accessibilità
1. ≤ 640 px: una colonna; immagine 88 px; i 4 dati in griglia 2×2; riquadri impilati; pulsanti a tutta larghezza (≥ 44 px); nessuno scroll orizzontale; le pillole scorrono in orizzontale solo se non entrano.
2. Solo tastiera: Tab raggiunge pillole, menu "Ordina", pulsanti e link con il contorno blu; Invio attiva "Mostra QR", "Invita amici", recensione; Esc chiude la finestra di recensione.
3. Barre con `role="progressbar"`; icone decorative `aria-hidden`; pillole con `aria-pressed`.

## 6. Aumenta la quantità (richiede Stripe in modalità test)
Prerequisiti: chiavi test in `api/config.php`, `stripe listen --forward-to http://localhost:8888/buypool/api/pagamento/webhook` attivo (con il secret `whsec_…` in config) — oppure nessun listener: la pagina, al ritorno da Stripe, chiama `GET /pagamento/stato` che applica comunque l'aumento (self-healing). Carta di prova `4242 4242 4242 4242`.
1. Con un'adesione `prenotata` o `confermata` a un gruppo aperto, in "I miei acquisti" (e nel dettaglio gruppo) compare "Aumenta la quantità". Non compare se il gruppo è scaduto, se hai già 99 pezzi, se l'adesione è già pagata o in "Pagamento non riuscito".
2. La finestra mostra lo stepper "pezzi in più", "Dopo il pagamento avrai N pezzi" e "Acconto aggiuntivo €X" (X = pezzi in più × prezzo del primo scaglione × % acconto). Con meno di €0,50 il pulsante è disattivato. Esc / "Annulla" chiudono.
3. "Vai al pagamento" → Stripe per il SOLO acconto aggiuntivo. Pagando: ritorno su `#/acquisti?id=…&pagamento=in_verifica…` con "Stiamo confermando il tuo pagamento…", poi toast "Quantità aggiornata" e la scheda mostra i nuovi pezzi; "Acconto versato" = somma dei due acconti; "Saldo previsto" ricalcolato.
4. Annullando su Stripe si torna alla pagina senza modifiche e senza addebiti.
5. Gruppo che raggiunge il minimo grazie all'aumento: tutte le adesioni diventano "Gruppo attivo" e partono le notifiche "Minimo raggiunto". Aderire o aumentare DOPO il minimo: l'adesione è "Gruppo attivo" (confermata) e alla chiusura il saldo viene addebitato.
6. Annullare l'adesione dopo un aumento (finché il minimo non è raggiunto): vengono rimborsati ENTRAMBI gli acconti su Stripe.
7. Gruppo non riuscito: la chiusura rimborsa tutti gli acconti (iniziale + aumenti); in "Annullati" l'importo è la somma; se un rimborso fallisce l'adesione resta com'è e al giro successivo si riprova solo quello mancante.
8. Chiusura con aumento: il saldo addebitato = quantità totale × prezzo dello scaglione raggiunto × (1 + commissione) − somma degli acconti.
9. Test automatici: `php tests/aumento_test.php` (regole pure) e `php tests/aumento_db_test.php --esegui` (DB di sviluppo, PaymentIntent e client Stripe finti: aumento, idempotenza, rifiuti, doppio acconto, rimborsi).
