<?php
declare(strict_types=1);

/**
 * Connessione PDO condivisa.
 * EMULATE_PREPARES => false non e' un dettaglio: senza, PHP costruisce la
 * query come stringa e le prepared statement proteggono molto meno.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $file = __DIR__ . '/../config.php';
    if (!file_exists($file)) {
        json_errore('CONFIG_MANCANTE',
            'Manca api/config.php. Copiare config.example.php e metterci i valori veri.', 500);
    }
    $c = require $file;

    try {
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['db']};charset=utf8mb4",
            $c['user'], $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        error_log('DB: ' . $e->getMessage());   // il dettaglio nel log, non all'utente
        json_errore('DB_NON_RAGGIUNGIBILE',
            'Database non raggiungibile. Controllare che MySQL sia avviato in MAMP '
            . '(porta 8889) e che il database buypool esista.', 500);
    }
    return $pdo;
}
