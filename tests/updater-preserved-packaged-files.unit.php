<?php
declare(strict_types=1);

/**
 * storage/calendar is in the Updater's preservePaths, so copyDirectory() keeps
 * an existing storage/calendar/.htaccess and the release's new one (which no
 * longer grants every .ics file) never reached upgraded installs. The update
 * now heals that file after the copy, as public/.htaccess is healed.
 *
 *   A. copyDirectory() alone keeps the old file (the reason for the heal).
 *   B. healPreservedPackagedFiles() writes the packaged content over an old,
 *      missing or symlinked file, and leaves an identical one untouched.
 *   C. installUpdate() calls the heal right after the copy.
 *   D. The other preserved data next to it is not touched.
 *
 * No database needed. Run: php tests/updater-preserved-packaged-files.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\Updater;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

$tmp = sys_get_temp_dir() . '/pk_upd_preserved_' . bin2hex(random_bytes(5));
$source = $tmp . '/package';
$target = $tmp . '/install';
mkdir($source . '/storage/calendar', 0755, true);
mkdir($target . '/storage/calendar', 0755, true);

$packaged = (string) file_get_contents($root . '/storage/calendar/.htaccess');
file_put_contents($source . '/storage/calendar/.htaccess', $packaged);
file_put_contents($source . '/storage/calendar/.gitkeep', '');
$old = "# Allow public access to calendar files (.ics)\n<FilesMatch \"\\.ics$\">\n    Require all granted\n</FilesMatch>\n";
file_put_contents($target . '/storage/calendar/.htaccess', $old);
file_put_contents($target . '/storage/calendar/library-calendar.ics', "BEGIN:VCALENDAR\nEND:VCALENDAR\n");

$ref = new ReflectionClass(Updater::class);
$updater = $ref->newInstanceWithoutConstructor();
$ref->getProperty('rootPath')->setValue($updater, $target);
$copy = $ref->getMethod('copyDirectory');
$heal = $ref->getMethod('healPreservedPackagedFiles');
$calendarHtaccess = $target . '/storage/calendar/.htaccess';

echo "A. copyDirectory() keeps a preserved file\n";
$copy->invoke($updater, $source, $target);
$check((string) file_get_contents($calendarHtaccess) === $old, 'the old storage/calendar/.htaccess survives the copy (preservePaths)');

echo "B. The heal writes the packaged content\n";
$heal->invoke($updater, $source);
$check((string) file_get_contents($calendarHtaccess) === $packaged, 'an old file is replaced by the packaged one');
$check(str_contains($packaged, 'mod_rewrite.c') && !str_contains((string) file_get_contents($calendarHtaccess), "# Allow public access to calendar files"),
    'the installed file is the one that grants .ics only for the rewrite');
$mtime = filemtime($calendarHtaccess);
touch($calendarHtaccess, $mtime - 100);
clearstatcache();
$heal->invoke($updater, $source);
clearstatcache();
$check(filemtime($calendarHtaccess) === $mtime - 100, 'an identical file is left untouched');

unlink($calendarHtaccess);
$heal->invoke($updater, $source);
$check(is_file($calendarHtaccess) && (string) file_get_contents($calendarHtaccess) === $packaged, 'a missing file is written');

$outside = $tmp . '/outside.txt';
file_put_contents($outside, 'ORIGINAL');
unlink($calendarHtaccess);
symlink($outside, $calendarHtaccess);
$heal->invoke($updater, $source);
$check((string) file_get_contents($outside) === 'ORIGINAL', 'a symlink in its place is never written through');
$check(!is_link($calendarHtaccess) && (string) file_get_contents($calendarHtaccess) === $packaged, 'the symlink is replaced by the packaged file');

echo "C. installUpdate() heals right after the copy\n";
$src = (string) file_get_contents($root . '/app/Support/Updater.php');
$install = substr($src, (int) strpos($src, 'public function installUpdate('), 6000);
$copyPos = strpos($install, '$this->copyDirectory($sourcePath, $this->rootPath);');
$healPos = strpos($install, '$this->healPreservedPackagedFiles($sourcePath);');
$check($copyPos !== false && $healPos !== false && $healPos > $copyPos, 'installUpdate() calls healPreservedPackagedFiles() after copyDirectory()');
$check(in_array('storage/calendar/.htaccess', $ref->getConstant('PACKAGED_FILES_UNDER_PRESERVED_PATHS'), true),
    'storage/calendar/.htaccess is on the heal list');

echo "D. Preserved data is left alone\n";
$check((string) file_get_contents($target . '/storage/calendar/library-calendar.ics') === "BEGIN:VCALENDAR\nEND:VCALENDAR\n",
    'the calendar file next to it is untouched');

// Cleanup: only the private temp tree created above.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // nosemgrep
}
@rmdir($tmp);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
