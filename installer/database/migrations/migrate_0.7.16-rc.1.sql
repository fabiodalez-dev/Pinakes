-- Migration 0.7.16-rc.1 — heal multi-publisher junction drift (issue #143).
--
-- The 0.7.15 migration created `libri_editori` and backfilled it from
-- `libri.editore_id`. But books written by the CSV / LibraryThing importers or
-- by bulk-enrichment AFTER that migration set `libri.editore_id` WITHOUT a
-- matching junction row. The importers now keep the junction in sync, but data
-- written before that fix drifted: junction-only consumers (OAI-PMH, BIBFRAME)
-- exported no publisher for those books.
--
-- Re-backfill every book's primary publisher into the junction at ordine 0.
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE, safe to re-run and
-- safe whether or not the 0.7.15 migration already created the table in the
-- same upgrade run.

CREATE TABLE IF NOT EXISTS `libri_editori` (
  `libro_id` int NOT NULL,
  `editore_id` int NOT NULL,
  `ordine` int DEFAULT NULL,
  PRIMARY KEY (`libro_id`,`editore_id`),
  KEY `libro_id` (`libro_id`),
  KEY `editore_id` (`editore_id`),
  CONSTRAINT `libri_editori_ibfk_1` FOREIGN KEY (`libro_id`) REFERENCES `libri` (`id`) ON DELETE CASCADE,
  CONSTRAINT `libri_editori_ibfk_2` FOREIGN KEY (`editore_id`) REFERENCES `editori` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `libri_editori` (`libro_id`, `editore_id`, `ordine`)
SELECT `id`, `editore_id`, 0
FROM `libri`
WHERE `editore_id` IS NOT NULL AND `deleted_at` IS NULL;
