# Runbook operativo — BuyPool

Guida al funzionamento quotidiano in produzione: monitoraggio, procedure regolari, troubleshooting.

## 1. Monitoraggio giornaliero

| Cosa | Come | Azione se anomalo |
|---|---|---|
| Stato servizio | `GET /api/salute` → `{"stato":"ok","php":"…"}` | Se 5xx: controllare error_log PHP |
| Email in coda | `SELECT COUNT(*) FROM email_inviate WHERE stato='in_coda'` | Se cresce: verificare SMTP (§4) |
| Pagamenti in attesa | `SELECT COUNT(*) FROM pagamenti WHERE stato='in_attesa'` | Molte righe ferme → webhook Stripe (§5) |
| Cron ultima esecuzione | `/var/log/buypool/cron.log` | Se mancante: verificare crontab/Task Scheduler |
| Errori PHP | `error_log` del web server | Errori `ERRORE:` → investigate su `getFile():line` |

## 2. Procedure regolari

### 2.1 Backup database (giornaliero)

```bash
mysqldump -u <user> -p --single-transaction buypool | gzip > /backup/buypool_$(date +%F).sql.gz
```

Retention consigliata: 30 giorni. **Verificare il restore** almeno una volta (`gunzip -c … | mysql buypool_restore`).

### 2.2 Backup uploads

```bash
rsync -av /percorso/buypool/api/uploads/ /backup/buypool_uploads/
```

### 2.3 Rotazione log

I log cron e PHP vanno ruotati (logrotate o equivalente Windows).

### 2.4 Ruotare un segreto

1. Generare il nuovo valore (es. Stripe dashboard → roll signing secret).
2. Aggiornare `api/config.php` (nessun restart necessario salvo OPcache).
3. Verificare con un evento reale (email di test / pagamento di prova).

## 3. Coda email (`email_inviate`)

Le email partono da eventi applicativi e sono **idempotenti** (mai duplicate).

| Stato | Significato | Azione |
|---|---|---|
| `in_coda` | Da inviare o invio fallito | Il cron orario riprova automaticamente (`email_flush`) |
| `inviata` | Inviata con successo | Nessuna |
| `solo_notifica` | Solo notifica in-app (niente email) | Nessuna |
| `scartata` | Scartata (regola applicativa) | Verificare il contesto se inatteso |

```sql
-- Coda attuale
SELECT evento, COUNT(*) FROM email_inviate WHERE stato = 'in_coda' GROUP BY evento;
-- Inviati nelle ultime 24h
SELECT COUNT(*) FROM email_inviate WHERE stato = 'inviata' AND data_invio > NOW() - INTERVAL 1 DAY;
```

Se la coda **non si svuota** dopo un'ora: §4.

## 4. Troubleshooting

### 4.1 Le email non partono

1. Verificare `mail_enabled=true` e credenziali SMTP in `config.php`.
2. Test manuale SMTP (dev): Mailpit su `127.0.0.1:1025` + UI `http://localhost:8025`.
3. Controllare SPF/DKIM del mittente (le email partono ma finiscono in spam).
4. I log di PHPMailer finiscono in `error_log` — un errore SMTP non blocca mai l'applicazione.

### 4.2 Un pagamento Stripe non viene registrato

1. Dashboard Stripe → Payments: il pagamento esiste? Stato `succeeded`?
2. Se sì → **webhook non arrivato**: verificare URL, segreto `whsec`, eventi sottoscritti; con Stripe CLI in dev: `stripe listen --forward-to …/api/pagamento/webhook`.
3. **Self-healing**: il cliente può toccare il pulsante di pagamento/pagamento-stato → `GET /pagamento/stato` riesamina Stripe e allinea il DB.
4. Dopo la risoluzione, il cron orario sanifica le code email.

### 4.3 Un cliente è in "Azione richiesta" (saldo non riuscito)

Flusso normale: il cliente ha **48 ore** per riprovare (promemoria a 24h). Scadute le 48h il cron:
1. annulla l'adesione;
2. rimborsa l'acconto su Stripe (idempotente);
3. invia email + notifica.

Se il rimborso fallisce (carta scaduta, Stripe error): il tentativo resta registrato in `pagamenti`; il cron riprova alla prossima esecuzione.

### 4.4 Un gruppo non si chiude alla scadenza

Il cron di chiusura può essere eseguito a mano:

```bash
php /percorso/buypool/scripts/saldi_scaduti.php
```

La chiusura manuale admin è su `#/admin/campagne`. La chiusura via API richiede `?secret=` uguale a `chiusura_secret`.

### 4.5 L'utente non riceve il link di reset

1. L'admin genera il link da `#/admin/utenti` → *reset-link* (nessun invio automatico: l'admin lo inoltra).
2. Il token è monouso e ha scadenza: generarne uno nuovo.
3. Verificare che l'email non sia in `in_coda`.

### 4.6 Errore "Account sospeso"

Comportamento voluto: i sospesi possono consultare ma non operare. Riattivare da `#/admin/utenti`.

### 4.7 Upload foto fallisce

1. Estensione `gd` e `fileinfo` attive su PHP.
2. Cartella `api/uploads/…` scrivibile dal web server.
3. Limite 5 MB / 300–6000 px / JPG-PNG-WEBP.

### 4.8 QR code non scansiona / "già usato"

I token sono monouso per design. Generare una nuova assegnazione o ripristinare lo stato da DB (`qr_codes.stato`) solo se falsa positiva documentata.

### 4.9 Situazione generica "l'app non risponde"

1. `GET /api/salute`.
2. error_log PHP (ultime righe).
3. Stato MySQL (`SHOW PROCESSLIST;` — query in attesa lunghe).
4. Disk space, memoria PHP-FPM/Apache.
5. Rollback: ripristinare il release precedente + restore DB se necessario (procedure di rollback validate in §2.1).

## 5. Chiusura campagna fallita — checklist incidente

1. Confermare che il gruppo è in `fallita` e che `pagamenti`/`eventi_stripe` mostrano i rimborsi.
2. Se mancano rimborsi: `php scripts/saldi_scaduti.php` (idempotente) oppure riprovare dal dettaglio admin.
3. Comunicare ai clienti: email evento `RIMBORSO` + notifica in-app partono automaticamente.
4. Tempi: 5–10 giorni lavorativi per il rimborso sulla carta.

## 6. Contatti e riferimenti

| Risorsa | Dove |
|---|---|
| Codici errore API | [05-api-reference.md](../tech/05-api-reference.md) |
| Stati e modelli | [04-modello-dati.md](../tech/04-modello-dati.md) |
| Setup servizi | [07-integrazioni-esterne.md](../tech/07-integrazioni-esterne.md) |
| Deploy/aggiornamenti | [09-guida-deploy.md](09-guida-deploy.md) |
| Test pre-release | [08-test-qa.md](../tech/08-test-qa.md) |
