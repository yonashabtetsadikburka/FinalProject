<?php
declare(strict_types=1);

/**
 * Elaborazione sicura delle foto caricate dai clienti (proposte).
 * Il file del client non viene mai conservato: viene decodificato e riscritto da zero con GD
 * (nessun metadato, nessun payload nascosto), ridimensionato e salvato con un nome casuale.
 */

const IMG_MAX_BYTES = 5 * 1024 * 1024;
const IMG_MAX_PX    = 6000;
const IMG_MIN_PX    = 300;
const IMG_LATO_MAX  = 1200;
const IMG_LATO_THUMB = 640;
const IMG_QUALITA_WEBP = 82;
const IMG_QUALITA_JPEG = 85;

const IMG_HTACCESS = <<<'HT'
# Solo immagini statiche: nessuna esecuzione di script in questa cartella
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
Options -ExecCGI -Indexes
RemoveHandler .php .phtml .phar
RemoveType .php .phtml .phar
Require all denied
<FilesMatch "^[a-f0-9]{32}(_t)?\.(webp|jpg)$">
    Require all granted
</FilesMatch>
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
</IfModule>

HT;

function immagine_cartella(): string
{
    return __DIR__ . '/../uploads/proposals';
}

/** Percorso relativo alla cartella api/ (come foto_path). */
function immagine_percorso_relativo(string $file): string
{
    return 'uploads/proposals/' . $file;
}

/** "abc.webp" -> "abc_t.webp" */
function immagine_nome_miniatura(string $file): string
{
    return preg_replace('/\.(webp|jpg)$/', '_t.$1', $file);
}

function immagine_nome_valido(string $file): bool
{
    return (bool)preg_match('/^[a-f0-9]{32}\.(webp|jpg)$/', $file);
}

/** Crea la cartella e, se manca, il suo .htaccess anti-esecuzione. */
function immagine_assicura_cartella(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('immagini: impossibile creare ' . $dir);
        throw new AppError('FOTO_ERRORE', 'Impossibile salvare la foto: riprova piu\' tardi', 500);
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, IMG_HTACCESS);
}

/** Orientamento EXIF (1-8) di un JPEG, senza l'estensione exif. 1 se assente/illeggibile. */
function immagine_orientamento_exif(string $path): int
{
    $f = @fopen($path, 'rb');
    if (!$f) return 1;
    $buf = (string)fread($f, 131072);
    fclose($f);
    if (strlen($buf) < 4 || substr($buf, 0, 2) !== "\xFF\xD8") return 1;
    $pos = 2;
    $len = strlen($buf);
    while ($pos + 4 <= $len) {
        if ($buf[$pos] !== "\xFF") return 1;
        $marker = ord($buf[$pos + 1]);
        if ($marker === 0xDA || $marker === 0xD9) return 1;             // inizio dati / fine
        $seg = unpack('n', substr($buf, $pos + 2, 2))[1];
        if ($marker === 0xE1 && substr($buf, $pos + 4, 6) === "Exif\0\0") {
            $t = substr($buf, $pos + 10, $seg - 8);                    // blocco TIFF
            if (strlen($t) < 8) return 1;
            $le = substr($t, 0, 2) === 'II';
            if (!$le && substr($t, 0, 2) !== 'MM') return 1;
            $u16 = fn(int $o) => strlen($t) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($t, $o, 2))[1] : 0;
            $u32 = fn(int $o) => strlen($t) >= $o + 4 ? unpack($le ? 'V' : 'N', substr($t, $o, 4))[1] : 0;
            $ifd = $u32(4);
            $n = $u16($ifd);
            for ($i = 0; $i < $n && $i < 64; $i++) {
                $e = $ifd + 2 + $i * 12;
                if ($u16($e) === 0x0112) {
                    $o = $u16($e + 8);
                    return ($o >= 1 && $o <= 8) ? $o : 1;
                }
            }
            return 1;
        }
        $pos += 2 + $seg;
    }
    return 1;
}

/** Applica l'orientamento EXIF e restituisce l'immagine raddrizzata. */
function immagine_raddrizza(GdImage $im, int $orient): GdImage
{
    switch ($orient) {
        case 2: imageflip($im, IMG_FLIP_HORIZONTAL); return $im;
        case 3: return imagerotate($im, 180, 0) ?: $im;
        case 4: imageflip($im, IMG_FLIP_VERTICAL); return $im;
        case 5: imageflip($im, IMG_FLIP_HORIZONTAL); return imagerotate($im, 90, 0) ?: $im;
        case 6: return imagerotate($im, -90, 0) ?: $im;
        case 7: imageflip($im, IMG_FLIP_HORIZONTAL); return imagerotate($im, -90, 0) ?: $im;
        case 8: return imagerotate($im, 90, 0) ?: $im;
        default: return $im;
    }
}

/** Ridimensiona (mai ingrandire) su canvas bianco: le trasparenze diventano bianche. */
function immagine_ridimensiona(GdImage $src, int $latoMax): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scala = min(1.0, $latoMax / max($w, $h));
    $nw = max(1, (int)round($w * $scala));
    $nh = max(1, (int)round($h * $scala));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/** "8M" -> byte (-1 / vuoto = illimitato). */
function immagine_ini_byte(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

function immagine_memory_limit_byte(): int
{
    return immagine_ini_byte((string)ini_get('memory_limit'));
}

function immagine_scrivi(GdImage $im, string $path, bool $webp): bool
{
    return $webp ? imagewebp($im, $path, IMG_QUALITA_WEBP) : imagejpeg($im, $path, IMG_QUALITA_JPEG);
}

/**
 * Valida e normalizza una foto. Ritorna ['file','thumb','w','h','formato'].
 * $daUpload=false salta is_uploaded_file (solo per i test con file locali).
 * Lancia AppError con messaggi chiari per l'utente (dettagli tecnici solo in error_log).
 */
function immagine_normalizza(string $tmp, ?string $dir = null, bool $daUpload = true): array
{
    $dir = $dir ?? immagine_cartella();
    if (!extension_loaded('gd')) {
        error_log('immagini: estensione GD non caricata');
        throw new AppError('FOTO_NON_DISPONIBILE', 'Il caricamento delle foto non e\' al momento disponibile', 503);
    }
    if ($daUpload && !is_uploaded_file($tmp)) {
        throw new AppError('FOTO_NON_VALIDA', 'File non valido', 422);
    }
    $dimensione = @filesize($tmp);
    if ($dimensione === false || $dimensione <= 0) {
        throw new AppError('FOTO_NON_VALIDA', 'File non valido', 422);
    }
    if ($dimensione > IMG_MAX_BYTES) {
        throw new AppError('FOTO_TROPPO_GRANDE', 'Il file supera 5 MB', 422);
    }

    // Tipo dal CONTENUTO (mai da estensione o Content-Type del client)
    $info = @getimagesize($tmp);
    $tipiAmmessi = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
    if ($info === false || !isset($tipiAmmessi[$info[2]])) {
        throw new AppError('FOTO_FORMATO', 'Formato non supportato: usa JPEG, PNG o WebP', 422);
    }
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime !== $tipiAmmessi[$info[2]]) {
            throw new AppError('FOTO_FORMATO', 'Formato non supportato: usa JPEG, PNG o WebP', 422);
        }
    }
    [$w, $h, $tipo] = [$info[0], $info[1], $info[2]];
    if ($w > IMG_MAX_PX || $h > IMG_MAX_PX) {
        throw new AppError('FOTO_DIMENSIONI', 'La foto e\' troppo grande: massimo ' . IMG_MAX_PX . '×' . IMG_MAX_PX . ' pixel', 422);
    }
    if ($w < IMG_MIN_PX || $h < IMG_MIN_PX) {
        throw new AppError('FOTO_DIMENSIONI', 'La foto e\' troppo piccola: minimo ' . IMG_MIN_PX . '×' . IMG_MIN_PX . ' pixel', 422);
    }

    $gd = gd_info();
    $webp = function_exists('imagewebp') && !empty($gd['WebP Support']);
    if ($tipo === IMAGETYPE_WEBP && empty($gd['WebP Support'])) {
        error_log('immagini: GD senza supporto WebP in lettura');
        throw new AppError('FOTO_FORMATO', 'Formato non supportato: usa JPEG o PNG', 422);
    }

    // Memoria: la bitmap decodificata pesa ~4 byte/pixel, piu' la copia raddrizzata
    $memoriaOriginale = ini_get('memory_limit');
    $serve = $w * $h * 4 * 2 + 32 * 1024 * 1024;
    $disponibile = immagine_memory_limit_byte() - memory_get_usage(true);
    if ($serve > $disponibile) {
        $nuovo = (int)ceil(($serve + memory_get_usage(true)) / 1048576) . 'M';
        if (@ini_set('memory_limit', $nuovo) === false) {
            error_log("immagini: memoria insufficiente per {$w}x{$h}");
            throw new AppError('FOTO_DIMENSIONI', 'Non riesco a elaborare una foto cosi\' grande: riducila e riprova', 422);
        }
    }

    $creati = [];
    $im = $ridotta = $mini = null;
    try {
        $decodifica = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$tipo];
        $im = @$decodifica($tmp);
        if (!$im) {
            throw new AppError('FOTO_NON_VALIDA', 'Il file non e\' un\'immagine valida', 422);
        }
        if ($tipo === IMAGETYPE_JPEG) {
            $im = immagine_raddrizza($im, immagine_orientamento_exif($tmp));
        }
        $ridotta = immagine_ridimensiona($im, IMG_LATO_MAX);
        $mini = immagine_ridimensiona($ridotta, IMG_LATO_THUMB);

        immagine_assicura_cartella($dir);
        $base = bin2hex(random_bytes(16));
        $file = $base . ($webp ? '.webp' : '.jpg');
        $thumb = immagine_nome_miniatura($file);
        $creati[] = $dir . '/' . $file;
        if (!immagine_scrivi($ridotta, $dir . '/' . $file, $webp)) {
            throw new AppError('FOTO_ERRORE', 'Impossibile salvare la foto: riprova', 500);
        }
        $creati[] = $dir . '/' . $thumb;
        if (!immagine_scrivi($mini, $dir . '/' . $thumb, $webp)) {
            throw new AppError('FOTO_ERRORE', 'Impossibile salvare la foto: riprova', 500);
        }
        return [
            'file' => $file, 'thumb' => $thumb,
            'w' => imagesx($ridotta), 'h' => imagesy($ridotta),
            'formato' => $webp ? 'webp' : 'jpg',
        ];
    } catch (Throwable $e) {
        foreach ($creati as $p) @unlink($p);
        if (!($e instanceof AppError)) {
            error_log('immagini: ' . $e->getMessage());
            throw new AppError('FOTO_ERRORE', 'Impossibile elaborare la foto: riprova con un\'altra immagine', 422);
        }
        throw $e;
    } finally {
        foreach ([$im, $ridotta, $mini] as $r) if ($r instanceof GdImage) imagedestroy($r);
        if (ini_get('memory_limit') !== $memoriaOriginale) @ini_set('memory_limit', (string)$memoriaOriginale);
    }
}

/** Elimina foto e miniatura. Il nome e' validato per evitare path traversal. */
function immagine_elimina(?string $file, ?string $dir = null): void
{
    if ($file === null || !immagine_nome_valido($file)) return;
    $dir = $dir ?? immagine_cartella();
    @unlink($dir . '/' . $file);
    @unlink($dir . '/' . immagine_nome_miniatura($file));
}
