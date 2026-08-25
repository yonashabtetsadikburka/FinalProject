<?php
declare(strict_types=1);

function catalogo_fornitori(): void
{
    richiedi_login();
    $st = db()->query(
        'SELECT id, nome, comune, descrizione FROM fornitori WHERE attivo = 1 ORDER BY nome'
    );
    json_ok($st->fetchAll());
}

function catalogo_prodotti(): void
{
    richiedi_login();
    $sql = 'SELECT p.id, p.nome, p.unita, p.confezione, p.latte_per_scatola,
                   p.lotto_minimo, p.prezzo_scatola,
                   f.id AS fornitore_id, f.nome AS fornitore
              FROM prodotti p JOIN fornitori f ON f.id = p.fornitore_id';
    $par = [];
    if (isset($_GET['fornitore_id'])) {
        $fid = filter_var($_GET['fornitore_id'], FILTER_VALIDATE_INT);
        if ($fid === false) throw new AppError('CAMPO_NON_VALIDO', 'fornitore_id non valido');
        $sql .= ' WHERE f.id = ?';
        $par[] = $fid;
    }
    $sql .= ' ORDER BY f.nome, p.nome';
    $st = db()->prepare($sql);
    $st->execute($par);
    json_ok($st->fetchAll());
}

function catalogo_punti_ritiro(): void
{
    richiedi_login();
    json_ok(db()->query('SELECT * FROM punti_ritiro ORDER BY comune, nome')->fetchAll());
}
