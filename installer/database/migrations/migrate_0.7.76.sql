-- 0.7.76 — Enrich backfilled loan events with the physical copy.
--
-- The 0.7.74 backfill (shipped in 0.7.75) synthesized loan.created /
-- loan.returned events without copia_id, even though the loans carry it:
-- the timeline card could not show WHICH physical copy was involved — the
-- exact information the feature was asked for. This migration copies the
-- loan's copia_id into every backfilled event that lacks it.
--
-- Idempotent and surgical: only source=backfill loan events, only when the
-- joined loan actually has a copy, and only when the snapshot does not
-- already carry one. Real runtime events (which already include copia_id)
-- and reservations (never copy-bound) are untouched. Verified live on two
-- production installs before being productized.

UPDATE log_modifiche lm
JOIN prestiti p ON p.id = JSON_EXTRACT(lm.dati_nuovi, '$._activity.entity_id')
SET lm.dati_nuovi = JSON_SET(lm.dati_nuovi, '$.copia_id', p.copia_id)
WHERE lm.tabella = 'libri'
  AND JSON_UNQUOTE(JSON_EXTRACT(lm.dati_nuovi, '$._activity.source')) = 'backfill'
  AND JSON_UNQUOTE(JSON_EXTRACT(lm.dati_nuovi, '$._activity.event')) IN ('loan.created', 'loan.returned')
  AND p.copia_id IS NOT NULL
  AND JSON_EXTRACT(lm.dati_nuovi, '$.copia_id') IS NULL;
