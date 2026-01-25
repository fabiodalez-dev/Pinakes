# Creating Updates for Pinakes

This guide explains how to create new versions of Pinakes, especially when database changes are required.

## Table of Contents

1. [Version Management](#version-management)
2. [Database Migrations](#database-migrations)
3. [Creating a New Release](#creating-a-new-release)
4. [Migration File Format](#migration-file-format)
5. [Testing Updates](#testing-updates)
6. [Best Practices](#best-practices)

---

## Version Management

### version.json

The application version is stored in `version.json` at the project root:

```json
{
  "name": "Pinakes",
  "version": "0.3.0",
  "description": "Library Management System - Sistema di Gestione Bibliotecaria"
}
```

**When releasing a new version:**
1. Update the `version` field following [Semantic Versioning](https://semver.org/)
2. Commit the change before creating the GitHub release

### Version Numbers

- **MAJOR** (X.0.0): Breaking changes, major rewrites
- **MINOR** (0.X.0): New features, backward-compatible
- **PATCH** (0.0.X): Bug fixes, minor improvements

---

## Database Migrations

### Overview

The updater system automatically runs SQL migrations when upgrading between versions. Migrations are stored in:

```
installer/database/migrations/
├── migrate_0.3.0.sql
├── migrate_0.4.0.sql
└── migrate_0.5.0.sql
```

### How Migrations Work

1. The updater reads `version.json` to get the current version
2. It scans the migrations folder for files matching `migrate_X.X.X.sql`
3. Only migrations **newer than current** and **<= target** are executed
4. Executed migrations are recorded in the `migrations` table
5. Each migration runs only once (idempotent)

### Creating a Migration File

**Step 1: Create the file**

Name the file `migrate_X.X.X.sql` where X.X.X is the target version:

```bash
# For version 0.4.0
touch installer/database/migrations/migrate_0.4.0.sql
```

**Step 2: Write the SQL**

```sql
-- Migration script for Pinakes 0.4.0
-- Description: Add reading_status field to libri table

-- Add new column
ALTER TABLE libri
ADD COLUMN reading_status ENUM('not_started', 'reading', 'completed', 'abandoned')
DEFAULT 'not_started' AFTER numero_copie;

-- Add index for performance
CREATE INDEX idx_reading_status ON libri(reading_status);

-- Note: This migration adds reading progress tracking to books
```

**Step 3: Update schema.sql**

Always sync changes to `installer/database/schema.sql` for fresh installations:

```sql
CREATE TABLE `libri` (
  ...
  `reading_status` enum('not_started','reading','completed','abandoned') DEFAULT 'not_started',
  ...
) ENGINE=InnoDB ...;
```

**Step 4: Update Installer.php if adding tables**

If you're adding new tables, update `EXPECTED_TABLES` in `installer/classes/Installer.php`:

```php
private const EXPECTED_TABLES = [
    ...
    'new_table_name',  // Add here
    ...
];
```

---

## Migration File Format

### Naming Convention

```
migrate_{VERSION}.sql
```

- Version must match the target release version
- Use dots, not underscores (e.g., `0.4.0` not `0_4_0`)

### SQL Guidelines

```sql
-- Migration script for Pinakes X.X.X
-- [Brief description of changes]

-- 1. Adding columns
ALTER TABLE table_name
ADD COLUMN new_column VARCHAR(255) DEFAULT NULL;

-- 2. Modifying columns
ALTER TABLE table_name
MODIFY COLUMN existing_column VARCHAR(500);

-- 3. Renaming columns
ALTER TABLE table_name
CHANGE COLUMN old_name new_name VARCHAR(100);

-- 4. Creating tables
CREATE TABLE IF NOT EXISTS new_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ...
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Adding indexes
CREATE INDEX idx_name ON table_name(column_name);

-- 6. Dropping (use with caution!)
-- ALTER TABLE table_name DROP COLUMN old_column;
-- DROP TABLE IF EXISTS deprecated_table;
```

### Important Rules

1. **Use IF EXISTS / IF NOT EXISTS** - Makes migrations idempotent
2. **One logical change per statement** - Easier to debug
3. **Add comments** - Document what and why
4. **Test rollback** - Know how to undo changes
5. **Avoid data loss** - Never DROP without backup plan

---

## Creating a New Release

### Checklist

- [ ] Update `version.json` with new version number
- [ ] Create migration file if database changes needed
- [ ] Update `installer/database/schema.sql` with any new tables/columns
- [ ] Update `installer/classes/Installer.php` EXPECTED_TABLES if new tables
- [ ] Add English translations to `locale/en_US.json`
- [ ] Test the migration on a copy of production database
- [ ] Commit all changes
- [ ] Create GitHub release with tag `vX.X.X`

### GitHub Release Steps

1. Go to GitHub repository
2. Click "Releases" → "Create a new release"
3. Tag: `vX.X.X` (e.g., `v0.4.0`)
4. Title: `Pinakes vX.X.X`
5. Description: Include changelog and migration notes
6. Publish release

### Release Notes Template

```markdown
## What's New in vX.X.X

### Features
- Feature 1
- Feature 2

### Bug Fixes
- Fixed issue with...

### Database Changes
This version includes database migrations. The updater will automatically:
- Add `new_column` to `table_name`
- Create new `table_name` table

### Breaking Changes
None / List any breaking changes

### Upgrade Notes
- Backup your database before updating
- Clear cache after update: `rm -rf storage/cache/*`
```

---

## Testing Updates

### Local Testing

1. **Create a test database backup**
```bash
mysqldump -u user -p database_name > backup.sql
```

2. **Run the migration manually**
```bash
mysql -u user -p database_name < installer/database/migrations/migrate_X.X.X.sql
```

3. **Verify the changes**
```sql
DESCRIBE table_name;
SHOW CREATE TABLE new_table;
```

### Testing the Full Update Flow

```php
<?php
// test_migration.php
require 'vendor/autoload.php';

// Load env and connect to DB
// ...

use App\Support\Updater;

$updater = new Updater($db);

// Test migration execution
$result = $updater->runMigrations('0.3.0', '0.4.0');
print_r($result);
```

---

## Best Practices

### DO

 Always backup before migrating
 Use transactions for critical changes
 Test on a copy of production data
 Include rollback instructions in comments
 Keep migrations focused and small
 Version everything together (code + schema + migrations)

### DON'T

 Never modify an already-released migration
 Don't delete data without confirmation
 Avoid changing column types that might lose data
 Don't skip version numbers in migrations
 Never run migrations on production without testing

### Handling Failed Migrations

If a migration fails:

1. Check `update_logs` table for error message
2. Fix the issue manually in the database
3. Mark migration as completed:
```sql
INSERT INTO migrations (version, filename, batch)
VALUES ('0.4.0', 'migrate_0.4.0.sql', 1);
```
4. Retry the update

---

## Database Tables for Update System

### migrations

Tracks which migrations have been executed:

```sql
CREATE TABLE `migrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,      -- '0.4.0'
  `filename` varchar(255) NOT NULL,    -- 'migrate_0.4.0.sql'
  `batch` int NOT NULL DEFAULT '1',    -- For grouped rollbacks
  `executed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_version` (`version`)
);
```

### update_logs

Logs all update attempts:

```sql
CREATE TABLE `update_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `from_version` varchar(20) NOT NULL,
  `to_version` varchar(20) NOT NULL,
  `status` enum('started','completed','failed','rolled_back'),
  `backup_path` varchar(500),
  `error_message` text,
  `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime,
  `executed_by` int,                   -- User ID who ran update
  PRIMARY KEY (`id`)
);
```

---

## Example: Complete Version Update

### Scenario: Adding a "favorites count" feature (v0.4.0)

**1. Create migration file** (`installer/database/migrations/migrate_0.4.0.sql`):

```sql
-- Migration script for Pinakes 0.4.0
-- Adds favorites_count cache column to libri for performance

ALTER TABLE libri
ADD COLUMN favorites_count INT UNSIGNED DEFAULT 0
AFTER numero_copie;

-- Create index for sorting by popularity
CREATE INDEX idx_favorites_count ON libri(favorites_count);

-- Initialize counts from wishlist table
UPDATE libri l
SET favorites_count = (
    SELECT COUNT(*) FROM wishlist w WHERE w.libro_id = l.id
);
```

**2. Update schema.sql**:

Add to `CREATE TABLE libri`:
```sql
`favorites_count` int unsigned DEFAULT '0',
```

**3. Update version.json**:

```json
{
  "version": "0.4.0"
}
```

**4. Commit and release**:

```bash
git add -A
git commit -m "Add favorites_count column for v0.4.0"
git tag v0.4.0
git push origin main --tags
```

**5. Create GitHub Release**

---

## Support

For issues with the update system:
- Check `storage/logs/` for error logs
- Review `update_logs` table for failed attempts
- Restore from `storage/backups/` if needed
