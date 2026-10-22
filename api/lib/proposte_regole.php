<?php
declare(strict_types=1);

/**
 * Regole di business delle proposte (gruppi d'acquisto proposti dai clienti).
 * Soglia e durata sono definite SOLO qui.
 */

const PROPOSTE_SOGLIA_VOTI    = 5;
const PROPOSTE_GIORNI_VALIDITA = 30;
/** Anti-spam: proposte contemporaneamente attive (in attesa + in votazione) per utente. */
const PROPOSTE_MAX_ATTIVE_PER_UTENTE = 5;

const PROPOSTA_NOME_MIN = 3;
const PROPOSTA_NOME_MAX = 80;
const PROPOSTA_DESCR_MAX = 300;
const PROPOSTA_LINK_MAX = 500;

/**
 * Valida i campi del modulo "Nuova proposta".
 * Ritorna [errori per campo, valori puliti]. Categoria: solo il formato (l'esistenza si controlla sul DB).
 */
function proposta_valida_campi(array $in): array
{
    $e = [];
    $v = [];

    $nome = trim((string)preg_replace('/\s+/u', ' ', (string)($in['nome_prodotto'] ?? '')));
    $len = mb_strlen($nome);
    if ($len < PROPOSTA_NOME_MIN) {
        $e['nome_prodotto'] = 'Il nome deve avere almeno ' . PROPOSTA_NOME_MIN . ' caratteri';
    } elseif ($len > PROPOSTA_NOME_MAX) {
        $e['nome_prodotto'] = 'Il nome puo\' avere al massimo ' . PROPOSTA_NOME_MAX . ' caratteri';
    }
    $v['nome_prodotto'] = $nome;

    $descr = trim((string)($in['descrizione'] ?? ''));
    if ($descr === '') {
        $e['descrizione'] = 'La descrizione e\' obbligatoria';
    } elseif (mb_strlen($descr) > PROPOSTA_DESCR_MAX) {
        $e['descrizione'] = 'La descrizione puo\' avere al massimo ' . PROPOSTA_DESCR_MAX . ' caratteri';
    }
    $v['descrizione'] = $descr;

    $link = trim((string)($in['link_riferimento'] ?? ''));
    if ($link !== '') {
        $parti = parse_url($link);
        $schema = strtolower((string)($parti['scheme'] ?? ''));
        if (mb_strlen($link) > PROPOSTA_LINK_MAX) {
            $e['link_riferimento'] = 'Il link puo\' avere al massimo ' . PROPOSTA_LINK_MAX . ' caratteri';
        } elseif (!in_array($schema, ['http', 'https'], true) || empty($parti['host'])
                  || filter_var($link, FILTER_VALIDATE_URL) === false) {
            $e['link_riferimento'] = 'Inserisci un link valido che inizi con http:// o https://';
        }
    }
    $v['link_riferimento'] = $link !== '' ? $link : null;

    $prezzo = str_replace(',', '.', trim((string)($in['prezzo_indicativo'] ?? '')));
    $v['prezzo_indicativo'] = null;
    if ($prezzo !== '') {
        if (str_starts_with($prezzo, '-')) {
            $e['prezzo_indicativo'] = 'Il prezzo non puo\' essere negativo';
        } elseif (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $prezzo)) {
            $e['prezzo_indicativo'] = 'Inserisci un prezzo valido (es. 49,90)';
        } else {
            $v['prezzo_indicativo'] = number_format((float)$prezzo, 2, '.', '');
        }
    }

    $cat = trim((string)($in['id_categoria'] ?? ''));
    $v['id_categoria'] = null;
    if ($cat !== '') {
        if (!ctype_digit($cat) || (int)$cat < 1) {
            $e['id_categoria'] = 'Categoria non valida';
        } else {
            $v['id_categoria'] = (int)$cat;
        }
    }

    return [$e, $v];
}

/** Testo di ricerca per i doppioni: minuscolo, spazi singoli, escape dei caratteri speciali di LIKE. */
function proposta_normalizza_ricerca(string $q): string
{
    $q = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $q)));
    return mb_substr($q, 0, 80);
}

/** La parola piu' lunga (>= 4 caratteri) del testo, o null. */
function proposta_parola_chiave(string $q): ?string
{
    $best = null;
    foreach (explode(' ', $q) as $w) {
        if (mb_strlen($w) >= 4 && ($best === null || mb_strlen($w) > mb_strlen($best))) $best = $w;
    }
    return $best;
}

/**
 * Una proposta in_votazione e' scaduta quando sono passati piu' di 30 giorni
 * dalla creazione (esattamente 30 giorni: ancora valida).
 */
function proposta_scaduta(string $stato, DateTimeInterface $creata, DateTimeInterface $adesso): bool
{
    if ($stato === 'scaduta') return true;
    if ($stato !== 'in_votazione') return false;
    $limite = DateTimeImmutable::createFromInterface($creata)->modify('+' . PROPOSTE_GIORNI_VALIDITA . ' days');
    return $adesso > $limite;
}

/**
 * Controlla se $utente puo' votare. Ritorna null se ok, altrimenti [codice, messaggio, http].
 * $p: stato, proponente_id. $scaduta: calcolata (SQL o proposta_scaduta()).
 */
function proposta_errore_voto(array $p, int $utente, bool $giaVotato, bool $scaduta): ?array
{
    if ((int)$p['proponente_id'] === $utente) {
        return ['VOTO_PROPRIO', 'Non puoi votare la tua proposta', 403];
    }
    if ($scaduta) {
        return ['PROPOSTA_SCADUTA', 'Questa proposta e\' scaduta: non si puo\' piu\' votare', 422];
    }
    if ($p['stato'] !== 'in_votazione') {
        return ['PROPOSTA_NON_VOTABILE', 'La proposta non e\' in fase di votazione', 422];
    }
    if ($giaVotato) {
        return ['GIA_VOTATO', 'Hai gia\' votato questa proposta', 409];
    }
    return null;
}

/** Controlla se si puo' togliere il voto (solo finche' e' in votazione). */
function proposta_errore_ritiro(array $p, bool $giaVotato, bool $scaduta): ?array
{
    if ($scaduta || $p['stato'] !== 'in_votazione') {
        return ['VOTO_NON_RITIRABILE', 'Puoi togliere il voto solo finche\' la proposta e\' in votazione', 422];
    }
    if (!$giaVotato) {
        return ['VOTO_INESISTENTE', 'Non hai votato questa proposta', 404];
    }
    return null;
}

/** Stato dopo un voto, dato il totale dei voti a favore. */
function proposta_stato_dopo_voto(int $totVoti): string
{
    return $totVoti >= PROPOSTE_SOGLIA_VOTI ? 'in_valutazione' : 'in_votazione';
}

/** "Mario Rossi" -> "Mario R." */
function nome_abbreviato(?string $nome, ?string $cognome): string
{
    $n = trim((string)$nome);
    $c = trim((string)$cognome);
    if ($c === '') return $n;
    return $n . ' ' . mb_strtoupper(mb_substr($c, 0, 1)) . '.';
}

function iniziali(?string $nome, ?string $cognome): string
{
    $n = trim((string)$nome);
    $c = trim((string)$cognome);
    $i = ($n !== '' ? mb_strtoupper(mb_substr($n, 0, 1)) : '') . ($c !== '' ? mb_strtoupper(mb_substr($c, 0, 1)) : '');
    return $i !== '' ? $i : '?';
}

/** Notifica nel sistema esistente (tabella notifiche, tipo SISTEMA, riferimento proposta). */
function notifica_proposta(PDO $pdo, int $idUtente, string $titolo, string $messaggio, int $idProposta): void
{
    $pdo->prepare(
        'INSERT INTO notifiche (id_utente, tipo, titolo, messaggio, tipo_riferimento, id_riferimento)
         VALUES (?, \'SISTEMA\', ?, ?, \'proposta\', ?)'
    )->execute([$idUtente, $titolo, $messaggio, $idProposta]);
}

/** Avvisa proponente e tutti i votanti a favore (una query per gli id, niente SELECT nei cicli). */
function notifica_proposta_a_coinvolti(PDO $pdo, int $idProposta, int $proponenteId, string $titolo, string $messaggio): void
{
    $st = $pdo->prepare(
        'SELECT DISTINCT id_utente FROM voti_proposte WHERE id_proposta = ? AND valore_voto = \'favore\''
    );
    $st->execute([$idProposta]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $ids[] = $proponenteId;
    foreach (array_unique($ids) as $uid) {
        notifica_proposta($pdo, $uid, $titolo, $messaggio, $idProposta);
    }
}

/**
 * Registra il voto in UNA transazione: lock della riga proposta, controlli,
 * INSERT voto, conteggio, eventuale passaggio a in_valutazione + notifiche.
 * Ritorna ['tot_voti','soglia','stato','ho_votato'].
 */
function proposte_registra_voto(PDO $pdo, int $idProposta, int $utente): array
{
    $pdo->beginTransaction();
    try {
        $p = proposta_blocca($pdo, $idProposta);
        $giaVotato = proposta_ha_voto($pdo, $idProposta, $utente);
        $err = proposta_errore_voto($p, $utente, $giaVotato, (bool)$p['scaduta']);
        if ($err) throw new AppError($err[0], $err[1], $err[2]);

        // Voti "contrario" legacy: non esistono piu' nel nuovo flusso, si sostituiscono
        $pdo->prepare('DELETE FROM voti_proposte WHERE id_proposta = ? AND id_utente = ? AND valore_voto = \'contrario\'')
            ->execute([$idProposta, $utente]);
        $pdo->prepare('INSERT INTO voti_proposte (id_proposta, id_utente, valore_voto) VALUES (?, ?, \'favore\')')
            ->execute([$idProposta, $utente]);

        $tot = proposta_conta_voti($pdo, $idProposta);
        $stato = proposta_stato_dopo_voto($tot);
        $pdo->prepare('UPDATE proposte_prodotti SET tot_voti = ?, stato = ? WHERE id_proposta = ?')
            ->execute([$tot, $stato, $idProposta]);

        if ($stato === 'in_valutazione') {
            notifica_proposta_a_coinvolti(
                $pdo, $idProposta, (int)$p['proponente_id'], 'Proposta in valutazione',
                'La proposta "' . $p['nome_prodotto'] . '" ha raggiunto ' . PROPOSTE_SOGLIA_VOTI
                . ' voti: BuyPool ora cerca il fornitore. Ti avvisiamo appena c\'e\' novita\'.'
            );
        }
        $pdo->commit();
        return ['tot_voti' => $tot, 'soglia' => PROPOSTE_SOGLIA_VOTI, 'stato' => $stato, 'ho_votato' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Rete di sicurezza: doppio voto concorrente respinto dal vincolo UNIQUE
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new AppError('GIA_VOTATO', 'Hai gia\' votato questa proposta', 409);
        }
        throw $e;
    }
}

/** Toglie il voto (solo in_votazione), stessa transazione/lock. */
function proposte_togli_voto(PDO $pdo, int $idProposta, int $utente): array
{
    $pdo->beginTransaction();
    try {
        $p = proposta_blocca($pdo, $idProposta);
        $giaVotato = proposta_ha_voto($pdo, $idProposta, $utente);
        $err = proposta_errore_ritiro($p, $giaVotato, (bool)$p['scaduta']);
        if ($err) throw new AppError($err[0], $err[1], $err[2]);

        $pdo->prepare('DELETE FROM voti_proposte WHERE id_proposta = ? AND id_utente = ?')
            ->execute([$idProposta, $utente]);
        $tot = proposta_conta_voti($pdo, $idProposta);
        $pdo->prepare('UPDATE proposte_prodotti SET tot_voti = ? WHERE id_proposta = ?')
            ->execute([$tot, $idProposta]);
        $pdo->commit();
        return ['tot_voti' => $tot, 'soglia' => PROPOSTE_SOGLIA_VOTI, 'stato' => 'in_votazione', 'ho_votato' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** SELECT ... FOR UPDATE della proposta, con flag scaduta calcolato dal DB (stesso orologio di data_proposta). */
function proposta_blocca(PDO $pdo, int $idProposta): array
{
    $st = $pdo->prepare(
        'SELECT id_proposta, stato, proponente_id, nome_prodotto,
                (data_proposta < NOW() - INTERVAL ' . PROPOSTE_GIORNI_VALIDITA . ' DAY) AS scaduta_sql
           FROM proposte_prodotti WHERE id_proposta = ? FOR UPDATE'
    );
    $st->execute([$idProposta]);
    $p = $st->fetch();
    if (!$p) throw new AppError('PROPOSTA_INESISTENTE', 'Proposta non trovata', 404);
    $p['scaduta'] = $p['stato'] === 'scaduta' || ($p['stato'] === 'in_votazione' && (int)$p['scaduta_sql'] === 1);
    return $p;
}

function proposta_ha_voto(PDO $pdo, int $idProposta, int $utente): bool
{
    $st = $pdo->prepare('SELECT 1 FROM voti_proposte WHERE id_proposta = ? AND id_utente = ? AND valore_voto = \'favore\'');
    $st->execute([$idProposta, $utente]);
    return (bool)$st->fetchColumn();
}

function proposta_conta_voti(PDO $pdo, int $idProposta): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM voti_proposte WHERE id_proposta = ? AND valore_voto = \'favore\'');
    $st->execute([$idProposta]);
    return (int)$st->fetchColumn();
}
