<?php
declare(strict_types=1);

/**
 * Test standalone di email/notifiche evento: formato importi, termine saldo, template, idempotenza del registro.
 * Nessun database: il registro e' simulato (stessa regola della chiave unica email_inviate).
 * Uso: php tests/eventi_test.php
 */
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/prezzi.php';
require __DIR__ . '/../api/lib/mail.php';
require __DIR__ . '/../api/lib/notifiche_eventi.php';

$fail = 0;
function verifica(string $nome, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $nome . ($ok || $extra === '' ? '' : "  ($extra)") . "\n";
    if (!$ok) $fail++;
}
function uguale(string $nome, $ottenuto, $atteso): void
{
    verifica($nome, $ottenuto === $atteso, 'ottenuto ' . var_export($ottenuto, true) . ', atteso ' . var_export($atteso, true));
}

// db() per lo shutdown flush: senza database il flush fallisce in silenzio (e' il contratto "mai bloccare")
if (!function_exists('db')) { function db(): PDO { throw new RuntimeException('nessun database nel test'); } }

// ---- formato italiano ----
uguale('importo: €3.659,58', formatta_euro(3659.58), '€3.659,58');
uguale('importo: €24,60', formatta_euro(24.6), '€24,60');
uguale('importo: €0,50', formatta_euro(0.5), '€0,50');
uguale('data GG/MM', formatta_ggmm('2026-10-05 14:30:00'), '05/10');
uguale('data GG/MM non valida', formatta_ggmm(null), '');
uguale('termine "GG/MM alle HH:MM"', formatta_scadenza_ora('2026-10-05 14:30:00'), '05/10 alle 14:30');

// ---- termine saldo: 48h, promemoria nelle ultime 24h ----
$adesso = 1_800_000_000;
uguale('termine: 48h dal fallimento', SALDO_TERMINE_ORE, 48);
uguale('fase: oltre 24h dalla scadenza = attesa', saldo_fase($adesso + 25 * 3600, $adesso), 'attesa');
uguale('fase: esattamente 24h = promemoria', saldo_fase($adesso + 24 * 3600, $adesso), 'promemoria');
uguale('fase: 1h alla scadenza = promemoria', saldo_fase($adesso + 3600, $adesso), 'promemoria');
uguale('fase: scadenza raggiunta = scaduto', saldo_fase($adesso, $adesso), 'scaduto');
uguale('fase: scadenza passata = scaduto', saldo_fase($adesso - 1, $adesso), 'scaduto');
uguale('fase: nessun termine (dati precedenti la migrazione) = attesa', saldo_fase(null, $adesso), 'attesa');

// ---- template: escape e contenuto ----
$h = mail_template_evento('Titolo <b>', ['Ciao <script>alert(1)</script>'], ['Importo' => '€10,00'], 'Vai', 'https://x.test/a?b=1&c="2"');
verifica('template: niente HTML dell\'utente non escapato', strpos($h, '<script>') === false && strpos($h, 'Titolo <b>') === false);
verifica('template: link escapato', strpos($h, 'b=1&amp;c=&quot;2&quot;') !== false);
verifica('template: riga chiave/valore', strpos($h, 'Importo') !== false && strpos($h, '€10,00') !== false);
verifica('template: mai la parola "campagna"', stripos($h, 'campagna') === false);

// ---- idempotenza del registro (simulato) ----
final class FakeStmt
{
    public int $rc = 0;
    public function __construct(private FakePdo $pdo, private string $sql) {}
    public function execute(array $p = []): bool
    {
        if (str_contains($this->sql, 'INSERT IGNORE INTO email_inviate')) {
            $chiave = implode('|', array_slice($p, 0, 4));
            $this->rc = isset($this->pdo->registro[$chiave]) ? 0 : 1;
            if ($this->rc) { $this->pdo->registro[$chiave] = true; $this->pdo->id++; }
        } elseif (str_contains($this->sql, 'INSERT INTO notifiche')) {
            $this->pdo->notifiche[] = $p;
            $this->rc = 1;
        }
        return true;
    }
    public function rowCount(): int { return $this->rc; }
}
final class FakePdo extends PDO
{
    public array $registro = [];
    public array $notifiche = [];
    public int $id = 0;
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) { return new FakeStmt($this, $query); }
    #[\ReturnTypeWillChange]
    public function lastInsertId(?string $name = null) { return (string)$this->id; }
}

$pdo = new FakePdo();
$notifica = ['tipo' => 'RIMBORSO', 'titolo' => 'Acconto rimborsato', 'messaggio' => 'x', 'rif_tipo' => 'prenotazione', 'rif_id' => 7];
$a = evento_emetti($pdo, 'RIMBORSO', 'prenotazione', 7, 3, 'Oggetto', '<p>ok</p>', $notifica);
$b = evento_emetti($pdo, 'RIMBORSO', 'prenotazione', 7, 3, 'Oggetto', '<p>ok</p>', $notifica);
verifica('prima emissione: nuova', $a === true);
verifica('seconda emissione (webhook/cron doppio): ignorata', $b === false);
uguale('una sola notifica per evento', count($pdo->notifiche), 1);
verifica('stesso evento, altra adesione: parte', evento_emetti($pdo, 'RIMBORSO', 'prenotazione', 8, 3, 'O', '<p>x</p>', $notifica) === true);
verifica('stessa adesione, altro evento: parte', evento_emetti($pdo, 'SALDO_FALLITO', 'prenotazione', 7, 3, 'O', '<p>x</p>') === true);
verifica('stesso gruppo, altro utente: parte', evento_emetti($pdo, 'MINIMO_RAGGIUNTO', 'colletta', 1, 4, 'O', '<p>x</p>') === true
    && evento_emetti($pdo, 'MINIMO_RAGGIUNTO', 'colletta', 1, 5, 'O', '<p>x</p>') === true
    && evento_emetti($pdo, 'MINIMO_RAGGIUNTO', 'colletta', 1, 4, 'O', '<p>x</p>') === false);

// Errore del database (es. migrazione mancante): nessuna eccezione, l'evento di business non si ferma
final class PdoRotto extends PDO
{
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) { throw new PDOException('Table email_inviate doesn\'t exist'); }
}
$errore = null;
$esito = null;
try { $esito = evento_emetti(new PdoRotto(), 'RIMBORSO', 'prenotazione', 1, 1, 'O', '<p>x</p>'); } catch (Throwable $e) { $errore = $e; }
verifica('errore del registro: nessuna eccezione verso il chiamante', $errore === null && $esito === false);

// Lettura dati con colonna saldo_scadenza mancante: la query principale funziona, il termine resta null
final class PdoSenzaColonna extends PDO
{
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        if (str_contains($query, 'saldo_scadenza')) throw new PDOException("Unknown column 'saldo_scadenza'");
        return new class {
            public function execute(array $p = []): bool { return true; }
            public function fetch() { return ['id' => 1, 'id_utente' => 2]; }
        };
    }
}
$d = null; $errore = null;
try { $d = evento_dati_prenotazione(new PdoSenzaColonna(), 1); } catch (Throwable $e) { $errore = $e; }
verifica('colonna saldo_scadenza mancante: nessuna eccezione, termine null', $errore === null && is_array($d) && $d['saldo_scadenza'] === null);

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test falliti\n";
exit($fail === 0 ? 0 : 1);
