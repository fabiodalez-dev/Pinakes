# Database Migrations

This directory contains SQL migration scripts for Pinakes version upgrades.

## Migration System

The migration system is managed by `app/Support/Updater.php` and automatically executes migrations during version upgrades.

### How It Works

1. **Automatic Detection**: Updater scans for `migrate_*.sql` files
2. **Version-Based Execution**: Migrations run between current version and target version
3. **Tracking**: Executed migrations are tracked in the `migrations` table
4. **Idempotency**: All migrations use `IF NOT EXISTS` checks to be safely re-runnable

### Migration File Format

```
migrate_X.Y.Z.sql
```

Where `X.Y.Z` is the target version (e.g., `migrate_0.4.7.sql`)

## Migration Principles

### 1. Idempotency (Critical!)

All migrations MUST be idempotent using prepared statements:

```sql
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'table_name'
                   AND COLUMN_NAME = 'column_name');
SET @sql = IF(@col_exists = 0,
              'ALTER TABLE `table_name` ADD COLUMN `column_name` TYPE',
              'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

### 2. Standard Structure

Each migration should include:

```sql
-- Migration script for Pinakes X.Y.Z
-- Description: Brief description of changes
-- Date: YYYY-MM-DD
-- Compatibility: MySQL 5.7+, MariaDB 10.0+
-- FULLY IDEMPOTENT: Uses prepared statements

-- Section 1: Description
-- ============================================================
[SQL statements]

-- Section 2: Description
-- ============================================================
[SQL statements]

-- End of migration
```

### 3. Safe Operations

✅ **Safe (Always):**
- Adding columns with `NULL` or `DEFAULT`
- Adding indexes
- Adding CHECK constraints (MySQL 8.0.16+)
- Using `ON DUPLICATE KEY UPDATE` for inserts

⚠️ **Careful:**
- Dropping columns (ensure no code uses them)
- Renaming columns (requires code changes)
- Changing data types (may lose data)
- Adding NOT NULL without DEFAULT (may fail on existing data)

❌ **Never:**
- DROP TABLE without backup
- TRUNCATE without backup
- Destructive operations without rollback plan

## Migration History

### 0.4.7 (2025-02-02)
**LibraryThing Comprehensive Migration**
- Consolidated all LibraryThing fields (25+ columns)
- Added `lt_fields_visibility` JSON column
- Created indexes for performance
- Added rating constraint (1-5 or NULL)

Key features:
- Review and rating fields (review, rating, comment, private_comment)
- Classification fields (dewey_wording, lccn, lc_classification, other_call_number)
- Date tracking (entry_date, date_started, date_read)
- Catalog IDs (bcid, barcode, oclc, work_id, issn)
- Languages (original_languages)
- Acquisition (source, from_where)
- Lending (lending_patron, lending_status, lending_start, lending_end)
- Value/condition (value, condition_lt)
- Visibility control (lt_fields_visibility JSON)

### 0.4.6 (2025-02-02)
**LibraryThing Missing Fields**
- Added dewey_wording, barcode, entry_date
- Added index for barcode

### 0.4.5 (2025-01-21)
**Pickup Confirmation Workflow**
- Added 'da_ritirare' state to prestiti
- Added pickup_deadline column
- Added email templates for pickup notifications

### 0.4.4
**System Settings and Email Templates**
- Various system configuration improvements

### 0.4.3
**Prestiti (Loans) Enhancements**
- Loan system improvements

### 0.4.2
**Calendar and Holidays**
- Calendar functionality

### 0.4.0
**Initial Migration**
- Base schema setup

## Testing Migrations

### Test on Development Database

```bash
# Create test database
mysql -u root -p -e "CREATE DATABASE pinakes_test"

# Import current schema
mysql -u root -p pinakes_test < backup.sql

# Run migration manually
mysql -u root -p pinakes_test < migrate_0.4.7.sql

# Verify results
mysql -u root -p pinakes_test -e "SHOW COLUMNS FROM libri LIKE '%lt_%'"
```

### Test Idempotency

Run the same migration twice - should not error:

```bash
mysql -u root -p pinakes_test < migrate_0.4.7.sql
mysql -u root -p pinakes_test < migrate_0.4.7.sql  # Should succeed
```

## Rollback Strategy

While migrations are designed to be forward-only, each version should document:

1. **What changed** (columns added, indexes created)
2. **Rollback commands** (DROP COLUMN, DROP INDEX)
3. **Data impact** (any data transformations)

### Example Rollback for 0.4.7

```sql
-- Rollback migrate_0.4.7.sql (LibraryThing fields)
-- WARNING: This will LOSE all LibraryThing data!

ALTER TABLE libri
    DROP COLUMN IF EXISTS review,
    DROP COLUMN IF EXISTS rating,
    DROP COLUMN IF EXISTS comment,
    DROP COLUMN IF EXISTS private_comment,
    -- ... (list all columns)
    DROP COLUMN IF EXISTS lt_fields_visibility;

DROP INDEX IF EXISTS idx_lt_rating ON libri;
DROP INDEX IF EXISTS idx_lt_date_read ON libri;
-- ... (list all indexes)
```

## Creating New Migrations

### Step 1: Determine Version

Next version after current latest (0.4.7 → 0.4.8)

### Step 2: Create File

```bash
touch installer/database/migrations/migrate_0.4.8.sql
```

### Step 3: Write Idempotent SQL

Use the template structure with prepared statements.

### Step 4: Test Thoroughly

- Test on fresh database
- Test on existing database
- Test running twice (idempotency)
- Test with various MySQL/MariaDB versions

### Step 5: Document

Update this README with:
- Version number
- Description
- Key changes
- Rollback procedure (if applicable)

## Compatibility

- **MySQL**: 5.7+
- **MariaDB**: 10.0+
- **CHECK Constraints**: MySQL 8.0.16+ (gracefully ignored on older versions)
- **JSON Type**: MySQL 5.7.8+, MariaDB 10.2.7+

## Troubleshooting

### Migration Not Running

1. Check `migrations` table exists:
   ```sql
   SHOW TABLES LIKE 'migrations';
   ```

2. Check migration is tracked:
   ```sql
   SELECT * FROM migrations WHERE version = '0.4.7';
   ```

3. Check Updater logs:
   ```bash
   tail -f storage/logs/app.log | grep -i updater
   ```

### Migration Fails

1. Check MySQL error log
2. Verify syntax is correct for your MySQL version
3. Check table exists and schema is as expected
4. Verify user has ALTER TABLE privileges

### Need to Re-run Migration

```sql
DELETE FROM migrations WHERE version = '0.4.7';
```

Then trigger update process again.

## Best Practices

1. ✅ **Always use idempotent checks**
2. ✅ **Test on a copy of production data**
3. ✅ **Document all changes in this README**
4. ✅ **Use prepared statements for conditional DDL**
5. ✅ **Add indexes for foreign keys and frequently queried columns**
6. ✅ **Use meaningful comments in SQL**
7. ✅ **Keep migrations focused (one feature per migration)**
8. ✅ **Backup before running in production**

## Resources

- [MySQL ALTER TABLE](https://dev.mysql.com/doc/refman/8.0/en/alter-table.html)
- [MySQL CHECK Constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html)
- [MySQL JSON Data Type](https://dev.mysql.com/doc/refman/8.0/en/json.html)
- [Idempotent Migrations Best Practices](https://www.percona.com/blog/idempotent-mysql-migrations/)
