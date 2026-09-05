-- Migration 0.7.80
-- Durable outbox for transactional/terminal circulation emails, plus
-- legacy-data normalization for the new circulation triggers.

-- ─────────────────────────────────────────────────────────────────────────
-- Normalize BEFORE the new guards land: the updater runs migrations first
-- and reapplies triggers afterwards, so these UPDATEs execute under the old
-- triggers. Without this, legacy queues holding duplicate/non-positive
-- positions or inverted request windows would make every subsequent reorder
-- (including the DataIntegrity repair) die on the new SIGNALs forever.
-- ─────────────────────────────────────────────────────────────────────────

-- 1) Inverted reservation windows: swap start/end via a snapshot join
--    (MySQL SET clauses see already-updated columns, a naive swap corrupts).
UPDATE prenotazioni p
  JOIN (
        SELECT id, data_inizio_richiesta AS di, data_fine_richiesta AS df
          FROM prenotazioni
         WHERE data_inizio_richiesta IS NOT NULL
           AND data_fine_richiesta IS NOT NULL
           AND data_inizio_richiesta > data_fine_richiesta
       ) s ON s.id = p.id
   SET p.data_inizio_richiesta = s.df,
       p.data_fine_richiesta   = s.di;

-- 2) Active queue positions: renumber to a clean 1..N per book using the
--    canonical repair ordering (queue_position ASC, id ASC — NULLs first),
--    which resolves duplicates, non-positive values and legacy NULLs in one
--    pass. Healthy queues match their ROW_NUMBER and are not written, so a
--    second run is a no-op.
UPDATE prenotazioni p
  JOIN (
        SELECT id,
               ROW_NUMBER() OVER (
                   PARTITION BY libro_id
                   ORDER BY queue_position ASC, id ASC
               ) AS rn
          FROM prenotazioni
         WHERE stato = 'attiva'
       ) ranked ON ranked.id = p.id
   SET p.queue_position = ranked.rn
 WHERE p.stato = 'attiva'
   AND (p.queue_position IS NULL OR p.queue_position <> ranked.rn);

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
