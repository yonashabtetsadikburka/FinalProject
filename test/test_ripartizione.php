<?php
require __DIR__ . '/../api/lib/ripartizione.php';

function caso(string $titolo, array $p, int $perScatola, int $minScatole) {
    $tot = array_sum(array_column($p,'latte'));
    $scatole = intdiv($tot,$perScatola);
    echo "\n$titolo\n  chieste $tot latte -> $scatole scatole";
    if ($scatole < $minScatole) { echo " (sotto il minimo $minScatole: DECADUTA)\n"; return; }
    $disp = $scatole*$perScatola;
    echo " = $disp latte\n";
    $r = ripartisci($p, $disp);
    $somma = array_sum(array_column($r,'latte'));
    $byId = []; foreach ($p as $x) $byId[$x['utente_id']] = $x['latte'];
    usort($r, fn($a,$b)=>$a['utente_id']<=>$b['utente_id']);
    foreach ($r as $a) {
        $d = $a['latte'] - $byId[$a['utente_id']];
        printf("    utente %d: chiesto %2d -> %2d %s\n",
            $a['utente_id'], $byId[$a['utente_id']], $a['latte'], $d ? "($d)" : "(pieno)");
    }
    printf("    somma %d %s %d  %s\n", $somma, $somma===$disp?'==':'!=', $disp,
        $somma===$disp ? 'OK' : 'ERRORE');
}

$t = '2026-08-01 10:00:00';
caso('A  avanzo di domanda (3+2+5+4 = 14)', [
  ['utente_id'=>1,'latte'=>3,'created_at'=>$t],
  ['utente_id'=>2,'latte'=>2,'created_at'=>$t],
  ['utente_id'=>3,'latte'=>5,'created_at'=>$t],
  ['utente_id'=>4,'latte'=>4,'created_at'=>$t]], 4, 2);

caso('B  multiplo esatto (4+4+4+4 = 16)', [
  ['utente_id'=>1,'latte'=>4,'created_at'=>$t],
  ['utente_id'=>2,'latte'=>4,'created_at'=>$t],
  ['utente_id'=>3,'latte'=>4,'created_at'=>$t],
  ['utente_id'=>4,'latte'=>4,'created_at'=>$t]], 4, 2);

caso('C  sotto il minimo (3+2+2 = 7)', [
  ['utente_id'=>1,'latte'=>3,'created_at'=>$t],
  ['utente_id'=>2,'latte'=>2,'created_at'=>$t],
  ['utente_id'=>3,'latte'=>2,'created_at'=>$t]], 4, 2);

caso('D  parita di resti -> vince chi ha aderito prima', [
  ['utente_id'=>1,'latte'=>3,'created_at'=>'2026-08-01 09:00:00'],
  ['utente_id'=>2,'latte'=>3,'created_at'=>'2026-08-01 10:00:00'],
  ['utente_id'=>3,'latte'=>3,'created_at'=>'2026-08-01 11:00:00']], 4, 1);
