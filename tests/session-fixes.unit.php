<?php
declare(strict_types=1);

/**
 * Unit tests for the fixes shipped in the 0.7.18.1 / 0.7.19-rc.4 cycle.
 *
 * Covers the deterministic, server-free parts of the work:
 *   - Installer::createHtaccess() self-heal (docroot=project-root routing)
 *   - CmsHelper slug/locale resolution (the /chi-siamo 404 fix)
 *   - goodlib badges layout + plugin version bump
 *   - static guards on book-detail CSS, the login rate limit and the
 *     CmsController resilient fallback
 *
 * Run:
 *   php tests/session-fixes.unit.php
 * Exits 0 on success, 1 on any failure.
 */

$ROOT = dirname(__DIR__);

require_once $ROOT . '/installer/classes/Installer.php';
require_once $ROOT . '/app/Support/CmsHelper.php';

use App\Support\CmsHelper;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

/** Throwaway temp dir for filesystem tests. */
$mktmp = static function (): string {
    $d = sys_get_temp_dir() . '/pk_sessfix_' . bin2hex(random_bytes(5));
    mkdir($d, 0777, true);
    return $d;
};
// These tests only ever write a single "$d/.htaccess" into a private temp dir,
// so cleanup is a targeted unlink of that one known file — no traversal.
$rmrf = static function (string $d): void {
    @unlink("$d/.htaccess"); // nosemgrep
    @rmdir($d);
};

// ---------------------------------------------------------------------------
echo "Installer::createHtaccess() — self-heal routing\n";
// ---------------------------------------------------------------------------

// 1. No .htaccess at all -> writes the route-to-public rewrite.
$d = $mktmp();
(new Installer($d))->createHtaccess();
$h1 = (string) @file_get_contents("$d/.htaccess");
$check(strpos($h1, 'RewriteRule ^(.*)$ public/$1') !== false,
    'no file: writes "RewriteRule ^(.*)\$ public/\$1"');

// 2. installer/scripts are excluded from the rewrite (served directly).
$check(strpos($h1, '(^|/)installer(/|$)') !== false,
    'no file: keeps installer exclusion');

// 3. calendar .ics files are served directly.
$check(strpos($h1, '/storage/calendar/') !== false && strpos($h1, '.ics') !== false,
    'no file: keeps .ics calendar exclusion');

// 4. sensitive root files are blocked.
$check(strpos($h1, '<FilesMatch') !== false && strpos($h1, '.env') !== false,
    'no file: blocks sensitive files (.env)');
$rmrf($d);

// 5. A cPanel-only .htaccess (PHP directives, no rewrite) is MERGED, not skipped.
$d = $mktmp();
file_put_contents("$d/.htaccess",
    "# BEGIN cPanel-generated php ini directives\n<IfModule lsapi_module>\nphp_flag log_errors On\n</IfModule>\n");
(new Installer($d))->createHtaccess();
$h5 = (string) @file_get_contents("$d/.htaccess");
$check(strpos($h5, 'cPanel-generated') !== false && strpos($h5, 'public/$1') !== false,
    'cPanel file: preserves cPanel block AND appends rewrite');
$rmrf($d);

// 6. An already-routed file is left untouched (idempotent).
$d = $mktmp();
(new Installer($d))->createHtaccess();
$before = (string) @file_get_contents("$d/.htaccess");
(new Installer($d))->createHtaccess();
$after = (string) @file_get_contents("$d/.htaccess");
$check($before === $after, 'idempotent: second call does not modify the file');

// 7. Exactly one rewrite rule after repeated calls (no duplication).
$check(substr_count($after, 'public/$1') === 1, 'idempotent: single "public/\$1" rule');
$rmrf($d);

// ---------------------------------------------------------------------------
echo "CmsHelper — slug/locale resolution (/chi-siamo fix)\n";
// ---------------------------------------------------------------------------

// 8. All slug variants for the About page (the resilience fallback source).
$check(CmsHelper::getSlugsForPage('about') === ['chi-siamo', 'about-us'],
    "getSlugsForPage('about') === ['chi-siamo','about-us']");

// 9. Contact variants too.
$check(CmsHelper::getSlugsForPage('contact') === ['contatti', 'contact'],
    "getSlugsForPage('contact') === ['contatti','contact']");

// 10. Unknown page id -> empty list (fallback no-ops, never crashes).
$check(CmsHelper::getSlugsForPage('does-not-exist') === [],
    "getSlugsForPage('unknown') === []");

// 11. Reverse lookup: both locale slugs map back to the 'about' page id.
$check(CmsHelper::getPageIdFromSlug('about-us') === 'about'
    && CmsHelper::getPageIdFromSlug('chi-siamo') === 'about',
    "getPageIdFromSlug('about-us')==='about' && ('chi-siamo')==='about'");

// 12. Localized canonical slug per locale.
$check(CmsHelper::getSlug('about', 'it_IT') === 'chi-siamo'
    && CmsHelper::getSlug('about', 'en_US') === 'about-us',
    "getSlug('about',it_IT)==='chi-siamo' && (en_US)==='about-us'");

// ---------------------------------------------------------------------------
echo "goodlib — badges layout + version bump\n";
// ---------------------------------------------------------------------------

// 13. Plugin version was bumped (policy: any plugin change bumps the version).
$pj = json_decode((string) file_get_contents($ROOT . '/storage/plugins/goodlib/plugin.json'), true);
$check(is_array($pj) && ($pj['version'] ?? '') === '1.0.1',
    "goodlib plugin.json version === '1.0.1'");

// Render badges.php in isolation (stub __()).
if (!function_exists('__')) { function __($s) { return $s; } }
$renderBadges = static function (string $context): string {
    $sources = ['anna' => ['label' => "Anna's Archive", 'icon' => 'fas fa-book-open', 'url' => 'https://x/%s']];
    $query = 'Test Book';
    $isbn = '';
    ob_start();
    require __DIR__ . '/../storage/plugins/goodlib/views/badges.php';
    return (string) ob_get_clean();
};

// 14. Frontend wrapper is forced full-width so it wraps onto its own row.
$check(strpos($renderBadges('frontend'), 'flex-basis:100%') !== false,
    "badges.php frontend: wrapper has flex-basis:100% (own row)");

// 15. Admin context is unchanged (no forced break).
$check(strpos($renderBadges('admin'), 'flex-basis:100%') === false,
    "badges.php admin: no flex-basis (layout unchanged)");

// ---------------------------------------------------------------------------
echo "Static guards — CSS, rate limit, resilient fallback\n";
// ---------------------------------------------------------------------------

$bookDetail = (string) file_get_contents($ROOT . '/app/Views/frontend/book-detail.php');

// 16. Genre separator is centered with the pills.
$check(strpos($bookDetail, '.genre-separator') !== false
    && strpos($bookDetail, 'align-items: center') !== false,
    'book-detail.php: .genre-separator rule + align-items:center present');

$webRoutes = (string) file_get_contents($ROOT . '/app/Routes/web.php');

// 17. Login rate limit relaxed to 15/300 (and the old 5/300 login limiter is gone).
$check(strpos($webRoutes, "RateLimitMiddleware(15, 300, 'login')") !== false
    && strpos($webRoutes, "RateLimitMiddleware(5, 300, 'login')") === false,
    "web.php: login limiter is 15/300 (not 5/300)");

$cmsCtrl = (string) file_get_contents($ROOT . '/app/Controllers/CmsController.php');

// 18. CmsController has the resilient fallback (matches any slug variant).
$check(strpos($cmsCtrl, 'getSlugsForPage') !== false
    && strpos($cmsCtrl, 'slug IN') !== false,
    'CmsController.php: resilient fallback (getSlugsForPage + slug IN) present');

// ---------------------------------------------------------------------------
echo "\n" . ($failed === 0
    ? "[OK] session-fixes unit: $passed passed\n"
    : "[FAIL] session-fixes unit: $failed failed, $passed passed\n");
exit($failed === 0 ? 0 : 1);
