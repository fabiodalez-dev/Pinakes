<?php
declare(strict_types=1);

/**
 * storage/calendar/.htaccess sits under a preserved path, so copyDirectory()
 * keeps the old copy, and an in-app upgrade runs the OLD Updater already in
 * memory, which never calls healPreservedPackagedFiles(). The first request
 * under the new code therefore heals it too, from PluginManager's
 * once-per-version block, with the content PackagedFileHeal::FILES carries.
 *
 *   A. FILES matches the shipped files byte for byte, and the Updater's list
 *      names the same files.
 *   B. healAll() replaces an old, missing or symlinked file, leaves an
 *      identical one untouched, and never writes through a symlink.
 *   C. PluginManager calls it in the once-per-version block, next to the
 *      other heals.
 *   D. The operator's own files next to it are left alone.
 *
 * No database needed. Run: php tests/packaged-file-heal-runtime.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\PackagedFileHeal;
use App\Support\Updater;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

echo "A. The embedded content is the shipped file\n";
foreach (PackagedFileHeal::FILES as $relative => $content) {
    $check($content === (string) file_get_contents($root . '/' . $relative), "$relative: FILES matches the file in the tree");
}
$listed = (new ReflectionClass(Updater::class))->getConstant('PACKAGED_FILES_UNDER_PRESERVED_PATHS');
$keys = array_keys(PackagedFileHeal::FILES);
sort($keys);
sort($listed);
$check($listed === $keys, "the Updater's list and PackagedFileHeal::FILES name the same files");

$tmp = sys_get_temp_dir() . '/pk_heal_runtime_' . bin2hex(random_bytes(5));
mkdir($tmp . '/storage/calendar', 0755, true);
$file = $tmp . '/storage/calendar/.htaccess';
$packaged = PackagedFileHeal::FILES['storage/calendar/.htaccess'];
$old = "# Allow public access to calendar files (.ics)\n<FilesMatch \"\\.ics$\">\n    Require all granted\n</FilesMatch>\n";
file_put_contents($file, $old);
file_put_contents($tmp . '/storage/calendar/library-calendar.ics', "BEGIN:VCALENDAR\nEND:VCALENDAR\n");

echo "B. healAll() on an installed tree\n";
$check(PackagedFileHeal::healAll($tmp) === [], 'nothing is reported as failed');
$check((string) file_get_contents($file) === $packaged, 'an old file is replaced by the shipped content');
$check(!str_contains((string) file_get_contents($file), '# Allow public access to calendar files'), 'the old unconditional grant is gone');
$mtime = filemtime($file);
touch($file, $mtime - 100);
clearstatcache();
PackagedFileHeal::healAll($tmp);
clearstatcache();
$check(filemtime($file) === $mtime - 100, 'an identical file is left untouched (idempotent)');
unlink($file);
PackagedFileHeal::healAll($tmp);
$check(is_file($file) && (string) file_get_contents($file) === $packaged, 'a missing file is written');
$outside = $tmp . '/outside.txt';
file_put_contents($outside, 'ORIGINAL');
unlink($file);
symlink($outside, $file);
PackagedFileHeal::healAll($tmp);
$check((string) file_get_contents($outside) === 'ORIGINAL', 'a symlink in its place is never written through');
$check(!is_link($file) && (string) file_get_contents($file) === $packaged, 'the symlink is replaced by the shipped file');
$check((fileperms($file) & 0777) === 0644, 'the mode is 0644');
$bare = $tmp . '/fresh';
mkdir($bare);
$check(PackagedFileHeal::healAll($bare) === [] && is_file($bare . '/storage/calendar/.htaccess'), 'a missing directory is created');

echo "C. PluginManager heals in the once-per-version block\n";
$pm = (string) file_get_contents($root . '/app/Support/PluginManager.php');
$block = strpos($pm, "'plugins_maintenance_'");
$icsPos = strpos($pm, 'MaintenanceService::healPublicIcsFile($this->db);', (int) $block);
$healPos = strpos($pm, 'PackagedFileHeal::healAll()', (int) $block);
$htaccessPos = strpos($pm, 'PrivateUploadsRewriteHeal::heal()', (int) $block);
$check($block !== false && $healPos !== false && $icsPos !== false && $healPos > $icsPos && $healPos < $htaccessPos,
    'PackagedFileHeal::healAll() runs in that block, after the public ICS heal and before the .htaccess one');
$check(substr_count($pm, 'PackagedFileHeal::healAll()') === 1, 'and only there');
$updater = (string) file_get_contents($root . '/app/Support/Updater.php');
$check(str_contains($updater, 'PackagedFileHeal::write(') && !str_contains($updater, "@rename(\$tmp, \$target)"),
    'the Updater writes through the same helper, not its own copy of the logic');

echo "D. The operator's own files are left alone\n";
$check((string) file_get_contents($tmp . '/storage/calendar/library-calendar.ics') === "BEGIN:VCALENDAR\nEND:VCALENDAR\n", 'the calendar next to it is untouched');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // nosemgrep
}
@rmdir($tmp);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
