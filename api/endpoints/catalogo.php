<?php
declare(strict_types=1);

function catalogo_fornitori(): void
{
    richiedi_login();
    $st = db()->query(
        'SELECT f.id, f.nome_azienda AS nome, f.indirizzo, f.descrizione, f.logo_url, f.sito_web,
                f.categoria, f.email_contatto, f.telefono,
                COUNT(DISTINCT c.id) AS num_campagne
           FROM fornitori f
      LEFT JOIN prodotti p ON p.id_fornitore = f.id
      LEFT JOIN collette c ON c.id_prodotto = p.id
          WHERE f.partner_pubblico = 1
          GROUP BY f.id
          ORDER BY f.nome_azienda'
    );
    $out = array_map(function ($f) {
        $f['id'] = (int)$f['id'];
        $f['num_campagne'] = (int)$f['num_campagne'];
        return $f;
    }, $st->fetchAll());
    json_ok($out);
}

function catalogo_prodotti(): void
{
    $io = richiedi_login();
    // Filtro votabili spostato lato client (la pagina dettaglio fornitore
    // deve vedere anche i prodotti già in campagna): qui solo flag.
    $sql = 'SELECT p.id, p.nome, p.descrizione, p.prezzo_unitario, p.prezzo_base, p.quantita_minima,
                   p.id_fornitore, f.nome_azienda AS fornitore,
                   c.id AS categoria_id, c.nome AS categoria,
                   (SELECT url FROM immagini_prodotto WHERE id_prodotto = p.id ORDER BY principale DESC, ordine ASC LIMIT 1) AS immagine
              FROM prodotti p
              JOIN fornitori f ON f.id = p.id_fornitore
         LEFT JOIN categorie  c ON c.id = p.id_categoria
              WHERE p.stato = :stato';
    $par = ['stato' => 'attivo'];

    if (isset($_GET['fornitore_id'])) {
        $fid = filter_var($_GET['fornitore_id'], FILTER_VALIDATE_INT);
        if ($fid === false) throw new AppError('CAMPO_NON_VALIDO', 'fornitore_id non valido');
        $sql .= ' AND p.id_fornitore = :fid';
        $par['fid'] = $fid;
    }
    $sql .= ' ORDER BY f.nome_azienda, p.nome';
    $st = db()->prepare($sql);
    $st->execute($par);
    $out = array_map(function ($p) {
        $p['id'] = (int)$p['id'];
        $p['id_fornitore'] = (int)$p['id_fornitore'];
        $p['prezzo_unitario'] = (float)$p['prezzo_unitario'];
        $p['prezzo_base'] = $p['prezzo_base'] !== null ? (float)$p['prezzo_base'] : (float)$p['prezzo_unitario'];
        $p['quantita_minima'] = (int)$p['quantita_minima'];
        return $p;
    }, $st->fetchAll());
    json_ok(['prodotti' => $out]);
}

function catalogo_sedi(): void
{
    richiedi_login();
    json_ok(db()->query('SELECT * FROM sedi ORDER BY citta, nome')->fetchAll());
}
