# Manual Update Instructions

## Important: Users on versions 0.4.1 - 0.4.3

If you're running Pinakes version 0.4.1, 0.4.2, or 0.4.3, the built-in updater has a bug that prevents automatic updates from working on shared hosting environments.

**The Problem:** The old Updater.php uses the system temp directory (`/tmp`), which fails on many shared hosting providers due to permission restrictions.

**The Solution:** Manually upload the fixed files via FTP before attempting to update.

---

## Option 1: Upload Files via FTP (Recommended)

1. Upload the `app/` folder from this directory, overwriting existing files:
   - `app/Support/Updater.php` - Complete fix with all improvements
   - `app/Controllers/UpdateController.php` - Added log viewer endpoint
   - `app/Views/admin/updates.php` - Fixed UI progress indicators
   - `app/Routes/web.php` - Added logs route

2. Ensure directories have correct permissions:
   ```bash
   chmod 775 storage
   chmod 775 storage/tmp
   chmod 775 storage/logs
   chmod 775 storage/backups
   ```

3. Verify logs are accessible at: `/admin/updates/logs`

4. Retry the update from the admin updates page

---

## Option 2: Use the Emergency Update Script

1. Upload `manual-update.php` to the ROOT of your site (same level as `index.php`)

2. Access it via browser: `https://yourdomain.com/manual-update.php`

3. The script will:
   - Verify permissions and disk space (minimum 200MB)
   - Check required extensions (ZipArchive, cURL)
   - Download the latest release from GitHub
   - Extract to `storage/tmp` (not system `/tmp`)
   - Automatic retry on extraction failure
   - Run database migrations

4. **DELETE `manual-update.php` after the update!**

---

## What Was Fixed

**Updater.php improvements:**
- ALWAYS uses `storage/tmp` instead of `sys_get_temp_dir()`
- Pre-flight checks: permissions, disk space (200MB minimum), required extensions
- Automatic cleanup of old temp directories
- Download with cURL (more reliable) + fallback to `file_get_contents`
- Automatic retry for ZIP extraction (3 attempts)
- Automatic memory increase if extraction fails
- More SQL errors ignored for idempotent migrations

**Ignorable SQL Errors (for idempotent migrations):**
- 1060: Duplicate column
- 1061: Duplicate key
- 1050: Table already exists
- 1091: Cannot DROP (doesn't exist)
- 1068: Multiple primary key
- 1022: Duplicate key entry
- 1826: Duplicate FK constraint
- 1146: Table doesn't exist (for DROP IF NOT EXISTS)

---

## Troubleshooting

If the update continues to fail:

1. Check logs at: `/admin/updates/logs`
2. Verify available disk space
3. Verify `storage/` folder permissions
4. Contact support with the log output

---

## Files in This Folder

| File | Purpose |
|------|---------|
| `app/Support/Updater.php` | **Critical** - Fixed updater with all improvements |
| `app/Controllers/UpdateController.php` | Added `/admin/updates/logs` endpoint |
| `app/Views/admin/updates.php` | Fixed progress step indicators |
| `app/Routes/web.php` | Added logs route |
| `manual-update.php` | Standalone emergency update script |
| `README.md` | This file (English) |
| `README.txt` | Italian instructions |
