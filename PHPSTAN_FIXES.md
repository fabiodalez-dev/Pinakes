# PHPStan Integration & Fixes

This document describes the PHPStan static analysis integration and all fixes applied to resolve errors.

## Configuration

- **Level**: 3
- **Config file**: `phpstan.neon`
- **Baseline**: `phpstan-baseline.neon` (282 baseline entries for View/template variable errors)
- **Stubs**: `phpstan-stubs.php` (helper function signatures)

### Running PHPStan

```bash
# Full analysis
./vendor/bin/phpstan analyse --memory-limit=512M

# Generate JSON report
./vendor/bin/phpstan analyse --memory-limit=512M --error-format=json > phpstan-report.json

# Regenerate baseline (if needed after fixing baselined errors)
./vendor/bin/phpstan analyse --memory-limit=512M --generate-baseline
```

---

## Fixes Applied

### 1. Duplicate `__()` Function Signature (installer/index.php)

**Problem**: The installer had its own `__()` function with signature `__(string $key)` while the main `app/helpers.php` uses `__(string $message, mixed ...$args)`. PHPStan detected 31 `arguments.count` errors.

**Fix**: Updated `installer/index.php` to match the main helper signature:
```php
function __(string $key, mixed ...$args): string {
    $translations = $GLOBALS['translations'] ?? [];
    $translated = $translations[$key] ?? $key;
    if (!empty($args)) {
        return sprintf($translated, ...$args);
    }
    return $translated;
}
```

**Files changed**: `installer/index.php`

**What it influences**: Translation function in installer now supports sprintf-style formatting like the main app.

**How to test**:
1. Run the installer wizard (`/installer/`)
2. Verify all translated strings display correctly
3. Check installer works in both Italian and English locales

---

### 2. Missing Namespace Prefixes for Global Classes

**Problem**: PHP classes like `mysqli` and `Exception` were used without the global namespace prefix (`\`), causing PHPStan `class.notFound` errors.

**Fixes applied**:

| File | Change |
|------|--------|
| `app/Controllers/ContactController.php` | `mysqli` → `\mysqli` (2 occurrences) |
| `app/Controllers/PluginController.php` | `Exception` → `\Exception` |
| `app/Support/PluginManager.php` | `Exception` → `\Exception` |

**What it influences**: No runtime behavior change (PHP resolves these correctly), but improves code clarity and PHPStan compatibility.

**How to test**:
1. Submit a contact form and verify it saves to database
2. Enable/disable a plugin in admin panel
3. No PHP errors should occur

---

### 3. PHPMailer SMTPSecure Type (Mailer.php)

**Problem**: `PHPMailer::$SMTPSecure` expects a string, but code assigned `false` when no encryption was selected.

**Fix**: Changed from `$mailer->SMTPSecure = false` to:
```php
$mailer->SMTPSecure = '';
$mailer->SMTPAutoTLS = false;
```

**Files changed**: `app/Support/Mailer.php`

**What it influences**: Email sending when SMTP security is set to "none".

**How to test**:
1. Configure SMTP settings with encryption = "none"
2. Send a test email from admin panel
3. Verify email is sent without TLS/SSL

---

### 4. Stream Creation in RateLimitMiddleware

**Problem**: `\Slim\Psr7\Stream::create()` static method doesn't exist. Code was using incorrect API.

**Fix**: Replaced with proper Stream instantiation:
```php
$body = fopen('php://temp', 'r+');
fwrite($body, json_encode([...]));
rewind($body);
return $response->withBody(new \Slim\Psr7\Stream($body));
```

**Files changed**: `app/Middleware/RateLimitMiddleware.php`

**What it influences**: Rate limiting response when limit is exceeded.

**How to test**:
1. Trigger rate limit by making rapid requests to a protected endpoint (e.g., `/api/scrape/isbn`)
2. Verify JSON error response is returned with status 429
3. Response should contain `{"error": "...", "retry_after": ...}`

---

### 5. CSRF Method Names (Views)

**Problem**: Views were calling non-existent CSRF methods: `generate()` and `generateToken()`.

**Fix**: Changed to correct method name `ensureToken()`:
```php
App\Support\Csrf::ensureToken()
```

**Files changed**:
- `app/Views/prenotazioni/crea_prenotazione.php`
- `app/Views/profile/reservations.php`

**What it influences**: CSRF token generation in reservation forms.

**How to test**:
1. Navigate to reservation creation page
2. Submit a reservation form
3. Verify form submits without CSRF errors

---

### 6. ErrorHandler Type in public/index.php

**Problem**: `getDefaultErrorHandler()` returns `callable|ErrorHandler`, PHPStan couldn't determine the type.

**Fix**: Added PHPDoc annotation:
```php
/** @var \Slim\Handlers\ErrorHandler $errorHandler */
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
```

**Files changed**: `public/index.php`

**What it influences**: No runtime change, just type documentation for static analysis.

**How to test**: Application error handling works correctly (trigger a 404 or 500 error).

---

### 7. ReservationManager PHPDoc Array Shape

**Problem**: PHPDoc for `sendReservationNotification()` parameter was missing required array keys.

**Fix**: Updated PHPDoc to specify complete array shape:
```php
@param array{id: int, libro_id: int, email: string, nome: string, cognome: string, data_inizio_richiesta: string, data_fine_richiesta: string} $reservation
```

**Files changed**: `app/Controllers/ReservationManager.php`

**What it influences**: No runtime change, improves IDE autocompletion and static analysis.

**How to test**: Reservation notifications are sent correctly when a book becomes available.

---

### 8. Unused Closure Variables in Routes (web.php)

**Problem**: 22 route closures had `use ($app)` but never used the `$app` variable.

**Fix**: Removed unused `use ($app)` from closures that don't need it.

**Routes fixed** (22 total):
- Login page (GET handler)
- Profile update page (GET)
- Profile password page (GET)
- `/register` (GET)
- `/register_success` (GET)
- Forgot password page (GET handler)
- `/admin/security-logs` (GET)
- `/admin/cms/{slug}` (GET)
- `/admin/cms/{slug}/update` (POST)
- `/admin/cms/upload` (POST)
- `/admin/utenti/crea` (GET)
- `/admin/autori/crea` (GET)
- `/prestiti/crea` (GET)
- `/admin/editori/crea` (GET)
- `/api/cover/download` (POST)
- `/api/scrape/isbn` (GET)
- Catalog legacy redirect
- Contact page (GET)
- `/proxy/cover` (GET)
- `/api/plugins/proxy-image` (GET)
- `/admin/updates/maintenance/clear` (POST)
- `/admin/updates/logs` (GET)

**What it influences**: No runtime change, cleaner code without unused variables.

**How to test**: All affected routes continue to work correctly:
- Auth pages (login, register, forgot password)
- Profile pages
- CMS pages
- User/author/loan creation forms
- Cover download/proxy
- ISBN scraping
- Contact page
- Update management

---

### 9. Dynamic Plugin Class Ignore

**Problem**: `Plugins\Z39Server` class is loaded dynamically at runtime via `require_once`, PHPStan can't find it.

**Fix**: Added to `phpstan.neon` ignoreErrors:
```yaml
ignoreErrors:
    - '#Instantiated class Plugins\\Z39Server#'
    - '#unknown class Plugins\\Z39Server#'
```

**What it influences**: PHPStan ignores errors for dynamically loaded plugin classes.

**How to test**: Z39.50 server plugin works correctly when enabled.

---

## Baseline Errors (282)

The following error types are baselined (not fixed) because they are false positives or would require significant refactoring:

| Error Type | Count | Description |
|------------|-------|-------------|
| `variable.undefined` | 240 | Variables passed to Views via `extract()` or `include` |
| `empty.variable` | 9 | `empty($var)` on variables that always exist |
| `isset.variable` | 7 | `isset($var)` on variables that always exist |
| `nullCoalesce.variable` | 4 | `$var ?? default` on always-defined variables |
| `offsetAccess.notFound` | 2 | Array key access on mixed types |
| Others | 20 | Various template-related false positives |

### Why Baselined?

Most errors are in View files (`app/Views/`) where variables are passed from controllers:

```php
// Controller
$data = ['title' => 'Page Title', 'items' => $items];
extract($data);
include 'view.php';

// View - PHPStan doesn't know $title exists
echo $title; // Error: Variable $title might not be defined
```

This is a common PHP pattern that PHPStan can't track across file boundaries.

---

## Stubs (phpstan-stubs.php)

Created stubs for helper functions defined with `function_exists()` guard:

- `__(string $message, mixed ...$args): string` - Translation
- `__n(string $singular, string $plural, int $count, mixed ...$args): string` - Plural translation
- `route_path(string $key): string` - Localized route path
- `slugify_text(?string $text): string` - URL slug generation
- `book_primary_author_name(array $book): string` - Get primary author

---

## Future Improvements

1. **Increase Level**: Currently at level 3. Consider increasing to 4 or 5 after fixing more type issues.

2. **View Type Hints**: Consider using a template engine with better type support (Twig, Blade) or adding PHPDoc to views.

3. **Reduce Baseline**: Periodically review `phpstan-baseline.neon` and fix errors that can be resolved.

4. **CI Integration**: Add PHPStan to CI pipeline:
```yaml
- name: PHPStan
  run: ./vendor/bin/phpstan analyse --memory-limit=512M --error-format=github
```
