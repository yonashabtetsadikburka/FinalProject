# Guida di deploy — BuyPool

Procedura di rilascio in produzione. Ambito: server Linux/Windows con PHP 8.3+ e MySQL 8+ (l'ambiente di sviluppo documentato nel README usa MAMP).

## 1. Requisiti

| Componente | Requisito |
|---|---|
| PHP | 8.3+ con estensioni: `gd`, `mbstring`, `json`, `pdo_mysql`, `fileinfo`, `openssl` |
| MySQL / MariaDB | 8+ (o equivalente), utf8mb4 |
| Web server | Apache (mod_rewrite) o Nginx, HTTPS attivo |
| Composer | 2.x (per `vendor/`) |
| Accesso cron | Linux crontab o Task Scheduler Windows |
| Esterni | Account Stripe (live), Google Cloud (OAuth), provider SMTP (es. Brevo) |

## 2. Rilascio

```bash
# 1. Deploy del codice (git clone o rsync) in /percorso/buypool

# 2. Dipendenze PHP (vendor/ è gitignored)
cd /percorso/buypool/api && composer install --no-dev --optimize-autoloader

# 3. Configurazione
cp api/config.example.php api/config.php   # poi compilare (vedi §3)
chmod 600 api/config.php

# 4. Database
mysql -u <user> -p -e "CREATE DATABASE buypool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u <user> -p buypool < api/schema.sql
mysql -u <user> -p buypool < api/seed_data.sql

# 5. Migrazioni (solo se il DB deriva da versioni precedenti — vedi §5)

# 6. Cartella uploads (scrivibile dal web server)
mkdir -p api/uploads/proposals && chown -R www-data:www-data api/uploads

# 7. Web server
#   DocumentRoot → frontend/   (oppure alias separati per / e /api)
#   Abilitare HTTPS + redirect 301 da HTTP
```

### Configurazione web server (sintesi)

| Requisito | Apache | Nginx |
|---|---|---|
| Frontend | DocumentRoot su `frontend/` | `root …/frontend; try_files $uri /index.html` |
| API | Alias `/api` → `api/` con rewrite su `index.php` | `location /api { try_files $uri /api/index.php?$query_string; }` |
| Uploads | `.htaccess` generato dall'app (no PHP) | `location ~* \.(php)$ { deny all; }` sotto `/api/uploads` |
| HTTPS | certbot / Let's Encrypt | idem |

### Frontend — costanti da cambiare

| File | Cosa cambiare |
|---|---|
| `frontend/js/constants.js` | `API_URL` → `https://dominio/api` |
| `frontend/js/pages/login.js` | `client_id` Google; `clientId` Microsoft (se attivo) |

## 3. `api/config.php` — tutti i campi

| Chiave | Descrizione | Esempio prod |
|---|---|---|
| `host` | Host MySQL (usare IP, non `localhost`, per forzare TCP) | `127.0.0.1` |
| `port` | Porta MySQL | `3306` |
| `db` | Nome database | `buypool` |
| `user` / `pass` | Credenziali DB | — |
| `google_client_id` | OAuth Google (Google Cloud Console) | `…apps.googleusercontent.com` |
| `microsoft_client_id` | OAuth Microsoft (**placeholder — non attivo**) | `—` |
| `stripe_secret_key` | Chiave secret Stripe | `sk_live_…` |
| `stripe_publishable_key` | Chiave pubblica Stripe | `pk_live_…` |
| `stripe_webhook_secret` | Segreto firma webhook | `whsec_…` |
| `frontend_url` | URL assoluto del frontend (redirect Stripe) | `https://dominio` |
| `mail_enabled` | Abilita invio email | `true` |
| `mail_from` / `mail_from_nome` | Mittente | `noreply@dominio.tld` |
| `smtp_host` / `smtp_port` | SMTP | `smtp-relay.brevo.com` / `587` |
| `smtp_auth` | Autenticazione SMTP | `true` |
| `smtp_user` / `smtp_pass` | Credenziali SMTP | — |
| `smtp_secure` | `tls` in prod (`''` in dev) | `tls` |
| `soglia_like_campagna` | Soglia like per auto-campagna | `5` |
| `costo_spedizione` | Costo fisso spedizione | `4.90` |
| `chiusura_secret` | Segreto per la chiusura campagne via cron | stringa casuale lunga |
| `acconto_percent` | Percentuale acconto (config di riferimento) | `20` |

> **Regola**: `config.php` non va mai committato (è nel `.gitignore`). Aggiornare `config.example.php` quando si aggiungono chiavi.

## 4. Cron / task scheduler

Tre script **CLI-only** e idempotenti in `scripts/`:

| Script | Frequenza | Cosa fa |
|---|---|---|
| `saldi_scaduti.php` | **Ogni ora** | Promemoria saldo a 24h dalla scadenza; a scadenza: annulla adesione + rimborso acconto; se tutti hanno pagato invia l'ordine al fornitore; **invia le email rimaste in coda** |
| `scadenza_proposte.php` | Ogni ora | Segna `scaduta` le proposte in votazione >30 giorni |
| `pulisci_foto_proposte.php` | Giornaliero | Rimuove le foto orfane in `uploads/` (dry-run di default; `--applica` per cancellare) |

Esempio crontab:

```cron
0 * * * *  php /percorso/buypool/scripts/saldi_scaduti.php >> /var/log/buypool/cron.log 2>&1
5 * * * *  php /percorso/buypool/scripts/scadenza_proposte.php >> /var/log/buypool/cron.log 2>&1
30 3 * * * php /percorso/buypool/scripts/pulisci_foto_proposte.php --applica >> /var/log/buypool/cron.log 2>&1
```

Windows: Task Scheduler, stessi comandi, frequenza oraria/giornaliera.

## 5. Migrazioni database

Non c'è un runner automatico: applicare a mano in ordine cronologico, eseguendo prima il file `*_up` (quello senza `_down`), conservando il `_down` come rollback.

| Migrazione | Contenuto |
|---|---|
| `2026-10-19_proposte_votazione.sql` | Ciclo vita votazione proposte |
| `2026-10-20_proposte_form_foto.sql` | Campi form proposta (categoria, prezzi, foto) |
| `2026-10-21_email_notifiche.sql` | Tabella `email_inviate` + `prenotazioni.saldo_scadenza` |

Un database creato da `schema.sql` aggiornato **non** richiede le migrazioni.

## 6. Stripe — setup produzione

1. Dashboard Stripe → Developers → **API keys**: copiare le chiavi **live** in `config.php`.
2. **Webhook**: Developers → Add endpoint → URL `https://dominio/api/pagamento/webhook` → eventi `payment_intent.succeeded`, `checkout.session.completed` → copiare il **Signing secret** in `stripe_webhook_secret`.
3. Verificare un pagamento di prova reale (microtransazione) e controllare la riga in `pagamenti` + `eventi_stripe`.

## 7. SMTP — setup produzione

1. Scegliere un provider transazionale (es. Brevo) e **verificare il dominio** (SPF + DKIM) — senza questo le email finiscono in spam.
2. Compilare il blocco `mail_*` / `smtp_*` di `config.php` (`mail_enabled=true`, `smtp_secure='tls'`).
3. Testare: `GET https://dominio/api/_dev/mail-test` è limitato a localhost → testare invece il reset password reale e verificare la ricezione.

## 8. Checklist pre-go-live

### Sicurezza
- [ ] HTTPS attivo + redirect 301 da HTTP; cookie sessione `secure` (vedi [06-sicurezza-gdpr.md](../tech/06-sicurezza-gdpr.md))
- [ ] CSRF esteso a tutte le scritture, Microsoft OAuth completato o disattivato
- [ ] TLS verify attivo per chiamate Google
- [ ] Header: CSP, X-Frame-Options, HSTS
- [ ] `config.php` con permessi `600`, non esportabile via web
- [ ] Endpoint `/_dev/*` assenti o disattivati

### Funzionale
- [ ] Suite test automatici: `php tests/*.php` e `node tests/*.mjs` → tutti verdi
- [ ] Checklist manuali in `docs/` eseguite
- [ ] Pagamento di prova end-to-end (acconto → conferma → saldo → QR)
- [ ] Rimborsi di prova (chiusura campagna fallita)
- [ ] Email: reset password, invito fornitore, notifica pagamento — ricevute e formattate
- [ ] I 3 cron eseguiti almeno una volta manualmente, senza errori

### Operativo
- [ ] Backup DB giornaliero + test di restore
- [ ] Log accessi/errori monitorati (error_log PHP)
- [ ] Amministratore creato (`UPDATE utenti SET ruolo='admin' …`) e login verificato
- [ ] Sedi di ritiro inserite (`sedi`)
- [ ] Categorie caricate (`seed_data.sql`)

## 9. Aggiornamenti successivi

```bash
cd /percorso/buypool
git pull
cd api && composer install --no-dev
# applicare eventuali nuove migrazioni SQL (vedi §5)
# svuotare la cache OPcache se usato: opcache_reset() o restart PHP
```

Documentazione collegata: [10-runbook.md](10-runbook.md) · [07-integrazioni-esterne.md](../tech/07-integrazioni-esterne.md) · [06-sicurezza-gdpr.md](../tech/06-sicurezza-gdpr.md)
