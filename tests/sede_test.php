<?php
declare(strict_types=1);

/** Test standalone della sede di ritiro effettiva. Uso: php tests/sede_test.php */
require __DIR__ . '/../api/lib/sede.php';

$fail = 0;
function uguale(string $nome, $ottenuto, $atteso): void
{
    global $fail;
    $ok = $ottenuto === $atteso;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $nome . ($ok ? '' : '  (ottenuto ' . var_export($ottenuto, true) . ', atteso ' . var_export($atteso, true) . ')') . "\n";
    if (!$ok) $fail++;
}

uguale('gruppo con la sua sede: usa quella', sede_effettiva(2, 1), 2);
uguale('gruppo senza sede (NULL): usa la predefinita', sede_effettiva(null, 1), 1);
uguale('gruppo con sede 0: usa la predefinita', sede_effettiva(0, 1), 1);
uguale('nessuna sede in tabella: ritiro non disponibile', sede_effettiva(null, null), null);
uguale('sede del gruppo anche senza predefinita', sede_effettiva(3, null), 3);

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test falliti\n";
exit($fail === 0 ? 0 : 1);
