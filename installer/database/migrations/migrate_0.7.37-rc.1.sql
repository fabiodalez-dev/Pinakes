-- Migration 0.7.37 — configurable registration fields (issue #255)
--
-- Adds the admin-defined custom registration fields: definitions in
-- `registrazione_campi`, per-user values in `utenti_campi_valori`.
-- The built-in field toggles (require_cognome / require_telefono /
-- require_indirizzo) need no schema: they live in system_settings with
-- code-side defaults that preserve the historical behaviour.
--
-- The surname stays a NOT NULL column on purpose: an optional surname is
-- stored as an empty string, because 70+ display paths concatenate
-- nome+cognome with CONCAT() and a NULL there would blank the whole name.
--
-- Idempotent (project rule 6): CREATE TABLE IF NOT EXISTS only; safe to
-- re-run on any install.

CREATE TABLE IF NOT EXISTS `registrazione_campi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `etichetta` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` enum('text','textarea','email','url','number','checkbox') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `obbligatorio` tinyint(1) NOT NULL DEFAULT '0',
  `attivo` tinyint(1) NOT NULL DEFAULT '1',
  `ordine` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utenti_campi_valori` (
  `utente_id` int NOT NULL,
  `campo_id` int NOT NULL,
  `valore` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`utente_id`, `campo_id`),
  KEY `idx_ucv_campo` (`campo_id`),
  CONSTRAINT `utenti_campi_valori_utente_fk` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE,
  CONSTRAINT `utenti_campi_valori_campo_fk` FOREIGN KEY (`campo_id`) REFERENCES `registrazione_campi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
