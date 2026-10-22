<?php
declare(strict_types=1);

/**
 * Verifica dell'elaborazione immagini con file reali generati al volo.
 * Uso:  php tests/immagini_test.php
 * Se GD non e' attivo in php.ini:  php -d extension=gd -d extension=fileinfo tests/immagini_test.php
 */
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/immagini.php';

if (!extension_loaded('gd')) {
    echo "GD non disponibile: abilita extension=gd in php.ini (oppure: php -d extension=gd tests/immagini_test.php)\n";
    exit(2);
}

$fail = 0;
function verifica(string $nome, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $nome . ($ok || $extra === '' ? '' : "  ($extra)") . "\n";
    if (!$ok) $fail++;
}
function rifiuto(string $path, ?string $dir = null): ?string
{
    try { immagine_normalizza($path, $dir ?? $GLOBALS['uscita'], false); return null; }
    catch (AppError $e) { return $e->codice; }
}
function rimuovi_dir(string $d): void
{
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
    @rmdir($d);
}

$base = sys_get_temp_dir() . '/bp_img_test_' . bin2hex(random_bytes(4));
$in = $base . '/in';
$uscita = $base . '/out';
mkdir($in, 0777, true);

/** Immagine di prova a colori con un gradiente. */
function crea(int $w, int $h, bool $alpha = false)
{
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) { imagealphablending($im, false); imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); }
    else for ($y = 0; $y < $h; $y += 10) imagefilledrectangle($im, 0, $y, $w, $y + 9, imagecolorallocate($im, ($y * 7) % 256, 90, 200));
    if ($alpha) imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h, 2), imagecolorallocatealpha($im, 255, 0, 0, 0));
    return $im;
}

/** Inserisce un blocco EXIF (con orientamento, descrizione segreta e GPS) subito dopo l'SOI di un JPEG. */
function con_exif(string $jpeg, int $orientamento): string
{
    $segreto = "SECRET_METADATA_12345\0";                       // 22 byte
    $ifd0 = pack('v', 3)
        . pack('vvVV', 0x010E, 2, strlen($segreto), 50)         // ImageDescription -> offset 50
        . pack('vvVv', 0x0112, 3, 1, $orientamento) . "\0\0"    // Orientation
        . pack('vvVV', 0x8825, 4, 1, 72)                        // puntatore GPS IFD
        . pack('V', 0);
    $gps = pack('v', 1) . pack('vvV', 0x0001, 2, 2) . "N\0\0\0" . pack('V', 0);
    $tiff = 'II' . pack('v', 42) . pack('V', 8) . $ifd0 . $segreto . $gps;
    $app1 = "Exif\0\0" . $tiff;
    return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
}

function scrivi(string $path, $im, string $tipo = 'jpeg'): string
{
    if ($tipo === 'jpeg') imagejpeg($im, $path, 92);
    elseif ($tipo === 'png') imagepng($im, $path);
    elseif ($tipo === 'webp') imagewebp($im, $path, 90);
    elseif ($tipo === 'gif') imagegif($im, $path);
    return $path;
}

// --- Casi validi ---
$jpeg = scrivi("$in/foto.jpg", crea(800, 600));
$r = immagine_normalizza($jpeg, $uscita, false);
$info = getimagesize("$uscita/{$r['file']}");
verifica('JPEG valido accettato', $info !== false && $r['w'] === 800 && $r['h'] === 600);
verifica('output WebP o JPEG valido', $info !== false && in_array($info[2], [IMAGETYPE_WEBP, IMAGETYPE_JPEG], true));
verifica('nome generato dal server (32 hex) e diverso dall\'originale', (bool)preg_match('/^[a-f0-9]{32}\.(webp|jpg)$/', $r['file']) && $r['file'] !== 'foto.jpg');
verifica('miniatura creata', is_file("$uscita/{$r['thumb']}"));
verifica('.htaccess anti-esecuzione creato nella cartella', is_file("$uscita/.htaccess") && str_contains((string)file_get_contents("$uscita/.htaccess"), 'engine off'));

$grande = scrivi("$in/grande.jpg", crea(3000, 2000));
$r = immagine_normalizza($grande, $uscita, false);
$t = getimagesize("$uscita/{$r['thumb']}");
verifica('lato lungo <= 1200 px (3000x2000 -> 1200x800)', $r['w'] === 1200 && $r['h'] === 800);
verifica('miniatura <= 640 px', $t !== false && max($t[0], $t[1]) === 640);

$png = scrivi("$in/trasparente.png", crea(600, 600, true), 'png');
$r = immagine_normalizza($png, $uscita, false);
$out = ($r['formato'] === 'webp') ? imagecreatefromwebp("$uscita/{$r['file']}") : imagecreatefromjpeg("$uscita/{$r['file']}");
$c = imagecolorat($out, 5, 5);
verifica('PNG con trasparenza: fondo bianco', (($c >> 16) & 255) > 245 && (($c >> 8) & 255) > 245 && ($c & 255) > 245);

if (!empty(gd_info()['WebP Support'])) {
    $webp = scrivi("$in/foto.webp", crea(700, 700), 'webp');
    verifica('WebP valido accettato', is_array(immagine_normalizza($webp, $uscita, false)));
} else {
    echo "[SKIP] WebP: GD senza supporto WebP (l'output sara' JPEG)\n";
}

// --- Metadati ed orientamento ---
$conExif = "$in/exif.jpg";
file_put_contents($conExif, con_exif((string)file_get_contents(scrivi("$in/base.jpg", crea(800, 400))), 6));
verifica('input di prova contiene EXIF/GPS', str_contains((string)file_get_contents($conExif), 'SECRET_METADATA_12345'));
verifica('orientamento EXIF letto correttamente', immagine_orientamento_exif($conExif) === 6);
$r = immagine_normalizza($conExif, $uscita, false);
$bytes = (string)file_get_contents("$uscita/{$r['file']}");
verifica('metadati assenti nell\'output (EXIF/GPS/descrizione)', !str_contains($bytes, 'SECRET_METADATA') && !str_contains($bytes, 'Exif') && !str_contains($bytes, 'GPS'));
verifica('orientamento 6 raddrizzato: 800x400 -> 400x800', $r['w'] === 400 && $r['h'] === 800, "{$r['w']}x{$r['h']}");

// --- Rifiuti ---
$prima = count(glob("$uscita/*.{webp,jpg}", GLOB_BRACE));
$finto = "$in/finto.jpg";
file_put_contents($finto, 'Questo non e\' un\'immagine <?php echo 1; ?>');
verifica('.jpg con contenuto non immagine rifiutato', rifiuto($finto) === 'FOTO_FORMATO');

$svg = "$in/img.svg";
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><script>alert(1)</script></svg>');
verifica('SVG rifiutato', rifiuto($svg) === 'FOTO_FORMATO');

$gif = scrivi("$in/anim.gif", crea(400, 400), 'gif');
verifica('GIF rifiutata', rifiuto($gif) === 'FOTO_FORMATO');

$larga = scrivi("$in/larga.jpg", crea(6001, 400));
verifica('immagine > 6000 px rifiutata', rifiuto($larga) === 'FOTO_DIMENSIONI');

$piccola = scrivi("$in/piccola.jpg", crea(200, 200));
verifica('immagine < 300 px rifiutata', rifiuto($piccola) === 'FOTO_DIMENSIONI');

$pesante = "$in/pesante.jpg";
file_put_contents($pesante, (string)file_get_contents($jpeg) . str_repeat('A', IMG_MAX_BYTES));
verifica('file > 5 MB rifiutato', rifiuto($pesante) === 'FOTO_TROPPO_GRANDE');

verifica('file non caricato via HTTP rifiutato (is_uploaded_file)', (function () use ($jpeg, $uscita) {
    try { immagine_normalizza($jpeg, $uscita, true); return false; } catch (AppError $e) { return $e->codice === 'FOTO_NON_VALIDA'; }
})());

verifica('nessun file lasciato in uscita dopo i rifiuti', count(glob("$uscita/*.{webp,jpg}", GLOB_BRACE)) === $prima);

$memoria = ini_get('memory_limit');
$enorme = scrivi("$in/enorme.jpg", crea(4000, 3000));
$r = immagine_normalizza($enorme, $uscita, false);
verifica('foto 4000x3000 elaborata e memory_limit ripristinato', $r['w'] === 1200 && ini_get('memory_limit') === $memoria);

// --- Cancellazione ---
$r = immagine_normalizza($jpeg, $uscita, false);
immagine_elimina($r['file'], $uscita);
verifica('immagine_elimina rimuove file e miniatura', !is_file("$uscita/{$r['file']}") && !is_file("$uscita/{$r['thumb']}"));
file_put_contents("$base/vittima.jpg", 'x');
immagine_elimina('../vittima.jpg', $uscita);
verifica('immagine_elimina rifiuta nomi non validi (path traversal)', is_file("$base/vittima.jpg"));
@unlink("$base/vittima.jpg");

rimuovi_dir($in);
rimuovi_dir($uscita);
@rmdir($base);

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test FALLITI\n";
exit($fail === 0 ? 0 : 1);
