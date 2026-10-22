<?php
declare(strict_types=1);

/**
 * Segna come "scaduta" le proposte in_votazione create da piu' di 30 giorni.
 * Idempotente: si puo' lanciare quante volte si vuole.
 * Uso:  php scripts/scadenza_proposte.php
 * Pianificazione: cron (es. ogni ora) o Utilita' di pianificazione di Windows.
 * Nota: l'API calcola comunque "scaduta" al volo, questo script serve solo a tenere allineato il DB.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/proposte_regole.php';

$pdo = db();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare(
        'SELECT id_proposta, proponente_id, nome_prodotto FROM proposte_prodotti
          WHERE stato = \'in_votazione\' AND data_proposta < NOW() - INTERVAL ' . PROPOSTE_GIORNI_VALIDITA . ' DAY
          FOR UPDATE'
    );
    $st->execute();
    $scadute = $st->fetchAll();

    $upd = $pdo->prepare('UPDATE proposte_prodotti SET stato = \'scaduta\' WHERE id_proposta = ? AND stato = \'in_votazione\'');
    foreach ($scadute as $p) {
        $upd->execute([(int)$p['id_proposta']]);
        notifica_proposta(
            $pdo, (int)$p['proponente_id'], 'Proposta scaduta',
            'La proposta "' . $p['nome_prodotto'] . '" non ha raggiunto ' . PROPOSTE_SOGLIA_VOTI
            . ' voti in ' . PROPOSTE_GIORNI_VALIDITA . ' giorni. Puoi riproporla quando vuoi.',
            (int)$p['id_proposta']
        );
    }
    $pdo->commit();
    echo count($scadute) . " proposte segnate come scadute\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . "\n");
    exit(1);
}
