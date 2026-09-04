-- Migration 0.7.80
-- Durable outbox for transactional/terminal circulation emails.

CREATE TABLE IF NOT EXISTS `email_delivery_outbox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `recipient_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `available_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claim_token` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_outbox_due` (`available_at`,`claim_token`),
  KEY `idx_email_outbox_claimed` (`claimed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
