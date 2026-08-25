-- =====================================================================
--  BuyPool - schema MySQL
--  Modello: si compra a SCATOLE, si distribuisce a LATTE.
--  La latta e' la confezione sigillata: non viene mai aperta.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS voti_proposta, proposte, log_eventi, assegnazioni,
                     partecipazioni, campagne, punti_ritiro,
                     scaglioni_prezzo, prodotti, fornitori, utenti;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- utenti
CREATE TABLE utenti (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(80)  NOT NULL,
  email          VARCHAR(120) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  telefono       VARCHAR(20)  NULL,
  ruolo          ENUM('utente','admin') NOT NULL DEFAULT 'utente',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------ fornitori
CREATE TABLE fornitori (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(120) NOT NULL,
  comune       VARCHAR(80)  NOT NULL,
  descrizione  TEXT         NULL,
  attivo       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- prodotti
-- confezione        = litri (o kg) contenuti in UNA latta -> mai aperta
-- latte_per_scatola = quante latte in una scatola -> unita' di ACQUISTO
-- lotto_minimo      = numero minimo di SCATOLE per ordine
CREATE TABLE prodotti (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  fornitore_id       INT NOT NULL,
  nome               VARCHAR(120) NOT NULL,
  unita              ENUM('litro','kg','pezzo') NOT NULL DEFAULT 'litro',
  confezione         DECIMAL(8,2) NOT NULL,
  latte_per_scatola  INT          NOT NULL,
  lotto_minimo       INT          NOT NULL DEFAULT 1,
  prezzo_scatola     DECIMAL(8,2) NOT NULL,
  CONSTRAINT fk_prod_forn FOREIGN KEY (fornitore_id)
    REFERENCES fornitori(id) ON DELETE RESTRICT,
  CONSTRAINT ck_prod_pos CHECK (confezione > 0
                                AND latte_per_scatola > 0
                                AND lotto_minimo > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------- scaglioni_prezzo (FR9, opz.)
CREATE TABLE scaglioni_prezzo (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  prodotto_id     INT NOT NULL,
  scatole_da      INT NOT NULL,
  prezzo_scatola  DECIMAL(8,2) NOT NULL,
  UNIQUE KEY uq_scaglione (prodotto_id, scatole_da),
  CONSTRAINT fk_scag_prod FOREIGN KEY (prodotto_id)
    REFERENCES prodotti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------- punti_ritiro
CREATE TABLE punti_ritiro (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(120) NOT NULL,
  indirizzo       VARCHAR(200) NOT NULL,
  comune          VARCHAR(80)  NOT NULL,
  finestra_ritiro VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- campagne
-- soglia_scatole viene COPIATA da prodotti.lotto_minimo all'apertura,
-- cosi' modificare il prodotto non riscrive la storia delle campagne.
CREATE TABLE campagne (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  prodotto_id         INT NOT NULL,
  aperta_da           INT NOT NULL,
  referente_id        INT NULL,
  punto_ritiro_id     INT NULL,
  soglia_scatole      INT NOT NULL,
  scadenza            DATETIME NOT NULL,
  stato               ENUM('aperta','soglia_raggiunta','ripartita','decaduta')
                      NOT NULL DEFAULT 'aperta',
  regola_arrotondamento ENUM('difetto','eccesso') NOT NULL DEFAULT 'difetto',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_camp_prod FOREIGN KEY (prodotto_id)
    REFERENCES prodotti(id)     ON DELETE RESTRICT,
  CONSTRAINT fk_camp_aperta FOREIGN KEY (aperta_da)
    REFERENCES utenti(id)       ON DELETE RESTRICT,
  CONSTRAINT fk_camp_refer FOREIGN KEY (referente_id)
    REFERENCES utenti(id)       ON DELETE SET NULL,
  CONSTRAINT fk_camp_punto FOREIGN KEY (punto_ritiro_id)
    REFERENCES punti_ritiro(id) ON DELETE SET NULL,
  INDEX idx_camp_stato (stato, scadenza)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------- partecipazioni
-- created_at e' lo SPAREGGIO dell'algoritmo di ripartizione: non toglierlo.
CREATE TABLE partecipazioni (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  campagna_id     INT NOT NULL,
  utente_id       INT NOT NULL,
  latte_richieste INT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_partecipazione (campagna_id, utente_id),
  CONSTRAINT fk_part_camp FOREIGN KEY (campagna_id)
    REFERENCES campagne(id) ON DELETE CASCADE,
  CONSTRAINT fk_part_utente FOREIGN KEY (utente_id)
    REFERENCES utenti(id)   ON DELETE CASCADE,
  CONSTRAINT ck_part_pos CHECK (latte_richieste > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------- assegnazioni
-- Scritta UNA SOLA VOLTA alla ripartizione. Mai aggiornata:
-- una correzione e' una riga nuova, cosi' la storia resta difendibile.
CREATE TABLE assegnazioni (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  campagna_id      INT NOT NULL,
  utente_id        INT NOT NULL,
  latte_assegnate  INT NOT NULL,
  quantita_totale  DECIMAL(8,2) NOT NULL,
  importo          DECIMAL(8,2) NOT NULL,
  token_ritiro     VARCHAR(64)  NOT NULL UNIQUE,
  ritirato_il      DATETIME     NULL,
  confermato_da    INT          NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ass_camp FOREIGN KEY (campagna_id)
    REFERENCES campagne(id) ON DELETE CASCADE,
  CONSTRAINT fk_ass_utente FOREIGN KEY (utente_id)
    REFERENCES utenti(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_ass_conf FOREIGN KEY (confermato_da)
    REFERENCES utenti(id)   ON DELETE SET NULL,
  INDEX idx_ass_ritiro (campagna_id, ritirato_il)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------ log_eventi
CREATE TABLE log_eventi (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  campagna_id  INT NOT NULL,
  stato_da     VARCHAR(20)  NULL,
  stato_a      VARCHAR(20)  NOT NULL,
  motivo       VARCHAR(200) NULL,
  eseguito_da  INT          NULL,
  eseguito_il  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_camp FOREIGN KEY (campagna_id)
    REFERENCES campagne(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_utente FOREIGN KEY (eseguito_da)
    REFERENCES utenti(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------ wishlist (FR11, opz.)
CREATE TABLE proposte (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  utente_id   INT NOT NULL,
  titolo      VARCHAR(120) NOT NULL,
  descrizione TEXT NULL,
  stato       ENUM('aperta','validata','respinta') NOT NULL DEFAULT 'aperta',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prop_utente FOREIGN KEY (utente_id)
    REFERENCES utenti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE voti_proposta (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  proposta_id INT NOT NULL,
  utente_id   INT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_voto (proposta_id, utente_id),
  CONSTRAINT fk_voto_prop FOREIGN KEY (proposta_id)
    REFERENCES proposte(id) ON DELETE CASCADE,
  CONSTRAINT fk_voto_utente FOREIGN KEY (utente_id)
    REFERENCES utenti(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
--  DATI DI ESEMPIO
-- =====================================================================

INSERT INTO utenti (nome, email, password_hash, ruolo) VALUES
  ('Admin',  'admin@buypool.test',  '$2y$10$PLACEHOLDER', 'admin'),
  ('Ali',    'ali@buypool.test',    '$2y$10$PLACEHOLDER', 'utente'),
  ('Marco',  'marco@buypool.test',  '$2y$10$PLACEHOLDER', 'utente'),
  ('Giulia', 'giulia@buypool.test', '$2y$10$PLACEHOLDER', 'utente'),
  ('Sara',   'sara@buypool.test',   '$2y$10$PLACEHOLDER', 'utente');

INSERT INTO fornitori (nome, comune, descrizione) VALUES
  ('Frantoio della Valnerina', 'Terni',       'Olio extravergine di oliva'),
  ('Azienda Agricola Colle',   'Narni',       'Legumi e farro'),
  ('Cantina di Amelia',        'Amelia',      'Vino sfuso e imbottigliato');

-- olio: latta da 5 L, scatola da 4 latte (20 L), minimo 2 scatole (40 L)
INSERT INTO prodotti
  (fornitore_id, nome, unita, confezione, latte_per_scatola, lotto_minimo, prezzo_scatola)
VALUES
  (1, 'Olio EVO Valnerina',   'litro', 5.00, 4, 2, 180.00),
  (2, 'Farro perlato',        'kg',    5.00, 4, 1,  42.00),
  (2, 'Lenticchie',           'kg',    1.00, 12, 1, 66.00);

INSERT INTO punti_ritiro (nome, indirizzo, comune, finestra_ritiro) VALUES
  ('Frantoio - ritiro in sede', 'Str. della Valnerina 1', 'Terni', 'Sab 09:00-12:00'),
  ('Casa referente',            'Via Roma 10',            'Terni', 'Da concordare');

-- campagna aperta: soglia 2 scatole = 8 latte
INSERT INTO campagne
  (prodotto_id, aperta_da, referente_id, punto_ritiro_id, soglia_scatole,
   scadenza, stato)
VALUES
  (1, 2, 2, 1, 2, DATE_ADD(NOW(), INTERVAL 10 DAY), 'aperta');

-- 3 + 2 + 5 + 4 = 14 latte -> 3 scatole (12 latte), 2 in eccesso di domanda
INSERT INTO partecipazioni (campagna_id, utente_id, latte_richieste) VALUES
  (1, 2, 3), (1, 3, 2), (1, 4, 5), (1, 5, 4);

INSERT INTO log_eventi (campagna_id, stato_a, motivo, eseguito_da) VALUES
  (1, 'aperta', 'Campagna creata', 2);

-- =====================================================================
--  QUERY UTILI
-- =====================================================================

-- Avanzamento di una campagna: scatole complete + quante latte
-- mancano per completare la prossima.
-- SELECT
--   c.id,
--   COALESCE(SUM(p.latte_richieste),0)                        AS latte_totali,
--   FLOOR(COALESCE(SUM(p.latte_richieste),0)/pr.latte_per_scatola) AS scatole_complete,
--   c.soglia_scatole,
--   (pr.latte_per_scatola
--     - MOD(COALESCE(SUM(p.latte_richieste),0), pr.latte_per_scatola))
--     % pr.latte_per_scatola                                  AS latte_per_prossima
-- FROM campagne c
-- JOIN prodotti pr        ON pr.id = c.prodotto_id
-- LEFT JOIN partecipazioni p ON p.campagna_id = c.id
-- WHERE c.id = 1
-- GROUP BY c.id, pr.latte_per_scatola, c.soglia_scatole;
