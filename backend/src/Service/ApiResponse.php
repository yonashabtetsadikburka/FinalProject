<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponse
{
    public static function ok(mixed $dati = null, int $http = 200): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'dati' => $dati], $http, [], false);
    }

    public static function errore(string $codice, string $messaggio, int $http = 400): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'errore' => ['codice' => $codice, 'messaggio' => $messaggio]], $http, [], false);
    }

    /** Decode JSON body, throw AppException on invalid JSON */
    public static function corpo(\Symfony\Component\HttpFoundation\Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '' || $raw === false) return [];
        $d = json_decode($raw, true);
        if (!is_array($d)) throw new \App\Exception\AppException('JSON_NON_VALIDO', "Il corpo non e' JSON valido");
        return $d;
    }

    public static function campo(array $d, string $nome): string
    {
        $v = trim((string)($d[$nome] ?? ''));
        if ($v === '') throw new \App\Exception\AppException('CAMPO_MANCANTE', "Manca il campo: $nome");
        return $v;
    }

    public static function campoInt(array $d, string $nome, int $min = 1): int
    {
        $v = filter_var($d[$nome] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v === null || $v < $min) {
            throw new \App\Exception\AppException('CAMPO_NON_VALIDO', "Il campo $nome deve essere un intero >= $min");
        }
        return (int)$v;
    }
}
