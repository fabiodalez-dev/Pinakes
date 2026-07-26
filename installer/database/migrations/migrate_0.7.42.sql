-- Migration 0.7.42
--
-- v0.7.42 added 4 UI strings for the security hardening in this release
-- (settings admin-only API-key notices, the "Authorization header:" label, and
-- the "Percorso file non valido" language-upload error). The da_DK catalogue was
-- brought to full parity with it_IT, so its shipped key count grew 6607 -> 6611.
--
-- Fresh installs and upgrades from <0.7.41 get 6611 straight from
-- migrate_0.7.41.sql (updated in lockstep with the locale file). This migration
-- only fixes installs that already applied migrate_0.7.41 at the old count of
-- 6607 — it bumps them to 6611 so the language-management UI reports the correct
-- completion. Idempotent: it never lowers a count and skips rows already at 6611.

UPDATE `languages`
SET `total_keys` = 6611,
    `translated_keys` = 6611,
    `completion_percentage` = 100.00,
    `updated_at` = NOW()
WHERE `code` = 'da_DK'
  AND `total_keys` < 6611;
