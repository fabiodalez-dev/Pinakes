-- Migration 0.7.16 — heal multi-publisher junction drift (issue #143).
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

-- Step 1 — demote impostors. A drifted book can already hold a NON-primary
-- publisher at ordine 0 (a real case: libro 94 had both 89:0 and 106:0 while
-- libri.editore_id = 106). If we only promoted the primary, two rows would sit
-- at ordine 0 and an order-sorted export/display could still pick the wrong
-- one. Deleting the impostor would destroy a legitimate co-publisher, so push
-- it just past the book's current highest ordine instead — it survives as a
-- co-publisher and vacates the primary slot. MAX(ordine)+1 from a materialized
-- derived table (no window functions — portable to MySQL 5.7).
UPDATE `libri_editori` `le`
JOIN `libri` `l`
  ON `l`.`id` = `le`.`libro_id`
 AND `l`.`deleted_at` IS NULL
JOIN (
  SELECT `libro_id`, MAX(`ordine`) AS `max_ord`
  FROM `libri_editori`
  GROUP BY `libro_id`
) `m` ON `m`.`libro_id` = `le`.`libro_id`
SET `le`.`ordine` = COALESCE(`m`.`max_ord`, 0) + 1
WHERE `le`.`ordine` = 0
  AND `l`.`editore_id` IS NOT NULL
  AND `le`.`editore_id` <> `l`.`editore_id`;

-- Step 2 — promote the primary. The INSERT IGNORE above is a no-op when the
-- primary publisher already has a junction row, but on a drifted install that
-- row may sit at a non-zero `ordine`. With the slot now cleared of impostors,
-- force each book's primary publisher to ordine 0 so junction-ordered
-- consumers (OAI-PMH, BIBFRAME) surface the right primary. Genuine
-- co-publishers (a different editore_id) keep their order.
UPDATE `libri_editori` `le`
JOIN `libri` `l`
  ON `l`.`id` = `le`.`libro_id`
 AND `l`.`editore_id` = `le`.`editore_id`
 AND `l`.`deleted_at` IS NULL
SET `le`.`ordine` = 0
WHERE `le`.`ordine` IS NULL OR `le`.`ordine` <> 0;
