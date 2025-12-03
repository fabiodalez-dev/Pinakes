# Changelog

All notable changes to Pinakes will be documented in this file.

## [0.3.0] - 2024-XX-XX

### Breaking Changes

**Database schema change**: The column `classificazione_dowey` has been renamed to `classificazione_dewey` (typo fix).

**For existing installations**, run the migration script BEFORE updating the code:

```sql
-- Run this SQL command on your database
ALTER TABLE libri
CHANGE COLUMN classificazione_dowey classificazione_dewey VARCHAR(20) DEFAULT NULL;
```

Or run the migration file:
```bash
mysql -u USERNAME -p DATABASE_NAME < installer/database/migrations/migrate_0.3.0.sql
```

### Removed

- **`classificazione` table** - The Dewey classification data is now stored in JSON files (`data/dewey/`) and managed by the dewey-editor plugin. The database table is no longer used.
- **`DeweyController.php`** - Legacy controller replaced by `DeweyApiController.php` which reads from JSON files.
- **`app/Views/admin/dewey.php`** - Legacy admin view replaced by dewey-editor plugin.
- **Foreign key columns** from `scaffali` and `mensole` tables (`dowey_category_id`, `dowey_subcategory_id`).

### Changed

- Database schema now has 39 tables (was 40).
- All references to `classificazione_dowey` renamed to `classificazione_dewey`.

### Fixed

- Typo "dowey" corrected to "dewey" throughout the codebase.

---

## [0.2.2] - Previous version

Initial stable release with Dewey classification support via database.
