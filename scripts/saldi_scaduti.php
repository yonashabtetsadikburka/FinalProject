<?php
declare(strict_types=1);

/**
 * Saldo non riuscito: promemoria a 24 ore dal termine e, a termine scaduto, annullamento dell'adesione
 * con rimborso automatico dell'acconto. Rinvia anche le email rimaste in coda.
 * Idempotente: promemoria ed email partono una sola volta (tabella email_inviate), un'adesione gia'
 * rimborsata non viene riprocessata.
 * Uso:  php scripts/saldi_scaduti.php
 * Pianificazione: cron ogni ora (es. "0 * * * * php /percorso/buypool/scripts/saldi_scaduti.php")
 * oppure Utilita' di pianificazione di Windows.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/../api/vendor/autoload.php';
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';
require __DIR__ . '/../api/lib/stato.php';
require __DIR__ . '/../api/lib/prezzi.php';
require __DIR__ . '/../api/lib/mail.php';
require __DIR__ . '/../api/lib/notifiche_eventi.php';
require __DIR__ . '/../api/lib/aumento.php';
require __DIR__ . '/../api/endpoints/campagne.php';
require __DIR__ . '/../api/endpoints/pagamento.php';

$pdo = db();
$cfg = require __DIR__ . '/../api/config.php';
$stripe = new \Stripe\StripeClient($cfg['stripe_secret_key']);

$promemoria = 0;
$annullate = 0;
$errori = 0;

try {
    $st = $pdo->query(
        "SELECT id, id_colletta, id_utente, quantita, importo_acconto, UNIX_TIMESTAMP(saldo_scadenza) AS scadenza_ts
           FROM prenotazioni
          WHERE stato = 'azione_richiesta' AND saldo_scadenza IS NOT NULL"
    );
    $righe = $st->fetchAll();
} catch (Throwable $e) {
    fwrite(STDERR, "Errore (migrazione 2026-10-21_email_notifiche applicata?): " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($righe as $r) {
    $pid = (int)$r['id'];
    $fase = saldo_fase((int)$r['scadenza_ts'], time());
    if ($fase === 'promemoria') {
        evento_saldo_promemoria($pdo, $pid);
        $promemoria++;
        continue;
    }
    if ($fase !== 'scaduto') continue;

    try {
        // 1. rimborso di tutti gli acconti (flusso esistente, idempotente)
        $esito = rimborsa_acconti($pdo, $stripe, $pid);
        if ($esito['falliti'] > 0) {
            error_log("saldi_scaduti: rimborso incompleto per prenotazione #$pid, si riprova al prossimo giro");
            $errori++;
            continue;
        }
        // 2. l'adesione diventa 'rimborsata' e i pezzi escono dal gruppo
        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE prenotazioni SET stato = 'rimborsata' WHERE id = ? AND stato = 'azione_richiesta'");
        $up->execute([$pid]);
        if ($up->rowCount() !== 1) {
            $pdo->rollBack();
            error_log("saldi_scaduti: prenotazione #$pid non piu' in azione_richiesta dopo il rimborso (pagata nel frattempo?): verificare");
            $errori++;
            continue;
        }
        $pdo->prepare('UPDATE collette SET quantita_attuale = GREATEST(0, quantita_attuale - ?) WHERE id = ?')
            ->execute([(int)$r['quantita'], (int)$r['id_colletta']]);
        evento_adesione_annullata_saldo($pdo, $pid, round((float)$r['importo_acconto'], 2));
        $pdo->commit();
        $annullate++;

        // 3. se gli altri hanno pagato tutti, l'ordine al fornitore puo' partire
        try {
            $s = $pdo->prepare("SELECT COUNT(*), SUM(CASE WHEN stato = 'pagata' THEN 1 ELSE 0 END) FROM prenotazioni WHERE id_colletta = ? AND stato <> 'rimborsata'");
            $s->execute([(int)$r['id_colletta']]);
            [$tot, $pag] = array_map('intval', $s->fetch(PDO::FETCH_NUM));
            $sc = $pdo->prepare('SELECT stato FROM collette WHERE id = ?');
            $sc->execute([(int)$r['id_colletta']]);
            if ($sc->fetchColumn() === 'ordine_pronto' && $pag > 0 && $pag === $tot) {
                invia_ordine_fornitore_al((int)$r['id_colletta'], null);
            }
        } catch (Throwable $e) {
            error_log("saldi_scaduti: invio ordine colletta #{$r['id_colletta']} fallito: " . $e->getMessage());
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("saldi_scaduti: prenotazione #$pid errore: " . $e->getMessage());
        $errori++;
    }
}

$inviate = email_flush($pdo);
echo "Promemoria: $promemoria, adesioni annullate e rimborsate: $annullate, email inviate: $inviate, errori: $errori\n";
exit($errori > 0 ? 1 : 0);
