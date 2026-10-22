<?php
declare(strict_types=1);

/** Test standalone della validazione dei campi del modulo. Uso: php tests/proposte_form_test.php */
require __DIR__ . '/../api/lib/risposta.php';
require __DIR__ . '/../api/lib/proposte_regole.php';

$fail = 0;
function verifica(string $nome, bool $ok): void
{
    global $fail;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $nome . "\n";
    if (!$ok) $fail++;
}
function campi(array $extra = []): array
{
    return array_merge(['nome_prodotto' => 'Cuffie wireless', 'descrizione' => 'Cuffie con cancellazione del rumore'], $extra);
}
function errori(array $in): array { return proposta_valida_campi($in)[0]; }

verifica('modulo valido senza errori', errori(campi()) === []);
verifica('nome di 2 caratteri rifiutato', isset(errori(campi(['nome_prodotto' => 'ab']))['nome_prodotto']));
verifica('nome di 3 caratteri accettato', !isset(errori(campi(['nome_prodotto' => 'abc']))['nome_prodotto']));
verifica('nome di 80 caratteri accettato', !isset(errori(campi(['nome_prodotto' => str_repeat('a', 80)]))['nome_prodotto']));
verifica('nome di 81 caratteri rifiutato', isset(errori(campi(['nome_prodotto' => str_repeat('a', 81)]))['nome_prodotto']));
verifica('spazi multipli normalizzati', proposta_valida_campi(campi(['nome_prodotto' => "  Cuffie    wireless "]))[1]['nome_prodotto'] === 'Cuffie wireless');
verifica('descrizione obbligatoria', isset(errori(campi(['descrizione' => '  ']))['descrizione']));
verifica('descrizione di 300 caratteri accettata', !isset(errori(campi(['descrizione' => str_repeat('è', 300)]))['descrizione']));
verifica('descrizione di 301 caratteri rifiutata', isset(errori(campi(['descrizione' => str_repeat('è', 301)]))['descrizione']));
verifica('link https accettato', !isset(errori(campi(['link_riferimento' => 'https://www.esempio.it/p?id=1']))['link_riferimento']));
verifica('link http accettato', !isset(errori(campi(['link_riferimento' => 'http://esempio.it']))['link_riferimento']));
verifica('link javascript: rifiutato', isset(errori(campi(['link_riferimento' => 'javascript:alert(1)']))['link_riferimento']));
verifica('link ftp: rifiutato', isset(errori(campi(['link_riferimento' => 'ftp://esempio.it/file']))['link_riferimento']));
verifica('link senza schema rifiutato', isset(errori(campi(['link_riferimento' => 'esempio.it']))['link_riferimento']));
verifica('link oltre 500 caratteri rifiutato', isset(errori(campi(['link_riferimento' => 'https://esempio.it/' . str_repeat('a', 500)]))['link_riferimento']));
verifica('link vuoto = null', proposta_valida_campi(campi())[1]['link_riferimento'] === null);
verifica('prezzo con virgola accettato (49,90)', proposta_valida_campi(campi(['prezzo_indicativo' => '49,90']))[1]['prezzo_indicativo'] === '49.90');
verifica('prezzo 0 accettato', !isset(errori(campi(['prezzo_indicativo' => '0']))['prezzo_indicativo']));
verifica('prezzo negativo rifiutato', isset(errori(campi(['prezzo_indicativo' => '-5']))['prezzo_indicativo']));
verifica('prezzo non numerico rifiutato', isset(errori(campi(['prezzo_indicativo' => 'abc']))['prezzo_indicativo']));
verifica('prezzo con 3 decimali rifiutato', isset(errori(campi(['prezzo_indicativo' => '1,999']))['prezzo_indicativo']));
verifica('prezzo vuoto = null', proposta_valida_campi(campi())[1]['prezzo_indicativo'] === null);
verifica('categoria numerica accettata', proposta_valida_campi(campi(['id_categoria' => '2']))[1]['id_categoria'] === 2);
verifica('categoria non valida rifiutata', isset(errori(campi(['id_categoria' => 'x']))['id_categoria']));

verifica('ricerca normalizzata (minuscolo e spazi)', proposta_normalizza_ricerca('  CUFFIE   Wireless ') === 'cuffie wireless');
verifica('parola chiave = la piu\' lunga >= 4', proposta_parola_chiave('le cuffie wireless') === 'wireless');
verifica('nessuna parola chiave se tutte corte', proposta_parola_chiave('tv 4k') === null);
verifica('limite anti-spam = 5', PROPOSTE_MAX_ATTIVE_PER_UTENTE === 5);

echo $fail === 0 ? "\nTutti i test superati\n" : "\n$fail test FALLITI\n";
exit($fail === 0 ? 0 : 1);
