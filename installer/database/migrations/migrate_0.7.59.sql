-- v0.7.59 — "Add to calendar" links in loan confirmation emails.
-- The loan_approved / loan_pickup_ready templates gain the
-- {{sezione_calendario}} placeholder (Google Calendar link + tokenized
-- per-loan .ics download, rendered by NotificationService at send time).
-- New installs get it from the SettingsMailTemplates defaults; this appends
-- the placeholder to already-seeded rows (all locales) that don't have it
-- yet, so upgraded installs get the feature too. Idempotent, and a template
-- an operator later strips the placeholder from is not touched again by
-- future runs (migrations execute once).
UPDATE `email_templates`
   SET `body` = CONCAT(`body`, '\n{{sezione_calendario}}')
 WHERE `name` IN ('loan_approved', 'loan_pickup_ready')
   AND `body` NOT LIKE '%sezione_calendario%'
   AND `body` NOT LIKE '%calendar_links%';
