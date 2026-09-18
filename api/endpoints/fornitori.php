<?php
declare(strict_types=1);

/**
 * ============================================================
 *  ANAGRAFICHE FORNITORI + INVITI DI ATTIVAZIONE
 * ============================================================
 *
 * Gli account fornitore riusano l'auth `utenti` (ruolo='fornitore').
 * Il collegamento e' `fornitori.id_utente`.
 * L'invito e' single-use con scadenza 7 giorni.
 */

function fornitori_admin_elenco(): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $st = db()->query(
        'SELECT f.id, f.nome_azienda, f.email_contatto, f.telefono, f.indirizzo,
                f.descrizione, f.piva, f.categoria, f.sito_web, f.partner_pubblico,
                f.num_campagne, f.id_utente, f.data_partnership,
                u.email AS email_account,
                (SELECT COUNT(*) FROM inviti_fornitore i WHERE i.id_fornitore = f.id AND i.usato = 0 AND i.scadenza > NOW()) AS inviti_pendenti
           FROM fornitori f
      LEFT JOIN utenti u ON u.id = f.id_utente
          ORDER BY f.nome_azienda ASC'
    );
    $out = array_map(function ($f) {
        $f['id'] = (int)$f['id'];
        $f['id_utente'] = $f['id_utente'] !== null ? (int)$f['id_utente'] : null;
        $f['num_campagne'] = (int)$f['num_campagne'];
        $f['inviti_pendenti'] = (int)$f['inviti_pendenti'];
        return $f;
    }, $st->fetchAll());
    json_ok($out);
}

function fornitori_admin_crea(): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $d = corpo();
    $nome = trim(campo($d, 'nome_azienda'));
    if ($nome === '') throw new AppError('NOME_MANCANTE', 'Nome azienda obbligatorio');

    $st = db()->prepare(
        'INSERT INTO fornitori (nome_azienda, email_contatto, telefono, indirizzo, descrizione, piva, categoria, sito_web)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $nome,
        $d['email_contatto'] ?? null,
        $d['telefono'] ?? null,
        $d['indirizzo'] ?? null,
        $d['descrizione'] ?? null,
        $d['piva'] ?? null,
        $d['categoria'] ?? null,
        normalizza_url($d['sito_web'] ?? null),
    ]);
    json_ok(['id' => (int)db()->lastInsertId()], 201);
}

/** Normalizza e valida un URL sito web (http/https). Ritorna null se vuoto. */
function normalizza_url($url): ?string
{
    $url = trim((string)($url ?? ''));
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new AppError('URL_NON_VALIDO', 'URL sito web non valido');
    }
    return $url;
}

/** Modifica scheda fornitore (solo admin). PUT /admin/fornitori/{id} */
function fornitori_admin_aggiorna(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $d = corpo();

    $st = db()->prepare('SELECT id FROM fornitori WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) throw new AppError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);

    $campi = [];
    $valori = [];
    foreach (['nome_azienda', 'email_contatto', 'telefono', 'indirizzo', 'descrizione', 'piva', 'categoria', 'logo_url'] as $k) {
        if (array_key_exists($k, $d)) {
            $v = trim((string)$d[$k]);
            if ($k === 'nome_azienda' && $v === '') throw new AppError('NOME_MANCANTE', 'Nome azienda obbligatorio');
            $campi[] = "$k = ?";
            $valori[] = $v !== '' ? $v : null;
        }
    }
    if (array_key_exists('sito_web', $d)) {
        $campi[] = 'sito_web = ?';
        $valori[] = normalizza_url($d['sito_web']);
    }
    if (array_key_exists('partner_pubblico', $d)) {
        $campi[] = 'partner_pubblico = ?';
        $valori[] = $d['partner_pubblico'] ? 1 : 0;
    }
    if (empty($campi)) throw new AppError('NESSUNA_MODIFICA', 'Nessun campo da aggiornare');

    $valori[] = $id;
    db()->prepare('UPDATE fornitori SET ' . implode(', ', $campi) . ' WHERE id = ?')->execute($valori);
    json_ok(['aggiornato' => true]);
}

function fornitori_invito_genera(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);

    $st = db()->prepare('SELECT id, nome_azienda, email_contatto, id_utente FROM fornitori WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) throw new AppError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);
    if ($f['id_utente'] !== null) {
        throw new AppError('GIA_COLLEGATO', 'Questo fornitore ha gia\' un account attivo collegato', 409);
    }
    if (empty($f['email_contatto'])) {
        throw new AppError('EMAIL_MANCANTE', 'Inserire prima una email di contatto per questo fornitore');
    }

    // Invalida inviti precedenti non usati
    db()->prepare('UPDATE inviti_fornitore SET usato = 1 WHERE id_fornitore = ? AND usato = 0')->execute([$id]);

    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO inviti_fornitore (id_fornitore, email, token, scadenza) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
    )->execute([$id, $f['email_contatto'], $token]);

    $cfg = require __DIR__ . '/../config.php';
    $link = rtrim($cfg['frontend_url'], '/') . '/fornitore-attiva.html?token=' . $token;

    json_ok(['token' => $token, 'link' => $link, 'scadenza_giorni' => 7], 201);
}

function fornitori_invito_verifica(string $token): void
{
    $st = db()->prepare(
        'SELECT i.email, i.scadenza, i.usato, f.nome_azienda
           FROM inviti_fornitore i JOIN fornitori f ON f.id = i.id_fornitore
          WHERE i.token = ?'
    );
    $st->execute([$token]);
    $inv = $st->fetch();
    if (!$inv) throw new AppError('INVITO_NON_VALIDO', 'Link di invito non valido', 404);
    if ((int)$inv['usato'] === 1 || strtotime($inv['scadenza']) < time()) {
        throw new AppError('INVITO_SCADUTO', 'Questo link di invito e\' scaduto o gia\' utilizzato', 410);
    }
    // Solo dati non sensibili
    json_ok(['nome_azienda' => $inv['nome_azienda'], 'email' => $inv['email']]);
}

function fornitori_attiva(): void
{
    $d = corpo();
    $token = trim($d['token'] ?? '');
    $nome = trim($d['nome'] ?? '');
    $cognome = trim($d['cognome'] ?? '');
    $password = (string)($d['password'] ?? '');
    if ($token === '') throw new AppError('TOKEN_MANCANTE', 'Token invito mancante');
    if ($nome === '') throw new AppError('NOME_MANCANTE', 'Nome referente obbligatorio');
    if (strlen($password) < 8) throw new AppError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT i.id, i.id_fornitore, i.email, i.scadenza, i.usato
               FROM inviti_fornitore i WHERE i.token = ? FOR UPDATE'
        );
        $st->execute([$token]);
        $inv = $st->fetch();
        if (!$inv) {
            $pdo->rollBack();
            throw new AppError('INVITO_NON_VALIDO', 'Link di invito non valido', 404);
        }
        if ((int)$inv['usato'] === 1 || strtotime($inv['scadenza']) < time()) {
            $pdo->rollBack();
            throw new AppError('INVITO_SCADUTO', 'Questo link di invito e\' scaduto o gia\' utilizzato', 410);
        }

        // Email gia' registrata? → collega invece di duplicare
        $st = $pdo->prepare('SELECT id, ruolo FROM utenti WHERE email = ?');
        $st->execute([$inv['email']]);
        $esistente = $st->fetch();

        if ($esistente) {
            $pdo->rollBack();
            throw new AppError('EMAIL_ESISTENTE', 'Esiste gia\' un account con questa email: fai login e chiedi all\'admin di collegarlo', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $st = $pdo->prepare(
            "INSERT INTO utenti (nome, cognome, email, password_hash, ruolo, stato) VALUES (?,?,?,?, 'fornitore', 'attivo')"
        );
        $st->execute([$nome, $cognome, $inv['email'], $hash]);
        $utente_id = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('UPDATE fornitori SET id_utente = ? WHERE id = ? AND id_utente IS NULL');
        $st->execute([$utente_id, (int)$inv['id_fornitore']]);
        if ($st->rowCount() === 0) {
            $pdo->rollBack();
            throw new AppError('GIA_COLLEGATO', 'Questo fornitore ha gia\' un account collegato', 409);
        }

        $pdo->prepare('UPDATE inviti_fornitore SET usato = 1 WHERE id = ?')->execute([(int)$inv['id']]);
        $pdo->commit();

        json_ok(['utente_id' => $utente_id], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Collega un utente esistente come account del fornitore.
 * Se l'utente non e' admin, il ruolo viene impostato a 'fornitore'.
 */
function fornitori_collega(int $id): void
{
    if (!sono_admin()) throw new AppError('NON_AUTORIZZATO', 'Solo gli admin', 403);
    $d = corpo();
    $utente_id = campo_int($d, 'utente_id');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, id_utente FROM fornitori WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $f = $st->fetch();
        if (!$f) {
            $pdo->rollBack();
            throw new AppError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);
        }
        if ($f['id_utente'] !== null) {
            $pdo->rollBack();
            throw new AppError('GIA_COLLEGATO', 'Questo fornitore ha gia\' un account collegato', 409);
        }

        $st = $pdo->prepare('SELECT id, ruolo FROM utenti WHERE id = ?');
        $st->execute([$utente_id]);
        $u = $st->fetch();
        if (!$u) {
            $pdo->rollBack();
            throw new AppError('UTENTE_INESISTENTE', 'Utente non trovato', 404);
        }

        $st = $pdo->prepare('SELECT id FROM fornitori WHERE id_utente = ?');
        $st->execute([$utente_id]);
        if ($st->fetch()) {
            $pdo->rollBack();
            throw new AppError('UTENTE_GIA_COLLEGATO', 'Questo utente e\' gia\' collegato a un altro fornitore', 409);
        }

        if ($u['ruolo'] !== 'admin') {
            $pdo->prepare("UPDATE utenti SET ruolo = 'fornitore' WHERE id = ?")->execute([$utente_id]);
        }
        $pdo->prepare('UPDATE fornitori SET id_utente = ? WHERE id = ?')->execute([$utente_id, $id]);
        $pdo->commit();

        json_ok(['id_fornitore' => $id, 'utente_id' => $utente_id]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Anagrafica del fornitore corrente (area fornitore).
 */
function fornitore_io(): void
{
    $fid = richiedi_fornitore();
    $st = db()->prepare(
        'SELECT id, nome_azienda, email_contatto, telefono, indirizzo, descrizione,
                logo_url, sito_web, piva, categoria, num_campagne, data_partnership
           FROM fornitori WHERE id = ?'
    );
    $st->execute([$fid]);
    $f = $st->fetch();
    if (!$f) throw new AppError('FORNITORE_INESISTENTE', 'Anagrafica non trovata', 404);
    $f['id'] = (int)$f['id'];
    $f['num_campagne'] = (int)$f['num_campagne'];
    json_ok($f);
}

/**
 * Il fornitore aggiorna i propri contatti + sito (non ragione sociale/P.IVA/categoria).
 * PUT /fornitore/io
 */
function fornitore_io_aggiorna(): void
{
    $fid = richiedi_fornitore();
    $d = corpo();

    $campi = [];
    $valori = [];
    foreach (['email_contatto', 'telefono', 'indirizzo', 'descrizione', 'logo_url'] as $k) {
        if (array_key_exists($k, $d)) {
            $v = trim((string)$d[$k]);
            $campi[] = "$k = ?";
            $valori[] = $v !== '' ? $v : null;
        }
    }
    if (array_key_exists('sito_web', $d)) {
        $campi[] = 'sito_web = ?';
        $valori[] = normalizza_url($d['sito_web']);
    }
    if (empty($campi)) throw new AppError('NESSUNA_MODIFICA', 'Nessun campo da aggiornare');

    $valori[] = $fid;
    db()->prepare('UPDATE fornitori SET ' . implode(', ', $campi) . ' WHERE id = ?')->execute($valori);
    json_ok(['aggiornato' => true]);
}

/**
 * ============================================================
 *  AREA FORNITORE — proposte, campagne, ordini
 * ============================================================
 */

/** Salva la foto della proposta, ritorna il path relativo. */
function fornitore_salva_foto(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new AppError('FOTO_ERRORE', 'Errore nel caricamento della foto');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new AppError('FOTO_TROPPO_GRANDE', 'La foto deve essere al massimo 5MB');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new AppError('FOTO_NON_VALIDA', 'Il file non e\' un\'immagine valida');
    }
    $estensioni = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!isset($estensioni[$info[2]])) {
        throw new AppError('FOTO_NON_VALIDA', 'Formato foto non valido (ammessi JPG, PNG, WebP)');
    }
    $nome = bin2hex(random_bytes(16)) . '.' . $estensioni[$info[2]];
    $dest = __DIR__ . '/../uploads/proposte/' . $nome;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new AppError('FOTO_ERRORE', 'Impossibile salvare la foto');
    }
    return 'uploads/proposte/' . $nome;
}

/**
 * Crea proposta prodotto completa (multipart/form-data).
 * POST /fornitore/proposte
 */
function fornitore_proposte_crea(): void
{
    $fid = richiedi_fornitore();
    $io = richiedi_utente_attivo();

    $nome = trim($_POST['nome_prodotto'] ?? '');
    if ($nome === '') throw new AppError('NOME_MANCANTE', 'Nome prodotto obbligatorio');
    $descrizione = trim($_POST['descrizione'] ?? '');
    $moq = isset($_POST['moq_richiesto']) && $_POST['moq_richiesto'] !== '' ? (int)$_POST['moq_richiesto'] : null;
    if ($moq !== null && $moq < 1) throw new AppError('MOQ_NON_VALIDO', 'MOQ deve essere almeno 1');
    $prezzo = isset($_POST['prezzo_base']) && $_POST['prezzo_base'] !== '' ? (float)$_POST['prezzo_base'] : null;
    if ($prezzo !== null && $prezzo <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzo base non valido');
    $prezzo_corrente = isset($_POST['prezzo_corrente']) && $_POST['prezzo_corrente'] !== '' ? (float)$_POST['prezzo_corrente'] : null;
    if ($prezzo_corrente !== null && $prezzo_corrente <= 0) throw new AppError('PREZZO_NON_VALIDO', 'Prezzo attuale non valido');
    $tempi = trim($_POST['tempi_consegna'] ?? '');

    $scaglioni = [];
    if (!empty($_POST['scaglioni'])) {
        $dec = json_decode($_POST['scaglioni'], true);
        if (!is_array($dec)) throw new AppError('SCAGLIONI_NON_VALIDI', 'Formato scaglioni non valido');
        foreach ($dec as $s) {
            $soglia = (int)($s['soglia'] ?? 0);
            $prz = (float)($s['prezzo'] ?? 0);
            if ($soglia < 1 || $prz <= 0) throw new AppError('SCAGLIONI_NON_VALIDI', 'Ogni scaglione deve avere soglia >= 1 e prezzo > 0');
            $scaglioni[] = ['soglia' => $soglia, 'prezzo' => round($prz, 2)];
        }
        usort($scaglioni, fn($a, $b) => $a['soglia'] <=> $b['soglia']);
    }

    $foto_path = null;
    if (!empty($_FILES['foto']) && (($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $foto_path = fornitore_salva_foto($_FILES['foto']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'INSERT INTO proposte_prodotti (proponente_tipo, proponente_id, nome_prodotto, descrizione,
                                            id_fornitore_suggerito, moq_richiesto, prezzo_base, prezzo_corrente, tempi_consegna, foto_path, stato)
             VALUES (\'fornitore\', ?, ?, ?, ?, ?, ?, ?, ?, ?, \'in_attesa\')'
        );
        $st->execute([$io, $nome, $descrizione, $fid, $moq, $prezzo, $prezzo_corrente, $tempi !== '' ? $tempi : null, $foto_path]);
        $id_proposta = (int)$pdo->lastInsertId();

        if (!empty($scaglioni)) {
            $stS = $pdo->prepare('INSERT INTO proposta_scaglioni (id_proposta, soglia, prezzo) VALUES (?,?,?)');
            foreach ($scaglioni as $s) {
                $stS->execute([$id_proposta, $s['soglia'], $s['prezzo']]);
            }
        }
        $pdo->commit();
        json_ok(['id_proposta' => $id_proposta], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($foto_path) @unlink(__DIR__ . '/../' . $foto_path);
        throw $e;
    }
}

/** Proprie proposte con voti e scaglioni. GET /fornitore/proposte */
function fornitore_proposte_mie(): void
{
    $fid = richiedi_fornitore();
    $io = utente_corrente_id();

    $st = db()->prepare(
        'SELECT pp.*,
                (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta = pp.id_proposta AND valore_voto = \'favore\') AS voti_favore,
                (SELECT COUNT(*) FROM voti_proposte WHERE id_proposta = pp.id_proposta AND valore_voto = \'contrario\') AS voti_contrari
           FROM proposte_prodotti pp
          WHERE pp.proponente_id = ? AND pp.proponente_tipo = \'fornitore\'
             OR pp.id_fornitore_suggerito = ?
          ORDER BY pp.data_proposta DESC'
    );
    $st->execute([$io, $fid]);
    $out = $st->fetchAll();

    $ids = array_column($out, 'id_proposta');
    $scaglioni = [];
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stS = db()->prepare("SELECT id_proposta, soglia, prezzo FROM proposta_scaglioni WHERE id_proposta IN ($ph) ORDER BY soglia ASC");
        $stS->execute($ids);
        foreach ($stS->fetchAll() as $s) {
            $scaglioni[(int)$s['id_proposta']][] = ['soglia' => (int)$s['soglia'], 'prezzo' => (float)$s['prezzo']];
        }
    }

    foreach ($out as &$p) {
        $p['id_proposta'] = (int)$p['id_proposta'];
        $p['voti_favore'] = (int)$p['voti_favore'];
        $p['voti_contrari'] = (int)$p['voti_contrari'];
        $p['moq_richiesto'] = $p['moq_richiesto'] !== null ? (int)$p['moq_richiesto'] : null;
        $p['prezzo_base'] = $p['prezzo_base'] !== null ? (float)$p['prezzo_base'] : null;
        $p['prezzo_corrente'] = $p['prezzo_corrente'] !== null ? (float)$p['prezzo_corrente'] : null;
        $p['scaglioni'] = $scaglioni[$p['id_proposta']] ?? [];
    }
    json_ok($out);
}

/** Campagne sui propri prodotti con % adesione. GET /fornitore/campagne */
function fornitore_campagne(): void
{
    $fid = richiedi_fornitore();
    $st = db()->prepare(
        'SELECT c.id, c.stato, c.quantita_minima, c.quantita_attuale, c.data_limite,
                c.prezzo_corrente, pr.nome AS prodotto,
                COUNT(DISTINCT p.id_utente) AS partecipanti
           FROM collette c
           JOIN prodotti pr ON pr.id = c.id_prodotto
      LEFT JOIN prenotazioni p ON p.id_colletta = c.id
          WHERE pr.id_fornitore = ?
          GROUP BY c.id
          ORDER BY c.data_limite ASC'
    );
    $st->execute([$fid]);
    $out = array_map(function ($c) {
        $c['id'] = (int)$c['id'];
        $c['quantita_minima'] = (int)$c['quantita_minima'];
        $c['quantita_attuale'] = (int)$c['quantita_attuale'];
        $c['partecipanti'] = (int)$c['partecipanti'];
        $c['prezzo_corrente'] = (float)$c['prezzo_corrente'];
        $c['percentuale_adesione'] = $c['quantita_minima'] > 0
            ? (int)round($c['quantita_attuale'] / $c['quantita_minima'] * 100) : 0;
        $c['stato'] = ricalcola_stato($c['id']);
        return $c;
    }, $st->fetchAll());
    json_ok($out);
}

/** Ordini ricevuti. GET /fornitore/ordini */
function fornitore_ordini(): void
{
    $fid = richiedi_fornitore();
    $st = db()->prepare(
        'SELECT o.id, o.id_colletta, o.quantita_ordinata, o.importo_totale, o.stato,
                o.data_ordine, o.data_consegna,
                c.stato AS stato_colletta, pr.nome AS prodotto
           FROM ordini_fornitore o
           JOIN collette c  ON c.id = o.id_colletta
           JOIN prodotti pr ON pr.id = c.id_prodotto
          WHERE o.id_fornitore = ?
          ORDER BY o.data_ordine DESC'
    );
    $st->execute([$fid]);
    $out = array_map(function ($o) {
        $o['id'] = (int)$o['id'];
        $o['id_colletta'] = (int)$o['id_colletta'];
        $o['quantita_ordinata'] = (int)$o['quantita_ordinata'];
        $o['importo_totale'] = (float)$o['importo_totale'];
        return $o;
    }, $st->fetchAll());
    json_ok($out);
}

/**
 * Avanza stato ordine: inviato → in_preparazione → consegnato.
 * POST /fornitore/ordini/{id}/avanza
 */
function fornitore_ordine_avanza(int $id): void
{
    $fid = richiedi_fornitore();
    richiedi_utente_attivo();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT id, id_colletta, id_fornitore, stato FROM ordini_fornitore WHERE id = ? FOR UPDATE'
        );
        $st->execute([$id]);
        $o = $st->fetch();
        if (!$o) {
            $pdo->rollBack();
            throw new AppError('ORDINE_INESISTENTE', 'Ordine non trovato', 404);
        }
        if ((int)$o['id_fornitore'] !== $fid) {
            $pdo->rollBack();
            throw new AppError('NON_AUTORIZZATO', 'Questo ordine non appartiene alla tua azienda', 403);
        }

        $transizioni = ['inviato' => 'ricevuto', 'ricevuto' => 'in_preparazione', 'in_preparazione' => 'evaso'];
        if (!isset($transizioni[$o['stato']])) {
            $pdo->rollBack();
            throw new AppError('STATO_NON_AVANZABILE', 'Ordine in stato "' . $o['stato'] . '": nessuna azione disponibile');
        }
        $nuovo = $transizioni[$o['stato']];

        if ($nuovo === 'evaso') {
            $pdo->prepare('UPDATE ordini_fornitore SET stato = \'evaso\', data_consegna = NOW() WHERE id = ?')->execute([$id]);
            $pdo->prepare('UPDATE collette SET stato = \'consegnata\', data_agg_stato = NOW() WHERE id = ?')->execute([(int)$o['id_colletta']]);

            // Notifica ai partecipanti
            $stProd = $pdo->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?');
            $stProd->execute([(int)$o['id_colletta']]);
            $nomeProdotto = $stProd->fetchColumn() ?: 'il tuo ordine';

            $stUt = $pdo->prepare(
                'SELECT DISTINCT p.id_utente FROM prenotazioni p
                  LEFT JOIN consegne co ON co.id_prenotazione = p.id
                 WHERE p.id_colletta = ? AND (co.modalita IS NULL OR co.modalita = \'ritiro_sede\')'
            );
            $stUt->execute([(int)$o['id_colletta']]);
            $stNot = $pdo->prepare(
                'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
                 VALUES (?, \'MERCE_PRONTA\', \'Merce Consegnata\', ?, \'colletta\', ?)'
            );
            foreach ($stUt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $stNot->execute([$uid, "Il fornitore ha consegnato \"$nomeProdotto\". Controlla il punto di ritiro.", (int)$o['id_colletta']]);
            }
        } else {
            $pdo->prepare('UPDATE ordini_fornitore SET stato = ? WHERE id = ?')->execute([$nuovo, $id]);
        }

        // Notifica a tutti gli admin ad ogni step
        $stForn = $pdo->prepare('SELECT nome_azienda FROM fornitori WHERE id = ?');
        $stForn->execute([$fid]);
        $nomeFornitore = $stForn->fetchColumn() ?: 'Un fornitore';
        $stProd2 = $pdo->prepare('SELECT pr.nome FROM collette c JOIN prodotti pr ON pr.id = c.id_prodotto WHERE c.id = ?');
        $stProd2->execute([(int)$o['id_colletta']]);
        $nomeProdotto2 = $stProd2->fetchColumn() ?: 'un prodotto';
        $stQuant = $pdo->prepare('SELECT quantita_ordinata FROM ordini_fornitore WHERE id = ?');
        $stQuant->execute([$id]);
        $pezzi = (int)$stQuant->fetchColumn();
        $labelStep = match($nuovo) { 'ricevuto' => 'ha preso in carico', 'in_preparazione' => 'ha messo in preparazione', 'evaso' => 'ha evaso', default => 'ha aggiornato' };
        $stAdmin = $pdo->prepare("SELECT id FROM utenti WHERE ruolo = 'admin'");
        $stAdmin->execute();
        $stNotAdmin = $pdo->prepare(
            'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
             VALUES (?, \'SISTEMA\', \'Aggiornamento Ordine\', ?, \'colletta\', ?)'
        );
        foreach ($stAdmin->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $stNotAdmin->execute([$adminId, "$nomeFornitore $labelStep l'ordine per \"$nomeProdotto2\" ($pezzi pezzi).", (int)$o['id_colletta']]);
        }

        $pdo->commit();
        json_ok(['stato' => $nuovo]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
