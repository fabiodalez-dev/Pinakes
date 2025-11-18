# Pinakes 📚

> Modern library automation with cataloging, circulation, patron self-service and a ready-made public portal — now deployable even on shared hosts, because dependencies are already vendored.

[![Version](https://img.shields.io/badge/version-0.1.1-0ea5e9?style=for-the-badge)](version.json)
[![Installer Ready](https://img.shields.io/badge/one--click_install-ready-22c55e?style=for-the-badge&logo=azurepipelines&logoColor=white)](installer)
[![License](https://img.shields.io/badge/License-GPL--3.0-orange?style=for-the-badge)](LICENSE)

[![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
![Slim](https://img.shields.io/badge/slim%20framework-2C3A3A?style=for-the-badge&logo=slim&logoColor=white)
[![MySQL](https://img.shields.io/badge/mysql-4479A1.svg?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Composer](https://img.shields.io/badge/Composer-885630?style=for-the-badge&logo=composer&logoColor=white)](https://getcomposer.org/)
[![JavaScript](https://img.shields.io/badge/javascript-%23323330.svg?style=for-the-badge&logo=javascript&logoColor=%23F7DF1E)](https://developer.mozilla.org/docs/Web/JavaScript)
![Node.js](https://img.shields.io/badge/node.js-339933?style=for-the-badge&logo=node.js&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/tailwindcss-0ea5e9?style=for-the-badge&logo=tailwindcss&logoColor=white)
![Bootstrap](https://img.shields.io/badge/bootstrap-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)

**Pinakes ships with the `vendor/` directory committed**, so you can upload the repo and start the installer without running Composer/NPM on production boxes. Point the installer at your database, pick Italian or English, and you get cataloging, circulation, the public portal and admin UI all at once.

---

## ⚡ Quick Start

1. **Clone or download** this repository – it already contains the `vendor/` directory and compiled frontend assets.
2. **Upload/extract** it on your server (shared hosting works fine because Composer isn’t required at deploy time).
3. **Run the installer** by visiting `/installer`, choose the UI language, drop database credentials and follow the guided wizard.
4. **Log in** with the admin account created during the wizard and start cataloguing.

> Want to regenerate dependencies locally? Run `composer install && npm install && npm run build` as usual; just remember to commit the refreshed `vendor/` directory when preparing a release.

---

## Story Behind the Name

**Pinakes** comes from the ancient Greek word *πίνακες* ("tables" or "catalogues"). Callimachus of Cyrene compiled the *Pinakes* around 245 BC for the Library of Alexandria: 120 scrolls that indexed more than 120,000 works with authorship, subject and location. This project borrows that same mission—organising and sharing knowledge with modern tools.

---

## Why Pinakes
- Complete ILS with cataloging, circulation, self-service frontend and REST APIs ready out of the box.
- Automatic metadata import through ISBN services plus manual control for every field.
- Multilingual public site, cookie banner, SEO tooling and sitemap already wired for libraries without a web team.
- Plugin-ready architecture so you can bolt on new scraping sources or business logic without touching core files.
- Designed for privacy-conscious deployments: self-hosted assets, configurable analytics, encrypted plugin secrets.

---

## Feature Highlights

### Catalog & Metadata (app/Views/libri/partials/book_form.php)
- Full book form with 60+ fields: Dewey classification, multi-language metadata, attachments, digital formats, price, acquisition, shelves and inventory numbers.
- Multi-copy management with per-copy status, provenance notes and QR/label export handled by `CopyRepository` + `DataIntegrity` resync.
- ISBN import widget hooks into the scraping pipeline; the bundled Open Library plugin enriches data and falls back to Google Books if needed.
- Dewey controller (`app/Controllers/DeweyController.php` + `DeweyApiController.php`) ships with 1369 classes already imported by the installer and exposes reseeding tools for librarians.

### SRU Interoperability (HTTP successor to Z39.50)
- Pinakes ships with an **SRU Server** plugin (`storage/plugins/z39-server/`) enabled by default: it doesn’t emulate legacy binary Z39.50, instead it implements the SRU 1.2 (Search/Retrieve via URL) standard—the modern HTTP-based protocol understood by most Z39.50/SRU clients.
- Once activated from the Plugin Manager it exposes `/api/sru` with the `explain`, `searchRetrieve`, and `scan` operations, outputting MARCXML, Dublin Core, MODS, or OAI_DC, and it bundles rate limiting, logging, and optional authentication.
- All parameters (supported schemas, record limits, host info, credentials) are configurable from Admin → Plugins → SRU Server, and the plugin provisions its logging / throttling tables on install.
- Result: your Pinakes catalog becomes queryable via SRU out of the box—no core patches or external services required, just toggle the built-in plugin.

### Patron Experience & Frontend (app/Views/frontend/*)
- Auto-generated pages for home, catalog, book detail, wishlist, profile and contact so a library can go live without building a separate site.
- `app/Views/frontend/catalog.php` provides advanced filters (search, genre, Dewey, format, availability, year sliders) plus instant AJAX facets.
- Each page renders Schema.org structured data, canonical URLs and meta tags. `SeoController` + `App\Support\SitemapGenerator` publish `/sitemap.xml` and `robots.txt` automatically.
- `app/Views/partials/cookie-banner.php` powers a configurable consent banner with granular toggles (essential/analytics/marketing) and localized copy.

### Loans, Reservations & Patron Services
- `PrestitiController`, `ReservationsController` and `LoanApprovalController` cover the full workflow: staff approval, FIFO reservations, renewals, overdue tracking and sanctions.
- Users manage loans, reservations, wishlist and history from `UserDashboardController` and `UserWishlistController`; review eligibility is enforced through `/api/user/can-review/{book}` before `RecensioniController` accepts content.
- Automatic notifications and reminders leverage `NotificationService`, `EmailService` and the `cron/automatic-notifications.php` script for due/overdue emails, reservation availability and approval flows.

### Automations, Messaging & Compliance
- Email settings and templates are editable from the admin UI (`SettingsController`) and persisted via `SettingsRepository`; common templates are pre-seeded during install.
- Public contact forms, password resets and onboarding rely on the same `EmailService`, ensuring HTML + plain-text fallbacks and queue-safe sending.
- The Settings view (`app/Views/admin/settings.php`) centralizes SMTP driver selection, registration approvals, CMS quick links and provides copy‑paste cron snippets for `cron/automatic-notifications.php`.

### Plugins, Scraping & Integrations
- `app/Support/Hooks.php` exposes action/filter hooks used across cataloging, scraping, login and frontend widgets; plugins register in `plugins`, `plugin_hooks`, `plugin_settings`, `plugin_data` and `plugin_logs` tables.
- The **Open Library + Google Books plugin** (`storage/plugins/open-library/`) wires into `scrape.sources`, `scrape.fetch.custom` and `scrape.data.modify` to fetch high-quality metadata and covers. Google Books API keys are stored in encrypted plugin settings (using `PLUGIN_ENCRYPTION_KEY` / `APP_KEY`).
- The **Digital Library plugin** (`storage/plugins/digital-library/`) adds eBook (PDF/ePub) and audiobook (MP3/M4A/OGG) support directly to the book cataloging form. It provides Uppy-powered upload widgets with progress tracking, file type validation (50MB for eBooks, 500MB for audiobooks), and frontend players with streaming support. Uploaded files are stored in `public/uploads/digital/` and accessible to patrons via the book detail page.
- The **API Book Scraper plugin** (`storage/plugins/api-book-scraper/`) enables integration with external book metadata APIs using API key authentication, prioritized above Open Library for faster ISBN lookups.
- Google Books is available as a fallback source inside the same plugin, so even ISBNs missing from Open Library can be prefilled.
- Plugin ZIPs can be uploaded from Admin → Plugins; lifecycle hooks (install/activate/deactivate/uninstall) are handled without touching core code.

### SEO, Privacy & Accessibility
- Sitemap + robots + canonical URLs handled by `SeoController`, `RouteTranslator` ensures SEO-friendly slugs in every supported language.
- Cookie banner, consent logging and selective script loading keep you compliant with EU requirements.
- All public assets (Bootstrap, Tailwind utilities, Inter font, Font Awesome, Uppy, TinyMCE, etc.) are self-hosted to avoid external trackers.

---

## Built-in API (app/Routes/web.php)
Authentication for private endpoints uses API keys stored under **Admin → Settings → API** and sent via `X-API-Key`. Selected endpoints:
- `GET /api/public/books/search` – lightweight catalog search for the public site.
- `GET /api/books/{id}/availability` and `/api/books/{id}/availability-calendar` – expose copy counts and reservation windows for custom kiosks.
- `GET /api/libri`, `/api/autori`, `/api/editori` – admin/staff listings (filterable by search, genre, etc.).
- `GET /api/dewey/categories|divisions|specifics` and `POST /api/dewey/reseed` – maintain classification trees directly from the UI.
- `GET /api/collocazione/suggerisci` / `/next` / `/export-csv` – automate shelving suggestions and exports.
- `POST /api/libri/{id}/increase-copies` – bulk-copy creation for inventory drives.
- `GET /api/scrape/isbn` – programmatic ISBN lookup using the active scraping sources (Open Library, Google Books, others added via plugins).
- `GET /api/stats/books-count`, `/api/stats/active-loans-count`, etc. – dashboards and wall displays.

All responses are JSON UTF-8, provide timestamps + metadata and honour HTTP status codes (including `429` when rate limits from `RateLimitMiddleware` kick in).

---

## Installation
Pinakes ships with `vendor/` and compiled frontend assets committed, so Composer/NPM are only required if you want to refresh dependencies locally.

1. **Clone the repository**
   ```bash
   git clone https://github.com/fabiodalez-dev/pinakes.git
   cd pinakes
   ```
2. **(Optional) Refresh back-end/frontend dependencies**
   ```bash
   composer install --no-dev --optimize-autoloader
   cd frontend && npm install && npm run build && cd ..
   ```
3. **Fix writable directories** – Git cannot store 0777, so run the helper script:
   ```bash
   ./bin/setup-permissions.sh
   # or set 777 manually on uploads/, storage/, backups/, public/uploads/
   ```
4. **Start a local server** (or configure your vhost) using the router so the installer can intercept routes:
   ```bash
   php -S 0.0.0.0:8000 router.php
   ```
5. **Run the web installer** – visit your domain. The installer (see `installer/README.md`) walks through:
   - requirement checks,
   - database credentials & connection test,
   - schema + sample data import (including Dewey, genres, email templates),
   - admin account creation,
   - app + email configuration.
   It writes `.env` and `.installed` automatically, so you do **not** need to duplicate `.env.example` manually.
6. **Post-install**
   - Remove/lock the `installer/` directory (button provided on the final step).
   - Configure SMTP, registration policy and CMS blocks from **Admin → Settings**.
   - Schedule `php cron/automatic-notifications.php` (daily); the Settings page includes copy‑paste snippets for your crontab.

---

## Installer Overview
The bundled installer (`installer/README.md`):
- validates PHP extensions and directory permissions;
- creates 39 tables, triggers and seed data (Dewey, genres, CMS placeholders, email templates, plugin system);
- **automatically installs 4 default plugins**: Open Library (metadata scraping), Z39 Server (SRU API), API Book Scraper (ISBN enrichment), and Digital Library (eBook/audiobook management);
- writes an `.env` with production defaults (APP_ENV=production, debug off) plus `.installed` lock;
- supports step-by-step deletion of itself once the instance is ready.
If you ever need to reinstall, delete `.installed` + `.env` or hit `/installer?force=1`.

---

## Open Library + Google Books Plugin
- Lives in `storage/plugins/open-library/` with `plugin.json`, activator and docs.
- Registers itself through hooks to appear alongside core scraping sources; priority is set so Open Library answers before any HTML scraper.
- When Open Library lacks data, the plugin decrypts the stored Google Books API key (using `PLUGIN_ENCRYPTION_KEY` or `APP_KEY`) and fetches metadata, covers and descriptions from the Google Books API.
- Supports subject mappings, language normalization and cover validation; settings are editable from the plugin screen and stored in `plugin_settings`.
- Designed to be extended—custom hooks let you alter priority, disable the source temporarily or add caching.

---

## Frontend Modules for Non‑Developers
- `app/Views/frontend/home.php` offers hero blocks, featured shelves and CTA sections editable from the CMS controller.
- `app/Views/frontend/catalog.php` handles filters, chips, saved searches, availability badges and integrates with `/api/public/books/search`.
- Book detail pages render copy availability, wishlist buttons, review forms and breadcrumb JSON-LD automatically.
- The cookie banner partial, privacy routes (`RouteTranslator::getRouteForLocale('cookies', …)`) and consent storage make the public site compliant out of the box.

---

## Email, Notifications & Automations
- `NotificationService` + `EmailService` centralize transactional email sending for registrations, password resets, loan reminders and reservation alerts.
- Templates are stored in the database and edited from Admin → Settings → Email Templates with live previews.
- `cron/automatic-notifications.php` processes overdue loans, expiring reservations and scheduled reminders; pair it with system cron.
- Webhooks/hooks allow plugins to inject additional notifications (e.g., Slack) by listening to `loan.status.changed`, `reservation.available`, etc.

---

## Tech Stack
**Backend** (composer.json): Slim 4.13, PHP-DI, Slim PSR-7 + CSRF, Monolog 3, PHPMailer 6.10, TCPDF 6.10, Google reCAPTCHA client, thepixeldeveloper/sitemap, emleons/sim-rating, vlucas/phpdotenv.

**Frontend** (package.json + frontend/package.json): Webpack 5, Tailwind CSS 3.4.18, Bootstrap 5.3.8, jQuery 3.7.1, DataTables 2.3.x (with Select/SearchBuilder/SearchPanes bundles), Chart.js 4.5, SweetAlert2 11, Flatpickr 4.6, Sortable.js 1.15, Choices.js 11, TinyMCE 8, Uppy 4 (Dashboard, DragDrop, Progress Bar, XHR Upload), jsPDF + jsPDF-autotable, JSZip, star-rating.js, Font Awesome icons and Inter font self-hosted.

---

## Handy Paths
- `app/Views/libri/partials/book_form.php` – catalog form logic, ISBN ingestion.
- `app/Controllers/PrestitiController.php` – core lending workflows.
- `app/Controllers/ReservationsController.php` & `ReservationsAdminController.php` – queue handling.
- `app/Controllers/UserWishlistController.php` & `RecensioniController.php` – wishlist and review UX.
- `app/Views/frontend/catalog.php` – public catalog filters and Schema.org output.
- `app/Views/partials/cookie-banner.php` – consent banner configuration.
- `app/Controllers/SeoController.php` – sitemap + robots.
- `storage/plugins/open-library/` – Open Library + Google Books integration example.
- `storage/plugins/digital-library/` – Digital Library plugin (eBook/audiobook upload and streaming).
- `storage/plugins/z39-server/` – SRU Server plugin (SRU 1.2 API for catalog interoperability).
- `storage/plugins/api-book-scraper/` – API Book Scraper plugin (external metadata integration).

---

## Contributing & License
Contributions, issues and feature requests are welcome via GitHub pull requests. Pinakes is released under the GNU General Public License v3.0 (see [LICENSE](LICENSE)).

If Pinakes helps your library, please ⭐ the repository!
