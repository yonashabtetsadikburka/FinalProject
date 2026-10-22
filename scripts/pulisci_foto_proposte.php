<?php
declare(strict_types=1);

/**
 * Elimina i file orfani di api/uploads/proposals/ (foto non piu' referenziate da nessuna proposta
 * ne' da un prodotto/campagna). Idempotente. Di default NON cancella: elenca soltanto.
 * Uso:  php scripts/pulisci_foto_proposte.php            (anteprima)
 *       php scripts/pulisci_foto_proposte.php --applica  (cancella)
 * Si possono tenere solo i file piu' vecchi di 1 ora, per non toccare upload in corso.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/immagini.php';

$applica = in_array('--applica', $argv ?? [], true);
$dir = immagine_cartella();
if (!is_dir($dir)) { echo "Cartella assente: nulla da fare\n"; exit(0); }

$pdo = db();
$usati = [];
foreach ($pdo->query('SELECT foto_file FROM proposte_prodotti WHERE foto_file IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $f) {
    $usati[$f] = true;
    $usati[immagine_nome_miniatura($f)] = true;
}
// Proposte pubblicate: la foto grande vive anche in immagini_prodotto
foreach ($pdo->query('SELECT url FROM immagini_prodotto WHERE url LIKE \'uploads/proposals/%\'')->fetchAll(PDO::FETCH_COLUMN) as $u) {
    $f = basename($u);
    $usati[$f] = true;
    $usati[immagine_nome_miniatura($f)] = true;
}

$orfani = 0;
$limite = time() - 3600;
foreach (scandir($dir) as $nome) {
    if ($nome === '.' || $nome === '..' || $nome === '.htaccess') continue;
    $percorso = $dir . '/' . $nome;
    if (!is_file($percorso) || isset($usati[$nome])) continue;
    if (filemtime($percorso) > $limite) continue;
    $orfani++;
    echo ($applica ? 'Elimino ' : 'Orfano  ') . $nome . "\n";
    if ($applica) @unlink($percorso);
}
echo $orfani . ($applica ? ' file eliminati' : ' file orfani (usa --applica per eliminarli)') . "\n";
