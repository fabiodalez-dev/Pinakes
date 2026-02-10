# Pinakes Installer

Automated web-based installer for Pinakes, a full-featured library management system.

## Requirements

- **PHP 8.1** or higher
- **MySQL 5.7+** or **MariaDB 10.3+**
- **Required PHP extensions**: PDO, PDO MySQL, MySQLi, Mbstring, JSON, GD, Fileinfo

## How It Works

When Pinakes detects it has not been installed yet (no `.installed` lock file), it automatically redirects to the installer wizard. The installer guides you through 8 steps to get a fully working instance.

### Step 0 — Language & Requirements

Select the application language (Italian or English) and verify that your server meets all requirements: PHP version, required extensions, and directory permissions.

### Step 1 — Database Configuration

Enter your MySQL/MariaDB credentials. The installer auto-detects the socket path when available.

### Step 2 — Connection Test

Validates the database connection and generates the `.env` configuration file.

### Step 3 — Schema Import

Creates all 46 database tables with proper foreign keys, indexes, and constraints.

### Step 4 — Seed Data

Populates the database with localized seed data:

- 181 literary genres
- 18 email templates (registration, loan notifications, reminders, etc.)
- CMS pages (About, Privacy Policy)
- Homepage content sections
- Dewey Decimal Classification (from bundled JSON)

### Step 5 — Triggers & Indexes

Installs 3 database triggers (loan validation, membership expiry) and optimization indexes.

### Step 6 — Admin Account & Email

Creates the first administrator account with auto-generated membership card number and bcrypt-hashed password. Configures the email driver (PHP mail, PHPMailer, or SMTP).

### Step 7 — Finalization

Sets directory permissions, installs bundled plugins, creates the `.installed` lock file, and offers the option to delete the installer directory.

---

## Database Schema

The installer creates **46 tables** organized into the following groups:

**Catalog** — `libri`, `autori`, `libri_autori`, `editori`, `generi`, `tag`, `libri_tag`, `copie`, `recensioni`

**Loans & Reservations** — `prestiti`, `prenotazioni`, `wishlist`, `donazioni`, `libri_donati`

**Shelving** — `sedi`, `scaffali`, `mensole`, `posizioni`

**Users & Access** — `utenti`, `staff`, `user_sessions`, `preferenze_notifica_utenti`, `api_keys`

**CMS & Content** — `cms_pages`, `home_content`, `email_templates`, `events`, `contact_messages`, `feedback`

**System** — `system_settings`, `admin_notifications`, `log_modifiche`, `import_logs`, `migrations`, `update_logs`, `languages`, `themes`

**Plugins** — `plugins`, `plugin_hooks`, `plugin_logs`, `plugin_data`, `plugin_settings`

**GDPR & Compliance** — `gdpr_requests`, `consent_log`

**Z39.50/SRU** — `z39_access_logs`, `z39_rate_limits`

### Migrations

The `database/migrations/` directory contains incremental migration scripts for upgrading from previous versions (0.3.0 through 0.4.8.1).

---

## Generated `.env` File

The installer generates a production-ready `.env` file with:

- Database credentials
- Application locale
- Encryption key (auto-generated, used for plugin API keys)
- Session lifetime
- HTTPS and canonical URL settings
- Debug mode disabled by default

For development environments, set `APP_ENV=development`, `APP_DEBUG=true`, and `DISPLAY_ERRORS=true` after installation.

---

## Security

- **Lock file** — `.installed` prevents re-running the installer
- **Password hashing** — bcrypt for all user passwords
- **CSRF protection** — token validation on all forms
- **Prepared statements** — all database queries use parameterized queries
- **Input validation** — all form fields validated server-side
- **File upload security** — type, size, and extension validation
- **Session hardening** — httpOnly, secure, samesite=Strict
- **Security headers** — CSP, X-XSS-Protection, X-Frame-Options

---

## Post-Installation

After completing the installer:

1. **Delete the `installer/` directory** for security (or use the built-in option in Step 7)
2. Verify the `.env` file is not accessible from the web
3. Configure your web server to point to the `public/` directory as document root
4. Set up a daily cron job for automated maintenance (see `docs/cron.md`)

The `.installed` lock file prevents accidental re-installation even if you forget to remove the installer directory.

---

## Reinstalling

To start a fresh installation, remove the lock file and the environment configuration:

```bash
rm .installed .env
```

Then access the application again to trigger the installer.

---

## Directory Structure

```text
installer/
├── classes/
│   ├── Installer.php        # Core installation logic
│   └── Validator.php        # Input validation
├── database/
│   ├── schema.sql           # 46 tables
│   ├── triggers.sql         # 3 triggers
│   ├── data_it_IT.sql       # Italian seed data
│   ├── data_en_US.sql       # English seed data
│   ├── indexes_optimization.sql
│   ├── indexes_optimization_mysql.sql
│   └── migrations/          # Incremental upgrade scripts
├── steps/
│   ├── step0.php … step7.php
├── assets/                  # Installer CSS/JS
├── index.php                # Entry point
└── README.md
```
