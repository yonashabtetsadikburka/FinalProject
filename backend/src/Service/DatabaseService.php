<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\AppException;

class DatabaseService
{
    private static ?\PDO $pdo = null;

    public function getPdo(): \PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $host = $_ENV['DATABASE_HOST'] ?? getenv('DATABASE_HOST') ?: '127.0.0.1';
        $port = $_ENV['DATABASE_PORT'] ?? getenv('DATABASE_PORT') ?: '8889';
        $db   = $_ENV['DATABASE_NAME'] ?? getenv('DATABASE_NAME') ?: 'buypool';
        $user = $_ENV['DATABASE_USER'] ?? getenv('DATABASE_USER') ?: 'root';
        $pass = $_ENV['DATABASE_PASSWORD'] ?? getenv('DATABASE_PASSWORD') ?: 'root';

        // Fallback to legacy config.php if exists (pour migration douce)
        $legacy = dirname(__DIR__, 2) . '/../api/config.php';
        // backend is at FinalProject/backend, legacy at FinalProject/api/config.php
        $legacy2 = dirname(__DIR__, 2) . '/api/config.php';
        // Try both
        foreach ([$legacy, $legacy2, __DIR__ . '/../../api/config.php'] as $f) {
            if (file_exists($f) && empty($_ENV['DATABASE_HOST'])) {
                $c = require $f;
                if (is_array($c)) {
                    $host = $c['host'] ?? $host;
                    $port = $c['port'] ?? $port;
                    $db   = $c['db'] ?? $db;
                    $user = $c['user'] ?? $user;
                    $pass = $c['pass'] ?? $pass;
                }
                break;
            }
        }

        try {
            self::$pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
                $user, $pass,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (\PDOException $e) {
            error_log('DB: ' . $e->getMessage());
            throw new AppException('DB_NON_RAGGIUNGIBILE',
                'Database non raggiungibile. Controllare che MySQL sia avviato (porta '.$port.') e che il database '.$db.' esista.', 500);
        }
        return self::$pdo;
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        $envMap = [
            'google_client_id' => 'GOOGLE_CLIENT_ID',
            'microsoft_client_id' => 'MICROSOFT_CLIENT_ID',
            'stripe_secret_key' => 'STRIPE_SECRET_KEY',
            'stripe_webhook_secret' => 'STRIPE_WEBHOOK_SECRET',
            'frontend_url' => 'FRONTEND_URL',
            'costo_spedizione' => 'COSTO_SPEDIZIONE',
        ];
        $envKey = $envMap[$key] ?? strtoupper($key);
        $val = $_ENV[$envKey] ?? getenv($envKey);
        if ($val !== false && $val !== null && $val !== '') return $val;

        // legacy config.php fallback
        foreach ([dirname(__DIR__,2).'/api/config.php', __DIR__.'/../../api/config.php'] as $f) {
            if (file_exists($f)) {
                $c = require $f;
                if (isset($c[$key])) return $c[$key];
            }
        }
        return $default;
    }
}
