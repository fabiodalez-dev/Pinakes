# PHPStan Integration & Fixes

This document describes the PHPStan static analysis integration and all fixes applied to resolve errors.

## Configuration

- **Level**: 4
- **Config file**: `phpstan.neon`
- **Baseline**: `phpstan-baseline.neon` (341 baseline entries for View/template variable errors + level 4 checks)
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
    static $translations = null;

    // Get locale from session (defaults to Italian)
    $locale = $_SESSION['app_locale'] ?? 'it';
    $message = $key;

    // Load English translations only when needed
    if ($locale === 'en_US') {
        if ($translations === null) {
            $translationFile = dirname(__DIR__) . '/locale/en_US.json';
            if (file_exists($translationFile)) {
                $translations = json_decode(file_get_contents($translationFile), true) ?? [];
            }
        }
        $message = $translations[$key] ?? $key;
    }

    if (!empty($args)) {
        return sprintf($message, ...$args);
    }
    return $message;
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

**What it influences**: Prevents potential runtime errors. In namespaced code, unqualified class names like `mysqli` resolve to `App\Controllers\mysqli` (not global `\mysqli`), which would cause "Class not found" errors. The explicit `\` prefix ensures correct resolution.

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
3. Response should contain `{"error": "...", "message": "..."}` with `Retry-After` HTTP header

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

### 10. Dead Code Removal (Level 4)

**Problem**: PHPStan level 4 detected unused methods and properties that were dead code.

**Fix**: Removed the following dead code:

| Type | Location | Item |
|------|----------|------|
| Method | `AutoriApiController.php` | `columnExists()` - never called |
| Method | `FrontendController.php` | `createSlug()` - never called |
| Method | `FrontendController.php` | `render()` - never called |
| Method | `SearchController.php` | `searchUsers()` - never called |
| Method | `UpdateController.php` | `redirect()` - never called |
| Method | `ReservationReassignmentService.php` | `findAvailableCopy()` - replaced by `findAvailableCopyExcluding()` |
| Property | `NotificationsController.php` | `$db` - assigned but never read |
| Property | `ReservationReassignmentService.php` | `$copyRepo` - assigned, usages commented out |
| Import | `ReservationReassignmentService.php` | `use App\Models\CopyRepository` - no longer needed |

**Files changed**:
- `app/Controllers/AutoriApiController.php`
- `app/Controllers/FrontendController.php`
- `app/Controllers/SearchController.php`
- `app/Controllers/UpdateController.php`
- `app/Controllers/Admin/NotificationsController.php`
- `app/Services/ReservationReassignmentService.php`

**What it influences**: No runtime change - removed code was never executed.

**How to test**: Application functions normally - no functionality removed since code was dead.

**Note**: `RateLimitMiddleware::$maxAttempts` was NOT removed despite being flagged. The property is assigned from constructor but never read - this is a **design bug** (the middleware accepts rate limit config but delegates to `RateLimiter::isLimited()` which ignores it). This should be fixed separately by actually using `$maxAttempts` in the rate limiting logic.

---

## Baseline Errors (341)

The following error types are baselined (not fixed) because they are false positives or would require significant refactoring:

| Error Type | Count | Description |
|------------|-------|-------------|
| `variable.undefined` | 91 | Variables passed to Views via `extract()` or `include` |
| `nullCoalesce.offset` | 18 | Defensive `??` on array keys that always exist |
| `empty.variable` | 8 | `empty($var)` on variables that always exist |
| `notIdentical.alwaysTrue` | 5 | Comparisons that always evaluate to true |
| `isset.offset` | 5 | `isset()` on array keys that always exist |
| `nullCoalesce.variable` | 4 | `$var ?? default` on always-defined variables |
| `isset.variable` | 4 | `isset($var)` on variables that always exist |
| `if.alwaysTrue` | 4 | Conditions that are always true |
| `function.alreadyNarrowedType` | 4 | Redundant type checks |
| Others | 15 | Various level 4 checks (ternary, boolean, instanceof) |

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

1. **Increase Level**: Currently at level 4. Consider increasing to level 5 after addressing more type issues in Views.

2. **View Type Hints**: Consider using a template engine with better type support (Twig, Blade) or adding PHPDoc to views.

3. **Reduce Baseline**: Periodically review `phpstan-baseline.neon` and fix errors that can be resolved.

4. **CI Integration**: Add PHPStan to CI pipeline:
```yaml
- name: PHPStan
  run: ./vendor/bin/phpstan analyse --memory-limit=512M --error-format=github
```
