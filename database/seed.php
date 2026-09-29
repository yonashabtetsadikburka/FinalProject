<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // solo da riga di comando
/**
 * Dati di esempio per provare l'app. Da terminale:
 *
 *     php database/seed.php
 *
 * Si puo' lanciare piu' volte: se l'admin di esempio esiste gia', non fa nulla.
 * Tutti gli account di esempio usano la stessa password (solo per sviluppo!):
 * vedi DEMO_PASSWORD qui sotto. Le email finiscono in .test (dominio riservato,
 * non esiste davvero: nessuno puo' ricevere posta a quegli indirizzi).
 */
const DEMO_PASSWORD = 'Demo1234!';

$cfg = require __DIR__ . '/../api/config.php';
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
    $cfg['user'], $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

if ((int)$pdo->query("SELECT COUNT(*) FROM utenti WHERE email = 'admin@buypool.test'")->fetchColumn() > 0) {
    echo "I dati di esempio ci sono gia': non faccio nulla.\n";
    exit(0);
}

$hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
$ins  = function (string $sql, array $p) use ($pdo): int {
    $pdo->prepare($sql)->execute($p);
    return (int)$pdo->lastInsertId();
};
$giorni = fn(int $n) => date('Y-m-d H:i:s', strtotime(($n >= 0 ? "+$n" : "$n") . ' days'));

$pdo->beginTransaction();

// ---------- utenti ----------
$utente = fn(string $nome, string $cognome, string $email, string $ruolo, string $tipo = 'privato') =>
    $ins('INSERT INTO utenti (nome, cognome, email, password_hash, ruolo, tipo, privacy_accettata_at)
          VALUES (?,?,?,?,?,?,NOW())', [$nome, $cognome, $email, $hash, $ruolo, $tipo]);

$admin  = $utente('Admin', 'BuyPool', 'admin@buypool.test', 'admin');
$mario  = $utente('Mario', 'Rossi', 'mario.rossi@buypool.test', 'cliente');
$giulia = $utente('Giulia', 'Bianchi', 'giulia.bianchi@buypool.test', 'cliente');
$luca   = $utente('Luca', 'Verdi', 'luca.verdi@buypool.test', 'cliente');
$sara   = $utente('Sara', 'Neri', 'sara.neri@buypool.test', 'cliente');
$uTech  = $utente('TechWorld', '', 'tech@buypool.test', 'fornitore', 'b2b');
$uCasa  = $utente('CasaBella', '', 'casa@buypool.test', 'fornitore', 'b2b');
$clienti = [$mario, $giulia, $luca, $sara];

// ---------- fornitori (la scheda esiste anche senza account) ----------
$forn = fn(?int $idUtente, string $nome, string $email, string $cat, string $desc, int $trust) =>
    $ins('INSERT INTO fornitori (id_utente, nome_azienda, email_contatto, categoria, descrizione,
                                 partner_pubblico, trust_score)
          VALUES (?,?,?,?,?,1,?)', [$idUtente, $nome, $email, $cat, $desc, $trust]);
$fTech = $forn($uTech, 'TechWorld', 'tech@buypool.test', 'Elettronica', 'Elettronica di consumo selezionata, con garanzia italiana.', 92);
$fCasa = $forn($uCasa, 'CasaBella', 'casa@buypool.test', 'Casa', 'Articoli per la casa e la cucina di qualita\'.', 88);
$fSport = $forn(null,   'SportPro', 'sport@buypool.test', 'Sport', 'Attrezzatura sportiva. Scheda in attesa di invito.', 75);

// ---------- categorie e punti di ritiro ----------
$cat = [];
foreach (['Elettronica', 'Casa', 'Sport', 'Alimentari'] as $n) {
    $cat[$n] = $ins('INSERT INTO categorie (nome) VALUES (?)', [$n]);
}
$ins('INSERT INTO sedi (nome, indirizzo, citta, telefono, orari) VALUES (?,?,?,?,?)',
     ['Punto Ritiro Centro', 'Via Roma 12', 'Milano', '02 1234567', 'Lun-Ven 9-18']);
$sede = $ins('INSERT INTO sedi (nome, indirizzo, citta, telefono, orari) VALUES (?,?,?,?,?)',
     ['Punto Ritiro Nord', 'Via Torino 5', 'Bergamo', '035 7654321', 'Lun-Sab 10-19']);

// ---------- prodotti ----------
$prod = fn(int $f, string $c, string $nome, string $desc, float $base, float $prezzo, int $moq) =>
    $ins('INSERT INTO prodotti (id_fornitore, id_categoria, nome, descrizione, prezzo_base, prezzo_unitario, quantita_minima)
          VALUES (?,?,?,?,?,?,?)', [$f, $cat[$c], $nome, $desc, $base, $prezzo, $moq]);
$pCuffie = $prod($fTech, 'Elettronica', 'Cuffie Bluetooth con cancellazione del rumore', 'Autonomia 30 ore, microfono integrato, custodia rigida.', 89.90, 59.90, 20);
$pPower  = $prod($fTech, 'Elettronica', 'Power bank 20.000 mAh', 'Ricarica rapida 22.5W, due porte USB-C e una USB-A.', 39.90, 27.90, 30);
$pRobot  = $prod($fCasa, 'Casa', 'Robot aspirapolvere con mappatura', 'Mappa la casa, 3 ore di autonomia, app dedicata.', 249.00, 179.00, 15);
$pPent   = $prod($fCasa, 'Casa', 'Set pentole antiaderenti 8 pezzi', 'Adatte a tutti i fuochi, induzione inclusa.', 129.00, 84.00, 10);
$pYoga   = $prod($fSport, 'Sport', 'Kit yoga: tappetino, blocchi e cinghia', 'Tappetino antiscivolo 6mm con accessori.', 45.00, 29.00, 25);
$pFit    = $prod($fSport, 'Sport', 'Set 5 elastici fitness', 'Cinque livelli di resistenza con sacca.', 24.90, 16.90, 20);

// ---------- campagne (+ prenotazioni coerenti con le quantita') ----------
/** @param array<int,int> $prenot  id_utente => quantita */
$campagna = function (int $prodotto, int $moq, int $scadeInGiorni, float $base, float $corrente,
                      string $stato, array $prenot, array $scaglioni = []) use ($pdo, $ins, $giorni, $admin, $sede): int {
    $totale = array_sum($prenot);
    $id = $ins('INSERT INTO collette (id_prodotto, id_aperta_da, id_referente, id_sede, quantita_minima, quantita_attuale,
                                      data_limite, stato, prezzo_base, prezzo_corrente, percentuale_commissione)
                VALUES (?,?,?,?,?,?,?,?,?,?,10.00)',
               [$prodotto, $admin, $admin, $sede, $moq, $totale, $giorni($scadeInGiorni), $stato, $base, $corrente]);
    foreach ($prenot as $u => $q) {
        $ins("INSERT INTO prenotazioni (id_colletta, id_utente, quantita, importo_acconto, stato) VALUES (?,?,?,0,'prenotata')",
             [$id, $u, $q]);
    }
    foreach ($scaglioni as $soglia => $prezzo) {
        $ins('INSERT INTO scaglioni_prezzo (id_colletta, soglia_partecipanti, prezzo_unitario) VALUES (?,?,?)', [$id, $soglia, $prezzo]);
    }
    return $id;
};
$cCuffie = $campagna($pCuffie, 20, 12, 89.90, 69.90, 'in_corso', [$mario => 4, $giulia => 3, $luca => 5, $sara => 2], [10 => 69.90, 20 => 59.90]);
$campagna($pPower, 30, 6, 39.90, 27.90, 'in_corso', [$giulia => 4, $luca => 5]);
$campagna($pRobot, 15, 5, 249.00, 179.00, 'riuscita', [$mario => 5, $giulia => 4, $luca => 3, $sara => 3]);
$campagna($pPent, 10, 20, 129.00, 84.00, 'in_corso', [$sara => 3]);
$campagna($pYoga, 25, 2, 45.00, 29.00, 'in_corso', [$mario => 8, $giulia => 6, $luca => 5, $sara => 3]);
$campagna($pFit, 20, -3, 24.90, 16.90, 'fallita', [$mario => 2, $sara => 2]);

// ---------- proposte dei clienti (in votazione) + voti ----------
$proposta = function (int $chi, string $nome, string $desc, array $voti) use ($pdo, $ins, $fTech) {
    $id = $ins("INSERT INTO proposte_prodotti (proponente_tipo, proponente_id, nome_prodotto, descrizione, stato)
                VALUES ('cliente',?,?,?, 'in_votazione')", [$chi, $nome, $desc]);
    foreach ($voti as $u => $v) {
        $ins('INSERT INTO voti_proposte (id_proposta, id_utente, valore_voto) VALUES (?,?,?)', [$id, $u, $v]);
    }
    $pdo->prepare('UPDATE proposte_prodotti SET tot_voti = ? WHERE id_proposta = ?')
        ->execute([array_sum(array_map(fn($v) => $v === 'favore' ? 1 : -1, $voti)), $id]);
};
$proposta($luca, 'Friggitrice ad aria 6 litri', 'Se ne comprassimo in tanti costerebbe molto meno.',
          [$giulia => 'favore', $sara => 'favore', $mario => 'favore']);
$proposta($sara, 'Monopattino elettrico pieghevole', 'Autonomia 25 km, per spostarsi in citta\'.',
          [$mario => 'favore', $luca => 'contrario']);

// ---------- qualche recensione e una notifica di benvenuto ----------
$ins('INSERT INTO recensioni_fornitore (id_fornitore, id_utente, voto, testo) VALUES (?,?,?,?)', [$fTech, $mario, 5, 'Consegna puntuale e prodotto come descritto.']);
$ins('INSERT INTO recensioni_fornitore (id_fornitore, id_utente, voto, testo) VALUES (?,?,?,?)', [$fTech, $giulia, 4, 'Ottimo rapporto qualita\'/prezzo.']);
$ins("INSERT INTO notifiche (id_utente, tipo, titolo, messaggio) VALUES (?, 'SISTEMA', 'Benvenuto su BuyPool', 'Partecipa a una campagna: piu\\' siamo, meno si paga!')", [$mario]);

$pdo->commit();

echo "Dati di esempio caricati.\n\n";
echo "Account (password per tutti: " . DEMO_PASSWORD . ")\n";
echo "  admin       admin@buypool.test\n";
echo "  cliente     mario.rossi@buypool.test   (anche giulia.bianchi / luca.verdi / sara.neri @buypool.test)\n";
echo "  fornitore   tech@buypool.test          (anche casa@buypool.test)\n";
