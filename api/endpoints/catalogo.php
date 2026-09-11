<?php
declare(strict_types=1);

/**
 * Catalogo pubblico (dietro login): fornitori, prodotti, sedi di ritiro.
 * I fornitori sono utenti con ruolo='fornitore' + tabella di dettaglio.
 */

/** num_campagne = collette aperte sui prodotti del fornitore. */
function _sql_fornitori(): string
{
    return
    'SELECT u.id_utente, u.email, u.telefono, u.indirizzo,
            fd.nome_azienda, fd.descrizione, fd.logo_url,
            fd.partner_pubblico, fd.data_partnership,
            (SELECT COUNT(*)
               FROM collette c JOIN prodotti p ON p.id_prodotto = c.id_prodotto
              WHERE p.id_fornitore = u.id_utente) AS num_campagne
       FROM utenti u
       JOIN fornitori_dettagli fd ON fd.id_utente = u.id_utente
      WHERE u.ruolo = \'fornitore\'';
}

function _normalizza_fornitore(array $r): array
{
    return [
        'id'               => (int)$r['id_utente'],
        'nome_azienda'     => $r['nome_azienda'],
        'descrizione'      => $r['descrizione'],
        'logo_url'         => $r['logo_url'],
        'partner_pubblico' => (bool)$r['partner_pubblico'],
        'data_partnership' => $r['data_partnership'],
        'email_contatto'   => $r['email'],
        'telefono'         => $r['telefono'],
        'indirizzo'        => $r['indirizzo'],
        'num_campagne'     => (int)$r['num_campagne'],
    ];
}

function catalogo_fornitori(): void
{
    richiedi_login();
    $st = db()->query(_sql_fornitori() . ' AND fd.partner_pubblico = 1 ORDER BY fd.nome_azienda');
    json_ok(array_map('_normalizza_fornitore', $st->fetchAll()));
}

function catalogo_fornitore(int $id): void
{
    richiedi_login();
    $st = db()->prepare(_sql_fornitori() . ' AND u.id_utente = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new AppError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);

    $f = _normalizza_fornitore($r);

    $st = db()->prepare(
        'SELECT id_prodotto, nome, descrizione, image_url, prezzo_unitario,
                quantita_minima, stato
           FROM prodotti WHERE id_fornitore = ? AND stato = \'attivo\'
          ORDER BY nome');
    $st->execute([$id]);
    $f['prodotti'] = array_map('_normalizza_prodotto', $st->fetchAll());

    json_ok($f);
}

function _normalizza_prodotto(array $r): array
{
    return [
        'id'              => (int)$r['id_prodotto'],
        'id_fornitore'    => isset($r['id_fornitore']) ? (int)$r['id_fornitore'] : null,
        'nome'            => $r['nome'],
        'descrizione'     => $r['descrizione'],
        'image_url'       => $r['image_url'],
        'prezzo_unitario' => (float)$r['prezzo_unitario'],
        'quantita_minima' => (int)$r['quantita_minima'],
        'stato'           => $r['stato'] ?? null,
        'fornitore'       => $r['nome_azienda'] ?? null,
    ];
}

function catalogo_prodotti(): void
{
    richiedi_login();
    $sql = 'SELECT p.id_prodotto, p.id_fornitore, p.nome, p.descrizione,
                   p.image_url, p.prezzo_unitario, p.quantita_minima, p.stato,
                   fd.nome_azienda
              FROM prodotti p
              JOIN fornitori_dettagli fd ON fd.id_utente = p.id_fornitore';
    $par = [];
    if (isset($_GET['fornitore_id'])) {
        $fid = filter_var($_GET['fornitore_id'], FILTER_VALIDATE_INT);
        if ($fid === false) throw new AppError('CAMPO_NON_VALIDO', 'fornitore_id non valido');
        $sql .= ' WHERE p.id_fornitore = ?';
        $par[] = $fid;
    }
    $sql .= ' ORDER BY fd.nome_azienda, p.nome';
    $st = db()->prepare($sql);
    $st->execute($par);
    json_ok(array_map('_normalizza_prodotto', $st->fetchAll()));
}

function catalogo_sedi(): void
{
    richiedi_login();
    $rows = db()->query('SELECT * FROM sedi ORDER BY citta, nome')->fetchAll();
    json_ok(array_map(fn($s) => [
        'id'        => (int)$s['id_sede'],
        'nome'      => $s['nome'],
        'indirizzo' => $s['indirizzo'],
        'citta'     => $s['citta'],
        'telefono'  => $s['telefono'],
        'orari'     => $s['orari'],
    ], $rows));
}
