
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  PRIMARY KEY (`id`),
  FULLTEXT KEY `ft_autori_nome` (`nome`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `locale` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_US',
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
  KEY `idx_active` (`is_active`),
  KEY `idx_cms_locale` (`locale`),
  KEY `idx_cms_slug_locale` (`slug`,`locale`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `copie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `libro_id` int NOT NULL,
  `sede_id` int DEFAULT NULL,
  `numero_inventario` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stato` enum('disponibile','prestato','prenotato','manutenzione','in_restauro','perso','danneggiato','in_trasferimento') COLLATE utf8mb4_unicode_ci DEFAULT 'disponibile',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_numero_inventario` (`numero_inventario`),
  KEY `idx_libro_id` (`libro_id`),
  KEY `idx_stato` (`stato`),
  KEY `idx_sede_id` (`sede_id`),
  CONSTRAINT `copie_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
  CONSTRAINT `copie_ibfk_2` FOREIGN KEY (`sede_id`) REFERENCES `sedi` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `seo_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Custom SEO title (overrides default)',
  `seo_description` text COLLATE utf8mb4_unicode_ci COMMENT 'Custom meta description',
  `seo_keywords` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SEO keywords (comma-separated)',
  `og_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Custom Open Graph image (overrides hero background)',
  `og_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Open Graph Title',
  `og_description` text COLLATE utf8mb4_unicode_ci COMMENT 'Open Graph Description',
  `og_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'website' COMMENT 'Open Graph Type (website, article, etc.)',
  `og_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Open Graph URL',
  `twitter_card` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'summary_large_image' COMMENT 'Twitter Card Type',
  `twitter_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Twitter Card Title',
  `twitter_description` text COLLATE utf8mb4_unicode_ci COMMENT 'Twitter Card Description',
  `twitter_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Twitter Card Image URL',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Se 0 la sezione non viene mostrata',
  `display_order` int DEFAULT '0' COMMENT 'Ordine di visualizzazione',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_key` (`section_key`),
  KEY `idx_active` (`is_active`),
  KEY `idx_order` (`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contenuti editabili homepage';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `import_logs`
-- Import tracking system for CSV and LibraryThing imports
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `import_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique identifier for this import session',
  `import_type` enum('csv','librarything') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type of import',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Original filename uploaded',
  `user_id` int DEFAULT NULL COMMENT 'User who initiated the import',
  `total_rows` int NOT NULL DEFAULT '0' COMMENT 'Total rows in CSV',
  `imported` int NOT NULL DEFAULT '0' COMMENT 'Successfully imported books (new)',
  `updated` int NOT NULL DEFAULT '0' COMMENT 'Updated existing books',
  `failed` int NOT NULL DEFAULT '0' COMMENT 'Failed rows',
  `authors_created` int NOT NULL DEFAULT '0' COMMENT 'New authors created',
  `publishers_created` int NOT NULL DEFAULT '0' COMMENT 'New publishers created',
  `scraped` int NOT NULL DEFAULT '0' COMMENT 'Books enriched via scraping',
  `errors_json` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'JSON array of errors with line numbers and messages',
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When import started',
  `completed_at` timestamp NULL DEFAULT NULL COMMENT 'When import completed or failed',
  `status` enum('processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'processing' COMMENT 'Current import status',
  PRIMARY KEY (`id`),
  UNIQUE KEY `import_id` (`import_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_import_type` (`import_type`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_status` (`status`),
  KEY `idx_type_status` (`import_type`,`status`),
  CONSTRAINT `import_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Import tracking and error logging';
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `featured_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` text COLLATE utf8mb4_unicode_ci,
  `seo_keywords` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `og_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `og_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `og_description` text COLLATE utf8mb4_unicode_ci,
  `og_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'article',
  `og_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twitter_card` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'summary_large_image',
  `twitter_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twitter_description` text COLLATE utf8mb4_unicode_ci,
  `twitter_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_events_active` (`is_active`),
  KEY `idx_events_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Library events and activities';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `languages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Language code: it_IT, en_US, es_ES, fr_FR, de_DE, etc.',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'English name: Italian, English, Spanish, French, German',
  `native_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Native name: Italiano, English, Español, Français, Deutsch',
  `flag_emoji` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 0xF09F8C90 COMMENT 'Flag emoji: ??, ??, ??, ??, ??',
  `is_default` tinyint(1) DEFAULT '0' COMMENT 'Is this the default language for new users?',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Is this language active and selectable?',
  `translation_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path to translation file: locale/es_ES.json',
  `total_keys` int DEFAULT '0' COMMENT 'Total translation keys in system',
  `translated_keys` int DEFAULT '0' COMMENT 'Number of translated keys for this language',
  `completion_percentage` decimal(5,2) DEFAULT '0.00' COMMENT 'Translation completion percentage',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_code` (`code`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_is_default` (`is_default`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores available languages for the application';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `libri` (
  `id` int NOT NULL AUTO_INCREMENT,
  `isbn10` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `isbn13` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issn` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ISSN for periodicals (LibraryThing)',
  `titolo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sottotitolo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anno_pubblicazione` smallint unsigned DEFAULT NULL,
  `lingua` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_languages` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Original languages (LibraryThing)',
  `edizione` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_pagine` int DEFAULT NULL,
  `genere_id` int DEFAULT NULL,
  `sottogenere_id` int DEFAULT NULL,
  `scaffale_id` int DEFAULT NULL,
  `mensola_id` int DEFAULT NULL,
  `posizione_progressiva` int DEFAULT NULL,
  `collocazione` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posizione_id` int DEFAULT NULL,
  `stato` enum('disponibile','prestato','prenotato','perso','danneggiato') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'disponibile',
  `data_acquisizione` date DEFAULT NULL,
  `entry_date` date DEFAULT NULL COMMENT 'LibraryThing entry date',
  `date_started` date DEFAULT NULL COMMENT 'Date started reading (LibraryThing)',
  `date_read` date DEFAULT NULL COMMENT 'Date finished reading (LibraryThing)',
  `tipo_acquisizione` enum('acquisto','donazione') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'acquisto',
  `copertina_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descrizione` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `review` text COLLATE utf8mb4_unicode_ci COMMENT 'Book review (LibraryThing)',
  `rating` tinyint unsigned DEFAULT NULL COMMENT 'Rating 1-5 (LibraryThing)',
  `comment` text COLLATE utf8mb4_unicode_ci COMMENT 'Public comment (LibraryThing)',
  `private_comment` text COLLATE utf8mb4_unicode_ci COMMENT 'Private comment (LibraryThing)',
  `parole_chiave` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `formato` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cartaceo',
  `peso` float DEFAULT NULL,
  `dimensioni` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `physical_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Physical description (LibraryThing)',
  `prezzo` decimal(10,2) DEFAULT NULL,
  `value` decimal(10,2) DEFAULT NULL COMMENT 'Current value (LibraryThing)',
  `condition_lt` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Physical condition (LibraryThing)',
  `lt_fields_visibility` json DEFAULT NULL COMMENT 'Frontend visibility preferences for LibraryThing fields',
  `copie_totali` int DEFAULT '1',
  `copie_disponibili` int DEFAULT '1',
  `editore_id` int DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Source/vendor (LibraryThing)',
  `from_where` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'From where acquired (LibraryThing)',
  `lending_patron` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Current lending patron (LibraryThing)',
  `lending_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Lending status (LibraryThing)',
  `lending_start` date DEFAULT NULL COMMENT 'Lending start date (LibraryThing)',
  `lending_end` date DEFAULT NULL COMMENT 'Lending end date (LibraryThing)',
  `numero_inventario` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `classificazione_dewey` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dewey_wording` text COLLATE utf8mb4_unicode_ci COMMENT 'Dewey classification description (LibraryThing)',
  `lccn` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Library of Congress Control Number (LibraryThing)',
  `lc_classification` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'LC Classification (LibraryThing)',
  `other_call_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Other call number (LibraryThing)',
  `collana` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_serie` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note_varie` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `file_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `traduttore` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `illustratore` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ean` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'European Article Number',
  `bcid` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'BCID (LibraryThing)',
  `barcode` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Physical barcode (LibraryThing)',
  `oclc` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'OCLC number (LibraryThing)',
  `work_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'LibraryThing Work ID',
  `data_pubblicazione` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'Original publication date in Italian format',
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `isbn13` (`isbn13`),
  KEY `genere_id` (`genere_id`),
  KEY `idx_libri_sottogenere` (`sottogenere_id`),
  KEY `posizione_id` (`posizione_id`),
  KEY `idx_libri_titolo_sottotitolo` (`titolo`,`sottotitolo`),
  KEY `editore_id` (`editore_id`),
  KEY `idx_libri_stato` (`stato`),
  KEY `fk_libri_mensola` (`mensola_id`),
  KEY `idx_libri_scaffale_mensola` (`scaffale_id`,`mensola_id`),
  KEY `idx_libri_posizione_progressiva` (`posizione_progressiva`),
  KEY `idx_libri_deleted_at` (`deleted_at`),
  KEY `idx_lt_rating` (`rating`),
  KEY `idx_lt_date_read` (`date_read`),
  KEY `idx_lt_lending_status` (`lending_status`),
  KEY `idx_lt_lccn` (`lccn`),
  KEY `idx_lt_barcode` (`barcode`),
  KEY `idx_lt_oclc` (`oclc`),
  KEY `idx_lt_work_id` (`work_id`),
  KEY `idx_lt_issn` (`issn`),
  FULLTEXT KEY `ft_libri_search` (`titolo`,`sottotitolo`,`descrizione`,`parole_chiave`),
  CONSTRAINT `fk_libri_mensola` FOREIGN KEY (`mensola_id`) REFERENCES `mensole` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_libri_scaffale` FOREIGN KEY (`scaffale_id`) REFERENCES `scaffali` (`id`) ON DELETE SET NULL,
  CONSTRAINT `libri_ibfk_1` FOREIGN KEY (`editore_id`) REFERENCES `editori` (`id`) ON DELETE SET NULL,
  CONSTRAINT `libri_ibfk_3` FOREIGN KEY (`genere_id`) REFERENCES `generi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_libri_sottogenere` FOREIGN KEY (`sottogenere_id`) REFERENCES `generi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `libri_ibfk_5` FOREIGN KEY (`posizione_id`) REFERENCES `posizioni` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_lt_rating` CHECK ((`rating` is null or (`rating` between 1 and 5)))
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `libri_autori` (
  `libro_id` int NOT NULL,
  `autore_id` int NOT NULL,
  `ruolo` enum('principale','co-autore','traduttore','illustratore') COLLATE utf8mb4_unicode_ci NOT NULL,
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
  `descrizione` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ordine` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `scaffale_id` (`scaffale_id`,`numero_livello`),
  KEY `genere_id` (`genere_id`),
  CONSTRAINT `mensole_ibfk_1` FOREIGN KEY (`scaffale_id`) REFERENCES `scaffali` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mensole_ibfk_2` FOREIGN KEY (`genere_id`) REFERENCES `generi` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin hooks registry';
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin activity and error logs';
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin-specific settings and configuration';
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plugin registry and metadata';
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `pickup_deadline` date DEFAULT NULL,
  `data_restituzione` date DEFAULT NULL,
  `stato` enum('pendente','prenotato','da_ritirare','in_corso','restituito','in_ritardo','perso','danneggiato','annullato','scaduto') COLLATE utf8mb4_unicode_ci DEFAULT 'pendente',
  `origine` enum('richiesta','prenotazione','diretto') COLLATE utf8mb4_unicode_ci DEFAULT 'richiesta',
  `sanzione` decimal(10,2) DEFAULT '0.00',
  `renewals` int DEFAULT '0',
  `processed_by` int DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `warning_sent` tinyint(1) DEFAULT '0',
  `overdue_notification_sent` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `libro_id` (`libro_id`),
  KEY `utente_id` (`utente_id`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_prestiti_data_scadenza` (`data_scadenza`),
  KEY `idx_prestiti_status` (`stato`,`data_scadenza`),
  KEY `idx_prestiti_libro_stato` (`libro_id`,`stato`),
  KEY `idx_prestiti_attivo_stato` (`attivo`,`stato`),
  KEY `idx_prestiti_stato_origine` (`stato`,`origine`),
  KEY `idx_copia_id` (`copia_id`),
  KEY `idx_prestiti_pickup_deadline` (`pickup_deadline`),
  CONSTRAINT `fk_prestiti_copia` FOREIGN KEY (`copia_id`) REFERENCES `copie` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `prestiti_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`),
  CONSTRAINT `prestiti_ibfk_2` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`),
  CONSTRAINT `prestiti_ibfk_3` FOREIGN KEY (`processed_by`) REFERENCES `staff` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `lettera` (`lettera`),
  UNIQUE KEY `uniq_codice` (`codice`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sedi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nome della sede (es. Sede Centrale, Succursale Nord)',
  `codice` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Codice identificativo della sede',
  `indirizzo` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `citta` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cap` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provincia` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orari_apertura` text COLLATE utf8mb4_unicode_ci COMMENT 'Orari di apertura in formato libero',
  `note` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_codice` (`codice`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sedi della biblioteca (per sistemi multi-sede)';
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `privacy_accettata` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'GDPR privacy policy acceptance flag',
  `data_accettazione_privacy` datetime DEFAULT NULL COMMENT 'UTC timestamp of privacy acceptance',
  `privacy_policy_version` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Version of accepted privacy policy',
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
  KEY `idx_utenti_data_ultimo_accesso` (`data_ultimo_accesso`),
  KEY `idx_tipo_utente` (`tipo_utente`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `z39_access_logs`
-- Plugin: Z39.50/SRU Server
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `z39_access_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Client IP address',
  `user_agent` text COLLATE utf8mb4_unicode_ci COMMENT 'Client user agent',
  `operation` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SRU operation (explain, searchRetrieve, scan)',
  `query` text COLLATE utf8mb4_unicode_ci COMMENT 'CQL query string',
  `format` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Record format requested',
  `num_records` int DEFAULT '0' COMMENT 'Number of records returned',
  `response_time_ms` int DEFAULT NULL COMMENT 'Response time in milliseconds',
  `http_status` int DEFAULT NULL COMMENT 'HTTP status code',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT 'Error message if any',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_operation` (`operation`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `z39_rate_limits`
-- Plugin: Z39.50/SRU Server
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `z39_rate_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_count` int DEFAULT '1',
  `window_start` datetime NOT NULL,
  `last_request` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ip_window` (`ip_address`,`window_start`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `themes`
-- Theme Management System
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `themes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Theme display name',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique theme identifier',
  `version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '1.0.0' COMMENT 'Theme version',
  `author` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Admin' COMMENT 'Theme author',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'Theme description',
  `active` tinyint(1) DEFAULT '0' COMMENT '1 = active theme, 0 = inactive',
  `settings` json DEFAULT NULL COMMENT 'Theme settings (colors, typography, logo, advanced)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_active` (`active`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Theme management system';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
-- Application Update System
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Version number (e.g., 0.3.0)',
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Migration filename',
  `batch` int NOT NULL DEFAULT '1' COMMENT 'Batch number for rollback',
  `executed_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'When migration was executed',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_version` (`version`),
  KEY `idx_batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks executed database migrations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `update_logs`
-- Application Update System
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `update_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `from_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('started','completed','failed','rolled_back') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'started',
  `backup_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path to backup file',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `executed_by` int DEFAULT NULL COMMENT 'User ID who initiated update',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs all update attempts';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_sessions`
-- GDPR: Database-backed persistent sessions ("Remember Me")
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utente_id` int NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 hash of the token',
  `device_info` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Browser/device identifier',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IPv4 or IPv6',
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `is_revoked` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_user_sessions_utente_id` (`utente_id`),
  KEY `idx_user_sessions_token_hash` (`token_hash`),
  KEY `idx_user_sessions_expires_at` (`expires_at`),
  KEY `idx_user_sessions_is_revoked` (`is_revoked`),
  CONSTRAINT `fk_user_sessions_utente` FOREIGN KEY (`utente_id`)
      REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Database-backed persistent sessions for Remember Me';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `gdpr_requests`
-- GDPR: Track data export/delete/rectification requests
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gdpr_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utente_id` int DEFAULT NULL COMMENT 'NULL if user deleted',
  `utente_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Preserved for audit',
  `request_type` enum('export','delete','rectification') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','processing','completed','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  `processed_by` int DEFAULT NULL COMMENT 'Admin user ID',
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_gdpr_requests_utente_id` (`utente_id`),
  KEY `idx_gdpr_requests_status` (`status`),
  KEY `idx_gdpr_requests_type` (`request_type`),
  CONSTRAINT `fk_gdpr_requests_utente` FOREIGN KEY (`utente_id`)
      REFERENCES `utenti` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gdpr_requests_admin` FOREIGN KEY (`processed_by`)
      REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='GDPR data subject request tracking';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `consent_log`
-- GDPR Article 7: Audit trail for consent changes
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consent_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utente_id` int DEFAULT NULL,
  `consent_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'privacy_policy, marketing, analytics, etc.',
  `consent_given` tinyint(1) NOT NULL,
  `consent_version` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_consent_log_utente_id` (`utente_id`),
  KEY `idx_consent_log_type` (`consent_type`),
  KEY `idx_consent_log_created_at` (`created_at`),
  KEY `idx_consent_log_utente_type` (`utente_id`, `consent_type`, `created_at`),
  CONSTRAINT `fk_consent_log_utente` FOREIGN KEY (`utente_id`)
      REFERENCES `utenti` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='GDPR Article 7 consent audit trail';
/*!40101 SET character_set_client = @saved_cs_client */;


/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
