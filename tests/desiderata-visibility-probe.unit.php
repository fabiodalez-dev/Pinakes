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

/**
 * Both processes run with apc.enable_cli=0, and that is deliberate. On the
 * command line APCu (when enabled for the CLI) gives every process a private
 * segment, so nothing written in one process is visible to the next — unlike
 * PHP-FPM, whose workers share one APCu segment, which is exactly the case this
 * code serves. With APCu off, QueryCache uses its file backend, which is shared
 * across processes the same way, so the test observes cross-worker behaviour.
 */
$php = 'php -d apc.enable_cli=0';

/**
 * The shared-cache step and the probe run in SEPARATE processes. Seeding and
 * probing in the same process would let a process-local cache satisfy the
 * probe, and the check would pass without proving the one thing it is for:
 * that a worker which has never probed can rely on what ANOTHER worker learned.
 */
$cacheOp = static function (string $key, string $op) use ($root, $php): void {
    $code = <<<'CODE'
require $argv[1] . '/vendor/autoload.php';
if ($argv[3] === 'seed') { \App\Support\QueryCache::set($argv[2], true, 60); }
if ($argv[3] === 'clear') { \App\Support\QueryCache::delete($argv[2]); }
CODE;
    shell_exec(sprintf('%s -r %s %s %s %s 2>/dev/null', $php, escapeshellarg($code), escapeshellarg($root), escapeshellarg($key), escapeshellarg($op)));
};

/** A brand-new process whose first probe fails; it never touches the cache itself. */
$probe = static function () use ($root, $php): string {
    $code = <<<'CODE'
require $argv[1] . '/vendor/autoload.php';
if (!function_exists('__')) { function __(string $t, mixed ...$a): string { return $a ? vsprintf($t, $a) : $t; } }
$db = mysqli_init();          // never connected: every query on it fails
echo \App\Support\BookVisibility::hasDesiderata($db) ? 'present' : 'absent';
echo '|' . \App\Support\BookVisibility::catalogue($db, 'l');
echo '|' . (\App\Support\BookVisibility::hasCataloguedAt($db) ? 'present' : 'absent');
echo '|' . implode('', \App\Support\BookVisibility::catalogueBirth($db));
CODE;
    return trim((string) shell_exec(sprintf('%s -r %s %s 2>/dev/null', $php, escapeshellarg($code), escapeshellarg($root))));
};

$desiderataKey = 'visibility_desiderata_column_seen';
$cataloguedKey = 'visibility_catalogued_at_column_seen';

echo "A fresh worker whose first probe fails, after another worker saw the columns\n";

$cacheOp($desiderataKey, 'seed');
$cacheOp($cataloguedKey, 'seed');
[$wanted, $predicate, $stamped, $birth] = explode('|', $probe() . '|||');
$check($wanted === 'present', 'answers "present" for is_desiderata');
$check($predicate === 'l.is_desiderata = 0', 'so the catalogue keeps filtering the wish list out (' . $predicate . ')');
$check($stamped === 'present', 'answers "present" for catalogued_at as well');
$check($birth === ', catalogued_at, NOW()', 'so a holding created by that worker still carries its stamp (' . $birth . ')');

echo "\nA fresh worker whose first probe fails, when no worker has ever seen them\n";

$cacheOp($desiderataKey, 'clear');
$cacheOp($cataloguedKey, 'clear');
[$wanted, $predicate, $stamped, $birth] = explode('|', $probe() . '|||');
$check($wanted === 'absent', 'still answers "absent" — the guess an installation without the plugin needs');
$check($predicate === '1=1', 'and emits no predicate on a column that may not exist (' . $predicate . ')');
$check($stamped === 'absent' && $birth === '', 'and writes no catalogued_at column into an INSERT');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
