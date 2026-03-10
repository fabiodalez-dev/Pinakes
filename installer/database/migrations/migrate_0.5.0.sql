-- Migration script for Pinakes 0.5.0
-- Description: Add social sharing settings
-- Date: 2026-03-10
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Uses INSERT IGNORE to skip if already present

-- =============================================================================
-- Add default sharing providers setting
-- =============================================================================
-- Enables Facebook, X, WhatsApp and Email share buttons on the book detail page.
-- Admins can customise the selection in Settings > Sharing.

INSERT IGNORE INTO system_settings (category, setting_key, setting_value, description, updated_at)
VALUES ('sharing', 'enabled_providers', 'facebook,x,whatsapp,email',
        'Enabled social sharing providers on book detail page', NOW());
