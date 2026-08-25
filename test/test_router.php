<?php
function abbina(string $schema, string $percorso, ?array &$par): bool {
    $s = $schema   === '' ? [] : explode('/', $schema);
    $p = $percorso === '' ? [] : explode('/', $percorso);
    if (count($s) !== count($p)) return false;
    $par = [];
    foreach ($s as $i => $seg) {
        if ($seg === '{id}')      { if (!ctype_digit($p[$i])) return false; $par[] = (int)$p[$i]; }
        elseif ($seg === '{token}'){ if (!ctype_alnum($p[$i])) return false; $par[] = $p[$i]; }
        elseif ($seg !== $p[$i])   { return false; }
    }
    return true;
}
$casi = [
  ['campagne',                       'campagne',                     true,  []],
  ['campagne/{id}',                  'campagne/7',                   true,  [7]],
  ['campagne/{id}',                  'campagne/abc',                 false, null],
  ['campagne/{id}',                  'campagne',                     false, null],
  ['campagne/{id}/partecipazioni',   'campagne/12/partecipazioni',   true,  [12]],
  ['campagne/{id}/partecipazioni',   'campagne/12/assegnazioni',     false, null],
  ['mie/assegnazioni/{id}/qr',       'mie/assegnazioni/3/qr',        true,  [3]],
  ['ritiro/{token}',                 'ritiro/a1b2c3',                true,  ['a1b2c3']],
  ['ritiro/{token}',                 'ritiro/../../etc/passwd',      false, null],
  ['campagne',                       'campagne/7',                   false, null],
];
$ko = 0;
foreach ($casi as [$sch,$pat,$att,$parAtt]) {
    $par = null;
    $r = abbina($sch,$pat,$par);
    $ok = ($r === $att) && (!$att || $par === $parAtt);
    if (!$ok) { $ko++; printf("FALLITO  %-32s vs %-28s\n", $sch, $pat); }
}
echo $ko === 0 ? "tutti i " . count($casi) . " casi di routing OK\n" : "$ko falliti\n";
