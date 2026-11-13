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
 *   D. (review of PR #536) A copy that cannot be removed is reported, never
 *      hidden behind a success: SitemapCache::withdraw() and
 *      MaintenanceService::withdrawPublicIcsFile() say whether a copy is still
 *      there, generateIcsCalendar() returns false for it, and the Advanced save
 *      withdraws the calendar too and flashes an error for either survivor.
 *   E. (review of PR #536) The publishers read the sitemap revision BEFORE
 *      private mode and withdraw what saveTo() wrote if it moved, so private
 *      mode switched on between that read and saveTo() cannot publish.
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

// A file that cannot be removed: its directory loses the write bit. Root
// ignores that, so the checks depending on it are skipped there.
$canLockDirs = !(function_exists('posix_geteuid') && posix_geteuid() === 0);
$lockDir = static function (string $dir): int {
    $mode = fileperms($dir) & 0777;
    chmod($dir, 0500);
    clearstatcache();
    return $mode;
};
$unlockDir = static function (string $dir, int $mode): void {
    chmod($dir, $mode);
    clearstatcache();
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

// ---------------------------------------------------------------------------
echo "D (behaviour). A public copy that cannot be removed is reported\n";
// ---------------------------------------------------------------------------
@unlink($ics); // nosemgrep
$check(MaintenanceService::withdrawPublicIcsFile($ics) === true, 'withdrawPublicIcsFile(): no copy at all counts as withdrawn');
file_put_contents($ics, $canary);
$check(MaintenanceService::withdrawPublicIcsFile($ics) === true && !is_file($ics), 'withdrawPublicIcsFile(): a removable copy is removed');

$sitemapFile = $tmp . '/sitemap.xml';
$check(\App\Support\SitemapCache::withdraw('test', $sitemapFile) === true, 'SitemapCache::withdraw(): no file at all counts as withdrawn');
file_put_contents($sitemapFile, '<urlset/>');
$check(\App\Support\SitemapCache::withdraw('test', $sitemapFile) === true && !is_file($sitemapFile), 'SitemapCache::withdraw(): a removable file is removed');

if ($canLockDirs) {
    file_put_contents($ics, $canary);
    file_put_contents($sitemapFile, '<urlset/>');
    $mode = $lockDir($tmp);
    try {
        $check(MaintenanceService::withdrawPublicIcsFile($ics) === false && is_file($ics),
            'withdrawPublicIcsFile(): a copy that survives the unlink is reported (false), not passed off as withdrawn');
        $check(\App\Support\SitemapCache::invalidate('test', $sitemapFile) === false
            && \App\Support\SitemapCache::withdraw('test', $sitemapFile) === false && is_file($sitemapFile),
            'SitemapCache::withdraw(): a file that survives the unlink is reported (false), unlike invalidate() whose false also means "absent"');
    } finally {
        $unlockDir($tmp, $mode);
    }

    // generateIcsCalendar() on the fixed path: storage/calendar made read-only for the call.
    $liveDir = dirname($livePath);
    $backup = is_file($livePath) ? file_get_contents($livePath) : null;
    $setPrivateMode(true);
    file_put_contents($livePath, $canary);
    $mode = $lockDir($liveDir);
    try {
        $result = (new MaintenanceService($unconnected))->generateIcsCalendar();
        clearstatcache();
        $check($result === false && is_file($livePath),
            'generateIcsCalendar(): private mode with a copy that cannot be removed returns false, so runAllLocked() records an error');
    } catch (\Throwable $e) {
        $check(false, 'generateIcsCalendar() threw ' . get_class($e) . ': ' . $e->getMessage());
    } finally {
        $unlockDir($liveDir, $mode);
        if ($backup !== null) {
            file_put_contents($livePath, $backup);
        } elseif (is_file($livePath)) {
            @unlink($livePath); // nosemgrep
        }
    }
} else {
    echo "  SKIP running as root: a read-only directory does not stop unlink()\n";
}
@unlink($ics); // nosemgrep
@unlink($sitemapFile); // nosemgrep
@rmdir($tmp);

// ---------------------------------------------------------------------------
echo "C, D, E. Sitemap and calendar publishers (source structure)\n";
// ---------------------------------------------------------------------------
$settings = (string) file_get_contents($ROOT . '/app/Controllers/SettingsController.php');
$advStart = strpos($settings, 'public function updateAdvancedSettings(');
$advEnd = strpos($settings, 'public function regenerateSitemap(');
$adv = ($advStart === false || $advEnd === false) ? '' : substr($settings, $advStart, $advEnd - $advStart);
$check(preg_match("/if \(\\\$settings\['private_mode'\] === '1'\) \{\s*if \(!\\\\App\\\\Support\\\\SitemapCache::withdraw\(/", $adv) === 1,
    'saving Advanced settings with private mode on withdraws the published sitemap');
$pmBranch = preg_match("/if \(\\\$settings\['private_mode'\] === '1'\) \{(.*?)\n        \}\n/s", $adv, $m) === 1 ? $m[1] : '';
$check(str_contains($pmBranch, 'if (!\App\Support\MaintenanceService::withdrawPublicIcsFile())'),
    'saving Advanced settings with private mode on withdraws the static calendar copy too (D)');
$check(substr_count($pmBranch, '$withdrawalErrors[] = __(') === 2
    && preg_match('/\$errorMessages = \$withdrawalErrors;.*\$_SESSION\[\'error_message\'\] = implode\(/s', $adv) === 1,
    'a sitemap or calendar copy that survives the withdrawal is flashed as an error, not hidden behind the success (D)');

$regenStart = strpos($settings, 'public function regenerateSitemap(');
$regenEnd = $regenStart === false ? false : strpos($settings, 'public function flushAllCaches(', $regenStart);
$regen = ($regenStart === false || $regenEnd === false) ? '' : substr($settings, $regenStart, $regenEnd - $regenStart);
$revRead = strpos($regen, '$revisionAtStart = \App\Support\SitemapCache::revision();');
$guard = strpos($regen, "ConfigStore::get('advanced.private_mode'");
$save = strpos($regen, '->saveTo(');
$recheck = strpos($regen, 'if (\App\Support\SitemapCache::revision() !== $revisionAtStart) {');
$success = strpos($regen, "\$_SESSION['success_message']");
$check($guard !== false && $save !== false && $guard < $save
    && preg_match('/if \(\$privateMode\) \{\s*\$_SESSION\[\'error_message\'\] = \\\\App\\\\Support\\\\SitemapCache::withdraw\([^)]*\)\s*\?\s*__\([^;]+:\s*__\([^;]+;\s*return \$this->redirect\(/', substr($regen, $guard, $save - $guard)) === 1,
    '"Rigenera adesso" refuses to publish while private mode is on, and says so when the file cannot be withdrawn (D)');
$check($revRead !== false && $guard !== false && $save !== false && $recheck !== false && $success !== false
    && $revRead < $guard && $save < $recheck && $recheck < $success
    && preg_match('/\{\s*\/\/[^\n]*\n\s*\/\/[^\n]*\n\s*\$_SESSION\[\'error_message\'\] = \\\\App\\\\Support\\\\SitemapCache::withdraw\([^;]+;\s*return \$this->redirect\(/', substr($regen, $recheck, $success - $recheck)) === 1,
    '"Rigenera adesso" reads the revision before private mode and withdraws the file when it moved by the end of saveTo() (E)');

$script = (string) file_get_contents($ROOT . '/scripts/generate-sitemap.php');
$sRev = strpos($script, '$revisionAtStart = \App\Support\SitemapCache::revision();');
$pm = strpos($script, "get('advanced', 'private_mode'");
$saveTo = strpos($script, '->saveTo(');
$sRecheck = strpos($script, 'if (\App\Support\SitemapCache::revision() !== $revisionAtStart) {');
$sStats = strpos($script, '$stats = $generator->getStats();');
$check($pm !== false && $saveTo !== false && $pm < $saveTo
    && preg_match('/if \(\$privateMode\) \{\s*if \(!\\\\App\\\\Support\\\\SitemapCache::withdraw\([^;]+\)\) \{\s*fwrite\(STDERR,[^;]+;\s*\$mysqli->close\(\);\s*exit\(1\);\s*\}\s*fwrite\(STDOUT,[^;]+;\s*\$mysqli->close\(\);\s*exit\(0\);/', $script) === 1,
    'scripts/generate-sitemap.php withdraws and exits before saveTo() while private mode is on, non-zero when the file survives (D)');
$check($sRev !== false && $pm !== false && $sRecheck !== false && $sStats !== false
    && $sRev < $pm && $saveTo < $sRecheck && $sRecheck < $sStats
    && preg_match('/if \(!\\\\App\\\\Support\\\\SitemapCache::withdraw\([^;]+\)\) \{\s*fwrite\(STDERR,[^;]+;\s*\$mysqli->close\(\);\s*exit\(1\);\s*\}\s*fwrite\(STDOUT,[^;]+;\s*\$mysqli->close\(\);\s*exit\(0\);/', substr($script, $sRecheck, $sStats - $sRecheck)) === 1,
    'scripts/generate-sitemap.php reads the revision before private mode and withdraws the file when it moved by the end of saveTo() (E)');

$runtimeCache->setValue(null, $previousCache);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
