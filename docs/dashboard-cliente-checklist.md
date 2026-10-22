# Dashboard cliente: checklist test manuale

Prerequisiti: Apache e MySQL avviati da MAMP. Utenti di prova nel DB di sviluppo: `nuovo@test.it` (nessun dato), `test@test.it` e `voter@test.it` (storico), `yonashabtetsadikburka@gmail.com` (molti ordini), un fornitore e un admin.
Verifiche automatiche già pronte: `php tests/dashboard_cliente_test.php`, `node tests/format_test.mjs`, `node tests/stato_percorso_test.mjs`.
Dopo ogni modifica ai CSS ricarica con `Ctrl+F5`.

## 1. Utente nuovo senza nulla (`nuovo@test.it`)
1. Accedi → Dashboard. Nessun errore in console.
2. Frase: "Benvenuto! Scopri i gruppi a cui puoi unirti." Pulsante "Esplora i gruppi →" porta alla pagina Gruppi d'acquisto.
3. Striscia: Risparmiato €0,00 (riga "rispetto al listino"), Speso €0,00, Saldo in arrivo €0,00 con "Nessun addebito in programma", Gruppi in corso 0.
4. Nessun blocco "Da fare".
5. Compaiono "Gruppi consigliati per te" (motivo "Tra i più popolari") subito sotto la striscia, poi "Non partecipi a nessun gruppo" con "Esplora i gruppi".
6. Ordini recenti: "Non hai ancora ordini."; notifiche: "Nessuna notifica per ora."; proposte: "Hai un'idea? Proponi un prodotto…".

## 2. Utente con ritiri pronti
1. Prepara un ordine `pagata` con ritiro in sede, QR `generato` e gruppo `consegnata` (il ritiro è "pronto" solo quando il fornitore ha evaso il gruppo; con il gruppo ancora `ordine_pronto` o `ordine_fornitore` non compare).
2. Frase: "Hai **N ritiri pronti** …" (singolare "1 ritiro pronto").
3. "Da fare" con filo ambra a sinistra, badge col numero, riga sede ("Ritiro presso <sede>: indirizzo · orari"; se la sede non ha dati la riga sparisce).
4. "Mostra QR" apre il QR (stesso modale di Ordini); "Chiudi" lo chiude.
5. Con più di 3 elementi compare "Vedi tutti (N)" verso Ordini.

## 3. Utente con gruppi in corso
1. Partecipa a un gruppo aperto (acconto) con un cliente di prova.
2. "Gruppi in corso" nella striscia = numero giusto; "Saldo in arrivo" > 0 con "addebito automatico il GG/MM, solo se i gruppi raggiungono il minimo"; passando il mouse o con Tab sull'icona "i" compare "Stima: il prezzo finale può essere più basso se il gruppo cresce."
3. Sezione "I miei gruppi in corso": foto o icona, "Hai prenotato N pezzi", "X / Y pz" e messaggio coerente (prima del primo scaglione "per attivare il gruppo"; dopo "per far scendere il prezzo a €Z"; ultimo scaglione "Prezzo più basso raggiunto"), barra a un solo colore, scadenza "Scade tra 6g 20h" (sotto il giorno "5h 20m").
4. Clic sulla card → dettaglio del gruppo. Clic su "Invita amici" → popover WhatsApp/Telegram/Copia link/Altro, **senza** aprire il dettaglio; secondo clic sul pulsante lo chiude; Esc lo chiude.
5. Fai crescere il gruppo (altri clienti prenotano) e ricarica: barra e messaggio si aggiornano.
6. "Tutte le partecipazioni →" porta alla pagina Partecipazioni.

## 4. Pagamento non riuscito
1. Porta una prenotazione a `azione_richiesta` (chiusura di un gruppo con carta rifiutata, o `UPDATE prenotazioni SET stato='azione_richiesta' WHERE id=…` su dati di prova).
2. "Da fare" mostra "Pagamento non riuscito · <prodotto>" con "Aggiorna il pagamento" → reindirizza al checkout Stripe di `POST /pagamento/riprova`. In Ordini l'etichetta è "Pagamento non riuscito" (passo 4).

## 5. Ordini recenti e notifiche
1. Ultimi 3 ordini con etichetta e 6 puntini: gruppo in corso (3 blu), inviato al fornitore (5 blu), pronto per il ritiro (6 blu, etichetta ambra), ritirato (6 verdi), gruppo non riuscito (nessun puntino).
2. Ultime 3 notifiche (anche già lette) con testo a 2 righe con ellissi e data "oggi / ieri / 2 giorni fa"; le non lette hanno il puntino blu.
3. "Tutti gli ordini →" e "Tutte →" portano alle pagine giuste.

## 6. Le mie proposte
1. Crea una proposta dalla pagina Proposte; compare nella dashboard con "In attesa".
2. Dopo l'approvazione admin: "In votazione", "N voti su 5"; a 5 voti "In valutazione"; pubblicata "Gruppo aperto"; oltre 30 giorni "Scaduta".
3. Il titolo apre la pagina Proposte sulla scheda giusta.

## 7. Mobile e accessibilità
1. Larghezza ≤ 760 px (DevTools): una colonna; striscia 2×2; "Da fare" con pulsanti a tutta larghezza; gruppi in corso, consigliati, ordini, notifiche, proposte impilati; nessun scroll orizzontale.
2. 761–1000 px: consigliati e proposte a 2 colonne, ordini e notifiche impilati.
3. Solo tastiera: Tab raggiunge tutti i pulsanti/link con il contorno blu di focus; Invio attiva "Mostra QR", "Invita amici", "Unisciti al gruppo".
4. Barre di avanzamento: nell'ispezione `role="progressbar"` con `aria-valuemin/max/now`. Icone decorative `aria-hidden`.

## 8. Errori e ruoli
1. Spegni MySQL (o blocca una chiamata in DevTools → Network): solo la sezione interessata mostra un messaggio breve; le altre funzionano.
2. Accedi come **fornitore** e come **admin**: le loro dashboard (`#/fornitore`, `#/admin`) sono invariate. Aprendo a mano `#/dashboard` con questi ruoli le sezioni mostrano il messaggio di errore (l'endpoint è riservato ai clienti).
