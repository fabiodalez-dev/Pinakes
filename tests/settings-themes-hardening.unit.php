<?php
declare(strict_types=1);

/**
 * Guard suite for the settings/themes hardening pass.
 *
 * Static checks slice each hardened handler out of its source file (anchored
 * at the method definition, bounded at the next method — a vanished anchor is
 * a failure, never a silent skip) and assert the inline admin re-check /
 * sanitization landed INSIDE that body. Behavioral checks run against the
 * real DB: ThemeManager::activateTheme() must refuse a non-existent id
 * without dethroning the active theme, ThemeColorizer must derive
 * primary_dark, and SettingsRepository must round-trip (set → get → delete)
 * a cookie_banner override — the clear-to-default mechanism the fix uses.
 *
 * Run: php tests/settings-themes-hardening.unit.php
 */

use App\Models\SettingsRepository;
use App\Support\ThemeColorizer;
use App\Support\ThemeManager;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

// The E2E runner exports the literal string 'undefined' for unset variables;
// treat it (and the empty string) as absent so the .env fallback still wins.
$envOverride = static function (string $key, string $fallback): string {
    $value = getenv($key);
    if ($value === false || $value === '' || $value === 'undefined') {
        return $fallback;
    }
    return $value;
};

$dbHost = $envOverride('E2E_DB_HOST', $env['DB_HOST'] ?? '127.0.0.1');
$dbUser = $envOverride('E2E_DB_USER', $env['DB_USER'] ?? '');
$dbPass = $envOverride('E2E_DB_PASS', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = $envOverride('E2E_DB_NAME', $env['DB_NAME'] ?? '');
$dbPort = (int) $envOverride('E2E_DB_PORT', $env['DB_PORT'] ?? '3306');
$socket = $envOverride('E2E_DB_SOCKET', $env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');

try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $description) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $description . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$run = bin2hex(random_bytes(5));
$testSettingKey = "zz_hardening_probe_{$run}";

// FK-safe cleanup: the only persistent fixture is one system_settings row
// keyed by this run (no FKs point at system_settings). Themes are only ever
// re-activated in place, never inserted.
$cleanup = static function () use ($db, $testSettingKey): void {
    try {
        $stmt = $db->prepare("DELETE FROM system_settings WHERE category = 'cookie_banner' AND setting_key = ?");
        $stmt->bind_param('s', $testSettingKey);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup warning: ' . $e->getMessage() . PHP_EOL);
    }
};

set_exception_handler(static function (Throwable $e) use ($cleanup): void {
    $cleanup();
    fwrite(STDERR, 'UNCAUGHT: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
});

/**
 * Slice one method body out of a source file: anchored at the definition,
 * bounded at the next method definition (or EOF). Returns null when the
 * anchor is gone — callers must treat that as a FAIL, so a renamed/removed
 * handler can never green-wash its own guard.
 */
$sliceMethod = static function (string $source, string $method): ?string {
    $anchor = strpos($source, 'function ' . $method . '(');
    if ($anchor === false) {
        return null;
    }
    if (preg_match(
        '/\n    (?:public|private|protected)\s+(?:static\s+)?function\s/',
        $source,
        $m,
        PREG_OFFSET_CAPTURE,
        $anchor
    ) === 1) {
        return substr($source, $anchor, $m[0][1] - $anchor);
    }
    return substr($source, $anchor);
};

$adminGate = "(\$_SESSION['user']['tipo_utente'] ?? '') !== 'admin'";

// ---------------------------------------------------------------------------
// 1. STATIC — SettingsController admin-gated handlers carry the inline
//    tipo_utente re-check in their own body (AdminAuthMiddleware admits staff).
// ---------------------------------------------------------------------------
echo "-- SettingsController inline admin gates --\n";
$settingsSource = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');

foreach ([
    'updateAdvancedSettings',
    'updateEmailSettings',
    'updateEmailTemplate',
    'updateCookieBannerTexts',
    'createApiKey',
    'toggleApiKey',
    'deleteApiKey',
] as $method) {
    $body = $sliceMethod($settingsSource, $method);
    $check(
        $body !== null && str_contains($body, $adminGate),
        "SettingsController::{$method} re-checks tipo_utente inline"
    );
}

// The custom-field delete is a branch inside saveRegistrationCustomFields:
// the admin gate must sit inside the delete branch, BEFORE the DELETE runs.
$body = $sliceMethod($settingsSource, 'saveRegistrationCustomFields');
$deleteBranch = $body !== null ? strpos($body, "!empty(\$row['delete'])") : false;
$gatePos = $body !== null ? strpos($body, $adminGate) : false;
$deleteExec = $body !== null ? strpos($body, '$delete->bind_param') : false;
$check(
    $deleteBranch !== false && $gatePos !== false && $deleteExec !== false
        && $deleteBranch < $gatePos && $gatePos < $deleteExec,
    'custom-field delete branch is admin-gated before the DELETE executes'
);

// ---------------------------------------------------------------------------
// 2. STATIC — ThemeController mutating/AJAX endpoints each gate on admin
//    inside their own body.
// ---------------------------------------------------------------------------
echo "-- ThemeController inline admin gates --\n";
$themeSource = (string) file_get_contents($root . '/app/Controllers/ThemeController.php');

foreach (['save', 'saveLayout', 'activate', 'reset', 'checkContrast'] as $method) {
    $body = $sliceMethod($themeSource, $method);
    $check(
        $body !== null && str_contains($body, "\$_SESSION['user']['tipo_utente'] !== 'admin'"),
        "ThemeController::{$method} re-checks tipo_utente inline"
    );
}

// ---------------------------------------------------------------------------
// 3. STATIC — SeoController llmsTxt API section accepts the bool ConfigStore
//    hydrates (no bare === '1' string comparison for api.enabled).
// ---------------------------------------------------------------------------
echo "-- SeoController llms API-section flag --\n";
$seoSource = (string) file_get_contents($root . '/app/Controllers/SeoController.php');
$body = $sliceMethod($seoSource, 'llmsTxt');
$check(
    $body !== null
        && str_contains($body, "filter_var(ConfigStore::get('api.enabled'")
        && str_contains($body, 'FILTER_VALIDATE_BOOLEAN'),
    'llmsTxt reads api.enabled through filter_var(FILTER_VALIDATE_BOOLEAN)'
);
$check(
    $body !== null
        && preg_match("/ConfigStore::get\\('api\\.enabled'[^)]*\\)\\s*[!=]==\\s*'1'/", $body) !== 1,
    'llmsTxt has no bare === \'1\' comparison against api.enabled'
);

// ---------------------------------------------------------------------------
// 4. STATIC — cookie-banner HTML is sanitized; smtp_port clamp is bounded.
// ---------------------------------------------------------------------------
echo "-- Input sanitization / clamping --\n";
$body = $sliceMethod($settingsSource, 'updateCookieBannerTexts');
$check(
    $body !== null && str_contains($body, 'sanitizeHtml('),
    'updateCookieBannerTexts sanitizes the HTML description fields'
);
$body = $sliceMethod($settingsSource, 'updateEmailSettings');
$check(
    $body !== null && preg_match('/smtp_port.*min\(65535|min\(65535.*smtp_port/s', (string) $body) === 1,
    'updateEmailSettings clamps smtp_port with a 65535 upper bound'
);

// ---------------------------------------------------------------------------
// 5. BEHAVIORAL — activateTheme(non-existent id) refuses and never dethrones
//    the active theme; re-activating the active id succeeds (positive control).
// ---------------------------------------------------------------------------
echo "-- ThemeManager::activateTheme against the real DB --\n";
$activeBefore = $db->query('SELECT id FROM themes WHERE active = 1')->fetch_all(MYSQLI_ASSOC);
$check(count($activeBefore) === 1, 'exactly one active theme exists before the probe');

$themeManager = new ThemeManager($db);
$bogusId = 2000000000;
$check($themeManager->activateTheme($bogusId) === false, 'activateTheme(non-existent id) returns false');

$activeAfter = $db->query('SELECT id FROM themes WHERE active = 1')->fetch_all(MYSQLI_ASSOC);
$check(
    count($activeAfter) === 1
        && count($activeBefore) === 1
        && (int) $activeAfter[0]['id'] === (int) $activeBefore[0]['id'],
    'previously active theme is still the active one (rollback, no dethroning)'
);

if (count($activeBefore) === 1) {
    $activeId = (int) $activeBefore[0]['id'];
    $check($themeManager->activateTheme($activeId) === true, 'activateTheme(currently active id) returns true');
    $stillActive = $db->query('SELECT id FROM themes WHERE active = 1')->fetch_all(MYSQLI_ASSOC);
    $check(
        count($stillActive) === 1 && (int) $stillActive[0]['id'] === $activeId,
        'positive control leaves the same single theme active'
    );
} else {
    $check(false, 'activateTheme positive control skipped: no single active theme');
    $check(false, 'active-theme invariant unverifiable without a single active theme');
}

// ---------------------------------------------------------------------------
// 6. BEHAVIORAL — the derived palette exposes primary_dark as a real
//    darkened #rrggbb, distinct from primary (layout.php consumes it).
// ---------------------------------------------------------------------------
echo "-- ThemeColorizer palette --\n";
$palette = (new ThemeColorizer())->generateColorPalette(['primary' => '#aa0055']);
$check(array_key_exists('primary_dark', $palette), 'palette contains a primary_dark key');
$check(
    isset($palette['primary_dark'], $palette['primary'])
        && strtolower((string) $palette['primary_dark']) !== strtolower((string) $palette['primary']),
    'primary_dark differs from primary'
);
$check(
    preg_match('/^#[0-9a-f]{6}$/i', (string) ($palette['primary_dark'] ?? '')) === 1,
    'primary_dark parses as #rrggbb'
);

// ---------------------------------------------------------------------------
// 7. STATIC — frontend/layout.php emits --primary-dark in :root and renders
//    the theme custom_css through ContentSanitizer::sanitizeCustomCss.
// ---------------------------------------------------------------------------
echo "-- frontend/layout.php theme output --\n";
$layoutSource = (string) file_get_contents($root . '/app/Views/frontend/layout.php');
$rootBlockStart = strpos($layoutSource, ':root {');
$primaryDarkPos = strpos($layoutSource, '--primary-dark:');
$check(
    $rootBlockStart !== false && $primaryDarkPos !== false && $primaryDarkPos > $rootBlockStart,
    'layout.php emits --primary-dark inside the :root block'
);
$check(
    str_contains($layoutSource, "\$themePalette['primary_dark']"),
    '--primary-dark is fed from the generated palette'
);
$check(
    str_contains($layoutSource, "ContentSanitizer::sanitizeCustomCss(\$themeAdvanced['custom_css'])"),
    'theme custom_css is re-sanitized through ContentSanitizer::sanitizeCustomCss at render'
);

// ---------------------------------------------------------------------------
// 8. BEHAVIORAL — SettingsRepository round-trip on cookie_banner: set a
//    marker on a NEW test-only key, read it back, delete the row, verify it
//    is gone (the clear-to-default mechanism updateCookieBannerTexts uses).
// ---------------------------------------------------------------------------
echo "-- SettingsRepository cookie_banner round-trip --\n";
$repository = new SettingsRepository($db);
$repository->ensureTables();
$marker = "marker-{$run}";

$repository->set('cookie_banner', $testSettingKey, $marker);
$check(
    $repository->get('cookie_banner', $testSettingKey) === $marker,
    'set() then get() round-trips the marker value'
);

$repository->delete('cookie_banner', $testSettingKey);
$check(
    $repository->get('cookie_banner', $testSettingKey, 'shipped-default') === 'shipped-default',
    'after delete() the stored override is gone and the default wins'
);

$stmt = $db->prepare("SELECT COUNT(*) FROM system_settings WHERE category = 'cookie_banner' AND setting_key = ?");
$stmt->bind_param('s', $testSettingKey);
$stmt->execute();
$rowCount = (int) $stmt->get_result()->fetch_row()[0];
$stmt->close();
$check($rowCount === 0, 'the system_settings row itself was removed (clear-to-default)');

$cleanup();

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
