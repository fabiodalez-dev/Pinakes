-- Migration 0.7.46
-- Issue #301: optional automatic approval of immediate loan requests.
-- Safe and idempotent: existing administrators keep the manual workflow.

INSERT INTO `system_settings` (`category`, `setting_key`, `setting_value`, `description`)
VALUES ('loans', 'auto_approve_requests', '0', 'Approva automaticamente le richieste di prestito')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
