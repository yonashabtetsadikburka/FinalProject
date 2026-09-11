<?php
declare(strict_types=1);

/** Elenco utenti (solo admin). */
function admin_utenti(): void
{
    richiedi_admin();
    $rows = db()->query(
        'SELECT id_utente, nome, cognome, email, ruolo, stato, data_iscrizione
           FROM utenti ORDER BY data_iscrizione DESC')->fetchAll();
    json_ok(array_map(fn($u) => [
        'id'              => (int)$u['id_utente'],
        'nome'            => $u['nome'],
        'cognome'         => $u['cognome'],
        'email'           => $u['email'],
        'ruolo'           => $u['ruolo'],
        'stato'           => $u['stato'],
        'data_iscrizione' => $u['data_iscrizione'],
    ], $rows));
}

/** KPI per la dashboard admin. */
function admin_statistiche(): void
{
    richiedi_admin();
    $pdo = db();

    $utenti      = (int)$pdo->query('SELECT COUNT(*) AS n FROM utenti')->fetch()['n'];
    $prodotti    = (int)$pdo->query('SELECT COUNT(*) AS n FROM prodotti')->fetch()['n'];
    $prenotazioni= (int)$pdo->query('SELECT COUNT(*) AS n FROM prenotazioni')->fetch()['n'];
    $proposte    = (int)$pdo->query('SELECT COUNT(*) AS n FROM proposte_prodotti')->fetch()['n'];

    // collette per stato
    $per_stato = [];
    foreach ($pdo->query('SELECT stato, COUNT(*) AS n FROM collette GROUP BY stato') as $r) {
        $per_stato[$r['stato']] = (int)$r['n'];
    }

    json_ok([
        'utenti'            => $utenti,
        'prodotti'          => $prodotti,
        'prenotazioni'      => $prenotazioni,
        'proposte'          => $proposte,
        'collette_totali'   => array_sum($per_stato),
        'collette_per_stato'=> $per_stato,
    ]);
}
