# Installation

Guide to installing Pinakes on a web server.

## Requirements

### Server

| Component | Version | Notes |
|-----------|---------|-------|
| PHP | 8.0+ | 8.1 recommended |
| MySQL/MariaDB | 5.7+ / 10.3+ | InnoDB required |
| Apache/Nginx | 2.4+ / 1.18+ | mod_rewrite for Apache |

### PHP Extensions

Required:
- `mysqli` - Database connection
- `json` - JSON parsing
- `mbstring` - Multibyte strings
- `curl` - HTTP requests
- `zip` - Archive management

Optional:
- `gd` or `imagick` - Image processing
- `intl` - Internationalization
- `opcache` - Bytecode cache

### Disk Space

- Application: ~50 MB
- Dependencies: ~100 MB
- Database: variable (depends on catalog)
- Uploads: variable (covers, ebooks)

Recommended: at least 1 GB available

## Installation

### 1. Download

```bash
# Clone repository
git clone https://github.com/fabiodalez-dev/Pinakes.git biblioteca

# Or download release
wget https://github.com/fabiodalez-dev/Pinakes/releases/latest/download/pinakes.zip
unzip pinakes.zip -d biblioteca
```

### 2. Web Server Configuration

**Apache** - Create virtual host:

```apache
<VirtualHost *:80>
    ServerName biblioteca.example.com
    DocumentRoot /var/www/biblioteca/public

    <Directory /var/www/biblioteca/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx**:

```nginx
server {
    listen 80;
    server_name biblioteca.example.com;
    root /var/www/biblioteca/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 3. Permissions

```bash
cd /var/www/biblioteca

# Writable folders
chmod -R 755 storage
chmod -R 755 public/uploads

# Webserver owner
chown -R www-data:www-data storage public/uploads
```

### 4. Database Creation

> **Important**: The database must be created **before** running the installer and must be **empty**.

The installer automatically imports the table schema, but does not create the database itself.

```bash
# Access MySQL
mysql -u root -p

# Create database
CREATE DATABASE pinakes_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Create dedicated user (recommended)
CREATE USER 'pinakes_user'@'localhost' IDENTIFIED BY 'secure_password';

# Assign permissions
GRANT ALL PRIVILEGES ON pinakes_db.* TO 'pinakes_user'@'localhost';
FLUSH PRIVILEGES;

EXIT;
```

**Note**: If you're using shared hosting, create the database and user from the control panel (cPanel → MySQL Databases, Plesk → Databases).

### 5. Environment Configuration

```bash
# Copy template
cp .env.example .env

# Edit with your settings
nano .env
```

`.env` contents:

```ini
DB_HOST=localhost
DB_NAME=pinakes_db
DB_USER=pinakes_user
DB_PASS=secure_password

APP_URL=https://biblioteca.example.com
APP_DEBUG=false
```

### 6. Installation Wizard

1. Open browser to `http://biblioteca.example.com/installer`
2. Follow the steps:
   - Verify requirements
   - Database configuration
   - Admin creation
   - Initial settings
3. When complete, delete the `installer/` folder (optional)

## Post-Installation

### Cron Job

For scheduled tasks (notifications, cleanup):

```bash
# Add to crontab
crontab -e

# Run every 5 minutes
*/5 * * * * cd /var/www/biblioteca && php cli/cron.php >> /dev/null 2>&1
```

### HTTPS

Recommended for production:

```bash
# With Certbot (Let's Encrypt)
certbot --apache -d biblioteca.example.com
```

Update `.env`:
```ini
APP_URL=https://biblioteca.example.com
```

### Initial Backup

After installation, create a backup immediately:

1. Go to **Administration → Backup**
2. Click **Create full backup**
3. Download and store in a safe location

## Updating

To update an existing installation:

1. **Preferred**: Use integrated update
   - Go to **Administration → Updates**
   - Click **Update** if available

2. **Manual**: See [update guide](../admin/updater.md)

## Troubleshooting

### Error 500

Check:
1. Apache/Nginx log: `/var/log/apache2/error.log`
2. PHP log: configured in `php.ini`
3. Application log: `storage/logs/app.log`

### Blank page

```bash
# Check PHP errors
php -l public/index.php

# Enable debug temporarily
# In .env:
APP_DEBUG=true
```

### Database connection failed

1. Verify the database exists (see step 4)
2. Verify credentials in `.env`
3. Verify MySQL is running
4. Verify user has permissions on the database

### Insufficient permissions

```bash
# Fix permissions
chown -R www-data:www-data /var/www/biblioteca
find /var/www/biblioteca -type d -exec chmod 755 {} \;
find /var/www/biblioteca -type f -exec chmod 644 {} \;
chmod -R 775 storage public/uploads
```

---

## Frequently Asked Questions (FAQ)

### 1. Can I install Pinakes on shared hosting?

Yes, Pinakes works on shared hosting if the provider meets the requirements:
- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.3+
- FTP or File Manager access

**Procedure**:
1. Upload files via FTP to the domain root
2. Configure the `.env` file with database credentials
3. Open `yoursite.com/installer` in browser
4. Follow the installation wizard

**Note**: Some hosts limit PHP functions. Verify that `curl`, `zip`, and `mysqli` are enabled.

---

### 2. How much disk space does Pinakes need?

Minimum recommended space:

| Component | Space |
|-----------|-------|
| Application | ~50 MB |
| Dependencies | ~100 MB |
| Database | 10-500 MB (depends on catalog) |
| Book covers | Variable (~200 KB per cover) |
| Backups | Variable |

**Recommendation**: Start with at least 1 GB free. For large catalogs (10,000+ books with covers), consider 5+ GB.

---

### 3. How do I configure the cron job for automatic notifications?

The cron job executes scheduled operations (due date emails, status updates, etc.).

**Linux/cPanel**:
```bash
# Every 5 minutes
*/5 * * * * cd /path/to/pinakes && php cli/cron.php >> /dev/null 2>&1
```

**Plesk**: Use "Scheduled Tasks" in the panel.

**Hosting without cron access**: Some providers offer web-based alternatives. Alternatively, you can use external services like `cron-job.org` to call a dedicated URL.

---

### 4. How do I install an SSL certificate (HTTPS)?

**With Let's Encrypt (free)**:
```bash
# Apache
sudo certbot --apache -d biblioteca.yoursite.com

# Nginx
sudo certbot --nginx -d biblioteca.yoursite.com
```

**On shared hosting**: Use the provider's panel (cPanel → SSL/TLS, Plesk → Certificates).

**After installation**:
1. Update `.env`: `APP_URL=https://biblioteca.yoursite.com`
2. In Settings → Advanced, enable "Force HTTPS"

---

### 5. Can I use SQLite instead of MySQL?

No, Pinakes requires MySQL or MariaDB. The system uses MySQL-specific features:
- InnoDB transactions
- Foreign keys
- MySQL-specific queries

If you don't have MySQL access, many free hosts like InfinityFree or 000webhost offer MySQL databases.

---

### 6. How do I migrate Pinakes to a new server?

1. **On the old server**:
   - Create a full backup (Administration → Backup)
   - Download the ZIP file

2. **On the new server**:
   - Install Pinakes normally
   - Upload the backup via Administration → Backup
   - Click "Restore"

3. **Update DNS**: Point the domain to the new server

**Manual alternative**:
```bash
# Export database
mysqldump -u user -p pinakes > backup.sql

# Copy files
rsync -avz /var/www/biblioteca new_server:/var/www/

# Import database on new server
mysql -u user -p pinakes < backup.sql
```

---

### 7. The installer shows "requirements not met", what do I do?

Verify each requirement in the list:

| Error | Solution |
|-------|----------|
| PHP version | Ask hosting to update PHP |
| mysqli extension | Enable in php.ini or hosting panel |
| curl extension | Enable in php.ini or hosting panel |
| Folder not writable | `chmod 755 storage` and `chmod 755 public/uploads` |
| ZipArchive | Enable zip extension in PHP |

If using cPanel: go to "Select PHP Version" to enable extensions.

---

### 8. How do I run the installation without wizard (CLI)?

For automated or headless installations:

```bash
# 1. Copy configuration
cp .env.example .env

# 2. Edit .env with correct credentials
nano .env

# 3. Run CLI installation
php installer/install-cli.php

# 4. Create admin
php cli/create-admin.php admin@example.com password
```

Useful for automatic deployment with scripts or CI/CD.

---

### 9. How do I upgrade PHP from 7.x to 8.x?

1. **Verify compatibility**: Pinakes requires PHP 8.0+
2. **On shared hosting**: Change version from panel (cPanel → PHP Selector)
3. **On VPS**:
   ```bash
   # Ubuntu/Debian
   sudo apt install php8.1 php8.1-mysql php8.1-curl php8.1-zip php8.1-mbstring
   sudo update-alternatives --set php /usr/bin/php8.1
   ```
4. **Restart Apache/Nginx**

---

### 10. Pinakes doesn't load after installation, blank screen

Solutions in order:

1. **Enable debug**: In `.env`, set `APP_DEBUG=true`
2. **Check PHP log**: `tail -50 /var/log/php/error.log`
3. **Check app log**: `tail -50 storage/logs/app.log`
4. **Verify syntax**: `php -l public/index.php`
5. **Permissions**: `chmod -R 755 storage`
6. **mod_rewrite**: Make sure it's enabled (Apache: `a2enmod rewrite`)

If nothing works, verify that DocumentRoot points to `public/`, not the project root.
