<?php
declare(strict_types=1);

/** Test standalone delle regole pure. Uso: php tests/proposte_regole_test.php */
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/proposte_regole.php';

$fail = 0;
function verifica(string $nome, bool $ok): void
{
    global $fail;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $nome . "\n";
    if (!$ok) $fail++;
}
function codice(?array $e): ?string { return $e[0] ?? null; }

$creata = new DateTimeImmutable('2026-01-01 12:00:00');
$votabile = ['stato' => 'in_votazione', 'proponente_id' => 1];

verifica('soglia = 5', PROPOSTE_SOGLIA_VOTI === 5);
verifica('4 voti: resta in_votazione', proposta_stato_dopo_voto(4) === 'in_votazione');
verifica('5a votazione: in_valutazione', proposta_stato_dopo_voto(5) === 'in_valutazione');
verifica('voto valido', proposta_errore_voto($votabile, 2, false, false) === null);
verifica('voto doppio rifiutato', codice(proposta_errore_voto($votabile, 2, true, false)) === 'GIA_VOTATO');
verifica('voto sulla propria proposta rifiutato (403)', proposta_errore_voto($votabile, 1, false, false)[2] === 403);
verifica('voto su scaduta rifiutato', codice(proposta_errore_voto($votabile, 2, false, true)) === 'PROPOSTA_SCADUTA');
verifica('voto su in_valutazione rifiutato', codice(proposta_errore_voto(['stato' => 'in_valutazione', 'proponente_id' => 1], 2, false, false)) === 'PROPOSTA_NON_VOTABILE');
verifica('voto su pubblicata rifiutato', codice(proposta_errore_voto(['stato' => 'pubblicata', 'proponente_id' => 1], 2, false, false)) === 'PROPOSTA_NON_VOTABILE');
verifica('rimozione voto in_votazione ok', proposta_errore_ritiro($votabile, true, false) === null);
verifica('rimozione voto in_valutazione rifiutata', codice(proposta_errore_ritiro(['stato' => 'in_valutazione', 'proponente_id' => 1], true, false)) === 'VOTO_NON_RITIRABILE');
verifica('rimozione voto su scaduta rifiutata', codice(proposta_errore_ritiro($votabile, true, true)) === 'VOTO_NON_RITIRABILE');
verifica('rimozione senza voto rifiutata', codice(proposta_errore_ritiro($votabile, false, false)) === 'VOTO_INESISTENTE');

verifica('29 giorni: non scaduta', !proposta_scaduta('in_votazione', $creata, $creata->modify('+29 days')));
verifica('esattamente 30 giorni: ancora valida', !proposta_scaduta('in_votazione', $creata, $creata->modify('+30 days')));
verifica('30 giorni + 1 secondo: scaduta', proposta_scaduta('in_votazione', $creata, $creata->modify('+30 days +1 second')));
verifica('in_valutazione non scade mai', !proposta_scaduta('in_valutazione', $creata, $creata->modify('+90 days')));
verifica('stato scaduta e\' scaduta', proposta_scaduta('scaduta', $creata, $creata));

verifica('nome abbreviato', nome_abbreviato('Mario', 'Rossi') === 'Mario R.');
verifica('nome senza cognome', nome_abbreviato('Mario', '') === 'Mario');
verifica('iniziali', iniziali('mario', 'rossi') === 'MR');

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test FALLITI\n";
exit($fail === 0 ? 0 : 1);
