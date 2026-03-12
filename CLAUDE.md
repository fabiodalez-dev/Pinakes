# Pinakes — CLAUDE.md

## ABSOLUTE RULES — VIOLATION = PRODUCTION BREAKAGE

### 1. NEVER create ZIP packages manually
- **NEVER use `git archive` directly**
- **NEVER bypass `./scripts/create-release.sh`**
- **ALWAYS use `./scripts/create-release.sh X.Y.Z`** to create ANY distributable ZIP
- phpstan was removed from composer deps (installed system-wide) so the autoloader no longer references dev-only packages
- The script still verifies the ZIP is clean before uploading
- `git archive` uses COMMITTED files, NOT working directory files
- If asked to create a ZIP on a non-main branch: REFUSE or adapt the script process (composer install --no-dev → commit vendor/composer → archive → verify → restore dev). NEVER skip steps.

### 1b. Autoloader safety check
- After ANY `composer install`, `composer update`, or `composer require`, verify: `grep -c "phpstan" vendor/composer/autoload_static.php` must return `0`
- phpstan is NOT a project dependency — if it appears in the autoloader, something went wrong
- If found: run `composer install --no-dev --optimize-autoloader` to fix

### 2. Soft-delete: ALWAYS `AND deleted_at IS NULL`
- Every query on `libri` table MUST include this condition
- Nullify unique-indexed columns (isbn10, isbn13, ean) on soft-delete

### 3. View escaping
- `htmlspecialchars(url(...), ENT_QUOTES, 'UTF-8')` in ALL HTML attributes
- `route_path()` also needs escaping in HTML attributes
- `json_encode(..., JSON_HEX_TAG)` for any PHP→JS in views

### 4. No hardcoded routes
- Use `route_path('key')` or `RouteTranslator::route('key')`, never `/accedi` or `/login`

### 5. Exception handling
- `\Throwable` not `\Exception` (strict_types TypeError extends \Error)
- `SecureLogger::error()` not `error_log()` for sensitive contexts

### 6. Migration file version MUST be ≤ release version
- **NEVER name a migration file with a version higher than the release version**
- The updater uses `version_compare($migrationVersion, $toVersion, '<=')` — migrations with a higher version are **silently skipped**
- Example: release `0.4.9.9` + `migrate_0.5.0.sql` → migration NEVER runs (0.5.0 > 0.4.9.9)
- **Before every release**: verify ALL `migrate_*.sql` files have version ≤ `version.json`
- If multiple migrations needed for one release: merge them into ONE file named after the release version

## Build & Test

```bash
# Dev server
php -S localhost:8081 -t public

# PHPStan (system-wide: ~/.composer/vendor/bin/phpstan)
phpstan analyse --memory-limit=512M --level=1

# Release (THE ONLY WAY)
./scripts/create-release.sh X.Y.Z
```

## E2E Tests (Playwright)

All E2E tests require `/tmp/run-e2e.sh` which sets DB/admin credentials as env vars. Always use `--workers=1` for serial execution.

```bash
# Run a single test file
/tmp/run-e2e.sh tests/<file>.spec.js --config=tests/playwright.config.js --workers=1

# Run a specific test by name
/tmp/run-e2e.sh tests/<file>.spec.js --config=tests/playwright.config.js --workers=1 --grep "test name"

# Run ALL tests (comprehensive suite)
/tmp/run-e2e.sh tests/full-test.spec.js --config=tests/playwright.config.js --workers=1
```

### Comprehensive suite: `full-test.spec.js` (97 tests, ~2.5 min)

Single file covering the entire application lifecycle. Run this for full regression coverage.

| Phase | Tests | Description |
|-------|-------|-------------|
| 1. Installation | 8 | Fresh install wizard (Italian): language, requirements, DB, schema, admin user, app name, email, completion. **Skips automatically if app already installed.** |
| 2. Login & Dashboard | 3 | Admin login, dashboard content loads without JS errors, sidebar navigation |
| 3. Manual Book Creation | 5 | Navigate to form, fill basic fields (title, subtitle with special chars, edition, year), fill people/classification (Choices.js author, publisher, genre cascade 3 levels), fill content/physical (TinyMCE, keywords, pages, copies, series, notes), save and verify in DB |
| 4. ISBN Scraping | 3 | Enter ISBN, trigger import, verify populated fields, save scraped book |
| 5. Scraping-Pro Plugin | 4 | Navigate to plugins, activate scraping-pro, import with richer data, save |
| 6. Edit Book | 4 | Open edit form, modify all fields (title, genre, language, copies), save and verify DB, verify frontend display |
| 7. Author Management | 6 | Create 3 authors, merge two, edit merged author, verify autocomplete (#58, #74) |
| 8. Publisher Management | 4 | Create 2 publishers, merge, edit merged publisher |
| 9. Bulk Cover Download | 2 | Select books, trigger bulk cover download |
| 10. CSV/TSV Import & Export | 4 | Navigate to import, upload CSV, upload TSV, export and verify structure (#33, #77) |
| 11. Settings | 10 | All settings tabs: general, email, loans, registration, homepage, API, maintenance, i18n, security, appearance |
| 12. CMS & Events | 5 | CMS pages list, edit CMS page (TinyMCE), create event (TinyMCE + Flatpickr), verify on frontend, delete event (#70) |
| 13. Shelf/Location | 5 | Create shelf, create position (mensola), assign book, verify assignment persists, verify on frontend |
| 14. Admin Loan | 5 | Setup borrower user, create loan via admin UI, verify active loan, return book, verify availability restored |
| 15. User Reservation & Approval | 7 | User login, request loan (Flatpickr SweetAlert), verify pending, admin approves, admin pickup, admin return, calendar check (#29) |
| 16. Overlap Prevention | 3 | Active loan setup, duplicate attempt (same user/book fails), overlapping dates fail |
| 17. Frontend Search | 4 | Search by title, author, keyword (#66), genre browsing (#71) |
| 18. Issue Regressions | 10 | #53 (Danish chars), #34 (placeholder image), #76 (digital badge), #72 (scroll-to-top), #73 (keyboard shortcuts), #77 (export selected), #67 (genre filter), #27 (staff role), #32 (book condition), numero_pagine normalization |
| 19. Security | 4 | Unauthenticated redirect, XSS escaping, CSRF tokens, soft-deleted book hidden |
| 20. Cleanup | 1 | Delete all test data in FK-safe order |

### Individual test files (for targeted testing)

| File | Description |
|------|-------------|
| `smoke-install.spec.js` | Install wizard (Italian) + basic book creation with Choices.js |
| `install-german.spec.js` | Install wizard (German locale) |
| `admin-features.spec.js` | Settings tabs, CMS homepage, user registration, book creation with ISBN scraping, concurrent loan prevention |
| `extra-features.spec.js` | Events (CRUD + IntlDateFormatter), collocazione (shelf/position), contact form, user profile, wishlist, admin user management, password reset |
| `loan-reservation.spec.js` | Full loan lifecycle: user request → admin approve → pickup → return, with Flatpickr SweetAlert and calendar verification |
| `genre-bugs.spec.js` | Genre CRUD, 3-level cascade selection, genre assignment to books |
| `genre-merge-rearrange.spec.js` | Genre merge and rearrange operations |
| `import-books.spec.js` | CSV/TSV file import with various column formats |
| `security-hardening.spec.js` | CSRF protection, XSS escaping, soft-delete visibility, auth redirects |
| `issue-63-genre-fields.spec.js` | Genre fields pre-populated on edit |
| `issue-66-keyword-search.spec.js` | Keyword search returns results |
| `issue-74-author-autocomplete.spec.js` | Choices.js author autocomplete |
| `issue-74-publisher-autocomplete.spec.js` | Choices.js publisher autocomplete |
| `issue-74-scraping-authors.spec.js` | Author populated after ISBN scraping |
| `issue-77-export-selected.spec.js` | Export CSV with `?ids=` returns only selected books |
| `issue-78-edit-fields.spec.js` | Language/genre persist after edit |
| `issue-regression.spec.js` | Mixed regression tests for various issues |
| `email-notifications.spec.js` | All 16 email types via Mailpit (SMTP + phpmail drivers). Requires Mailpit running on port 1025/8025. |

## Git Hooks
- `commit-msg`: Rejects `Co-Authored-By: Claude` — omit from commits
- `pre-commit`: PHPStan level 1 (BLOCKING), unescaped url() in HTML attrs (BLOCKING), hardcoded routes (warning), forEach+return false (warning)
