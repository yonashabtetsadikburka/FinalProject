-- ============================================================
-- SCHEMA DATABASE — Applicazione di Collette (Acquisto di Gruppo)
-- Versione con tabella utenti unificata (ruolo: cliente/fornitore/admin)
-- ============================================================

CREATE DATABASE IF NOT EXISTS collette_acquisto_gruppo
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE collette_acquisto_gruppo;

-- ------------------------------------------------------------
-- 1. UTENTI — Tabella unica per TUTTI gli account della
--    piattaforma (clienti, fornitori, admin). Il campo "ruolo"
--    determina il tipo di account; i dati specifici di
--    fornitore/admin sono nelle rispettive tabelle di dettaglio
-- ------------------------------------------------------------
CREATE TABLE utenti (
    id_utente           INT AUTO_INCREMENT PRIMARY KEY,
    cognome             VARCHAR(100) NOT NULL,
    nome                VARCHAR(100) NOT NULL,
    email               VARCHAR(150) NOT NULL UNIQUE,
    password            VARCHAR(255) NOT NULL,
    telefono            VARCHAR(20),
    indirizzo           VARCHAR(255),
    ruolo               ENUM('cliente','fornitore','admin') NOT NULL DEFAULT 'cliente',
    stripe_customer_id  VARCHAR(100) UNIQUE,           -- usato solo per ruolo = cliente
    data_iscrizione     DATETIME DEFAULT CURRENT_TIMESTAMP,
    stato               ENUM('attivo','sospeso') DEFAULT 'attivo'
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. FORNITORI_DETTAGLI — Dati specifici dei fornitori (1:1 con
--    utenti quando ruolo = 'fornitore'); separati perché non si
--    applicano a clienti/admin. Elenco pubblico per trasparenza
-- ------------------------------------------------------------
CREATE TABLE fornitori_dettagli (
    id_utente           INT PRIMARY KEY,
    nome_azienda        VARCHAR(150) NOT NULL,
    descrizione         TEXT,
    logo_url            VARCHAR(255),
    partner_pubblico    BOOLEAN DEFAULT TRUE,
    data_partnership    DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_utente) REFERENCES utenti(id_utente) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. AMMINISTRATORI_DETTAGLI — Livello di permesso specifico
--    degli admin (1:1 con utenti quando ruolo = 'admin')
-- ------------------------------------------------------------
CREATE TABLE amministratori_dettagli (
    id_utente           INT PRIMARY KEY,
    livello_permesso    ENUM('super_admin','gestore_colletta','gestore_consegna') DEFAULT 'gestore_colletta',

    FOREIGN KEY (id_utente) REFERENCES utenti(id_utente) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. SEDI — Uffici fisici dell'agenzia per il ritiro
-- ------------------------------------------------------------
CREATE TABLE sedi (
    id_sede             INT AUTO_INCREMENT PRIMARY KEY,
    nome                VARCHAR(100) NOT NULL,
    indirizzo           VARCHAR(255) NOT NULL,
    citta               VARCHAR(100) NOT NULL,
    telefono            VARCHAR(20),
    orari               VARCHAR(255)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. PROPOSTE_PRODOTTI — Prodotti suggeriti dai clienti;
--    id_utente deve avere ruolo = 'cliente', id_fornitore_suggerito
--    e id_admin_gestione puntano anch'essi a utenti, con il ruolo
--    corrispondente (controllo da fare a livello applicativo,
--    l'SQL puro non impone il ruolo su una FK)
-- ------------------------------------------------------------
CREATE TABLE proposte_prodotti (
    id_proposta         INT AUTO_INCREMENT PRIMARY KEY,
    id_utente           INT NOT NULL,
    nome_prodotto       VARCHAR(200) NOT NULL,
    descrizione         TEXT,
    image_url           VARCHAR(255),
    id_fornitore_suggerito INT NULL,
    stato               ENUM('in_attesa','approvata_admin','rifiutata','in_votazione','pubblicata','respinta_votazione')
                             DEFAULT 'in_attesa',
    id_admin_gestione   INT NULL,
    data_proposta       DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_gestione       DATETIME NULL,

    FOREIGN KEY (id_utente) REFERENCES utenti(id_utente) ON DELETE CASCADE,
    FOREIGN KEY (id_fornitore_suggerito) REFERENCES utenti(id_utente) ON DELETE SET NULL,
    FOREIGN KEY (id_admin_gestione) REFERENCES utenti(id_utente) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. VOTI_PROPOSTE — Voti dei clienti sulle proposte approvate
-- ------------------------------------------------------------
CREATE TABLE voti_proposte (
    id_voto             INT AUTO_INCREMENT PRIMARY KEY,
    id_proposta         INT NOT NULL,
    id_utente           INT NOT NULL,
    valore_voto         ENUM('favore','contrario') NOT NULL,
    data_voto           DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_proposta) REFERENCES proposte_prodotti(id_proposta) ON DELETE CASCADE,
    FOREIGN KEY (id_utente) REFERENCES utenti(id_utente) ON DELETE CASCADE,
    UNIQUE KEY uniq_voto_per_utente (id_proposta, id_utente)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. PRODOTTI — Catalogo ufficiale; id_fornitore punta a un
--    utente con ruolo = 'fornitore'
-- ------------------------------------------------------------
CREATE TABLE prodotti (
    id_prodotto         INT AUTO_INCREMENT PRIMARY KEY,
    id_fornitore        INT NOT NULL,
    id_proposta_origine INT NULL,
    nome                VARCHAR(200) NOT NULL,
    descrizione         TEXT,
    image_url           VARCHAR(255),
    prezzo_unitario     DECIMAL(10,2) NOT NULL,
    quantita_minima     INT NOT NULL,
    stato               ENUM('attivo','archiviato') DEFAULT 'attivo',
    data_creazione      DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_fornitore) REFERENCES utenti(id_utente) ON DELETE CASCADE,
    FOREIGN KEY (id_proposta_origine) REFERENCES proposte_prodotti(id_proposta) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. COLLETTE — Campagna di acquisto di gruppo per un prodotto
-- ------------------------------------------------------------
CREATE TABLE collette (
    id_colletta         INT AUTO_INCREMENT PRIMARY KEY,
    id_prodotto         INT NOT NULL,
    quantita_minima     INT NOT NULL,
    quantita_attuale    INT DEFAULT 0,
    data_inizio         DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_limite         DATETIME NOT NULL,
    stato               ENUM('in_corso','riuscita','fallita','ordine_fornitore','consegnata','annullata')
                             DEFAULT 'in_corso',
    data_agg_stato      DATETIME NULL,

    FOREIGN KEY (id_prodotto) REFERENCES prodotti(id_prodotto) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9. PRENOTAZIONI — Partecipazione di un cliente a una colletta
-- ------------------------------------------------------------
CREATE TABLE prenotazioni (
    id_prenotazione     INT AUTO_INCREMENT PRIMARY KEY,
    id_colletta         INT NOT NULL,
    id_utente           INT NOT NULL,
    quantita            INT NOT NULL DEFAULT 1,
    importo_acconto     DECIMAL(10,2) NOT NULL,
    importo_saldo       DECIMAL(10,2) NULL,
    stato               ENUM('prenotata','confermata','annullata','rimborsata') DEFAULT 'prenotata',
    data_prenotazione   DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_colletta) REFERENCES collette(id_colletta) ON DELETE CASCADE,
    FOREIGN KEY (id_utente) REFERENCES utenti(id_utente) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10. PAGAMENTI — Transazioni collegate a Stripe
-- ------------------------------------------------------------
CREATE TABLE pagamenti (
    id_pagamento            INT AUTO_INCREMENT PRIMARY KEY,
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

    FOREIGN KEY (id_prenotazione) REFERENCES prenotazioni(id_prenotazione) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 11. EVENTI_STRIPE — Registro webhook Stripe (idempotenza/audit)
-- ------------------------------------------------------------
CREATE TABLE eventi_stripe (
    id_evento           INT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id     VARCHAR(100) NOT NULL UNIQUE,
    tipo_evento         VARCHAR(100) NOT NULL,
    id_pagamento        INT NULL,
    payload_json        JSON NULL,
    elaborato           BOOLEAN DEFAULT FALSE,
    data_ricezione      DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (id_pagamento) REFERENCES pagamenti(id_pagamento) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 12. CONSEGNE — Ritiro in sede o consegna a domicilio
-- ------------------------------------------------------------
CREATE TABLE consegne (
    id_consegna         INT AUTO_INCREMENT PRIMARY KEY,
    id_prenotazione     INT NOT NULL UNIQUE,
    modalita            ENUM('ritiro_sede','consegna_domicilio') NOT NULL,
    id_sede             INT NULL,
    indirizzo_consegna  VARCHAR(255) NULL,
    importo_consegna    DECIMAL(10,2) DEFAULT 0.00,
    stato               ENUM('in_attesa','pronta','spedita','consegnata','ritirata') DEFAULT 'in_attesa',
    data_prevista       DATETIME NULL,
    data_effettiva      DATETIME NULL,

    FOREIGN KEY (id_prenotazione) REFERENCES prenotazioni(id_prenotazione) ON DELETE CASCADE,
    FOREIGN KEY (id_sede) REFERENCES sedi(id_sede) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 13. ORDINI_FORNITORE — Trattativa e ordine di gruppo;
--     id_admin punta a un utente con ruolo = 'admin'
-- ------------------------------------------------------------
CREATE TABLE ordini_fornitore (
    id_ordine           INT AUTO_INCREMENT PRIMARY KEY,
    id_colletta         INT NOT NULL UNIQUE,
    id_admin            INT NULL,
    quantita_ordinata   INT NOT NULL,
    prezzo_negoziato    DECIMAL(10,2) NULL,
    stato               ENUM('da_negoziare','confermato','in_transito','ricevuto') DEFAULT 'da_negoziare',
    data_ordine         DATETIME NULL,
    data_ricezione_agenzia DATETIME NULL,

    FOREIGN KEY (id_colletta) REFERENCES collette(id_colletta) ON DELETE CASCADE,
    FOREIGN KEY (id_admin) REFERENCES utenti(id_utente) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 14. NOTIFICHE — Avvisi per gli eventi chiave del percorso utente
-- ------------------------------------------------------------
CREATE TABLE notifiche (
    id_notifica         INT AUTO_INCREMENT PRIMARY KEY,
    id_utente           INT NOT NULL,
    tipo                ENUM('scaglione_raggiunto','scadenza_campagna','ordine_disponibile_ritiro',
                              'ritiro_confermato','proposta_approvata') NOT NULL,
    titolo              VARCHAR(150) NOT NULL,
    messaggio           TEXT NOT NULL,
    tipo_riferimento    ENUM('colletta','prenotazione','proposta') NULL,
    id_riferimento      INT NULL,
    letta               BOOLEAN DEFAULT FALSE,
    data_creazione      DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_lettura        DATETIME NULL,

    FOREIGN KEY (id_utente) REFERENCES utenti(id_utente) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- INDICI utili
-- ------------------------------------------------------------
CREATE INDEX idx_utenti_ruolo ON utenti(ruolo);
CREATE INDEX idx_collette_stato ON collette(stato);
CREATE INDEX idx_prenotazioni_colletta ON prenotazioni(id_colletta);
CREATE INDEX idx_prodotti_fornitore ON prodotti(id_fornitore);
CREATE INDEX idx_proposte_stato ON proposte_prodotti(stato);
CREATE INDEX idx_pagamenti_stripe_intent ON pagamenti(stripe_payment_intent_id);
CREATE INDEX idx_eventi_stripe_tipo ON eventi_stripe(tipo_evento);
CREATE INDEX idx_notifiche_utente_letta ON notifiche(id_utente, letta);