mysqldump: [Warning] Using a password on the command line interface can be insecure.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('new_message','new_reservation','new_user','overdue_loan','new_loan_request','new_review','general') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `link` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `related_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_type` (`type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `api_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `idx_api_key` (`api_key`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `autori` (
  `id` int NOT NULL AUTO_INCREMENT,
  `data_nascita` date DEFAULT NULL,
  `data_morte` date DEFAULT NULL,
  `nazionalità` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pseudonimo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `biografia` text COLLATE utf8mb4_unicode_ci,
  `sito_web` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `classificazione` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int DEFAULT NULL,
  `codice` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `livello` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `classificazione_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `classificazione` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1370 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `image` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug` (`slug`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cognome` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indirizzo` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaggio` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `privacy_accepted` tinyint(1) DEFAULT '0',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_read` tinyint(1) DEFAULT '0',
  `is_archived` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_read` (`is_read`),
  KEY `idx_archived` (`is_archived`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libro_id` int NOT NULL,
  `numero_inventario` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stato` enum('disponibile','prestato','manutenzione','perso','danneggiato') COLLATE utf8mb4_unicode_ci DEFAULT 'disponibile',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_numero_inventario` (`numero_inventario`),
  KEY `idx_libro_id` (`libro_id`),
  KEY `idx_stato` (`stato`),
  CONSTRAINT `copie_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `donazioni` (
  `id` int NOT NULL AUTO_INCREMENT,
  `donatore_id` int NOT NULL,
  `data_donazione` datetime DEFAULT CURRENT_TIMESTAMP,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `stato` enum('in_valutazione','accettata','rifiutata') COLLATE utf8mb4_unicode_ci DEFAULT 'in_valutazione',
  `valore` decimal(10,2) DEFAULT NULL,
  `tipo_donazione` enum('libri','fondi','materiali','altro') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_receipt` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `donatore_id` (`donatore_id`),
  KEY `idx_donazioni_tipo_donazione` (`tipo_donazione`),
  CONSTRAINT `donazioni_ibfk_1` FOREIGN KEY (`donatore_id`) REFERENCES `utenti` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `editori` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `indirizzo` text COLLATE utf8mb4_unicode_ci,
  `sito_web` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referente_nome` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referente_telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referente_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `codice_fiscale` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codice_fiscale` (`codice_fiscale`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `locale` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'it_IT',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_locale` (`name`,`locale`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feedback` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utente_id` int NOT NULL,
  `tipo` enum('feedback','problema','suggerimento') COLLATE utf8mb4_unicode_ci NOT NULL,
  `messaggio` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_invio` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `utente_id` (`utente_id`),
  KEY `idx_feedback_tipo` (`tipo`),
  CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `generi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `parent_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_nome_parent` (`nome`,`parent_id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `generi_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `generi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=309 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `home_content` (
  `id` int NOT NULL AUTO_INCREMENT,
  `section_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Chiave sezione (hero, features, cta, etc)',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Titolo principale',
  `subtitle` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Sottotitolo o descrizione',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Contenuto aggiuntivo (HTML permesso)',
  `button_text` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Testo pulsante CTA',
  `button_link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Link pulsante CTA',
  `background_image` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Percorso immagine di sfondo',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Se 0 la sezione non viene mostrata',
  `display_order` int DEFAULT '0' COMMENT 'Ordine di visualizzazione',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_key` (`section_key`),
  KEY `idx_active` (`is_active`),
  KEY `idx_order` (`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contenuti editabili homepage';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `libri` (
  `id` int NOT NULL AUTO_INCREMENT,
  `isbn10` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `isbn13` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `titolo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sottotitolo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anno_pubblicazione` year DEFAULT NULL,
  `lingua` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `edizione` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_pagine` int DEFAULT NULL,
  `genere_id` int DEFAULT NULL,
  `scaffale_id` int DEFAULT NULL,
  `mensola_id` int DEFAULT NULL,
  `posizione_progressiva` int DEFAULT NULL,
  `collocazione` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posizione_id` int DEFAULT NULL,
  `stato` enum('disponibile','prestato','prenotato','perso','danneggiato') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'disponibile',
  `data_acquisizione` date DEFAULT NULL,
  `tipo_acquisizione` enum('acquisto','donazione') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'acquisto',
  `copertina_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descrizione` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `parole_chiave` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `formato` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cartaceo',
  `peso` float DEFAULT NULL,
  `dimensioni` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prezzo` decimal(10,2) DEFAULT NULL,
  `copie_totali` int DEFAULT '1',
  `copie_disponibili` int DEFAULT '1',
  `editore_id` int DEFAULT NULL,
  `numero_inventario` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `classificazione_dowey` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collana` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_serie` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note_varie` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `file_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `traduttore` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ean` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'European Article Number',
  `data_pubblicazione` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'Original publication date in Italian format',
  PRIMARY KEY (`id`),
  UNIQUE KEY `isbn13` (`isbn13`),
  KEY `genere_id` (`genere_id`),
  KEY `posizione_id` (`posizione_id`),
  KEY `idx_libri_titolo_sottotitolo` (`titolo`,`sottotitolo`),
  KEY `editore_id` (`editore_id`),
  KEY `idx_libri_stato` (`stato`),
  KEY `fk_libri_mensola` (`mensola_id`),
  KEY `idx_libri_scaffale_mensola` (`scaffale_id`,`mensola_id`),
  KEY `idx_libri_posizione_progressiva` (`posizione_progressiva`),
  FULLTEXT KEY `ft_libri_search` (`titolo`,`sottotitolo`,`descrizione`,`parole_chiave`),
  CONSTRAINT `fk_libri_mensola` FOREIGN KEY (`mensola_id`) REFERENCES `mensole` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_libri_scaffale` FOREIGN KEY (`scaffale_id`) REFERENCES `scaffali` (`id`) ON DELETE SET NULL,
  CONSTRAINT `libri_ibfk_1` FOREIGN KEY (`editore_id`) REFERENCES `editori` (`id`) ON DELETE SET NULL,
  CONSTRAINT `libri_ibfk_3` FOREIGN KEY (`genere_id`) REFERENCES `generi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `libri_ibfk_5` FOREIGN KEY (`posizione_id`) REFERENCES `posizioni` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `libri_autori` (
  `libro_id` int NOT NULL,
  `autore_id` int NOT NULL,
  `ruolo` enum('principale','co-autore','traduttore') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordine_credito` int DEFAULT NULL,
  PRIMARY KEY (`libro_id`,`autore_id`,`ruolo`),
  KEY `libro_id` (`libro_id`),
  KEY `autore_id` (`autore_id`),
  CONSTRAINT `libri_autori_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
  CONSTRAINT `libri_autori_ibfk_2` FOREIGN KEY (`autore_id`) REFERENCES `autori` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `libri_donati` (
  `id` int NOT NULL AUTO_INCREMENT,
  `donazione_id` int NOT NULL,
  `libro_id` int NOT NULL,
  `quantita` int DEFAULT '1',
  `condizione` enum('nuovo','ottimo','buono','discreto') COLLATE utf8mb4_unicode_ci DEFAULT 'ottimo',
  `note_condizione` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `libro_id` (`libro_id`),
  KEY `idx_libri_donati_donazione_id` (`donazione_id`),
  CONSTRAINT `libri_donati_ibfk_1` FOREIGN KEY (`donazione_id`) REFERENCES `donazioni` (`id`),
  CONSTRAINT `libri_donati_ibfk_2` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `libri_tag` (
  `libro_id` int NOT NULL,
  `tag_id` int NOT NULL,
  PRIMARY KEY (`libro_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `libri_tag_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
  CONSTRAINT `libri_tag_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tag` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_modifiche` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tabella` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int NOT NULL,
  `azione` enum('inserimento','aggiornamento','cancellazione') COLLATE utf8mb4_unicode_ci NOT NULL,
  `dati_precedenti` text COLLATE utf8mb4_unicode_ci,
  `dati_nuovi` text COLLATE utf8mb4_unicode_ci,
  `utente_id` int DEFAULT NULL,
  `data_modifica` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `utente_id` (`utente_id`),
  KEY `idx_log_modifiche_tabella_record_id` (`tabella`,`record_id`),
  CONSTRAINT `log_modifiche_ibfk_1` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mensole` (
  `id` int NOT NULL AUTO_INCREMENT,
  `scaffale_id` int NOT NULL,
  `numero_livello` int NOT NULL,
  `genere_id` int DEFAULT NULL,
  `dowey_subcategory_id` int DEFAULT NULL,
  `descrizione` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ordine` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `scaffale_id` (`scaffale_id`,`numero_livello`),
  KEY `genere_id` (`genere_id`),
  KEY `fk_mensole_dowey` (`dowey_subcategory_id`),
  CONSTRAINT `fk_mensole_dowey` FOREIGN KEY (`dowey_subcategory_id`) REFERENCES `classificazione` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mensole_ibfk_1` FOREIGN KEY (`scaffale_id`) REFERENCES `scaffali` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mensole_ibfk_2` FOREIGN KEY (`genere_id`) REFERENCES `generi` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugin_data` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` int unsigned NOT NULL COMMENT 'Reference to plugin',
  `data_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Data key',
  `data_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Data value (can store JSON)',
  `data_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string' COMMENT 'Data type hint',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plugin_key` (`plugin_id`,`data_key`),
  KEY `idx_plugin_id` (`plugin_id`),
  CONSTRAINT `fk_plugin_data_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Generic plugin data storage';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugin_hooks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` int unsigned NOT NULL COMMENT 'Reference to plugin',
  `hook_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Hook identifier',
  `callback_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PHP class to call',
  `callback_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Method name in class',
  `priority` int NOT NULL DEFAULT '10' COMMENT 'Execution priority (lower = earlier)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether hook is active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hook_name` (`hook_name`,`priority`),
  KEY `idx_plugin_id` (`plugin_id`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `fk_plugin_hooks_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin hooks registry';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugin_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` int unsigned DEFAULT NULL COMMENT 'Reference to plugin (NULL for system logs)',
  `level` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info' COMMENT 'Log level',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Log message',
  `context` json DEFAULT NULL COMMENT 'Additional context data',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plugin_id` (`plugin_id`),
  KEY `idx_level` (`level`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_plugin_logs_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin activity and error logs';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugin_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` int unsigned NOT NULL COMMENT 'Reference to plugin',
  `setting_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Setting key',
  `setting_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Setting value (can store JSON)',
  `autoload` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether to autoload this setting',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_plugin_setting` (`plugin_id`,`setting_key`),
  KEY `idx_plugin_id` (`plugin_id`),
  KEY `idx_autoload` (`autoload`),
  CONSTRAINT `fk_plugin_settings_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin-specific settings and configuration';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Plugin unique identifier',
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable plugin name',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Plugin description',
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Plugin version',
  `author` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Plugin author name',
  `author_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Author website URL',
  `plugin_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Plugin website/repository URL',
  `is_active` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether plugin is activated',
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Plugin directory path',
  `main_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Main plugin file',
  `requires_php` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Minimum PHP version required',
  `requires_app` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Minimum app version required',
  `metadata` json DEFAULT NULL COMMENT 'Additional plugin metadata',
  `installed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Installation timestamp',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  `activated_at` datetime DEFAULT NULL COMMENT 'Last activation timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_plugin_name` (`name`),
  KEY `idx_active` (`is_active`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin registry and metadata';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `posizioni` (
  `id` int NOT NULL AUTO_INCREMENT,
  `scaffale_id` int NOT NULL,
  `mensola_id` int NOT NULL,
  `genere_id` int NOT NULL,
  `descrizione` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ordine` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `scaffale_id` (`scaffale_id`,`mensola_id`,`genere_id`),
  KEY `mensola_id` (`mensola_id`),
  KEY `genere_id` (`genere_id`),
  CONSTRAINT `posizioni_ibfk_1` FOREIGN KEY (`scaffale_id`) REFERENCES `scaffali` (`id`) ON DELETE CASCADE,
  CONSTRAINT `posizioni_ibfk_2` FOREIGN KEY (`mensola_id`) REFERENCES `mensole` (`id`) ON DELETE CASCADE,
  CONSTRAINT `posizioni_ibfk_3` FOREIGN KEY (`genere_id`) REFERENCES `generi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `preferenze_notifica_utenti` (
  `utente_id` int NOT NULL,
  `notifica_tipo` enum('email','sms','push') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`utente_id`,`notifica_tipo`),
  CONSTRAINT `preferenze_notifica_utenti_ibfk_1` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prenotazioni` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libro_id` int NOT NULL,
  `utente_id` int NOT NULL,
  `data_prenotazione` datetime DEFAULT CURRENT_TIMESTAMP,
  `data_inizio_richiesta` date DEFAULT NULL,
  `data_fine_richiesta` date DEFAULT NULL,
  `data_scadenza_prenotazione` datetime DEFAULT NULL,
  `queue_position` int DEFAULT NULL,
  `notifica_inviata` tinyint(1) DEFAULT '0',
  `stato` enum('attiva','completata','annullata') COLLATE utf8mb4_unicode_ci DEFAULT 'attiva',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `libro_id` (`libro_id`),
  KEY `utente_id` (`utente_id`),
  KEY `idx_prenotazioni_data_scadenza_prenotazione` (`data_scadenza_prenotazione`),
  CONSTRAINT `prenotazioni_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`),
  CONSTRAINT `prenotazioni_ibfk_2` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prestiti` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libro_id` int NOT NULL,
  `copia_id` int DEFAULT NULL,
  `utente_id` int NOT NULL,
  `data_prestito` date NOT NULL,
  `data_scadenza` date NOT NULL,
  `data_restituzione` date DEFAULT NULL,
  `stato` enum('pendente','in_corso','restituito','in_ritardo','perso','danneggiato') COLLATE utf8mb4_unicode_ci DEFAULT 'pendente',
  `sanzione` decimal(10,2) DEFAULT '0.00',
  `renewals` int DEFAULT '0',
  `processed_by` int DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `warning_sent` tinyint(1) DEFAULT '0',
  `overdue_notification_sent` tinyint(1) DEFAULT '0',
  `active_copia_id` int GENERATED ALWAYS AS ((case when (`attivo` = 1) then `copia_id` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_prestiti_active_copia` (`active_copia_id`),
  KEY `libro_id` (`libro_id`),
  KEY `utente_id` (`utente_id`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_prestiti_data_scadenza` (`data_scadenza`),
  KEY `idx_prestiti_status` (`stato`,`data_scadenza`),
  KEY `idx_prestiti_libro_stato` (`libro_id`,`stato`),
  KEY `idx_prestiti_attivo_stato` (`attivo`,`stato`),
  KEY `idx_copia_id` (`copia_id`),
  CONSTRAINT `fk_prestiti_copia` FOREIGN KEY (`copia_id`) REFERENCES `copie` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `prestiti_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`),
  CONSTRAINT `prestiti_ibfk_2` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`),
  CONSTRAINT `prestiti_ibfk_3` FOREIGN KEY (`processed_by`) REFERENCES `staff` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recensioni` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libro_id` int NOT NULL,
  `utente_id` int NOT NULL,
  `stelle` int NOT NULL DEFAULT '0',
  `titolo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `stato` enum('pendente','approvata','rifiutata') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `approved_by` int DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `data_recensione` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_book_review` (`libro_id`,`utente_id`),
  KEY `idx_recensioni_libro_id` (`libro_id`),
  KEY `idx_recensioni_utente_id` (`utente_id`),
  KEY `idx_recensioni_stato` (`stato`),
  KEY `idx_recensioni_approved_by` (`approved_by`),
  CONSTRAINT `recensioni_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recensioni_ibfk_2` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recensioni_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recensioni_chk_1` CHECK ((`stelle` between 1 and 5))
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scaffali` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codice` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lettera` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ordine` int DEFAULT '0',
  `dowey_category_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lettera` (`lettera`),
  UNIQUE KEY `uniq_codice` (`codice`),
  KEY `fk_scaffali_dowey` (`dowey_category_id`),
  CONSTRAINT `fk_scaffali_dowey` FOREIGN KEY (`dowey_category_id`) REFERENCES `classificazione` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cognome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ruolo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_assunzione` date DEFAULT NULL,
  `stato` enum('attivo','inattivo') COLLATE utf8mb4_unicode_ci DEFAULT 'attivo',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_setting` (`category`,`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=181 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tag` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descrizione` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `utenti` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codice_tessera` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cognome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indirizzo` text COLLATE utf8mb4_unicode_ci,
  `data_nascita` date DEFAULT NULL,
  `sesso` enum('M','F','Altro') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_profilo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_registrazione` datetime DEFAULT CURRENT_TIMESTAMP,
  `data_scadenza_tessera` date DEFAULT NULL,
  `stato` enum('attivo','sospeso','scaduto') COLLATE utf8mb4_unicode_ci DEFAULT 'attivo',
  `tipo_utente` enum('standard','premium','staff','admin') COLLATE utf8mb4_unicode_ci DEFAULT 'standard',
  `cod_fiscale` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note_utente` text COLLATE utf8mb4_unicode_ci,
  `email_verificata` tinyint(1) DEFAULT '0',
  `locale` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'it_IT',
  `token_verifica_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_token_verifica` datetime DEFAULT NULL,
  `token_reset_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_token_reset` datetime DEFAULT NULL,
  `data_ultimo_accesso` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codice_tessera` (`codice_tessera`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `cod_fiscale` (`cod_fiscale`),
  KEY `idx_utenti_email` (`email`),
  KEY `idx_utenti_codice_tessera` (`codice_tessera`),
  KEY `idx_utenti_cod_fiscale` (`cod_fiscale`),
  KEY `idx_utenti_data_scadenza_tessera` (`data_scadenza_tessera`),
  KEY `idx_utenti_data_ultimo_accesso` (`data_ultimo_accesso`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wishlist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utente_id` int NOT NULL,
  `libro_id` int NOT NULL,
  `data_aggiunta` datetime DEFAULT CURRENT_TIMESTAMP,
  `notified` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `utente_id` (`utente_id`,`libro_id`),
  KEY `idx_wishlist_utente_id` (`utente_id`),
  KEY `idx_wishlist_libro_id` (`libro_id`),
  CONSTRAINT `wishlist_ibfk_1` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wishlist_ibfk_2` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

