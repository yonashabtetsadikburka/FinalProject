<?php
declare(strict_types=1);

/**
 * Test di integrazione di "Aumenta la quantita'" sul DB di SVILUPPO (nessuna chiamata Stripe reale: PaymentIntent finti,
 * client Stripe finto per i rimborsi). Crea dati temporanei (email test-aumento-*@example.invalid) e li cancella a fine test.
 * Uso:  php tests/aumento_integrazione_test.php --esegui
 */
if (!in_array('--esegui', $argv ?? [], true)) { echo "Aggiungi --esegui per lanciare il test sul DB configurato.\n"; exit(2); }

require __DIR__ . '/../api/vendor/autoload.php';
foreach (['lib/risposta.php', 'lib/connessione.php', 'lib/auth.php', 'lib/stato.php', 'lib/prezzi.php', 'lib/aumento.php',
          'endpoints/pagamento.php', 'endpoints/campagne.php', 'endpoints/partecipazioni.php'] as $f) {
    require __DIR__ . '/../api/' . $f;
}
// Il codice sotto test registra errori attesi (rimborsi finti): niente rumore sul terminale
ini_set('error_log', sys_get_temp_dir() . '/aumento_integrazione_test.log');

$pdo = db();
$fail = 0;
function verifica(string $nome, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $nome . ($ok || $extra === '' ? '' : "  ($extra)") . "\n";
    if (!$ok) $fail++;
}
function uguale(string $nome, $ottenuto, $atteso): void
{
    verifica($nome, $ottenuto == $atteso, 'ottenuto ' . var_export($ottenuto, true) . ', atteso ' . var_export($atteso, true));
}
function q(string $sql, array $p = []) { global $pdo; $st = $pdo->prepare($sql); $st->execute($p); return $st; }
function val(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

/** PaymentIntent finto. */
function piFinto(string $id, int $centesimi, array $meta): object
{
    return (object)['id' => $id, 'amount' => $centesimi, 'amount_received' => $centesimi, 'payment_method' => 'pm_test', 'status' => 'succeeded', 'metadata' => (object)$meta];
}
$tag = 'test-aumento-' . bin2hex(random_bytes(3));
$ev = 0;
function nuovoEvento(): int
{
    global $pdo, $tag, $ev;
    $ev++;
    $pdo->prepare('INSERT INTO eventi_stripe (stripe_event_id, tipo_evento, payload_json) VALUES (?, ?, ?)')->execute(["{$tag}_$ev", 'test', '{}']);
    return (int)$pdo->lastInsertId();
}

$utenti = [];
$fornitore = $prodotto = $colletta = null;
try {
    foreach (['A', 'B', 'C'] as $n) {
        q('INSERT INTO utenti (nome, cognome, email, password_hash, ruolo) VALUES (?, ?, ?, \'x\', \'cliente\')', ["Test$n", 'Aumento', "$tag-" . strtolower($n) . '@example.invalid']);
        $utenti[$n] = (int)$pdo->lastInsertId();
    }
    q('INSERT INTO fornitori (nome_azienda) VALUES (?)', ["Fornitore $tag"]);
    $fornitore = (int)$pdo->lastInsertId();
    q('INSERT INTO prodotti (id_fornitore, nome, prezzo_unitario, quantita_minima) VALUES (?, ?, 100, 10)', [$fornitore, "Prodotto $tag"]);
    $prodotto = (int)$pdo->lastInsertId();
    q('INSERT INTO collette (id_prodotto, quantita_minima, quantita_attuale, data_limite, stato, prezzo_base, prezzo_corrente, percentuale_commissione)
       VALUES (?, 10, 0, NOW() + INTERVAL 10 DAY, \'in_corso\', 120, 100, 10)', [$prodotto]);
    $colletta = (int)$pdo->lastInsertId();
    q('INSERT INTO scaglioni_prezzo (id_colletta, soglia_partecipanti, prezzo_unitario) VALUES (?, 10, 100), (?, 30, 90)', [$colletta, $colletta]);

    $adesione = fn(string $u, string $piId, int $qta, int $cent) => webhook_acconto_crea(piFinto($piId, $cent, [
        'tipo' => 'acconto', 'colletta_id' => $colletta, 'quantita' => $qta, 'utente_id' => $utenti[$u], 'delivery_type' => 'ritiro_sede', 'id_sede' => '', 'indirizzo' => '',
    ]), nuovoEvento());
    $aumento = fn(string $u, int $prenId, string $piId, int $k, int $cent) => webhook_aumento_applica(piFinto($piId, $cent, [
        'tipo' => 'aumento', 'aumento_prenotazione_id' => $prenId, 'colletta_id' => $colletta, 'quantita_aggiuntiva' => $k, 'utente_id' => $utenti[$u],
    ]), nuovoEvento());
    $pren = fn(string $u) => q('SELECT * FROM prenotazioni WHERE id_colletta = ? AND id_utente = ?', [$colletta, $utenti[$u]])->fetch();
    $attuale = fn() => (int)val('SELECT quantita_attuale FROM collette WHERE id = ?', [$colletta]);

    // ---- adesione iniziale ----
    $adesione('A', "pi_{$tag}_a1", 4, 8000);          // 4 pz x 100 x 20% = 80
    $pa = $pren('A');
    uguale('adesione A: prenotata, 4 pezzi, acconto 80', [$pa['stato'], (int)$pa['quantita'], (float)$pa['importo_acconto']], ['prenotata', 4, 80.0]);
    uguale('gruppo: 4 pezzi', $attuale(), 4);

    // ---- aumento +3 ----
    $aumento('A', (int)$pa['id'], "pi_{$tag}_a2", 3, 6000);
    $pa = $pren('A');
    uguale('aumento +3: quantita 7, acconto 140 (somma dei due)', [(int)$pa['quantita'], (float)$pa['importo_acconto']], [7, 140.0]);
    uguale('aumento +3: gruppo a 7 pezzi', $attuale(), 7);
    uguale('aumento +3: due pagamenti acconto confermati', (int)val("SELECT COUNT(*) FROM pagamenti WHERE id_prenotazione = ? AND tipo_pagamento = 'acconto' AND stato = 'confermato'", [$pa['id']]), 2);
    uguale('aumento +3: ancora prenotata (minimo non raggiunto)', $pa['stato'], 'prenotata');
    uguale('aumento +3: notifica all\'utente', (int)val("SELECT COUNT(*) FROM notifiche WHERE id_utente = ? AND messaggio LIKE 'Hai aggiunto 3 pezzi%'", [$utenti['A']]), 1);

    // ---- idempotenza: stesso PaymentIntent due volte ----
    $aumento('A', (int)$pa['id'], "pi_{$tag}_a2", 3, 6000);
    $pa = $pren('A');
    uguale('idempotenza: stesso PI non raddoppia (quantita 7, gruppo 7)', [(int)$pa['quantita'], $attuale()], [7, 7]);

    // ---- il minimo viene raggiunto: tutti 'confermata' ----
    $adesione('B', "pi_{$tag}_b1", 3, 6000);
    uguale('B porta il gruppo a 10: gruppo riuscita', val('SELECT stato FROM collette WHERE id = ?', [$colletta]), 'riuscita');
    uguale('minimo raggiunto: A e B diventano confermata', [$pren('A')['stato'], $pren('B')['stato']], ['confermata', 'confermata']);

    // ---- BUG corretto: chi aderisce dopo il minimo nasce 'confermata' ----
    $adesione('C', "pi_{$tag}_c1", 1, 2000);
    uguale('adesione dopo il minimo: C nasce confermata (verra\' addebitata alla chiusura)', $pren('C')['stato'], 'confermata');

    // ---- aumento con gruppo gia' riuscita ----
    $aumento('A', (int)$pa['id'], "pi_{$tag}_a3", 2, 4000);
    $pa = $pren('A');
    uguale('aumento a gruppo riuscita: quantita 9, acconto 180, confermata', [(int)$pa['quantita'], (float)$pa['importo_acconto'], $pa['stato']], [9, 180.0, 'confermata']);
    uguale('gruppo a 13 pezzi (A 9 + B 3 + C 1)', $attuale(), 13);

    // ---- il saldo previsto usa la formula esistente con l'acconto sommato ----
    $saldo = saldo_chiusura((int)$pa['quantita'], prezzo_per_stima_saldo([['soglia' => 10, 'prezzo' => 100.0], ['soglia' => 30, 'prezzo' => 90.0]], $attuale(), 100.0), 10.0, (float)$pa['importo_acconto']);
    uguale('saldo a chiusura: 9 x 100 x 1,10 - 180 = 810', $saldo, 810.0);

    // ---- rifiuti: nessuna modifica, nessun pagamento registrato ----
    $aumento('A', (int)$pa['id'], "pi_{$tag}_a4", 95, 1000);   // 9 + 95 = 104 > 99
    uguale('tetto 99: aumento rifiutato (quantita invariata, nessun pagamento)', [(int)$pren('A')['quantita'], (int)val('SELECT COUNT(*) FROM pagamenti WHERE stripe_payment_intent_id = ?', ["pi_{$tag}_a4"])], [9, 0]);
    $aumento('B', (int)$pa['id'], "pi_{$tag}_x1", 1, 1000);    // prenotazione di A con utente B
    uguale('aumento su prenotazione altrui rifiutato', [(int)$pren('A')['quantita'], (int)val('SELECT COUNT(*) FROM pagamenti WHERE stripe_payment_intent_id = ?', ["pi_{$tag}_x1"])], [9, 0]);
    q('UPDATE collette SET data_limite = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$colletta]);
    $aumento('A', (int)$pa['id'], "pi_{$tag}_a5", 1, 2000);
    uguale('gruppo scaduto: aumento rifiutato', [(int)$pren('A')['quantita'], $attuale()], [9, 13]);
    q('UPDATE collette SET data_limite = NOW() + INTERVAL 5 DAY, chiusura_data = NOW() WHERE id = ?', [$colletta]);
    $aumento('A', (int)$pa['id'], "pi_{$tag}_a6", 1, 2000);
    uguale('gruppo gia\' chiuso: aumento rifiutato', [(int)$pren('A')['quantita'], $attuale()], [9, 13]);
    q('UPDATE collette SET chiusura_data = NULL WHERE id = ?', [$colletta]);
    q("UPDATE prenotazioni SET stato = 'pagata' WHERE id = ?", [$pa['id']]);
    $aumento('A', (int)$pa['id'], "pi_{$tag}_a7", 1, 2000);
    uguale('adesione gia\' pagata: aumento rifiutato', (int)$pren('A')['quantita'], 9);
    q("UPDATE prenotazioni SET stato = 'confermata' WHERE id = ?", [$pa['id']]);

    // ---- rimborsi con PIU' acconti (client Stripe finto) ----
    $finto = new class {
        public array $chiamate = [];
        public array $falliscono = [];
        public $refunds;
        public function __construct() { $self = $this; $this->refunds = new class($self) {
            public function __construct(private $padre) {}
            public function create(array $p, array $opt = []) {
                if (in_array($p['payment_intent'], $this->padre->falliscono, true)) throw new RuntimeException('Rimborso rifiutato');
                $this->padre->chiamate[] = $p['payment_intent'];
                return (object)['id' => 're_test'];
            }
        }; }
    };
    $esito = rimborsa_acconti($pdo, $finto, (int)$pa['id']);
    uguale('rimborso: TUTTI i 3 acconti rimborsati', [$esito['rimborsati'], $esito['falliti'], count($finto->chiamate)], [3, 0, 3]);
    uguale('rimborso: pagamenti segnati rimborsato', (int)val("SELECT COUNT(*) FROM pagamenti WHERE id_prenotazione = ? AND stato = 'rimborsato'", [$pa['id']]), 3);
    $finto->chiamate = [];
    $esito = rimborsa_acconti($pdo, $finto, (int)$pa['id']);
    uguale('rimborso idempotente: seconda volta niente da fare', [$esito['rimborsati'], count($finto->chiamate)], [0, 0]);
    uguale('la somma dei pagamenti confermati non conta piu\' gli acconti rimborsati', (float)val("SELECT COALESCE(SUM(importo),0) FROM pagamenti WHERE id_prenotazione = ? AND stato = 'confermato'", [$pa['id']]), 0.0);

    // un rimborso su due fallisce: il primo e' fatto, il secondo si riprova da solo
    $pb = $pren('B');
    q('INSERT INTO pagamenti (id_prenotazione, tipo_pagamento, importo, stripe_payment_intent_id, stato) VALUES (?, \'acconto\', 10, ?, \'confermato\')', [$pb['id'], "pi_{$tag}_b9"]);
    $finto->falliscono = ["pi_{$tag}_b9"];
    $finto->chiamate = [];
    $esito = rimborsa_acconti($pdo, $finto, (int)$pb['id']);
    uguale('rimborso parziale: 1 riuscito, 1 fallito', [$esito['rimborsati'], $esito['falliti']], [1, 1]);
    $finto->falliscono = [];
    $finto->chiamate = [];
    $esito = rimborsa_acconti($pdo, $finto, (int)$pb['id']);
    uguale('al secondo giro si riprova SOLO l\'acconto non rimborsato', [$esito['rimborsati'], $finto->chiamate], [1, ["pi_{$tag}_b9"]]);
} finally {
    q('DELETE FROM eventi_stripe WHERE stripe_event_id LIKE ?', ["{$tag}_%"]);
    if ($colletta) q('DELETE FROM collette WHERE id = ?', [$colletta]);
    if ($prodotto) q('DELETE FROM prodotti WHERE id = ?', [$prodotto]);
    if ($fornitore) q('DELETE FROM fornitori WHERE id = ?', [$fornitore]);
    foreach ($utenti as $id) q('DELETE FROM utenti WHERE id = ?', [$id]);
}

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test FALLITI\n";
exit($fail === 0 ? 0 : 1);
