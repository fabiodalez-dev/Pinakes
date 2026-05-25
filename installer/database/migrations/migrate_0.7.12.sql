-- Phase 4 cleanup — drop reserved-but-unused place_id column from
-- archive_activities. The column was added in migrate_0.7.09.sql; it
-- was reserved for the Phase 4 archive_places FK that Phase 4 (0.7.10)
-- never delivered, and no application code reads or writes it. The
-- original DDL declares the column without an inline reservation
-- comment (FIX F027: prior wording quoted a comment string not present
-- verbatim in 0.7.09.sql). See review F015 (rev_01KRRE2QJR3QSTGJ4FZEK7MVDW).
--
-- Wrapped in a tab/col exists guard so an upgrade from a release that
-- pre-dates the archives plugin (≤ v0.7.6) does not error on a table
-- that hasn't been created yet — the plugin's ensureSchema() will
-- create archive_activities (already without place_id, since the
-- DDL in ArchivesPlugin was updated) on first onActivate.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'archive_activities'
      AND COLUMN_NAME  = 'place_id'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE archive_activities DROP COLUMN place_id',
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;
