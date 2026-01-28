# Backup and Restore

Guide to managing backups in Pinakes.

## Backup Types

### Database Backup

Includes:
- All database tables
- User, book, loan data
- Configurations and settings

Format: Compressed SQL (.sql.gz)

### Full Backup

Includes:
- Complete database
- Uploaded files (`storage/uploads/`)
- Installed plugins (`storage/plugins/`)
- Configurations (`.env`, `config.local.php`)

Format: ZIP

## Creating Backups

### Manual Backup

1. Go to **Administration → Backup**
2. Click **Create backup**
3. Select the type (Database/Full)
4. Wait for completion
5. The backup appears in the list

### Automatic Backup

The system creates automatic backups:
- Before each update
- Optionally on a scheduled basis (requires cron)

### Cron Job

For scheduled backups, add to crontab:

```bash
# Daily backup at 3:00 AM
0 3 * * * cd /path/to/pinakes && php cli/backup.php
```

## Managing Backups

### Viewing

The list shows:
- Creation date and time
- Type (database/full)
- File size
- Available actions

### Download

1. Find the backup in the list
2. Click **Download**
3. The file downloads to your computer

### Deletion

1. Select the backups to delete
2. Click **Delete selected**
3. Confirm the deletion

## Restore

### From Interface

1. Go to **Administration → Backup**
2. Find the desired backup
3. Click **Restore**
4. Confirm the operation
5. Wait for completion

**Warning**: Restore overwrites current data.

### From External File

1. Click **Upload backup**
2. Select the backup file
3. Click **Upload and restore**

### Manual (Emergency)

If the interface doesn't work:

```bash
# Database restore
gunzip -c storage/backups/backup_2024-01-15.sql.gz | mysql -u user -p database

# File restore
unzip storage/backups/backup_2024-01-15.zip -d /path/to/pinakes
```

## Storage

### Location

Backups are saved in:
```
storage/backups/
├── db_2024-01-15_10-30-00.sql.gz
├── full_2024-01-14_03-00-00.zip
└── ...
```

### Automatic Cleanup

The system can automatically delete old backups:
- Configurable in Settings
- Keeps the last N backups
- Respects a minimum retention period

### Disk Space

Monitor available space:
- Full backups can be large
- Consider external storage for backups
- Use compression to save space

## Best Practices

1. **Regular backups**: at least daily for database
2. **Test restore**: periodically verify that backups work
3. **External copy**: keep backups off the server
4. **Before changes**: manual backup before critical operations
5. **Rotation**: delete old backups to save space

---

## Frequently Asked Questions (FAQ)

### 1. How often should I backup?

Depends on library activity:

| Activity Level | Recommended Frequency |
|----------------|----------------------|
| High (many loans/day) | Daily |
| Medium | Every 2-3 days |
| Low | Weekly |
| After important changes | Always |

**Tip**: Configure daily automatic backup and keep backups for the last 7 days.

---

### 2. How much space do backups take?

Approximate estimate:

- **Database backup** (data only): 1-50 MB (depends on catalog)
- **Full backup** (database + files): 50-500 MB

**To reduce space**:
- Backups are already compressed (.gz and .zip)
- Delete old backups (keep only the last 5-7)
- Don't do too many full backups (once a week is enough)

---

### 3. Does restore overwrite everything?

Yes, restore **completely overwrites** current data:
- Database: all tables are recreated
- Files (full backup): are replaced

**Before restoring**:
- Make sure you have a backup of the current state
- Notify users if the site is in production

---

### 4. Can I restore only certain tables?

Not from the interface, but manually yes:

1. Download the database backup (.sql.gz)
2. Decompress: `gunzip backup.sql.gz`
3. Open the SQL file with an editor
4. Extract the INSERT queries for the desired table
5. Execute only those queries on the database

**Alternative**: Use phpMyAdmin for selective table import.

---

### 5. How do I make external backups (cloud, Google Drive)?

Pinakes saves backups in `storage/backups/`. To copy them elsewhere:

**Manual**:
1. Download the backup from the interface
2. Upload to Google Drive/Dropbox/NAS

**Automatic (with rclone)**:
```bash
# After the backup cron, sync to cloud
0 4 * * * rclone copy /path/to/storage/backups remote:pinakes-backups
```

**Hosting with cPanel**: Use the "Backup Wizard" feature to export to external destinations.

---

### 6. The backup failed, what should I check?

Common causes:

1. **Insufficient disk space**: Free space or delete old backups
2. **Permissions**: `chmod 755 storage/backups`
3. **PHP timeout**: Increase `max_execution_time` in php.ini
4. **PHP memory**: Increase `memory_limit` if the database is large

Check the log: `storage/logs/app.log` for error details.

---

### 7. How do I restore a backup if I can't access admin?

Manual restore via terminal/phpMyAdmin:

```bash
# Database
gunzip -c storage/backups/db_2024-01-15.sql.gz | mysql -u user -p database

# Files (if full backup)
unzip storage/backups/full_2024-01-15.zip -d /var/www/biblioteca
```

**With phpMyAdmin**:
1. Decompress the .sql.gz file
2. Open phpMyAdmin
3. Select the database
4. "Import" tab → upload the .sql file

---

### 8. Can I schedule automatic backups without SSH access?

Yes, with some methods:

**cPanel → Cron Jobs**:
```
0 3 * * * cd /home/user/public_html && php cli/backup.php
```

**External services** (cron-job.org):
- Create a protected endpoint that performs the backup
- Configure it to be called every day

**Hosting plugins**: Some providers offer integrated automatic backups.

---

### 9. How do I verify that a backup is working?

The only sure way is to **test the restore**:

1. Create a test environment (subdomain, localhost)
2. Install clean Pinakes
3. Restore the backup
4. Verify all data is present

**Quick check** (not complete):
- Open the .sql.gz file and verify it contains the tables
- Check the size (a very small backup might be incomplete)

---

### 10. What happens to backups during an update?

The update system:
1. Automatically creates a full backup **before** updating
2. Saves it with name `pre_update_X.X.X_TIMESTAMP.zip`
3. If the update fails, you can restore this backup

**Important**: Don't delete pre-update backups until you verify everything works.
