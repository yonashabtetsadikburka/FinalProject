-- ============================================================
-- BuyPool — Schema Database Finale (17 tabelle)
-- ============================================================

CREATE DATABASE IF NOT EXISTS buypool
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE buypool;

-- ============================================================
-- 1. UTENTI — Account (clienti, fornitori e admin)
-- ============================================================
CREATE TABLE utenti (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nome                VARCHAR(100) NOT NULL,
    cognome             VARCHAR(100) NOT NULL DEFAULT '',
    email               VARCHAR(255) NOT NULL UNIQUE,
    password_hash       VARCHAR(255),
    telefono            VARCHAR(30) DEFAULT '',
    indirizzo           TEXT,
    cap                 VARCHAR(10) NULL,
    citta               VARCHAR(100) NULL,
    provincia           VARCHAR(5) NULL,
    tipo                ENUM('privato','b2b') DEFAULT 'privato',
    ruolo               ENUM('cliente','fornitore','admin') DEFAULT 'cliente',
    stato               ENUM('attivo','sospeso') DEFAULT 'attivo',
    google_id           VARCHAR(255) UNIQUE,
    microsoft_id        VARCHAR(255) UNIQUE,
    stripe_customer_id  VARCHAR(100) UNIQUE,
    partita_iva         VARCHAR(20),
    codice_fiscale      VARCHAR(16) NULL,
    data_iscrizione     DATETIME DEFAULT CURRENT_TIMESTAMP,
    privacy_accettata_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. FORNITORI — Catalogo fornitori pubblico (tabella separata)
-- ============================================================
CREATE TABLE fornitori (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nome_azienda        VARCHAR(200) NOT NULL,
    email_contatto      VARCHAR(255),
    telefono            VARCHAR(30),
    indirizzo           TEXT,
    descrizione         TEXT,
    logo_url            VARCHAR(500),
    partner_pubblico    BOOLEAN DEFAULT TRUE,
    num_campagne        INT DEFAULT 0,
    data_partnership    DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_utente           INT NULL UNIQUE,
    piva                VARCHAR(20) NULL,
    categoria           VARCHAR(100) NULL,
    sito_web            VARCHAR(500) NULL,
    FOREIGN KEY (id_utente) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2b. INVITI_FORNITORE — Inviti di attivazione account fornitore
-- ============================================================
CREATE TABLE inviti_fornitore (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_fornitore        INT NOT NULL,
    email               VARCHAR(255) NOT NULL,
    token               VARCHAR(100) NOT NULL UNIQUE,
    scadenza            DATETIME NOT NULL,
    usato               TINYINT(1) DEFAULT 0,
    data_creazione      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_fornitore) REFERENCES fornitori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. CATEGORIE — Classificazione dei prodotti
-- ============================================================
CREATE TABLE categorie (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nome                VARCHAR(100) NOT NULL UNIQUE,
    descrizione         VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. SEDI — Punti di ritiro
-- ============================================================
CREATE TABLE sedi (
    id_sede             INT AUTO_INCREMENT PRIMARY KEY,
    nome                VARCHAR(200) NOT NULL,
    indirizzo           VARCHAR(255) NOT NULL,
    citta               VARCHAR(100) NOT NULL,
    telefono            VARCHAR(30),
    orari               VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. PROPOSTE_PRODOTTI — Proposte prodotti
-- ============================================================
CREATE TABLE proposte_prodotti (
    id_proposta             INT AUTO_INCREMENT PRIMARY KEY,
    proponente_tipo         ENUM('cliente','fornitore','admin') NOT NULL DEFAULT 'cliente',
    proponente_id           INT NOT NULL,
    nome_prodotto           VARCHAR(200) NOT NULL,
    descrizione             TEXT,
    id_fornitore_suggerito  INT NULL,
    stato                   ENUM('in_attesa','in_votazione','approvata_admin','rifiutata','pubblicata','respinta_votazione') DEFAULT 'in_attesa',
    id_admin_gestione       INT NULL,
    tot_voti                INT DEFAULT 0,
    data_proposta           DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_gestione           DATETIME NULL,
    moq_richiesto           INT NULL,
    prezzo_base             DECIMAL(10,2) NULL,
    prezzo_corrente         DECIMAL(10,2) NULL,
    tempi_consegna          VARCHAR(100) NULL,
    link_riferimento        VARCHAR(500) NULL,
    foto_path               VARCHAR(500) NULL,
    motivo                  TEXT NULL,
    FOREIGN KEY (proponente_id) REFERENCES utenti(id) ON DELETE CASCADE,
    FOREIGN KEY (id_fornitore_suggerito) REFERENCES fornitori(id) ON DELETE SET NULL,
    FOREIGN KEY (id_admin_gestione) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. VOTI_PROPOSTE — Voti degli utenti sulle proposte
-- ============================================================
CREATE TABLE voti_proposte (
    id_voto             INT AUTO_INCREMENT PRIMARY KEY,
    id_proposta         INT NOT NULL,
    id_utente           INT NOT NULL,
    valore_voto         ENUM('favore','contrario') NOT NULL,
    data_voto           DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_proposta) REFERENCES proposte_prodotti(id_proposta) ON DELETE CASCADE,
    FOREIGN KEY (id_utente) REFERENCES utenti(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_voto_per_utente (id_proposta, id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- 6c. RECENSIONI_FORNITORE — Recensioni (stelle + testo) dei fornitori
-- ============================================================
CREATE TABLE recensioni_fornitore (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_fornitore        INT NOT NULL,
    id_utente           INT NOT NULL,
    voto                TINYINT NOT NULL,
    testo               TEXT NULL,
    data_creazione      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_fornitore) REFERENCES fornitori(id) ON DELETE CASCADE,
    FOREIGN KEY (id_utente) REFERENCES utenti(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_recensione_fornitore_utente (id_fornitore, id_utente),
    CONSTRAINT chk_voto_range CHECK (voto BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6d. RECENSIONI_CAMPAGNA — Recensioni (stelle + testo) delle singole campagne
-- ============================================================
CREATE TABLE recensioni_campagna (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_colletta         INT NOT NULL,
    id_utente           INT NOT NULL,
    voto                TINYINT NOT NULL,
    testo               TEXT NULL,
    data_creazione      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_colletta) REFERENCES collette(id) ON DELETE CASCADE,
    FOREIGN KEY (id_utente) REFERENCES utenti(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_recensione_campagna_utente (id_colletta, id_utente),
    CONSTRAINT chk_voto_campagna_range CHECK (voto BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5b. PROPOSTA_SCAGLIONI — Prezzi a scaglioni delle proposte
-- ============================================================
CREATE TABLE proposta_scaglioni (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_proposta         INT NOT NULL,
    soglia              INT NOT NULL,
    prezzo              DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (id_proposta) REFERENCES proposte_prodotti(id_proposta) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. PRODOTTI — Catalogo prodotti
-- ============================================================
CREATE TABLE prodotti (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_fornitore        INT NOT NULL,
    id_categoria        INT NULL,
    id_proposta_origine INT NULL,
    nome                VARCHAR(200) NOT NULL,
    descrizione         TEXT,
    prezzo_unitario     DECIMAL(10,2) NOT NULL,
    prezzo_base         DECIMAL(10,2) NULL,
    quantita_minima     INT NOT NULL DEFAULT 1,
    stato               ENUM('attivo','archiviato') DEFAULT 'attivo',
    data_creazione      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_fornitore) REFERENCES fornitori(id) ON DELETE CASCADE,
    FOREIGN KEY (id_categoria) REFERENCES categorie(id) ON DELETE SET NULL,
    FOREIGN KEY (id_proposta_origine) REFERENCES proposte_prodotti(id_proposta) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. IMMAGINI_PRODOTTO — Galleria immagini per prodotto
-- ============================================================
CREATE TABLE immagini_prodotto (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_prodotto         INT NOT NULL,
    url                 VARCHAR(500) NOT NULL,
    ordine              INT DEFAULT 0,
    principale          BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (id_prodotto) REFERENCES prodotti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. COLLETTE — Campagne di acquisto collettivo
-- ============================================================
CREATE TABLE collette (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    id_prodotto             INT NOT NULL,
    id_aperta_da            INT NULL,
    id_referente            INT NULL,
    id_sede                 INT NULL,
    quantita_minima         INT NOT NULL,
    quantita_attuale        INT DEFAULT 0,
    data_inizio             DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_limite             DATETIME NOT NULL,
    stato                   ENUM('in_corso','riuscita','fallita','ordine_pronto','ordine_fornitore','consegnata','annullata') DEFAULT 'in_corso',
    data_agg_stato          DATETIME NULL,
    regola_arrotondamento   ENUM('difetto','eccesso') DEFAULT 'difetto',
    prezzo_base             DECIMAL(10,2) NOT NULL,
    prezzo_corrente         DECIMAL(10,2) NOT NULL,
    percentuale_commissione DECIMAL(5,2) DEFAULT 10.00,
    id_admin_conferma       INT NULL,
    data_conferma           DATETIME NULL,
    chiusura_data           DATETIME NULL,
    FOREIGN KEY (id_prodotto) REFERENCES prodotti(id) ON DELETE CASCADE,
    FOREIGN KEY (id_aperta_da) REFERENCES utenti(id) ON DELETE SET NULL,
    FOREIGN KEY (id_referente) REFERENCES utenti(id) ON DELETE SET NULL,
    FOREIGN KEY (id_sede) REFERENCES sedi(id_sede) ON DELETE SET NULL,
    FOREIGN KEY (id_admin_conferma) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 10. SCAGLIONI_PREZZO — Prezzi scalari per numero partecipanti
-- ============================================================
CREATE TABLE scaglioni_prezzo (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    id_colletta             INT NOT NULL,
    soglia_partecipanti     INT NOT NULL,
    prezzo_unitario         DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (id_colletta) REFERENCES collette(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_scaglione (id_colletta, soglia_partecipanti)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 11. PRENOTAZIONI — Adesioni degli acquirenti
-- ============================================================
CREATE TABLE prenotazioni (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_colletta         INT NOT NULL,
    id_utente           INT NOT NULL,
    quantita            INT NOT NULL DEFAULT 1,
    importo_acconto     DECIMAL(10,2) NOT NULL DEFAULT 0,
    importo_saldo       DECIMAL(10,2) NULL,
    importo_commissione DECIMAL(10,2) DEFAULT 0,
    stato               ENUM('prenotata','confermata','pagata','annullata','rimborsata','azione_richiesta') DEFAULT 'prenotata',
    data_prenotazione   DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_pagamento      DATETIME NULL,
    FOREIGN KEY (id_colletta) REFERENCES collette(id) ON DELETE CASCADE,
    FOREIGN KEY (id_utente) REFERENCES utenti(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_pren_utente_colletta (id_colletta, id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 12. PAGAMENTI — Transazioni Stripe
-- ============================================================
CREATE TABLE pagamenti (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    id_prenotazione         INT NOT NULL,
    tipo_pagamento          ENUM('acconto','saldo','consegna') NOT NULL,
    importo                 DECIMAL(10,2) NOT NULL,
    valuta                  CHAR(3) DEFAULT 'EUR',
    commissione_agenzia     DECIMAL(10,2) DEFAULT 0.00,
    stripe_payment_intent_id VARCHAR(100) UNIQUE,
    stripe_charge_id        VARCHAR(100),
    stripe_payment_method   VARCHAR(50),
    stato_stripe            VARCHAR(50),
    stato                   ENUM('in_attesa','confermato','fallito','rimborsato') DEFAULT 'in_attesa',
    data_pagamento          DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_conferma           DATETIME NULL,
    FOREIGN KEY (id_prenotazione) REFERENCES prenotazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 13. EVENTI_STRIPE — Registro webhook Stripe (idempotenza)
-- ============================================================
CREATE TABLE eventi_stripe (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id     VARCHAR(100) NOT NULL UNIQUE,
    tipo_evento         VARCHAR(100) NOT NULL,
    id_pagamento        INT NULL,
    payload_json        JSON NULL,
    elaborato           BOOLEAN DEFAULT FALSE,
    data_ricezione      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_pagamento) REFERENCES pagamenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 14. QR_CODES — QR code per verifica ritiro in sede
-- ============================================================
CREATE TABLE qr_codes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_prenotazione     INT NOT NULL,
    token               VARCHAR(64) NOT NULL UNIQUE,
    quantita_assegnata  INT NOT NULL,
    stato               ENUM('generato','scansionato','annullato') DEFAULT 'generato',
    data_generazione    DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_scansione      DATETIME NULL,
    FOREIGN KEY (id_prenotazione) REFERENCES prenotazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 15. CONSEGNE — Opzione domicilio
-- ============================================================
CREATE TABLE consegne (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_prenotazione     INT NOT NULL UNIQUE,
    modalita            ENUM('ritiro_sede','consegna_domicilio') NOT NULL,
    id_sede             INT NULL,
    indirizzo_consegna  VARCHAR(255) NULL,
    importo_consegna    DECIMAL(10,2) DEFAULT 0.00,
    stato               ENUM('in_attesa','pronta','spedita','consegnata','ritirata') DEFAULT 'in_attesa',
    data_prevista       DATETIME NULL,
    data_effettiva      DATETIME NULL,
    FOREIGN KEY (id_prenotazione) REFERENCES prenotazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (id_sede) REFERENCES sedi(id_sede) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 16. ORDINI_FORNITORE — Ordini al fornitore
-- ============================================================
CREATE TABLE ordini_fornitore (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    id_colletta             INT NOT NULL UNIQUE,
    id_fornitore            INT NOT NULL,
    id_admin                INT NULL,
    quantita_ordinata       INT NOT NULL,
    prezzo_negoziato        DECIMAL(10,2) NULL,
    importo_totale          DECIMAL(10,2) NOT NULL,
    stato                   ENUM('da_negoziare','inviato','ricevuto','in_preparazione','evaso','consegnato','annullato') DEFAULT 'da_negoziare',
    data_ordine             DATETIME NULL,
    data_consegna           DATETIME NULL,
    FOREIGN KEY (id_colletta) REFERENCES collette(id) ON DELETE CASCADE,
    FOREIGN KEY (id_fornitore) REFERENCES fornitori(id) ON DELETE CASCADE,
    FOREIGN KEY (id_admin) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 17. NOTIFICHE — Avvisi utente
-- ============================================================
CREATE TABLE notifiche (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_utente           INT NOT NULL,
    tipo                ENUM(
        'SCADENZA','ORDINE_DISPONIBILE','ORDINE_INVIATO','RITIRATO',
        'PROPOSTA_APPROVATA','PAGAMENTO_RIUSCITO','PAGAMENTO_RICEVUTO','PAGAMENTO_FALLITO',
        'RIMBORSO','MOQ_RAGGIUNTO','ORDINE_CONFERMATO','MERCE_PRONTA','SPEDITO',
        'NUOVO_SCAGLIONE','TRACCIAMENTO','SISTEMA','ORDINE_RICEVUTO'
    ) NOT NULL,
    titolo              VARCHAR(150) NOT NULL,
    messaggio           TEXT NOT NULL,
    tipo_riferimento    ENUM('colletta','prenotazione','proposta') NULL,
    id_riferimento      INT NULL,
    letta               BOOLEAN DEFAULT FALSE,
    data_creazione      DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_lettura        DATETIME NULL,
    FOREIGN KEY (id_utente) REFERENCES utenti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- RESET_PASSWORD — Link monouso di reset password
-- ============================================================
CREATE TABLE reset_password (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_utente           INT NOT NULL,
    token               VARCHAR(100) NOT NULL UNIQUE,
    scadenza            DATETIME NOT NULL,
    usato               TINYINT(1) DEFAULT 0,
    data_creazione      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_utente) REFERENCES utenti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INDICI
-- ============================================================
CREATE INDEX idx_utenti_ruolo ON utenti(ruolo);
CREATE INDEX idx_collette_stato ON collette(stato, data_limite);
CREATE INDEX idx_prenotazioni_colletta ON prenotazioni(id_colletta);
CREATE INDEX idx_prodotti_fornitore ON prodotti(id_fornitore);
CREATE INDEX idx_prodotti_categoria ON prodotti(id_categoria);
CREATE INDEX idx_proposte_stato ON proposte_prodotti(stato);
CREATE INDEX idx_pagamenti_stripe_intent ON pagamenti(stripe_payment_intent_id);
CREATE INDEX idx_eventi_stripe_tipo ON eventi_stripe(tipo_evento);
CREATE INDEX idx_notifiche_utente_letta ON notifiche(id_utente, letta);
