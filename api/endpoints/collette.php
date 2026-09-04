<?php
declare(strict_types=1);

/**
 * COLLETTE = campagne di acquisto di gruppo (una per prodotto).
 * L'avanzamento (quantita_attuale) e' la SOMMA delle prenotazioni attive:
 * lo calcolo qui con una sottoquery, cosi' e' sempre coerente con lo stato.
 */
function _sql_collette(): string
{
    return
    'SELECT c.id_colletta, c.id_prodotto, c.quantita_minima, c.data_inizio,
            c.data_limite, c.stato, c.data_agg_stato,
            p.nome AS prodotto, p.descrizione AS prodotto_descrizione,
            p.image_url, p.prezzo_unitario, p.id_fornitore,
            fd.nome_azienda AS fornitore,
            (SELECT COALESCE(SUM(pr.quantita),0)
               FROM prenotazioni pr
              WHERE pr.id_colletta = c.id_colletta
                AND pr.stato IN (\'prenotata\',\'confermata\')) AS quantita_attuale,
            (SELECT COUNT(*)
               FROM prenotazioni pr
              WHERE pr.id_colletta = c.id_colletta
                AND pr.stato IN (\'prenotata\',\'confermata\')) AS partecipanti
       FROM collette c
       JOIN prodotti p             ON p.id_prodotto = c.id_prodotto
       JOIN fornitori_dettagli fd  ON fd.id_utente  = p.id_fornitore';
}

function _normalizza_colletta(array $r): array
{
    $minima  = (int)$r['quantita_minima'];
    $attuale = (int)$r['quantita_attuale'];
    return [
        'id'               => (int)$r['id_colletta'],
        'id_prodotto'      => (int)$r['id_prodotto'],
        'quantita_minima'  => $minima,
        'quantita_attuale' => $attuale,
        'percentuale'      => $minima > 0 ? min(100, (int)round($attuale / $minima * 100)) : 0,
        'soglia_raggiunta' => $attuale >= $minima,
        'partecipanti'     => (int)$r['partecipanti'],
        'data_inizio'      => $r['data_inizio'],
        'data_limite'      => $r['data_limite'],
        'stato'            => $r['stato'],
        'data_agg_stato'   => $r['data_agg_stato'],
        // dati prodotto/fornitore annidati come si aspetta il front-end
        'prodotto' => [
            'id'              => (int)$r['id_prodotto'],
            'nome'            => $r['prodotto'],
            'descrizione'     => $r['prodotto_descrizione'],
            'image_url'       => $r['image_url'],
            'prezzo_unitario' => (float)$r['prezzo_unitario'],
        ],
        'fornitore' => [
            'id'           => (int)$r['id_fornitore'],
            'nome_azienda' => $r['fornitore'],
        ],
    ];
}

function collette_elenco(): void
{
    richiedi_login();
    $rows = db()->query(_sql_collette() . ' ORDER BY c.data_limite ASC')->fetchAll();
    $out  = array_map('_normalizza_colletta', $rows);
    // lo stato puo' cambiare per scadenza: ricalcolo in lettura.
    foreach ($out as &$c) { $c['stato'] = ricalcola_stato($c['id']); }
    json_ok($out);
}

function collette_dettaglio(int $id): void
{
    $io = richiedi_login();
    $st = db()->prepare(_sql_collette() . ' WHERE c.id_colletta = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new AppError('COLLETTA_INESISTENTE', 'Colletta non trovata', 404);

    $c = _normalizza_colletta($r);
    $c['stato'] = ricalcola_stato($id);

    // partecipanti
    $st = db()->prepare(
        'SELECT pr.id_utente, u.nome, u.cognome, pr.quantita, pr.stato,
                pr.data_prenotazione
           FROM prenotazioni pr JOIN utenti u ON u.id_utente = pr.id_utente
          WHERE pr.id_colletta = ? AND pr.stato IN (\'prenotata\',\'confermata\')
          ORDER BY pr.data_prenotazione ASC');
    $st->execute([$id]);
    $c['prenotazioni'] = array_map(fn($p) => [
        'id_utente' => (int)$p['id_utente'],
        'nome'      => $p['nome'],
        'cognome'   => $p['cognome'],
        'quantita'  => (int)$p['quantita'],
        'stato'     => $p['stato'],
    ], $st->fetchAll());

    // la mia prenotazione (se c'e')
    $c['mia_prenotazione'] = null;
    foreach ($c['prenotazioni'] as $p) {
        if ($p['id_utente'] === $io) { $c['mia_prenotazione'] = $p; break; }
    }

    json_ok($c);
}

/**
 * Creazione colletta. Riservata all'admin: e' l'agenzia che apre le
 * campagne sul catalogo.
 */
function collette_crea(): void
{
    richiedi_admin();
    $d           = corpo();
    $id_prodotto = campo_int($d, 'id_prodotto');
    $data_limite = campo($d, 'data_limite');       // 'YYYY-MM-DD HH:MM:SS'

    $ts = strtotime($data_limite);
    if ($ts === false) throw new AppError('DATA_NON_VALIDA', 'Formato data_limite non valido');
    if ($ts <= time())  throw new AppError('DATA_NEL_PASSATO', 'La data limite deve essere futura');

    $st = db()->prepare('SELECT quantita_minima FROM prodotti WHERE id_prodotto = ?');
    $st->execute([$id_prodotto]);
    $p = $st->fetch();
    if (!$p) throw new AppError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);

    // la soglia si COPIA dal prodotto, salvo override esplicito
    $minima = isset($d['quantita_minima'])
        ? campo_int($d, 'quantita_minima')
        : (int)$p['quantita_minima'];

    $st = db()->prepare(
        'INSERT INTO collette (id_prodotto, quantita_minima, data_limite)
         VALUES (?,?,?)');
    $st->execute([$id_prodotto, $minima, date('Y-m-d H:i:s', $ts)]);

    json_ok(['id' => (int)db()->lastInsertId()], 201);
}

/**
 * Cambio di stato manuale dell'admin: ordine_fornitore / consegnata /
 * annullata. Gli stati automatici (in_corso/riuscita/fallita) li gestisce
 * ricalcola_stato().
 */
function collette_aggiorna(int $id): void
{
    richiedi_admin();
    $stato = campo(corpo(), 'stato');
    imposta_stato_manuale($id, $stato);
    json_ok(['id' => $id, 'stato' => $stato]);
}
