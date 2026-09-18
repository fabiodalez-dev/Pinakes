<?php
declare(strict_types=1);

/**
 * A failed desiderata probe must not publish the wish list on an installation
 * where the column is known to exist — including in a worker that has never
 * probed before.
 *
 * hasDesiderata() memoises "the column exists" in a function static, which a
 * PHP-FPM worker only fills after its first successful probe. A brand-new
 * worker whose FIRST probe failed used to answer "absent", so catalogue()
 * degraded to 1=1 and the wanted titles appeared as holdings in the catalogue,
 * feeds, sitemap and interop protocols for that call. The fix records the fact
 * in the shared cache (APCu or the file backend), which survives across
 * workers.
 *
 * Each scenario runs in its OWN PHP process, because the thing under test is
 * precisely a process that has never seen the column: in one process the
 * static from the first scenario would answer every later one.
 *
 * No database is needed: the failing probe is simulated with a closed
 * connection, which is what a dropped link looks like to mysqli.
 *
 * Run:  php tests/desiderata-visibility-probe.unit.php
 */

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

/** Run a probe in a fresh process: 'seed' | 'clear' decide the shared cache first. */
$probe = static function (string $cache) use ($root): string {
    $code = <<<'CODE'
require $argv[1] . '/vendor/autoload.php';
if (!function_exists('__')) { function __(string $t, mixed ...$a): string { return $a ? vsprintf($t, $a) : $t; } }
$key = 'visibility_desiderata_column_seen';
if ($argv[2] === 'seed') { \App\Support\QueryCache::set($key, true, 60); }
if ($argv[2] === 'clear') { \App\Support\QueryCache::delete($key); }
$db = mysqli_init();          // never connected: every query on it fails
echo \App\Support\BookVisibility::hasDesiderata($db) ? 'present' : 'absent';
echo '|' . \App\Support\BookVisibility::catalogue($db, 'l');
CODE;
    $cmd = sprintf('php -r %s %s %s 2>/dev/null', escapeshellarg($code), escapeshellarg($root), escapeshellarg($cache));

    return trim((string) shell_exec($cmd));
};

echo "A fresh worker whose first probe fails\n";

[$verdict, $predicate] = explode('|', $probe('seed') . '|');
$check($verdict === 'present', 'answers "present" when another worker has already seen the column');
$check($predicate === 'l.is_desiderata = 0', 'so the catalogue keeps filtering the wish list out (' . $predicate . ')');

[$verdict, $predicate] = explode('|', $probe('clear') . '|');
$check($verdict === 'absent', 'still answers "absent" when nothing has ever seen the column — the guess an installation without the plugin needs');
$check($predicate === '1=1', 'and emits no predicate on a column that may not exist (' . $predicate . ')');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
