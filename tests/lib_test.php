<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da riga di comando
/** Utilita' condivise dai test end-to-end: client HTTP con cookie e asserzioni. */

$falliti = 0; $totale = 0;

function ok(string $nome, bool $cond, string $dettaglio = ''): void
{
    global $falliti, $totale;
    $totale++;
    echo ($cond ? "  ok      " : "  FALLITO ") . $nome . (!$cond && $dettaglio !== '' ? "   -> $dettaglio" : '') . "\n";
    if (!$cond) $falliti++;
}

/** Client HTTP con cookie jar proprio: ognuno e' un "browser" separato. */
class Client
{
    public string $jar;
    public int $id = 0;
    public function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'bp'); }
    public function __destruct() { @unlink($this->jar); }

    /** @return array{0:int,1:array} [http, json] */
    public function call(string $m, string $path, $body = null, array $headers = [], bool $multipart = false): array
    {
        $ch = curl_init($this->base . $path);
        $h = $headers;
        if ($body !== null && !$multipart) {
            if (!is_string($body)) $body = json_encode($body);
            $h[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$http, json_decode((string)$raw, true) ?? ['_raw' => $raw]];
    }
    /** Intestazioni della risposta (per controllare i cookie). */
    public function intestazioni(string $m, string $path, $body = null): string
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
        $out = (string)curl_exec($ch);
        return substr($out, 0, (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    }
    public function login(string $email): array
    {
        return $this->call('POST', '/login', ['email' => $email, 'password' => 'Demo1234!']);
    }
}
$codice = fn(array $r) => $r[1]['errore']['codice'] ?? '';

