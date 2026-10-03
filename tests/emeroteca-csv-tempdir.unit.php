<?php
declare(strict_types=1);
/**
 * A broken temporary directory must be reported as such.
 *
 * php://temp keeps up to 2 MB in memory and spills the rest to a file in the
 * system temp directory. When that directory is missing or unwritable the write
 * stores nothing, and the CSV parser used to report "CSV vuoto" — blaming the
 * operator's file for a broken server. It cost a release gate an afternoon:
 * reinstall-test.sh exported TMPDIR before creating it, so every >2 MB import
 * inside the gate looked like an empty upload.
 *
 * Isolated in its own process on purpose: PHP resolves the temp directory once
 * and caches it, so poisoning it inside a larger suite would break every later
 * spill in that run.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/src/Services/ContributionService.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/src/Services/ContributionCsv.php';

use App\Plugins\Emeroteca\Services\ContributionCsv;
use App\Plugins\Emeroteca\Services\ContributionService;

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

putenv('TMPDIR=/pinakes-nonexistent-' . bin2hex(random_bytes(4)));
// No connection is needed: the failure happens while buffering the upload,
// before the first query.
$csv = new ContributionCsv(new ContributionService(new mysqli()));

$payload = "titolo\n" . str_repeat('Riga lunga ' . str_repeat('x', 200) . "\n", 12000);
$check(strlen($payload) > 2 * 1024 * 1024, 'the probe payload is large enough to spill to disk (' . strlen($payload) . ' bytes)');

try {
    $csv->preview($payload);
    $check(false, 'a broken temporary directory must not pass silently');
} catch (\RuntimeException $e) {
    $check(
        str_contains($e->getMessage(), 'temporary directory'),
        'the failure names the temporary directory: ' . $e->getMessage()
    );
} catch (\InvalidArgumentException $e) {
    $check(false, 'the broken temporary directory was blamed on the uploaded file: ' . $e->getMessage());
}

// A payload that stays in memory is unaffected by the broken directory, so the
// guard cannot be rejecting ordinary imports. Header only: parsing it never
// reaches the database, which this process deliberately does not have.
$check($csv->preview("titolo\n") === [], 'a small CSV still parses with the same broken directory');

echo "\n$passed PASS, $failed FAIL\n";
exit($failed ? 1 : 0);
