<?php
declare(strict_types=1);

/**
 * Review of PR #536 — a single stray table could lock out an install/reinstall.
 *
 * On a reinstall .installed still exists and, from step 2, .env points at the
 * new empty database. The hourly cron/automatic-notifications.php had no
 * installer check: its retryQueuedEmailDeliveries() ran
 * EmailOutboxSchema::ensure() and created email_delivery_outbox between step 2
 * and step 3. Step 3 then refused the import ("database not empty"), and the
 * plain CREATE TABLE of schema.sql would have failed on that table anyway.
 * (The step-2 import proof is covered in
 * tests/security-audit-installer-installed-predicate.unit.php.)
 *
 *   A. App\Support\InstallInProgress::active(): live only for a regular marker
 *      younger than the installer's absolute TTL (same constant).
 *   B. Every cron/maintenance entry point that writes to the database skips its
 *      run while the marker is live, before it opens a connection: each real
 *      script runs in a scratch tree, with and without a fresh marker.
 *
 * No database needed. Run: php tests/security-audit-installer-cron-guard.unit.php
 */

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $key, mixed ...$args): string
    {
        return $args ? sprintf($key, ...$args) : $key;
    }
}
require_once $ROOT . '/installer/classes/Installer.php';

use App\Support\InstallInProgress;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

$tmp = sys_get_temp_dir() . '/pk_cron_guard_' . bin2hex(random_bytes(5));
mkdir($tmp . '/storage', 0700, true);
$rm = static function (string $dir) use (&$rm): void {
    if (is_link($dir) || is_file($dir)) { @unlink($dir); return; }
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') { $rm($dir . '/' . $e); }
    }
    @rmdir($dir);
};

try {
    // -----------------------------------------------------------------------
    echo "A. InstallInProgress::active()\n";
    // -----------------------------------------------------------------------
    $hasClass = class_exists(InstallInProgress::class);
    $check($hasClass, 'App\\Support\\InstallInProgress exists');
    $active = static fn (string $root, ?int $now = null): bool => $hasClass && InstallInProgress::active($root, $now);
    $marker = $tmp . '/storage/.install_in_progress';
    $now = time();
    $check(!$active($tmp), 'no marker: no install in progress');
    file_put_contents($marker, hash('sha256', 'x') . "\n" . $now);
    $check($active($tmp, $now), 'a fresh marker: install in progress');
    $check($hasClass && InstallInProgress::ABSOLUTE_TTL === Installer::INSTALL_OWNER_ABSOLUTE_TTL,
        'the TTL is the installer\'s own absolute TTL');
    $check(!$active($tmp, $now + Installer::INSTALL_OWNER_ABSOLUTE_TTL + 1), 'a marker past the absolute TTL blocks nothing (the installer would discard it)');
    file_put_contents($marker, hash('sha256', 'x') . "\n");
    touch($marker, $now - 60);
    $check($active($tmp, $now), 'a marker without a claim time falls back to its mtime');
    unlink($marker);
    file_put_contents($tmp . '/elsewhere', "x\n" . $now);
    symlink($tmp . '/elsewhere', $marker);
    $check(!$active($tmp, $now), 'a symlinked marker is not trusted');
    unlink($marker);

    // -----------------------------------------------------------------------
    echo "B. Cron and maintenance scripts skip their run during an install\n";
    // -----------------------------------------------------------------------
    $tree = $tmp . '/tree';
    mkdir($tree . '/storage/cache', 0700, true);
    mkdir($tree . '/storage/tmp', 0700, true);
    mkdir($tree . '/storage/logs', 0700, true);
    mkdir($tree . '/cron', 0700);
    mkdir($tree . '/scripts', 0700);
    symlink($ROOT . '/vendor', $tree . '/vendor');
    $scripts = [
        'cron/automatic-notifications.php',
        'cron/full-maintenance.php',
        'scripts/maintenance.php',
        'scripts/check-expired-reservations.php',
        'scripts/bulk-enrich-cron.php',
    ];
    copy($ROOT . '/scripts/_db_bootstrap.php', $tree . '/scripts/_db_bootstrap.php');
    foreach ($scripts as $script) {
        copy($ROOT . '/' . $script, $tree . '/' . $script);
    }
    $run = static function (string $script) use ($tree): array {
        $proc = proc_open([PHP_BINARY, $tree . '/' . $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tree);
        if (!is_resource($proc)) {
            return ['code' => -1, 'out' => ''];
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($proc), 'out' => (string) $out];
    };
    $treeMarker = $tree . '/storage/.install_in_progress';
    foreach ($scripts as $script) {
        file_put_contents($treeMarker, hash('sha256', 'x') . "\n" . time());
        $during = $run($script);
        @unlink($treeMarker);
        $check($during['code'] === 0 && str_contains($during['out'], 'the installer is running'),
            "{$script}: skipped while the installer marker is live (exit {$during['code']})");
        $after = $run($script);
        $check(!str_contains($after['out'], 'the installer is running'),
            "{$script}: runs normally without the marker (control)");
        $src = (string) file_get_contents($tree . '/' . $script);
        $guard = strpos($src, 'InstallInProgress::active(');
        $firstDb = min(array_filter([
            strpos($src, 'new mysqli('),
            strpos($src, 'pinakes_db_from_env()'),
        ], static fn ($p): bool => $p !== false) ?: [PHP_INT_MAX]);
        $check($guard !== false && $guard < $firstDb, "{$script}: the guard comes before the database connection");
    }
} finally {
    $rm($tmp);
}

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
