# System Requirements

Detailed specifications for installing Pinakes.

## Minimum Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| PHP | 8.0 | 8.1+ |
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.3 | 10.6+ |
| RAM | 512 MB | 1 GB+ |
| Disk | 500 MB | 2 GB+ |

## PHP

### Version

- **Minimum**: PHP 8.0
- **Recommended**: PHP 8.1 or 8.2
- **Tested up to**: PHP 8.3

### Required Extensions

| Extension | Purpose |
|-----------|---------|
| `mysqli` | MySQL/MariaDB database connection |
| `json` | JSON encoding/decoding |
| `mbstring` | UTF-8 string support |
| `curl` | HTTP requests (scraping, API) |
| `zip` | Archive extraction (updates, plugins) |

### Optional Extensions

| Extension | Purpose |
|-----------|---------|
| `gd` | Image resizing |
| `imagick` | Advanced image processing |
| `intl` | Locale date/number formatting |
| `opcache` | Bytecode cache (performance) |
| `apcu` | In-memory cache |

### php.ini Configuration

```ini
; Recommended values
memory_limit = 256M
max_execution_time = 120
upload_max_filesize = 50M
post_max_size = 50M
max_input_vars = 3000

; Security
expose_php = Off
display_errors = Off
log_errors = On

; Sessions
session.cookie_httponly = 1
session.cookie_secure = 1    ; If HTTPS
session.use_strict_mode = 1
```

## Database

### MySQL

- **Minimum**: 5.7
- **Recommended**: 8.0+
- **Charset**: `utf8mb4`
- **Collation**: `utf8mb4_unicode_ci`
- **Storage Engine**: InnoDB (required)

### MariaDB

- **Minimum**: 10.3
- **Recommended**: 10.6+
- Same settings as MySQL

### Configuration

```ini
[mysqld]
# Charset
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# InnoDB
default-storage-engine = InnoDB
innodb_file_per_table = 1
innodb_buffer_pool_size = 256M

# Connections
max_connections = 100
wait_timeout = 28800
```

## Web Server

### Apache

- **Minimum**: 2.4
- **Required modules**: `mod_rewrite`, `mod_headers`

Verify modules:
```bash
apache2ctl -M | grep -E "rewrite|headers"
```

Enable if missing:
```bash
a2enmod rewrite headers
systemctl restart apache2
```

### Nginx

- **Minimum**: 1.18
- Requires `try_files` configuration for routing

## Operating System

### Linux (Recommended)

- Ubuntu 20.04+
- Debian 11+
- CentOS 8+ / Rocky Linux 8+
- Any distribution with PHP 8.0+

### Windows

- Windows Server 2019+
- XAMPP / WAMP for local development
- IIS with PHP FastCGI

### macOS

- macOS 12+
- MAMP for local development

## Network

| Service | Port | Notes |
|---------|------|-------|
| HTTP | 80 | Redirect to HTTPS |
| HTTPS | 443 | Recommended for production |
| MySQL | 3306 | Localhost only |
| SMTP | 25/465/587 | For sending email |

### Firewall

```bash
# UFW (Ubuntu)
ufw allow 80/tcp
ufw allow 443/tcp
```

## Requirements Check

Pinakes includes an automatic checker:

1. Access `/installer` before installation
2. The first page shows the status of all requirements
3. Red items are blocking
4. Yellow items are warnings

### Manual Check

```bash
# PHP version
php -v

# Loaded extensions
php -m | grep -E "mysqli|json|mbstring|curl|zip"

# MySQL version
mysql --version

# Disk space
df -h
```

---

## Frequently Asked Questions (FAQ)

### 1. Does Pinakes work with PHP 7.4?

**No**, Pinakes requires **PHP 8.0 or higher**.

**Reasons:**
- Use of typed properties (`public string $name`)
- Union types (`int|string`)
- Named arguments
- Match expressions
- Constructor property promotion

**Upgrade:**
```bash
# Ubuntu/Debian
apt install php8.1 php8.1-mysqli php8.1-mbstring php8.1-curl php8.1-zip
```

---

### 2. Can I use SQLite instead of MySQL?

**No**, Pinakes is designed exclusively for MySQL/MariaDB.

**Reasons:**
- Use of `INSERT ... ON DUPLICATE KEY UPDATE`
- Transactions with `FOR UPDATE` locking
- MySQL-specific functions (`GROUP_CONCAT`, `JSON_*`)
- FULLTEXT indexes for search

**Alternatives:**
- MySQL 5.7+ (minimum)
- MariaDB 10.3+ (recommended, drop-in replacement)
- MySQL 8.0+ (optimal, better JSON performance)

---

### 3. Which PHP extensions are mandatory?

**Mandatory:**

| Extension | Purpose | Verify |
|-----------|---------|--------|
| `mysqli` | Database | `php -m | grep mysqli` |
| `json` | JSON API | Included by default in PHP 8+ |
| `mbstring` | UTF-8 | `php -m | grep mbstring` |
| `curl` | HTTP/Scraping | `php -m | grep curl` |
| `zip` | Updates | `php -m | grep zip` |

**Ubuntu installation:**
```bash
apt install php8.1-mysqli php8.1-mbstring php8.1-curl php8.1-zip
```

---

### 4. How much disk space is needed?

**Base installation:** ~50 MB

**Additional space:**
| Component | Estimate |
|-----------|----------|
| Book covers (1000 books) | 200-500 MB |
| Database backup | 10-100 MB per backup |
| Log files | 10-50 MB |
| Cache | 5-20 MB |

**Recommendation:** Plan for at least 2 GB for future growth.

---

### 5. Does Pinakes work on shared hosting?

**Yes**, with some conditions:

**Hosting requirements:**
- PHP 8.0+
- MySQL 5.7+
- `mod_rewrite` enabled
- Access to `.htaccess`
- At least 256 MB PHP RAM

**Common limitations:**
- Some hosts block external `curl` requests
- Short timeouts may interrupt large imports
- No SSH access for cron jobs

**Tested hosting:**
- SiteGround ✓
- Aruba (recent plans) ✓
- OVH ✓
- Altervista ❌ (old PHP)

---

### 6. How do I verify if my server meets the requirements?

**Method 1 - Installer:**
Access `/installer` before installation. The first page shows the status of all requirements with green/red indicators.

**Method 2 - CLI:**
```bash
# PHP version
php -v

# Extensions
php -m | grep -E "mysqli|json|mbstring|curl|zip"

# MySQL version
mysql --version

# PHP configuration
php -i | grep -E "memory_limit|upload_max"
```

---

### 7. Which ports need to be open on the firewall?

| Port | Service | Direction |
|------|---------|-----------|
| 80 | HTTP | Inbound |
| 443 | HTTPS | Inbound |
| 3306 | MySQL | Localhost only |
| 25/465/587 | SMTP | Outbound |

**UFW example (Ubuntu):**
```bash
ufw allow 80/tcp
ufw allow 443/tcp
# MySQL should NOT be publicly exposed
```

---

### 8. Can I install on Windows?

**Yes**, with some differences:

**Local development:**
- XAMPP (recommended): includes PHP, MySQL, Apache
- WAMP: popular alternative
- Laragon: modern and fast

**Windows Server production:**
- IIS with PHP FastCGI
- Apache for Windows
- Paths: use `/` or `\\` (Pinakes handles both)

**Caution:**
- Case-insensitive filesystem (differences from Linux)
- Newline CRLF vs LF
- Different file permissions

---

### 9. What is the recommended php.ini configuration?

```ini
; Memory and time
memory_limit = 256M
max_execution_time = 120
max_input_time = 120

; Upload
upload_max_filesize = 50M
post_max_size = 50M
max_file_uploads = 20
max_input_vars = 3000

; Security
expose_php = Off
display_errors = Off
log_errors = On
error_log = /path/to/php-error.log

; Sessions
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1

; Performance (optional)
opcache.enable = 1
opcache.memory_consumption = 128
```

---

### 10. Does Pinakes work with Nginx instead of Apache?

**Yes**, Nginx is fully supported.

**Minimum configuration:**
```nginx
server {
    listen 80;
    server_name biblioteca.example.com;
    root /var/www/pinakes/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(ht|git|env) {
        deny all;
    }
}
```

**Differences from Apache:**
- No `.htaccess` (rules in Nginx config)
- Uses PHP-FPM instead of mod_php
- Generally better performance
