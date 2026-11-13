<?php
declare(strict_types=1);

/**
 * Security audit 2026-11
 *   - public/.htaccess:static-sitemap-bypasses-private-mode
 *   - root-htaccess:storage-calendar-ics-bypasses-private-mode (static file half)
 *
 * Private mode is enforced only by PrivateModeMiddleware, but public/sitemap.xml
 * and storage/calendar/library-calendar.ics are static copies the web server can
 * serve without running PHP. While private mode is on they must not exist:
 *
 *   A. robots.txt (public in private mode) stops advertising /sitemap.xml.
 *   B. MaintenanceService writes no public calendar file and removes an old one
 *      (generateIcsCalendar() and healPublicIcsFile()), failing closed.
 *   C. The sitemap publishers refuse to publish and withdraw the published copy:
 *      Settings → Advanced save, "Rigenera adesso", scripts/generate-sitemap.php.
 *
 * No database needed (settings are seeded in ConfigStore's runtime cache; the
 * mysqli handed to MaintenanceService is never connected).
 * Run: php tests/security-audit-private-mode-static-copies.unit.php
 */

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $key, mixed ...$args): string
    {
        return $args ? sprintf($key, ...$args) : $key;
    }
}

use App\Controllers\SeoController;
use App\Support\ConfigStore;
use App\Support\MaintenanceService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

$runtimeCache = (new ReflectionClass(ConfigStore::class))->getProperty('runtimeCache');
$previousCache = $runtimeCache->getValue();
$setPrivateMode = static function (bool $on) use ($runtimeCache): void {
    $runtimeCache->setValue(null, [
        'advanced' => ['private_mode' => $on],
        'seo' => ['llms_txt_enabled' => '1'],
        'app' => ['canonical_url' => 'https://library.example'],
    ]);
};

// ---------------------------------------------------------------------------
echo "A. robots.txt\n";
// ---------------------------------------------------------------------------
$robots = static function (): string {
    $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://library.example/robots.txt');
    return (string) (new SeoController())->robots($request, new Response())->getBody();
};
$setPrivateMode(false);
$public = $robots();
$check(str_contains($public, 'Sitemap: ') && str_contains($public, '/sitemap.xml'), 'public site: robots.txt advertises the sitemap');
$setPrivateMode(true);
$private = $robots();
$check(!str_contains($private, 'Sitemap:') && !str_contains($private, 'llms.txt:'), 'private mode: robots.txt advertises neither sitemap nor llms.txt');
$check(str_contains($private, 'Disallow: /admin/'), 'private mode: the rest of robots.txt is unchanged');

// ---------------------------------------------------------------------------
echo "B. Public calendar file\n";
// ---------------------------------------------------------------------------
$unconnected = mysqli_init(); // never connected: any DB use would throw
$tmp = sys_get_temp_dir() . '/pk_secaudit_pm_' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
$ics = $tmp . '/library-calendar.ics';
$canary = "BEGIN:VCALENDAR\r\nSUMMARY:Prestito: Canary Title\r\nDESCRIPTION:Libro: Canary\\nUtente: a@b.example\r\nEND:VCALENDAR\r\n";

$setPrivateMode(true);
file_put_contents($ics, $canary);
$check(MaintenanceService::healPublicIcsFile($unconnected, $ics) === true && !is_file($ics),
    'healPublicIcsFile(): private mode removes the public copy instead of rewriting it');

$setPrivateMode(false);
file_put_contents($ics, $canary);
MaintenanceService::healPublicIcsFile($unconnected, $ics);
$check(!is_file($ics), 'healPublicIcsFile(): an unreadable private-mode setting fails closed (file removed)');

// generateIcsCalendar() writes the fixed storage/calendar path: preserve whatever is there.
$livePath = $ROOT . '/storage/calendar/library-calendar.ics';
$backup = is_file($livePath) ? file_get_contents($livePath) : null;
try {
    $setPrivateMode(true);
    file_put_contents($livePath, $canary);
    $service = new MaintenanceService($unconnected);
    $result = $service->generateIcsCalendar();
    clearstatcache();
    $check($result === true && !is_file($livePath), 'generateIcsCalendar(): private mode writes nothing and removes the old copy');
} catch (\Throwable $e) {
    $check(false, 'generateIcsCalendar() threw ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($backup !== null) {
        file_put_contents($livePath, $backup);
    } elseif (is_file($livePath)) {
        @unlink($livePath); // nosemgrep
    }
}
@rmdir($tmp);

// ---------------------------------------------------------------------------
echo "C. Sitemap publishers\n";
// ---------------------------------------------------------------------------
$settings = (string) file_get_contents($ROOT . '/app/Controllers/SettingsController.php');
$check(preg_match("/if \\(\\\$settings\\['private_mode'\\] === '1'\\) \\{\\s*\\\\App\\\\Support\\\\SitemapCache::invalidate\\(/", $settings) === 1,
    'saving Advanced settings with private mode on withdraws the published sitemap');
$regenStart = strpos($settings, 'public function regenerateSitemap(');
$regen = $regenStart === false ? '' : substr($settings, $regenStart, 2500);
$guard = strpos($regen, "ConfigStore::get('advanced.private_mode'");
$save = strpos($regen, '->saveTo(');
$check($guard !== false && $save !== false && $guard < $save
    && str_contains(substr($regen, $guard, $save - $guard), 'SitemapCache::invalidate(')
    && str_contains(substr($regen, $guard, $save - $guard), 'return $this->redirect('),
    '"Rigenera adesso" refuses to publish (and withdraws the file) before saveTo() while private mode is on');

$script = (string) file_get_contents($ROOT . '/scripts/generate-sitemap.php');
$pm = strpos($script, "get('advanced', 'private_mode'");
$saveTo = strpos($script, '->saveTo(');
$check($pm !== false && $saveTo !== false && $pm < $saveTo
    && preg_match('/if \(\$privateMode\) \{\s*\\\\App\\\\Support\\\\SitemapCache::invalidate\([^;]+;\s*fwrite\([^;]+;\s*\$mysqli->close\(\);\s*exit\(0\);/', $script) === 1,
    'scripts/generate-sitemap.php withdraws and exits before saveTo() while private mode is on');

$runtimeCache->setValue(null, $previousCache);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
