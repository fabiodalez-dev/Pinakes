-- Migration 0.7.65-rc.1
-- "Add to calendar" links in loan confirmation emails: append the
-- {{sezione_calendario}} placeholder to already-seeded loan_approved and
-- loan_pickup_ready templates so upgraded installs get the feature too.
-- Fresh installs receive it from the SettingsMailTemplates defaults.
-- Idempotent: a template that already carries the placeholder (or one an
-- operator later stripped it from) is not touched again — migrations run once.
--
-- The #366 NULL-deadline ready-pickup backfill is NOT here: it runs as PHP in
-- Updater::runMigrations() AFTER reapplyTriggers(), because a SQL migration
-- executes under the starting version's BEFORE UPDATE trigger, whose same-copy
-- overlap gate would abort on a preserved copy-bound pickup. See
-- App\Support\PickupDeadlineBackfill.
UPDATE `email_templates`
   SET `body` = CONCAT(`body`, '\n{{sezione_calendario}}')
 WHERE `name` IN ('loan_approved', 'loan_pickup_ready')
   AND `body` NOT LIKE '%sezione_calendario%'
   AND `body` NOT LIKE '%calendar_links%';
