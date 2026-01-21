# Pinakes Updater System - Technical Documentation

This document explains how the Pinakes auto-updater works, its requirements, and troubleshooting steps.

---

## Overview

The updater system allows administrators to update Pinakes directly from the admin panel (Admin > Updates). It downloads the latest release from GitHub, extracts it, runs database migrations, and replaces application files.

---

## How It Works

### Update Flow

1. **Check for updates** - Queries GitHub API for latest release
2. **Pre-flight checks** - Verifies permissions, disk space, PHP extensions
3. **Enable maintenance mode** - Creates `storage/.maintenance` file
4. **Download release** - Downloads ZIP from GitHub (cURL preferred, fallback to file_get_contents)
5. **Extract to temp** - Extracts to `storage/tmp/pinakes_update_*`
6. **Backup critical files** - Backs up `.env`, `config.local.php`, `version.json`
7. **Copy new files** - Overwrites application files (skips user data)
8. **Run migrations** - Executes SQL files from `installer/database/migrations/`
9. **Cleanup** - Removes temp files
10. **Disable maintenance mode** - Removes `.maintenance` file

### Key Files

| File | Purpose |
|------|---------|
| `app/Support/Updater.php` | Core updater logic |
| `app/Controllers/UpdateController.php` | Admin UI endpoints |
| `app/Views/admin/updates.php` | Admin UI view |
| `storage/.maintenance` | Maintenance mode flag |
| `storage/tmp/` | Temporary extraction directory |
| `storage/backups/` | Pre-update backups |
| `storage/logs/app.log` | Update logs |

---

## Directory Structure & Permissions

### Required Directories

```
storage/
├── tmp/           # Temporary files during update (775)
├── backups/       # Pre-update backups (775)
├── logs/          # Application logs (775)
├── cache/         # Cache files (775)
└── plugins/       # Plugin storage (775)
```

### Permission Requirements

| Directory | Permission | Owner |
|-----------|------------|-------|
| `storage/` | 775 | www-data (or web server user) |
| `storage/tmp/` | 775 | www-data |
| `storage/backups/` | 775 | www-data |
| `storage/logs/` | 775 | www-data |
| `app/` | 775 | www-data |
| `public/` | 775 | www-data |
| `config/` | 775 | www-data |

### Setting Permissions

```bash
# Linux/cPanel
chmod -R 775 storage app public config
chown -R www-data:www-data storage app public config

# Or if using cPanel/shared hosting
chmod -R 775 storage app public config
```

---

## PHP Requirements

### Required Extensions

| Extension | Purpose |
|-----------|---------|
| `ZipArchive` | Extract downloaded ZIP files |
| `curl` (recommended) | Download files reliably |
| `openssl` | HTTPS connections |

### PHP Settings

```ini
; Recommended php.ini settings for updates
memory_limit = 512M          ; Large ZIP extraction
max_execution_time = 600     ; 10 minutes timeout
allow_url_fopen = On         ; Fallback download method
```

---

## Critical: Temp Directory

### The Problem (v0.4.1 - v0.4.3)

Old versions used `sys_get_temp_dir()` which returns `/tmp` on most systems. On shared hosting, this directory often has:
- Cross-user restrictions
- Automatic cleanup (cron jobs)
- Permission issues
- Open_basedir restrictions

### The Solution (v0.4.4+)

The updater now ALWAYS uses `storage/tmp/` which:
- Is within the application directory
- Has correct permissions
- Is not affected by hosting restrictions
- Is automatically cleaned up by the updater

### Temporary Directory Structure

```
storage/tmp/
├── pinakes_update_abc123/     # Extraction directory (auto-deleted)
│   ├── update.zip             # Downloaded release
│   └── pinakes-vX.X.X/        # Extracted content
└── pinakes_app_backup_*/      # App backup (auto-deleted after 1 hour)
```

---

## Download Mechanism

### Primary: cURL

```php
$ch = curl_init($downloadUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_USERAGENT => 'Pinakes-Updater/1.0',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_BUFFERSIZE => 1024 * 1024,  // 1MB buffer
]);
```

### Fallback: file_get_contents

If cURL fails or is not available:

```php
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => ['User-Agent: Pinakes-Updater/1.0'],
        'timeout' => 300,
        'follow_location' => true
    ]
]);
$content = file_get_contents($downloadUrl, false, $context);
```

---

## ZIP Extraction

### Retry Mechanism

The updater attempts extraction up to 3 times:

```php
$maxRetries = 3;
for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        if ($zip->extractTo($extractPath)) {
            // Success
            break;
        }
    }
    // Increase memory and retry
    $currentLimit = ini_get('memory_limit');
    ini_set('memory_limit', (int)$currentLimit * 2 . 'M');
    sleep(2);
}
```

### Memory Auto-Increase

On extraction failure, the updater automatically doubles the memory limit and retries.

---

## Database Migrations

### Migration Files

Located in `installer/database/migrations/`:

```
migrate_0.4.0.sql
migrate_0.4.1.sql
migrate_0.4.2.sql
migrate_0.4.4.sql
```

### Execution Order

Migrations are executed in version order, only for versions newer than the current version.

### Migration SQL Format Requirements

**CRITICAL:** The updater parses SQL files using `explode(';', $sql)`. This means:

1. **Single-line INSERT statements required** for templates with HTML:
   ```sql
   -- CORRECT: Single line
   INSERT INTO `table` VALUES ('key', '<h1>HTML</h1><p>Content</p>');

   -- WRONG: Multi-line (breaks parser)
   INSERT INTO `table` VALUES ('key',
   '<h1>HTML</h1>
   <p>Content</p>');
   ```

2. **Escape single quotes** as `''` (two single quotes):
   ```sql
   'dall''amministratore'   -- Correct
   'dall'amministratore'    -- Wrong (syntax error)
   ```

3. **No semicolons inside string values** (would split the statement):
   ```sql
   -- WRONG: CSS inline styles have semicolons that break the parser
   INSERT INTO `email_templates` VALUES ('test', '<div style="padding: 20px; margin: 10px;">content</div>');
   -- The parser sees this as TWO statements:
   -- 1) INSERT INTO `email_templates` VALUES ('test', '<div style="padding: 20px
   -- 2) margin: 10px;">content</div>')

   -- CORRECT: Use HTML attributes or table-based layouts instead
   INSERT INTO `email_templates` VALUES ('test', '<table cellpadding="20"><tr><td>content</td></tr></table>');
   -- Or use single CSS property without semicolon
   INSERT INTO `email_templates` VALUES ('test', '<div style="padding:20px">content</div>');
   ```

   **Common problematic patterns:**
   - `style="color: red; font-size: 14px"` - Multiple CSS properties
   - `style="background-color: #fff; padding: 10px; margin: 5px"` - Any inline style with `;`

   **Safe alternatives:**
   - Use `<table bgcolor="#fff" cellpadding="10">` instead of inline styles
   - Use single CSS properties: `style="padding:20px"` (no trailing semicolon)
   - Use HTML attributes: `<font color="red">`, `<td align="center">`

4. **Comment lines** starting with `--` are filtered out before execution

### Idempotent Migrations

The updater ignores these MySQL errors to allow re-running migrations:

| Error Code | Description |
|------------|-------------|
| 1060 | Duplicate column name |
| 1061 | Duplicate key name |
| 1050 | Table already exists |
| 1091 | Can't DROP (doesn't exist) |
| 1068 | Multiple primary key |
| 1022 | Duplicate key entry |
| 1826 | Duplicate FK constraint |
| 1146 | Table doesn't exist |

This allows migrations to be run multiple times without failing.

---

## Pre-flight Checks

Before starting an update, the system verifies:

1. **Directory permissions** - `storage/tmp`, `storage/backups` writable
2. **Disk space** - Minimum 200MB free
3. **PHP extensions** - ZipArchive available
4. **Download capability** - cURL or allow_url_fopen enabled

```php
// Disk space check
$freeSpace = disk_free_space($rootPath);
if ($freeSpace < 200 * 1024 * 1024) {
    throw new RuntimeException('Insufficient disk space');
}
```

---

## Maintenance Mode

### Enabling

```php
file_put_contents('storage/.maintenance', json_encode([
    'time' => time(),
    'retry' => 300,
    'message' => 'System update in progress'
]));
```

### Auto-Expiry

Maintenance mode automatically expires after 30 minutes if not removed (failsafe for crashed updates).

### Checking

```php
if (file_exists('storage/.maintenance')) {
    $data = json_decode(file_get_contents('storage/.maintenance'), true);
    if (time() - $data['time'] > 1800) {
        // Auto-remove expired maintenance
        unlink('storage/.maintenance');
    }
}
```

---

## Skipped Files During Update

These files/directories are preserved during updates:

```php
$skipPaths = [
    '.env',
    '.installed',
    'config.local.php',
    'storage/uploads',
    'storage/backups',
    'storage/logs',
    'storage/plugins',
    'public/uploads'
];
```

---

## Logging

### Log Location

`storage/logs/app.log`

### Log Format

```json
{"timestamp":"2026-01-12 12:00:00","level":"INFO","message":"[Updater] Download started","context":{"url":"https://...","method":"curl"}}
```

### View Logs

Admin panel: `/admin/updates/logs`

Or via command line:
```bash
tail -100 storage/logs/app.log | grep Updater
```

---

## Troubleshooting

### Update Fails During Download

**Symptoms:** "Download failed" error

**Solutions:**
1. Check if cURL extension is enabled
2. Verify `allow_url_fopen = On` in php.ini
3. Check if GitHub is accessible from server
4. Verify SSL certificates are valid

### Update Fails During Extraction

**Symptoms:** "Extraction failed" or ZIP errors

**Solutions:**
1. Verify ZipArchive extension is installed
2. Check available disk space (need 200MB+)
3. Check memory_limit (need 256MB+)
4. Verify `storage/tmp` is writable

### Permission Denied Errors

**Solutions:**
```bash
chmod -R 775 storage app public config
chown -R www-data:www-data storage  # Linux
```

### Update Stuck in Maintenance Mode

**Solutions:**
1. Delete `storage/.maintenance` manually
2. Check `storage/logs/app.log` for errors

---

## Manual Update (Emergency)

For users on v0.4.1-0.4.3 with broken updater:

### Option 1: FTP Upload

1. Download latest release from GitHub
2. Extract `test-updater/app/` folder
3. Upload via FTP, overwriting existing files
4. Retry update from admin panel

### Option 2: Emergency Script

1. Upload `test-updater/manual-update.php` to site root
2. Access via browser: `https://yoursite.com/manual-update.php`
3. Delete the script after update completes

---

## Security Considerations

1. **Maintenance mode** - Prevents access during update
2. **Backup creation** - Critical files backed up before overwrite
3. **Checksum verification** - SHA256 checksums provided for releases
4. **HTTPS only** - All downloads over HTTPS
5. **Admin-only access** - Update functions require admin authentication

---

## Plugin Compatibility

### Updating Plugin Compatibility for New Releases

When releasing a new version of Pinakes, **always update the plugin compatibility** in all bundled plugins.

#### Plugin Compatibility Fields

Each plugin has a `plugin.json` file with these version fields:

```json
{
  "requires_app": "0.4.0",      // Minimum Pinakes version required
  "max_app_version": "0.4.4"   // Maximum compatible Pinakes version
}
```

#### Checklist for New Releases

1. **Update all plugin.json files** in `storage/plugins/*/plugin.json`:
   - Set `max_app_version` to the new release version

2. **Recreate plugin ZIP files**:
   ```bash
   cd /path/to/pinakes

   # Recreate all plugin ZIPs
   for plugin in api-book-scraper dewey-editor digital-library open-library scraping-pro z39-server; do
     version=$(jq -r '.version' "storage/plugins/$plugin/plugin.json")
     rm -f "${plugin}-v${version}.zip"
     cd storage/plugins && zip -r "../../${plugin}-v${version}.zip" "$plugin/" && cd ../..
   done

   # Also update installer plugin
   rm -f installer/plugins/dewey-editor.zip
   cd storage/plugins && zip -r ../../installer/plugins/dewey-editor.zip dewey-editor/ && cd ../..
   ```

3. **Current bundled plugins**:
   | Plugin | Version | File |
   |--------|---------|------|
   | api-book-scraper | 1.1.0 | api-book-scraper-v1.1.0.zip |
   | dewey-editor | 1.0.0 | dewey-editor-v1.0.0.zip |
   | digital-library | 1.0.0 | digital-library-v1.0.0.zip |
   | open-library | 1.0.0 | open-library-v1.0.0.zip |
   | scraping-pro | 1.4.0 | scraping-pro-v1.4.0.zip |
   | z39-server | 1.1.0 | z39-server-v1.1.0.zip |

#### Compatibility Check System

The update system checks plugin compatibility before updates:

- **Compatible**: `requires_app <= target_version <= max_app_version`
- **Incompatible**: Plugin warns user before update
- **Unknown**: Plugin without `max_app_version` shows warning

Users see compatibility warnings in the admin update panel before proceeding.

---

## Version History

| Version | Changes |
|---------|---------|
| 0.4.4 | Fixed: Always use storage/tmp, cURL download, retry mechanism, disk space check |
| 0.4.3 | Added: Log viewer endpoint |
| 0.4.2 | Added: Verbose logging |
| 0.4.1 | Bug: Uses sys_get_temp_dir() (fails on shared hosting) |
