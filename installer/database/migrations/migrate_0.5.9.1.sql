-- Migration script for Pinakes 0.5.9.1
-- Description: Seed missing shipped locales (de_DE) on IT/EN installs
-- Date: 2026-04-22
-- Tracks: issue #108
-- FULLY IDEMPOTENT: INSERT IGNORE + pre-check on `code` uniqueness

-- =============================================================================
-- Issue #108 — remember-me middleware: user locale not restored on auto-login
-- =============================================================================
--
-- Root cause: `data_it_IT.sql` and `data_en_US.sql` historically seeded only
-- two rows into the `languages` table (the installer-chosen locale + its
-- fallback). A user whose `utenti.locale` is set to a shipped-but-unseeded
-- locale (e.g. `de_DE` on an it_IT install) fails the `I18n::setLocale()`
-- gate that checks against `languages` table membership → session `locale`
-- never set → user sees default-locale labels after remember-me auto-login.
--
-- Fix for NEW installs is in data_it_IT.sql / data_en_US.sql (all three
-- locales seeded). This migration backfills EXISTING installs that pre-date
-- the fix.
--
-- `languages.code` is UNIQUE, so INSERT IGNORE is safe: it's a no-op when
-- the row is already present and adds the missing row otherwise.

INSERT IGNORE INTO `languages`
    (`code`, `name`, `native_name`, `flag_emoji`, `is_default`, `is_active`, `translation_file`, `total_keys`, `translated_keys`, `completion_percentage`)
VALUES
    ('de_DE', 'German', 'Deutsch', '🇩🇪', 0, 1, 'locale/de_DE.json', 4009, 4009, 100.00);
