# BuyPool — API

Backend PHP + MySQL. Acquisti collettivi: si compra a **scatole**, si distribuisce a **latte**.

## Avvio con MAMP

1. Copiare la cartella in `htdocs/buypool` (macOS: `/Applications/MAMP/htdocs/buypool`).
2. Avviare Apache e MySQL da MAMP.
3. Aprire phpMyAdmin, creare il database `buypool` (collation `utf8mb4_general_ci`)
   e importare `schema.sql`.
4. Copiare `api/config.example.php` in `api/config.php`.
   I valori di default di MAMP sono gia' quelli giusti.
5. Provare: <http://localhost:8888/buypool/api/salute>
   Deve rispondere `{"ok":true,"dati":{"stato":"ok", ...}}`.

### Tre cose che fanno perdere un pomeriggio con MAMP

- MySQL sta sulla porta **8889**, non 3306.
- La password di root e' **`root`**, non vuota (in XAMPP e' vuota).
- Usare **`127.0.0.1`** e non `localhost`: con `localhost` PHP cerca il socket
  Unix, che MAMP tiene in un percorso non standard.

Serve PHP **8.1 o superiore**. Impostarlo uguale per tutti da MAMP > Preferences.

## Struttura

```
api/
  index.php            router: tutte le richieste passano da qui
  config.php           credenziali — NEL .gitignore, non committare mai
  lib/
    db.php             connessione PDO
    risposta.php       json_ok() / json_errore() / validazione input
    auth.php           sessione
    stato.php          macchina a stati        <- DA COMPLETARE (ruolo B)
    ripartizione.php   algoritmo               <- lo sostituisce il ruolo A
  endpoints/           auth, catalogo, campagne, partecipazioni,
                       assegnazioni, ritiro
test/                  test eseguibili da riga di comando
schema.sql             schema + dati di esempio
```

## Formato delle risposte

Uguale per tutti gli endpoint. Concordato con il front-end, non cambiarlo
senza avvisare.

```json
{ "ok": true,  "dati": { } }
{ "ok": false, "errore": { "codice": "SOGLIA_NON_RAGGIUNTA", "messaggio": "..." } }
```

## Endpoint

| Metodo | Percorso | Stato |
|---|---|---|
| GET | `/salute` | funziona |
| POST | `/registrazione` | funziona |
| POST | `/login` | funziona |
| POST | `/logout` | funziona |
| GET | `/io` | funziona |
| GET | `/fornitori` | funziona |
| GET | `/prodotti?fornitore_id=` | funziona |
| GET | `/punti-ritiro` | funziona |
| GET | `/campagne` | funziona, include l'avanzamento |
| POST | `/campagne` | funziona |
| GET | `/campagne/{id}` | funziona, include i partecipanti |
| POST | `/campagne/{id}/partecipazioni` | funziona |
| DELETE | `/campagne/{id}/partecipazioni` | funziona |
| POST | `/campagne/{id}/ripartisci` | **da fare** |
| GET | `/campagne/{id}/assegnazioni` | funziona |
| GET | `/mie/assegnazioni/{id}/qr` | funziona |
| POST | `/ritiro/{token}` | **da fare** |

L'elenco campagne restituisce gia' `latte_totali`, `scatole_complete` e
`latte_per_prossima`, cosi' il front-end disegna le barre di avanzamento
senza chiamare il dettaglio una volta per riga.

## Che cosa manca

Tre funzioni, tutte segnate con `TODO(B)` e con i passi gia' scritti nei
commenti:

1. `lib/stato.php` → `ricalcola_stato()` — la macchina a stati.
   Adesso restituisce lo stato attuale senza toccarlo, cosi' il resto
   dell'API gira lo stesso.
2. `endpoints/assegnazioni.php` → `assegnazioni_ripartisci()`.
3. `endpoints/ritiro.php` → `ritiro_conferma()`.

`lib/ripartizione.php` contiene una versione provvisoria funzionante
dell'algoritmo, da sostituire con quella del ruolo A quando e' pronta.

## Test

```
php test/test_router.php          # 10 casi di routing
php test/test_ripartizione.php    # ripartizione su 4 scenari
```

## Autori

- Yonas Habtetsadik Burka
- Abdelhamid Limem
- Ali Taher
