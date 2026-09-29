-- ============================================================
--  BuyPool - schema del database (MySQL 8 / MariaDB 10.4+)
--
--  Base: le migrazioni Laravel del ramo main (tabelle, chiavi, vincoli),
--  estese con quello che l'API PHP usa davvero (i fornitori sono identificati
--  da fornitori.id, non da utenti.id: una scheda esiste anche prima che il
--  fornitore attivi l'account): ruolo 'cliente', stato
--  campagna 'ordine_pronto', ciclo di vita degli ordini fornitore, e le
--  tabelle recensioni / reset password / scaglioni proposta / like / limite ai
--  tentativi di login.
--
--  Uso:   mysql -u root -p < database/schema.sql
--  Il database si chiama buypool (o cambiare la riga USE qui sotto).
-- ============================================================

CREATE DATABASE IF NOT EXISTS `buypool` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `buypool`;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `categorie`;
CREATE TABLE `categorie` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `descrizione` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categorie_nome_unique` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `collette`;
CREATE TABLE `collette` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_prodotto` bigint unsigned NOT NULL,
  `id_aperta_da` bigint unsigned DEFAULT NULL,
  `id_referente` bigint unsigned DEFAULT NULL,
  `id_sede` bigint unsigned DEFAULT NULL,
  `quantita_minima` int NOT NULL,
  `quantita_attuale` int NOT NULL DEFAULT '0',
  `data_inizio` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_limite` datetime NOT NULL,
  `stato` enum('in_corso','riuscita','fallita','ordine_pronto','ordine_fornitore','consegnata','annullata') NOT NULL DEFAULT 'in_corso',
  `data_agg_stato` timestamp NULL DEFAULT NULL,
  `regola_arrotondamento` enum('difetto','eccesso') NOT NULL DEFAULT 'difetto',
  `prezzo_base` decimal(10,2) NOT NULL,
  `prezzo_corrente` decimal(10,2) NOT NULL,
  `percentuale_commissione` decimal(5,2) NOT NULL DEFAULT '10.00',
  `id_admin_conferma` bigint unsigned DEFAULT NULL,
  `data_conferma` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `collette_id_prodotto_foreign` (`id_prodotto`),
  KEY `collette_id_aperta_da_foreign` (`id_aperta_da`),
  KEY `collette_id_referente_foreign` (`id_referente`),
  KEY `collette_id_sede_foreign` (`id_sede`),
  KEY `collette_id_admin_conferma_foreign` (`id_admin_conferma`),
  KEY `collette_stato_data_limite_index` (`stato`,`data_limite`),
  CONSTRAINT `collette_id_admin_conferma_foreign` FOREIGN KEY (`id_admin_conferma`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `collette_id_aperta_da_foreign` FOREIGN KEY (`id_aperta_da`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `collette_id_prodotto_foreign` FOREIGN KEY (`id_prodotto`) REFERENCES `prodotti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collette_id_referente_foreign` FOREIGN KEY (`id_referente`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `collette_id_sede_foreign` FOREIGN KEY (`id_sede`) REFERENCES `sedi` (`id_sede`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `consegne`;
CREATE TABLE `consegne` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_prenotazione` bigint unsigned NOT NULL,
  `modalita` enum('ritiro_sede','consegna_domicilio') NOT NULL,
  `id_sede` bigint unsigned DEFAULT NULL,
  `indirizzo_consegna` varchar(255) DEFAULT NULL,
  `importo_consegna` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stato` enum('in_attesa','pronta','spedita','consegnata','ritirata') NOT NULL DEFAULT 'in_attesa',
  `data_prevista` datetime DEFAULT NULL,
  `data_effettiva` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `consegne_id_prenotazione_unique` (`id_prenotazione`),
  KEY `consegne_id_sede_foreign` (`id_sede`),
  CONSTRAINT `consegne_id_prenotazione_foreign` FOREIGN KEY (`id_prenotazione`) REFERENCES `prenotazioni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `consegne_id_sede_foreign` FOREIGN KEY (`id_sede`) REFERENCES `sedi` (`id_sede`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `eventi_stripe`;
CREATE TABLE `eventi_stripe` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stripe_event_id` varchar(100) NOT NULL,
  `tipo_evento` varchar(100) NOT NULL,
  `id_pagamento` bigint unsigned DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `elaborato` tinyint(1) NOT NULL DEFAULT '0',
  `data_ricezione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `eventi_stripe_stripe_event_id_unique` (`stripe_event_id`),
  KEY `eventi_stripe_id_pagamento_foreign` (`id_pagamento`),
  KEY `eventi_stripe_tipo_evento_index` (`tipo_evento`),
  CONSTRAINT `eventi_stripe_id_pagamento_foreign` FOREIGN KEY (`id_pagamento`) REFERENCES `pagamenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `fornitori`;
CREATE TABLE `fornitori` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_utente` bigint unsigned DEFAULT NULL,
  `nome_azienda` varchar(200) NOT NULL,
  `email_contatto` varchar(255) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `indirizzo` text,
  `descrizione` text,
  `logo_url` varchar(500) DEFAULT NULL,
  `partner_pubblico` tinyint(1) NOT NULL DEFAULT '1',
  `trust_score` int NOT NULL DEFAULT '0',
  `num_campagne` int NOT NULL DEFAULT '0',
  `data_partnership` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `categoria` varchar(100) DEFAULT NULL,
  `piva` varchar(20) DEFAULT NULL,
  `sito_web` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fornitori_id_utente_unique` (`id_utente`),
  CONSTRAINT `fornitori_id_utente_foreign` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `immagini_prodotto`;
CREATE TABLE `immagini_prodotto` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_prodotto` bigint unsigned NOT NULL,
  `url` varchar(500) NOT NULL,
  `ordine` int NOT NULL DEFAULT '0',
  `principale` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `immagini_prodotto_id_prodotto_foreign` (`id_prodotto`),
  CONSTRAINT `immagini_prodotto_id_prodotto_foreign` FOREIGN KEY (`id_prodotto`) REFERENCES `prodotti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `inviti_fornitore`;
CREATE TABLE `inviti_fornitore` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_fornitore` bigint unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `scadenza` datetime NOT NULL,
  `usato` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inviti_fornitore_token_unique` (`token`),
  KEY `inviti_fornitore_id_fornitore_foreign` (`id_fornitore`),
  CONSTRAINT `inviti_fornitore_id_fornitore_foreign` FOREIGN KEY (`id_fornitore`) REFERENCES `fornitori` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `migrations`;
DROP TABLE IF EXISTS `notifiche`;
CREATE TABLE `notifiche` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_utente` bigint unsigned NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `titolo` varchar(150) NOT NULL,
  `messaggio` text NOT NULL,
  `tipo_riferimento` enum('colletta','prenotazione','proposta') DEFAULT NULL,
  `id_riferimento` bigint unsigned DEFAULT NULL,
  `letta` tinyint(1) NOT NULL DEFAULT '0',
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_lettura` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifiche_id_utente_letta_index` (`id_utente`,`letta`),
  CONSTRAINT `notifiche_id_utente_foreign` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `ordini_fornitore`;
CREATE TABLE `ordini_fornitore` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_colletta` bigint unsigned NOT NULL,
  `id_fornitore` bigint unsigned NOT NULL,
  `id_admin` bigint unsigned DEFAULT NULL,
  `quantita_ordinata` int NOT NULL,
  `prezzo_negoziato` decimal(10,2) DEFAULT NULL,
  `importo_totale` decimal(10,2) NOT NULL,
  `stato` enum('da_negoziare','inviato','ricevuto','in_preparazione','evaso','consegnato','annullato') NOT NULL DEFAULT 'da_negoziare',
  `data_ordine` datetime DEFAULT NULL,
  `data_consegna` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ordini_fornitore_id_colletta_unique` (`id_colletta`),
  KEY `ordini_fornitore_id_fornitore_foreign` (`id_fornitore`),
  KEY `ordini_fornitore_id_admin_foreign` (`id_admin`),
  CONSTRAINT `ordini_fornitore_id_admin_foreign` FOREIGN KEY (`id_admin`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ordini_fornitore_id_colletta_foreign` FOREIGN KEY (`id_colletta`) REFERENCES `collette` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ordini_fornitore_id_fornitore_foreign` FOREIGN KEY (`id_fornitore`) REFERENCES `fornitori` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `pagamenti`;
CREATE TABLE `pagamenti` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_prenotazione` bigint unsigned NOT NULL,
  `tipo_pagamento` enum('acconto','saldo','consegna') NOT NULL,
  `importo` decimal(10,2) NOT NULL,
  `valuta` char(3) NOT NULL DEFAULT 'EUR',
  `commissione_agenzia` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stripe_payment_intent_id` varchar(100) DEFAULT NULL,
  `stripe_charge_id` varchar(100) DEFAULT NULL,
  `stripe_payment_method` varchar(50) DEFAULT NULL,
  `stato_stripe` varchar(50) DEFAULT NULL,
  `stato` enum('in_attesa','confermato','fallito','rimborsato') NOT NULL DEFAULT 'in_attesa',
  `data_pagamento` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_conferma` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pagamenti_stripe_payment_intent_id_unique` (`stripe_payment_intent_id`),
  KEY `pagamenti_id_prenotazione_foreign` (`id_prenotazione`),
  KEY `pagamenti_stripe_payment_intent_id_index` (`stripe_payment_intent_id`),
  CONSTRAINT `pagamenti_id_prenotazione_foreign` FOREIGN KEY (`id_prenotazione`) REFERENCES `prenotazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `prenotazioni`;
CREATE TABLE `prenotazioni` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_colletta` bigint unsigned NOT NULL,
  `id_utente` bigint unsigned NOT NULL,
  `quantita` int NOT NULL DEFAULT '1',
  `importo_acconto` decimal(10,2) NOT NULL DEFAULT '0.00',
  `importo_saldo` decimal(10,2) DEFAULT NULL,
  `importo_commissione` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stato` enum('prenotata','confermata','pagata','annullata','rimborsata') NOT NULL DEFAULT 'prenotata',
  `data_prenotazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_pagamento` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pren_utente_colletta` (`id_colletta`,`id_utente`),
  KEY `prenotazioni_id_utente_foreign` (`id_utente`),
  CONSTRAINT `prenotazioni_id_colletta_foreign` FOREIGN KEY (`id_colletta`) REFERENCES `collette` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prenotazioni_id_utente_foreign` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `prodotti`;
CREATE TABLE `prodotti` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_fornitore` bigint unsigned NOT NULL,
  `id_categoria` bigint unsigned DEFAULT NULL,
  `id_proposta_origine` bigint unsigned DEFAULT NULL,
  `nome` varchar(200) NOT NULL,
  `descrizione` text,
  `prezzo_unitario` decimal(10,2) NOT NULL,
  `quantita_minima` int NOT NULL DEFAULT '1',
  `stato` enum('attivo','archiviato') NOT NULL DEFAULT 'attivo',
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `prezzo_base` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prodotti_id_proposta_origine_foreign` (`id_proposta_origine`),
  KEY `prodotti_id_fornitore_index` (`id_fornitore`),
  KEY `prodotti_id_categoria_index` (`id_categoria`),
  CONSTRAINT `prodotti_id_categoria_foreign` FOREIGN KEY (`id_categoria`) REFERENCES `categorie` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prodotti_id_fornitore_foreign` FOREIGN KEY (`id_fornitore`) REFERENCES `fornitori` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prodotti_id_proposta_origine_foreign` FOREIGN KEY (`id_proposta_origine`) REFERENCES `proposte_prodotti` (`id_proposta`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `proposte_prodotti`;
CREATE TABLE `proposte_prodotti` (
  `id_proposta` bigint unsigned NOT NULL AUTO_INCREMENT,
  `proponente_tipo` enum('cliente','fornitore','admin','utente') NOT NULL DEFAULT 'cliente',
  `proponente_id` bigint unsigned NOT NULL,
  `nome_prodotto` varchar(200) NOT NULL,
  `descrizione` text,
  `id_fornitore_suggerito` bigint unsigned DEFAULT NULL,
  `stato` enum('in_attesa','in_votazione','approvata_admin','rifiutata','pubblicata','respinta_votazione') NOT NULL DEFAULT 'in_attesa',
  `id_admin_gestione` bigint unsigned DEFAULT NULL,
  `tot_voti` int NOT NULL DEFAULT '0',
  `data_proposta` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_gestione` timestamp NULL DEFAULT NULL,
  `foto_path` varchar(255) DEFAULT NULL,
  `moq_richiesto` int DEFAULT NULL,
  `motivo` text,
  `prezzo_base` decimal(10,2) DEFAULT NULL,
  `prezzo_corrente` decimal(10,2) DEFAULT NULL,
  `tempi_consegna` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id_proposta`),
  KEY `proposte_prodotti_proponente_id_foreign` (`proponente_id`),
  KEY `proposte_prodotti_id_fornitore_suggerito_foreign` (`id_fornitore_suggerito`),
  KEY `proposte_prodotti_id_admin_gestione_foreign` (`id_admin_gestione`),
  KEY `proposte_prodotti_stato_index` (`stato`),
  CONSTRAINT `proposte_prodotti_id_admin_gestione_foreign` FOREIGN KEY (`id_admin_gestione`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `proposte_prodotti_id_fornitore_suggerito_foreign` FOREIGN KEY (`id_fornitore_suggerito`) REFERENCES `fornitori` (`id`) ON DELETE SET NULL,
  CONSTRAINT `proposte_prodotti_proponente_id_foreign` FOREIGN KEY (`proponente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `qr_codes`;
CREATE TABLE `qr_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_prenotazione` bigint unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `quantita_assegnata` int NOT NULL,
  `stato` enum('generato','scansionato','annullato') NOT NULL DEFAULT 'generato',
  `data_generazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_scansione` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `qr_codes_token_unique` (`token`),
  KEY `qr_codes_id_prenotazione_foreign` (`id_prenotazione`),
  CONSTRAINT `qr_codes_id_prenotazione_foreign` FOREIGN KEY (`id_prenotazione`) REFERENCES `prenotazioni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `scaglioni_prezzo`;
CREATE TABLE `scaglioni_prezzo` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_colletta` bigint unsigned NOT NULL,
  `soglia_partecipanti` int NOT NULL,
  `prezzo_unitario` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scaglione` (`id_colletta`,`soglia_partecipanti`),
  CONSTRAINT `scaglioni_prezzo_id_colletta_foreign` FOREIGN KEY (`id_colletta`) REFERENCES `collette` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `sedi`;
CREATE TABLE `sedi` (
  `id_sede` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(200) NOT NULL,
  `indirizzo` varchar(255) NOT NULL,
  `citta` varchar(100) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `orari` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_sede`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `utenti`;
CREATE TABLE `utenti` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `cognome` varchar(100) NOT NULL DEFAULT '',
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `telefono` varchar(30) NOT NULL DEFAULT '',
  `indirizzo` text,
  `tipo` enum('privato','b2b') NOT NULL DEFAULT 'privato',
  `ruolo` enum('cliente','fornitore','admin') NOT NULL DEFAULT 'cliente',
  `stato` enum('attivo','sospeso') NOT NULL DEFAULT 'attivo',
  `google_id` varchar(255) DEFAULT NULL,
  `microsoft_id` varchar(255) DEFAULT NULL,
  `stripe_customer_id` varchar(100) DEFAULT NULL,
  `partita_iva` varchar(20) DEFAULT NULL,
  `data_iscrizione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `privacy_accettata_at` datetime DEFAULT NULL,
  `cap` varchar(10) DEFAULT NULL,
  `citta` varchar(100) DEFAULT NULL,
  `provincia` varchar(5) DEFAULT NULL,
  `codice_fiscale` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `utenti_email_unique` (`email`),
  UNIQUE KEY `utenti_google_id_unique` (`google_id`),
  UNIQUE KEY `utenti_microsoft_id_unique` (`microsoft_id`),
  UNIQUE KEY `utenti_stripe_customer_id_unique` (`stripe_customer_id`),
  KEY `utenti_ruolo_index` (`ruolo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `voti_proposte`;
CREATE TABLE `voti_proposte` (
  `id_voto` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_proposta` bigint unsigned NOT NULL,
  `id_utente` bigint unsigned NOT NULL,
  `valore_voto` enum('favore','contrario') NOT NULL,
  `data_voto` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_voto`),
  UNIQUE KEY `uniq_voto_per_utente` (`id_proposta`,`id_utente`),
  KEY `voti_proposte_id_utente_foreign` (`id_utente`),
  CONSTRAINT `voti_proposte_id_proposta_foreign` FOREIGN KEY (`id_proposta`) REFERENCES `proposte_prodotti` (`id_proposta`) ON DELETE CASCADE,
  CONSTRAINT `voti_proposte_id_utente_foreign` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tabelle usate dall'API che non esistevano nelle migrazioni Laravel
--

CREATE TABLE `proposta_scaglioni` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_proposta` bigint unsigned NOT NULL,
  `soglia` int NOT NULL,
  `prezzo` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scaglione_proposta` (`id_proposta`,`soglia`),
  CONSTRAINT `proposta_scaglioni_fk` FOREIGN KEY (`id_proposta`) REFERENCES `proposte_prodotti` (`id_proposta`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `recensioni_fornitore` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_fornitore` bigint unsigned NOT NULL,
  `id_utente` bigint unsigned NOT NULL,
  `voto` tinyint unsigned NOT NULL,
  `testo` text,
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rec_fornitore` (`id_fornitore`,`id_utente`),
  CONSTRAINT `rec_fornitore_fornitore_fk` FOREIGN KEY (`id_fornitore`) REFERENCES `fornitori` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rec_fornitore_utente_fk` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `recensioni_campagna` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_colletta` bigint unsigned NOT NULL,
  `id_utente` bigint unsigned NOT NULL,
  `voto` tinyint unsigned NOT NULL,
  `testo` text,
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rec_campagna` (`id_colletta`,`id_utente`),
  CONSTRAINT `rec_campagna_colletta_fk` FOREIGN KEY (`id_colletta`) REFERENCES `collette` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rec_campagna_utente_fk` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reset_password` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_utente` bigint unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `scadenza` datetime NOT NULL,
  `usato` tinyint(1) NOT NULL DEFAULT 0,
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reset_token` (`token`),
  CONSTRAINT `reset_password_utente_fk` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `prodotti_like` (
  `id_prodotto` bigint unsigned NOT NULL,
  `id_utente` bigint unsigned NOT NULL,
  `data_creazione` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_prodotto`,`id_utente`),
  CONSTRAINT `like_prodotto_fk` FOREIGN KEY (`id_prodotto`) REFERENCES `prodotti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `like_utente_fk` FOREIGN KEY (`id_utente`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_tentativi` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chiave` char(40) NOT NULL,
  `data_tentativo` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_chiave_data` (`chiave`,`data_tentativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
