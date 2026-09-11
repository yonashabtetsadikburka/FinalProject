<?php
declare(strict_types=1);

/**
 * PROPOSTE_PRODOTTI = wishlist: i clienti propongono prodotti, gli altri
 * votano. tot_voti = voti "favore".
 */
function proposte_elenco(): void
{
    $io = richiedi_login();
    $st = db()->prepare(
        'SELECT pp.id_proposta, pp.id_utente, pp.nome_prodotto, pp.descrizione,
                pp.image_url, pp.id_fornitore_suggerito, pp.stato,
                pp.data_proposta, pp.data_gestione,
                u.nome AS proponente_nome, u.cognome AS proponente_cognome,
                (SELECT COUNT(*) FROM voti_proposte v
                  WHERE v.id_proposta = pp.id_proposta AND v.valore_voto = \'favore\')
                  AS tot_voti,
                (SELECT COUNT(*) FROM voti_proposte v
                  WHERE v.id_proposta = pp.id_proposta AND v.id_utente = ?)
                  AS ho_votato
           FROM proposte_prodotti pp
           JOIN utenti u ON u.id_utente = pp.id_utente
          ORDER BY pp.data_proposta DESC');
    $st->execute([$io]);
    json_ok(array_map(fn($r) => [
        'id'                     => (int)$r['id_proposta'],
        'id_utente'              => (int)$r['id_utente'],
        'proponente'             => trim($r['proponente_nome'] . ' ' . $r['proponente_cognome']),
        'nome_prodotto'          => $r['nome_prodotto'],
        'descrizione'            => $r['descrizione'],
        'image_url'              => $r['image_url'],
        'id_fornitore_suggerito' => $r['id_fornitore_suggerito'] !== null ? (int)$r['id_fornitore_suggerito'] : null,
        'stato'                  => $r['stato'],
        'tot_voti'               => (int)$r['tot_voti'],
        'ho_votato'              => (int)$r['ho_votato'] > 0,
        'data_proposta'          => $r['data_proposta'],
        'data_gestione'          => $r['data_gestione'],
    ], $st->fetchAll()));
}

function proposte_crea(): void
{
    $io   = richiedi_login();
    $d    = corpo();
    $nome = campo($d, 'nome_prodotto');
    $desc = trim((string)($d['descrizione'] ?? '')) ?: null;
    $forn = isset($d['id_fornitore_suggerito']) ? campo_int($d, 'id_fornitore_suggerito') : null;

    $st = db()->prepare(
        'INSERT INTO proposte_prodotti
           (id_utente, nome_prodotto, descrizione, id_fornitore_suggerito)
         VALUES (?,?,?,?)');
    $st->execute([$io, $nome, $desc, $forn]);
    json_ok(['id' => (int)db()->lastInsertId()], 201);
}

/** Voto favore/contrario. Un voto per utente per proposta (upsert). */
function proposte_vota(int $id): void
{
    $io    = richiedi_login();
    $voto  = campo(corpo(), 'valore_voto');
    if (!in_array($voto, ['favore', 'contrario'], true)) {
        throw new AppError('VOTO_NON_VALIDO', 'valore_voto deve essere favore o contrario');
    }

    // proposta esistente?
    $st = db()->prepare('SELECT 1 FROM proposte_prodotti WHERE id_proposta = ?');
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('PROPOSTA_INESISTENTE', 'Proposta non trovata', 404);

    db()->prepare(
        'INSERT INTO voti_proposte (id_proposta, id_utente, valore_voto)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE valore_voto = VALUES(valore_voto), data_voto = NOW()')
        ->execute([$id, $io, $voto]);

    $st = db()->prepare(
        'SELECT COUNT(*) AS n FROM voti_proposte
          WHERE id_proposta = ? AND valore_voto = \'favore\'');
    $st->execute([$id]);
    json_ok(['tot_voti' => (int)$st->fetch()['n'], 'ho_votato' => true]);
}
