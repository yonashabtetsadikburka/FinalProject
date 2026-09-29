<?php
declare(strict_types=1);

/**
 * Forma UNICA di tutte le risposte dell'API. Concordata con il front-end:
 *   { "ok": true,  "dati": ... }
 *   { "ok": false, "errore": { "codice": "...", "messaggio": "..." } }
 * Non cambiarla senza avvisare chi fa il client.
 */

class AppError extends Exception
{
    public string $codice;
    public int $http;

    public function __construct(string $codice, string $messaggio = '', int $http = 400)
    {
        parent::__construct($messaggio !== '' ? $messaggio : $codice);
        $this->codice = $codice;
        $this->http   = $http;
    }
}

function json_ok($dati = null, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'dati' => $dati],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_errore(string $codice, string $messaggio, int $http = 400): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false,
                      'errore' => ['codice' => $codice, 'messaggio' => $messaggio]],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function corpo(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    if (!is_array($d)) throw new AppError('JSON_NON_VALIDO', "Il corpo non e' JSON valido");
    return $d;
}

function campo(array $d, string $nome): string
{
    $v = trim((string)($d[$nome] ?? ''));
    if ($v === '') throw new AppError('CAMPO_MANCANTE', "Manca il campo: $nome");
    return $v;
}

function campo_int(array $d, string $nome, int $min = 1): int
{
    $v = filter_var($d[$nome] ?? null, FILTER_VALIDATE_INT);
    if ($v === false || $v === null || $v < $min) {
        throw new AppError('CAMPO_NON_VALIDO', "Il campo $nome deve essere un intero >= $min");
    }
    return (int)$v;
}
