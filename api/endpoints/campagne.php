<?php
declare(strict_types=1);

/**
 * SELECT dell'avanzamento. Usata sia dall'elenco che dal dettaglio.
 *
 * Il doppio "% latte_per_scatola" finale NON e' di troppo: senza, quando il
 * totale e' gia' un multiplo esatto la query direbbe "mancano 4 latte"
 * invece di zero.
 */
function sql_campagne(): string
{
    return
    'SELECT c.id, c.stato, c.scadenza, c.soglia_scatole, c.regola_arrotondamento,
            c.aperta_da, c.referente_id, c.punto_ritiro_id,
            pr.id   AS prodotto_id,
            pr.nome AS prodotto,
            pr.unita, pr.confezione, pr.latte_per_scatola, pr.prezzo_scatola,
            f.nome  AS fornitore,
            pt.nome AS punto_ritiro,
            COALESCE(SUM(p.latte_richieste), 0) AS latte_totali,
            FLOOR(COALESCE(SUM(p.latte_richieste), 0) / pr.latte_per_scatola)
                AS scatole_complete,
            (pr.latte_per_scatola
              - MOD(COALESCE(SUM(p.latte_richieste), 0), pr.latte_per_scatola))
              % pr.latte_per_scatola AS latte_per_prossima,
            COUNT(DISTINCT p.utente_id) AS partecipanti
       FROM campagne c
       JOIN prodotti  pr ON pr.id = c.prodotto_id
       JOIN fornitori f  ON f.id  = pr.fornitore_id
       LEFT JOIN punti_ritiro   pt ON pt.id = c.punto_ritiro_id
       LEFT JOIN partecipazioni p  ON p.campagna_id = c.id';
}

/** Converte i tipi: MySQL restituisce tutto come stringa. */
function normalizza_campagna(array $r): array
{
    foreach (['id','soglia_scatole','latte_totali','scatole_complete',
              'latte_per_prossima','partecipanti','latte_per_scatola',
              'prodotto_id','aperta_da'] as $k) {
        if (isset($r[$k])) $r[$k] = (int)$r[$k];
    }
    foreach (['confezione','prezzo_scatola'] as $k) {
        if (isset($r[$k])) $r[$k] = (float)$r[$k];
    }
    $r['soglia_raggiunta'] = $r['scatole_complete'] >= $r['soglia_scatole'];
    return $r;
}

function campagne_elenco(): void
{
    richiedi_login();
    $st = db()->query(sql_campagne() . ' GROUP BY c.id ORDER BY c.scadenza ASC');
    $out = array_map('normalizza_campagna', $st->fetchAll());

    // Lo stato puo' essere cambiato per scadenza: ricalcolarlo in lettura.
    foreach ($out as &$c) { $c['stato'] = ricalcola_stato($c['id']); }
    json_ok($out);
}

function campagne_dettaglio(int $id): void
{
    richiedi_login();
    $st = db()->prepare(sql_campagne() . ' WHERE c.id = ? GROUP BY c.id');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('CAMPAGNA_INESISTENTE', 'Campagna non trovata', 404);

    $c = normalizza_campagna($c);
    $c['stato'] = ricalcola_stato($id);

    $st = db()->prepare(
        'SELECT p.utente_id, u.nome, p.latte_richieste, p.created_at
           FROM partecipazioni p JOIN utenti u ON u.id = p.utente_id
          WHERE p.campagna_id = ? ORDER BY p.created_at ASC');
    $st->execute([$id]);
    $c['partecipazioni'] = array_map(function ($p) {
        $p['utente_id'] = (int)$p['utente_id'];
        $p['latte_richieste'] = (int)$p['latte_richieste'];
        return $p;
    }, $st->fetchAll());

    $mio = utente_corrente_id();
    $c['mia_partecipazione'] = null;
    foreach ($c['partecipazioni'] as $p) {
        if ($p['utente_id'] === $mio) { $c['mia_partecipazione'] = $p['latte_richieste']; }
    }
    json_ok($c);
}

function campagne_crea(): void
{
    $io = richiedi_login();
    $d  = corpo();
    $prodotto_id = campo_int($d, 'prodotto_id');
    $scadenza    = campo($d, 'scadenza');           // 'YYYY-MM-DD HH:MM:SS'

    $ts = strtotime($scadenza);
    if ($ts === false) throw new AppError('DATA_NON_VALIDA', 'Formato scadenza non valido');
    if ($ts <= time())  throw new AppError('DATA_NEL_PASSATO', 'La scadenza deve essere futura');

    $st = db()->prepare('SELECT lotto_minimo FROM prodotti WHERE id = ?');
    $st->execute([$prodotto_id]);
    $p = $st->fetch();
    if (!$p) throw new AppError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);

    // La soglia si COPIA dal prodotto: se il fornitore cambia il minimo
    // l'anno prossimo, le campagne passate non si riscrivono da sole.
    $st = db()->prepare(
        'INSERT INTO campagne
           (prodotto_id, aperta_da, referente_id, punto_ritiro_id, soglia_scatole, scadenza)
         VALUES (?,?,?,?,?,?)');
    $st->execute([
        $prodotto_id, $io, $io,
        isset($d['punto_ritiro_id']) ? campo_int($d, 'punto_ritiro_id') : null,
        (int)$p['lotto_minimo'],
        date('Y-m-d H:i:s', $ts),
    ]);
    $id = (int)db()->lastInsertId();
    registra_evento($id, null, 'aperta', 'Campagna creata');
    json_ok(['id' => $id], 201);
}
