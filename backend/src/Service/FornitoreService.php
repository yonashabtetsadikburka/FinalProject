<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\AppException;

class FornitoreService
{
    public function normalizzaUrl(mixed $url): ?string
    {
        $url = trim((string)($url ?? ''));
        if ($url === '') return null;
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new AppException('URL_NON_VALIDO', 'URL sito web non valido');
        }
        return $url;
    }

    public function salvaFoto(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new AppException('FOTO_ERRORE', 'Errore nel caricamento della foto');
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new AppException('FOTO_TROPPO_GRANDE', 'La foto deve essere al massimo 5MB');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new AppException('FOTO_NON_VALIDA', "Il file non e' un'immagine valida");
        }
        $estensioni = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!isset($estensioni[$info[2]])) {
            throw new AppException('FOTO_NON_VALIDA', 'Formato foto non valido (ammessi JPG, PNG, WebP)');
        }
        $nome = bin2hex(random_bytes(16)) . '.' . $estensioni[$info[2]];
        // In Symfony, uploads go to public/uploads/proposte
        $destDir = dirname(__DIR__, 2) . '/public/uploads/proposte';
        if (!is_dir($destDir)) mkdir($destDir, 0777, true);
        // Also ensure legacy path exists for backward compat
        $legacyDir = dirname(__DIR__, 2) . '/../api/uploads/proposte';
        // We save to public dir
        $dest = $destDir . '/' . $nome;
        // move_uploaded_file only works for real uploads; fallback to rename/copy for tests
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            if (!@rename($file['tmp_name'], $dest) && !@copy($file['tmp_name'], $dest)) {
                throw new AppException('FOTO_ERRORE', 'Impossibile salvare la foto');
            }
        }
        return 'uploads/proposte/' . $nome;
    }
}
