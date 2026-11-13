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
 *   E. (review of PR #536) Publication and private-mode activation are
 *      serialised: SitemapGenerator::saveTo() writes a temporary file and only
 *      renames it into place under SitemapCache's publish lock, after re-reading
 *      private mode and the revision counter; the Advanced save writes the flag
 *      and withdraws the copies under the same lock. Executed with controlled
 *      interleavings, including a second process held on the lock.
 *   F. (review of PR #536) A revision counter that cannot be read or bumped
 *      fails closed: nothing is published, and the reason is reported.
 *
 * No database needed (settings are seeded in ConfigStore's runtime cache; the
 * mysqli handed to MaintenanceService is never connected; the generators used
 * here override the private-mode lookup).
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
$check(preg_match("/if \(\\\$settings\['private_mode'\] === '1'\) \{\s*\\\$publishLocked = \\\\App\\\\Support\\\\SitemapCache::acquirePublishLock\(\);\s*try \{\s*\\\$repository->set\('advanced', 'private_mode', '1'\);\s*ConfigStore::set\('advanced.private_mode', true\);\s*if \(!\\\\App\\\\Support\\\\SitemapCache::withdraw\(/", $adv) === 1
    && preg_match('/\} finally \{\s*if \(\$publishLocked\) \{\s*\\\\App\\\\Support\\\\SitemapCache::releasePublishLock\(\);/', $adv) === 1
    && str_contains($adv, "if (\$key === 'private_mode' && \$value === '1') {\n                continue;"),
    'saving Advanced settings with private mode on writes the flag and withdraws the sitemap under the publish lock (E)');
$pmBranch = preg_match("/if \(\\\$settings\['private_mode'\] === '1'\) \{(.*?)\n        \}\n/s", $adv, $m) === 1 ? $m[1] : '';
$check(str_contains($pmBranch, 'if (!\App\Support\MaintenanceService::withdrawPublicIcsFile())'),
    'saving Advanced settings with private mode on withdraws the static calendar copy too (D)');
$check(substr_count($pmBranch, '$withdrawalErrors[] = __(') === 2
    && preg_match('/\$errorMessages = \$withdrawalErrors;.*\$_SESSION\[\'error_message\'\] = implode\(/s', $adv) === 1,
    'a sitemap or calendar copy that survives the withdrawal is flashed as an error, not hidden behind the success (D)');

$regenStart = strpos($settings, 'public function regenerateSitemap(');
$regenEnd = $regenStart === false ? false : strpos($settings, 'public function flushAllCaches(', $regenStart);
$regen = ($regenStart === false || $regenEnd === false) ? '' : substr($settings, $regenStart, $regenEnd - $regenStart);
$guard = strpos($regen, "ConfigStore::get('advanced.private_mode'");
$save = strpos($regen, 'if (!$generator->saveTo($targetPath)) {');
$anySave = strpos($regen, '->saveTo(');
$success = strpos($regen, "\$_SESSION['success_message']");
$check($guard !== false && $anySave !== false && $guard < $anySave
    && preg_match('/if \(\$privateMode\) \{\s*\$_SESSION\[\'error_message\'\] = \\\\App\\\\Support\\\\SitemapCache::withdraw\([^)]*\)\s*\?\s*__\([^;]+:\s*__\([^;]+;\s*return \$this->redirect\(/', substr($regen, $guard, $anySave - $guard)) === 1,
    '"Rigenera adesso" refuses to publish while private mode is on, and says so when the file cannot be withdrawn (D)');
$check($save !== false && $success !== false && $save < $success
    && preg_match('/\$_SESSION\[\'error_message\'\] = match \(\$generator->getLastSkipReason\(\)\) \{.*?\};\s*return \$this->redirect\(/s', substr($regen, $save, $success - $save)) === 1,
    '"Rigenera adesso" reports a sitemap saveTo() did not publish instead of a success (E, F)');

$script = (string) file_get_contents($ROOT . '/scripts/generate-sitemap.php');
$pm = strpos($script, "get('advanced', 'private_mode'");
$saveTo = strpos($script, 'if (!$generator->saveTo($targetPath)) {');
$sAnySave = strpos($script, '->saveTo(');
$sStats = strpos($script, '$stats = $generator->getStats();');
$check($pm !== false && $sAnySave !== false && $pm < $sAnySave
    && preg_match('/if \(\$privateMode\) \{\s*if \(!\\\\App\\\\Support\\\\SitemapCache::withdraw\([^;]+\)\) \{\s*fwrite\(STDERR,[^;]+;\s*\$mysqli->close\(\);\s*exit\(1\);\s*\}\s*fwrite\(STDOUT,[^;]+;\s*\$mysqli->close\(\);\s*exit\(0\);/', $script) === 1,
    'scripts/generate-sitemap.php withdraws and exits before saveTo() while private mode is on, non-zero when the file survives (D)');
$check($saveTo !== false && $sStats !== false && $saveTo < $sStats
    && preg_match('/\$skipReason = \$generator->getLastSkipReason\(\);.*?fwrite\(STDERR,[^;]+;\s*\$mysqli->close\(\);\s*exit\(1\);.*?exit\(0\);/s', substr($script, $saveTo, $sStats - $saveTo)) === 1,
    'scripts/generate-sitemap.php reports a sitemap saveTo() did not publish, non-zero when the state is unusable (E, F)');

// ---------------------------------------------------------------------------
echo "E, F. Publication against private mode and a failing revision store (behaviour)\n";
// ---------------------------------------------------------------------------

/**
 * saveTo() with the real lock, counter and rename; only the database is
 * replaced. Private mode comes from a flag file, so another process can flip
 * it; $duringGenerate runs inside generate(), i.e. between the caller's own
 * check and the publication.
 */
final class InterleavedSitemapGenerator extends \App\Support\SitemapGenerator
{
    public ?\Closure $duringGenerate = null;

    public function __construct(private string $flagFile)
    {
        // No parent::__construct(): it needs a database.
    }

    protected function privateModeEnabledNow(): bool
    {
        return trim((string) @file_get_contents($this->flagFile)) === '1';
    }

    public function generate(): string
    {
        if ($this->duringGenerate !== null) {
            ($this->duringGenerate)();
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    }
}

$revisionFile = $ROOT . '/storage/cache/sitemap-revision';
$revisionBackup = is_file($revisionFile) ? (string) file_get_contents($revisionFile) : null;
$restoreRevision = static function () use ($revisionFile, $revisionBackup): void {
    if (is_dir($revisionFile)) {
        @rmdir($revisionFile);
    }
    if ($revisionBackup === null) {
        @unlink($revisionFile); // nosemgrep
    } else {
        @file_put_contents($revisionFile, $revisionBackup);
    }
};
register_shutdown_function($restoreRevision);

$work = sys_get_temp_dir() . '/pk_secaudit_lock_' . bin2hex(random_bytes(5));
mkdir($work, 0700, true);
$flag = $work . '/private-mode.flag';
$target = $work . '/sitemap.xml';
$leftovers = static fn (): array => glob($work . '/.sitemap.xml.*.tmp') ?: [];
$supportsSkip = method_exists(\App\Support\SitemapGenerator::class, 'getLastSkipReason');

try {
    // Baseline: nothing in the way, the file is published.
    file_put_contents($flag, '0');
    $gen = new InterleavedSitemapGenerator($flag);
    $gen->saveTo($target);
    $check(is_file($target) && $leftovers() === [], 'baseline: with private mode off and a usable counter the file is published, no temporary left');
    @unlink($target); // nosemgrep

    // E1. The flag is written while the document is generated, after the
    // caller's own check, with no revision bump: the window the review found.
    file_put_contents($flag, '0');
    $gen = new InterleavedSitemapGenerator($flag);
    $gen->duringGenerate = static function () use ($flag): void {
        file_put_contents($flag, '1');
    };
    $result = $gen->saveTo($target);
    $check(!is_file($target) && $result === false && $leftovers() === []
        && $supportsSkip && $gen->getLastSkipReason() === \App\Support\SitemapGenerator::SKIP_PRIVATE_MODE,
        'private mode switched on during generation: nothing is published (saveTo() re-reads the flag before the rename)');

    // E2. Another process is held on the publish lock while this one enables
    // private mode, the way the Advanced save does. The child must not be able
    // to put the file in place before the flag is set and the lock released.
    file_put_contents($flag, '0');
    $childScript = $work . '/child.php';
    file_put_contents($childScript, '<?php
declare(strict_types=1);
require ' . var_export($ROOT . '/vendor/autoload.php', true) . ';
final class ChildGenerator extends \App\Support\SitemapGenerator
{
    public function __construct(private string $flagFile) {}
    protected function privateModeEnabledNow(): bool { return trim((string) @file_get_contents($this->flagFile)) === "1"; }
    public function generate(): string { return "<urlset/>"; }
}
$g = new ChildGenerator($argv[1]);
$published = $g->saveTo($argv[2]);
echo json_encode(["published" => $published, "reason" => method_exists($g, "getLastSkipReason") ? $g->getLastSkipReason() : null]);
');
    $haveLockApi = method_exists(\App\Support\SitemapCache::class, 'acquirePublishLock');
    $parentLocked = $haveLockApi && \App\Support\SitemapCache::acquirePublishLock();
    $child = proc_open([PHP_BINARY, $childScript, $flag, $target], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    // Wait (bounded) until the child has written its temporary file, i.e. it
    // is at the lock. Synchronisation only: nothing below depends on timing.
    for ($i = 0; $i < 200 && $leftovers() === [] && !is_file($target); $i++) {
        usleep(50000);
    }
    $check(!is_file($target), 'a publisher cannot put the file in place while the private-mode save holds the lock');
    file_put_contents($flag, '1');
    if ($parentLocked) {
        \App\Support\SitemapCache::releasePublishLock();
    }
    $childOut = is_resource($child) ? (string) stream_get_contents($pipes[1]) : '';
    $childErr = is_resource($child) ? (string) stream_get_contents($pipes[2]) : '';
    if (is_resource($child)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($child);
    }
    $decoded = json_decode($childOut, true);
    $check(!is_file($target) && $leftovers() === [] && is_array($decoded) && $decoded['published'] === false
        && $decoded['reason'] === 'private_mode',
        'once released, the waiting publisher sees the flag and publishes nothing' . ($childErr !== '' ? ' [child: ' . trim($childErr) . ']' : ''));

    // F1. A revision counter that cannot be read: a directory in its place
    // (also makes every bump fail). Fails closed, also as root.
    file_put_contents($flag, '0');
    @unlink($revisionFile); // nosemgrep
    mkdir($revisionFile);
    $gen = new InterleavedSitemapGenerator($flag);
    $result = $gen->saveTo($target);
    $check(!is_file($target) && $result === false && $leftovers() === []
        && $supportsSkip && $gen->getLastSkipReason() === \App\Support\SitemapGenerator::SKIP_STATE_UNAVAILABLE,
        'an unreadable revision counter publishes nothing and reports why');
    file_put_contents($target, '<urlset/>');
    $check(\App\Support\SitemapCache::invalidate('test: counter unusable', $target) === true && !is_file($target),
        'an invalidation whose bump fails still withdraws the file');
    rmdir($revisionFile);

    // F2. A counter holding something other than a number reads as unknown.
    file_put_contents($revisionFile, "not-a-number\n");
    $gen = new InterleavedSitemapGenerator($flag);
    $result = $gen->saveTo($target);
    $check(!is_file($target) && $result === false,
        'a corrupt revision counter publishes nothing (it no longer reads as 0)');

    // F3. The counter moves during generation (a plugin toggled): not published.
    @unlink($revisionFile); // nosemgrep
    $gen = new InterleavedSitemapGenerator($flag);
    $gen->duringGenerate = static function (): void {
        \App\Support\SitemapCache::invalidate('test: plugin toggled', sys_get_temp_dir() . '/pk-absent-' . bin2hex(random_bytes(4)));
    };
    $result = $gen->saveTo($target);
    $check(!is_file($target) && $result === false
        && $supportsSkip && $gen->getLastSkipReason() === \App\Support\SitemapGenerator::SKIP_REVISION_CHANGED,
        'a revision bump during generation publishes nothing, without ever writing the target');
} catch (\Throwable $e) {
    $check(false, 'E/F threw ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    $restoreRevision();
    foreach (array_merge($leftovers(), [$target, $flag, $work . '/child.php']) as $file) {
        @unlink($file); // nosemgrep
    }
    @rmdir($work);
}

$runtimeCache->setValue(null, $previousCache);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
