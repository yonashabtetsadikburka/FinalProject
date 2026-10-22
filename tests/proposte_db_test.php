<?php
declare(strict_types=1);

/**
 * Test di integrazione sul DB di SVILUPPO (richiede la migrazione applicata).
 * Crea utenti/proposte temporanei con email test-proposte-*@example.invalid e li cancella a fine test.
 * Uso:  php tests/proposte_db_test.php --esegui
 */
if (!in_array('--esegui', $argv ?? [], true)) { echo "Aggiungi --esegui per lanciare il test sul DB configurato.\n"; exit(2); }

require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/proposte_regole.php';

$pdo = db();
$fail = 0;
function verifica(string $nome, bool $ok): void { global $fail; echo ($ok ? '[OK]   ' : '[FAIL] ') . $nome . "\n"; if (!$ok) $fail++; }
function errore(callable $f): ?string { try { $f(); return null; } catch (AppError $e) { return $e->codice; } }

$tag = 'test-proposte-' . bin2hex(random_bytes(3));
$utenti = [];
try {
    for ($i = 0; $i < 7; $i++) {
        $pdo->prepare('INSERT INTO utenti (nome, cognome, email, password_hash, ruolo) VALUES (?, ?, ?, \'x\', \'cliente\')')
            ->execute(["Test$i", 'Utente', "$tag-$i@example.invalid"]);
        $utenti[] = (int)$pdo->lastInsertId();
    }
    $proponente = $utenti[0];
    $nuova = function (string $stato = 'in_votazione', string $data = 'NOW()') use ($pdo, $proponente) {
        $pdo->exec("INSERT INTO proposte_prodotti (proponente_tipo, proponente_id, nome_prodotto, stato, data_proposta)
                    VALUES ('cliente', $proponente, 'Prodotto test', '$stato', $data)");
        return (int)$pdo->lastInsertId();
    };

    $id = $nuova();
    verifica('voto proprio rifiutato', errore(fn() => proposte_registra_voto($pdo, $id, $proponente)) === 'VOTO_PROPRIO');
    for ($i = 1; $i <= 4; $i++) $r = proposte_registra_voto($pdo, $id, $utenti[$i]);
    verifica('4 voti: in_votazione', $r['stato'] === 'in_votazione' && $r['tot_voti'] === 4);
    verifica('voto doppio rifiutato', errore(fn() => proposte_registra_voto($pdo, $id, $utenti[1])) === 'GIA_VOTATO');
    $r = proposte_togli_voto($pdo, $id, $utenti[4]);
    verifica('togli voto: 3 voti', $r['tot_voti'] === 3);
    proposte_registra_voto($pdo, $id, $utenti[4]);
    $r = proposte_registra_voto($pdo, $id, $utenti[5]);
    verifica('5a votazione: in_valutazione', $r['stato'] === 'in_valutazione' && $r['tot_voti'] === 5);
    verifica('6o voto rifiutato', errore(fn() => proposte_registra_voto($pdo, $id, $utenti[6])) === 'PROPOSTA_NON_VOTABILE');
    verifica('rimozione voto in valutazione rifiutata', errore(fn() => proposte_togli_voto($pdo, $id, $utenti[1])) === 'VOTO_NON_RITIRABILE');
    $n = (int)$pdo->query("SELECT COUNT(*) FROM notifiche WHERE tipo_riferimento='proposta' AND id_riferimento=$id")->fetchColumn();
    verifica('notifiche: proponente + 5 votanti', $n === 6);

    $id30 = $nuova('in_votazione', 'NOW() - INTERVAL 29 DAY');
    verifica('29 giorni: votabile', errore(fn() => proposte_registra_voto($pdo, $id30, $utenti[1])) === null);
    $id31 = $nuova('in_votazione', 'NOW() - INTERVAL 31 DAY');
    verifica('31 giorni: voto rifiutato', errore(fn() => proposte_registra_voto($pdo, $id31, $utenti[1])) === 'PROPOSTA_SCADUTA');
    $idS = $nuova('scaduta');
    verifica('stato scaduta: voto rifiutato', errore(fn() => proposte_registra_voto($pdo, $idS, $utenti[1])) === 'PROPOSTA_SCADUTA');
    verifica('UNIQUE sul DB', errore(function () use ($pdo, $id30, $utenti) {
        try { $pdo->prepare("INSERT INTO voti_proposte (id_proposta,id_utente,valore_voto) VALUES (?,?, 'favore')")->execute([$id30, $utenti[1]]); }
        catch (PDOException $e) { throw new AppError('DUP'); }
    }) === 'DUP');
} finally {
    if ($utenti) {   // ON DELETE CASCADE rimuove proposte, voti e notifiche
        $in = implode(',', $utenti);
        $pdo->exec("DELETE FROM utenti WHERE id IN ($in)");
    }
}
echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test FALLITI\n";
exit($fail === 0 ? 0 : 1);
