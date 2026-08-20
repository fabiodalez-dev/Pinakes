-- Migration 0.7.63-rc.1
-- Issue #366 follow-up: repair impossible ready-pickup rows left by releases
-- that promoted scheduled loans solely from their date window.
--
-- The UPDATE deliberately clears copia_id while demoting the row. This both
-- releases a stale physical assignment and makes the repair executable even
-- while the installation still has the pre-fix BEFORE UPDATE trigger: that
-- trigger only applies its overlap gate when NEW.copia_id is non-NULL. The
-- updater re-applies the corrected canonical trigger after migrations, and the
-- next activation assigns a genuinely free copy atomically before promotion.
--
-- The double derived table is the MySQL 5.7-compatible workaround for error
-- 1093 (selecting from prestiti while updating prestiti). Idempotent: repaired
-- rows no longer have stato='da_ritirare' and are not selected on a re-run.

UPDATE `prestiti`
SET `stato` = 'prenotato',
    `pickup_deadline` = NULL,
    `copia_id` = NULL
WHERE `id` IN (
    SELECT `stale_id`
    FROM (
        SELECT ready.id AS `stale_id`
        FROM `prestiti` ready
        LEFT JOIN `copie` assigned
          ON assigned.id = ready.copia_id
         AND assigned.libro_id = ready.libro_id
        WHERE ready.stato = 'da_ritirare'
          AND ready.attivo = 1
          AND (
              ready.copia_id IS NULL
              OR assigned.id IS NULL
              OR assigned.stato NOT IN ('disponibile', 'prenotato')
              OR EXISTS (
                  SELECT 1
                  FROM `prestiti` same_copy
                  WHERE same_copy.copia_id = ready.copia_id
                    AND same_copy.id <> ready.id
                    AND (
                        (same_copy.attivo = 1 AND same_copy.stato IN ('in_corso', 'in_ritardo', 'da_ritirare'))
                        OR (same_copy.attivo = 0 AND same_copy.stato = 'pendente' AND same_copy.copia_id IS NOT NULL)
                    )
              )
              OR NOT EXISTS (
                  SELECT 1
                  FROM `copie` free_copy
                  WHERE free_copy.libro_id = ready.libro_id
                    AND free_copy.stato IN ('disponibile', 'prenotato')
                    AND NOT EXISTS (
                        SELECT 1
                        FROM `prestiti` holder
                        WHERE holder.copia_id = free_copy.id
                          AND holder.id <> ready.id
                          AND (
                              (holder.attivo = 1 AND holder.stato IN ('in_corso', 'in_ritardo', 'da_ritirare'))
                              OR (holder.attivo = 0 AND holder.stato = 'pendente' AND holder.copia_id IS NOT NULL)
                          )
                    )
              )
          )
    ) AS `stale_ready_pickups`
);
