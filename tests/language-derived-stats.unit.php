<?php
declare(strict_types=1);

/**
 * Behavioural test for Language::getAllWithDerivedStats() / sourceKeyCount() /
 * translatedKeyCount() — the dynamic i18n completion model.
 *
 * Every __() key IS the Italian string, so Italian is the translation source and
 * its key count is the canonical denominator for every locale. These stats are
 * derived live from the locale files instead of the stored (stale) columns, so
 * they never drift and need no migration when keys are added.
 *
 * Asserts:
 *   - sourceKeyCount() equals the real it_IT.json key count,
 *   - getAllWithDerivedStats() gives EVERY locale the same total_keys (= source),
 *   - the source locale is 100% complete by definition,
 *   - completion_percentage is recomputed as translated/total,
 *   - translatedKeyCount() caps at the source total, ignores empty values,
 *     treats the source as complete, and returns 0 for a missing file.
 *
 * Run:  php tests/language-derived-stats.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Models\Language;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

// --- DB connection (same pattern as the other .unit.php tests) ---
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) { continue; }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
try {
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable: {$e->getMessage()}\n");
    exit(1);
}

$lang = new Language($db);
$srcPath = $root . '/locale/' . Language::SOURCE_LOCALE . '.json';
$srcCount = count(json_decode((string) file_get_contents($srcPath), true, 512, JSON_THROW_ON_ERROR));

// Reflection handle for the private translatedKeyCount().
$ref = new ReflectionMethod(Language::class, 'translatedKeyCount');
$ref->setAccessible(true);
$translated = static fn (string $code, int $total): int => $ref->invoke($lang, $code, $total);

echo "A. sourceKeyCount()\n";
$src = $lang->sourceKeyCount();
$check($src === $srcCount, "sourceKeyCount() == it_IT.json key count ({$srcCount})");
$check($src > 6000, "source key count is in the expected range (>6000): {$src}");
$check($lang->sourceKeyCount() === $src, 'sourceKeyCount() is stable across calls (static cache)');

echo "B. getAllWithDerivedStats() — total_keys linked to Italian for every locale\n";
$rows = $lang->getAllWithDerivedStats();
$check(count($rows) >= 5, 'returns all seeded locales (>=5)');

$allSameTotal = true;
$completionOk = true;
$translatedCapped = true;
$byCode = [];
foreach ($rows as $r) {
    $byCode[(string) $r['code']] = $r;
    if ((int) $r['total_keys'] !== $srcCount) { $allSameTotal = false; }
    if ((int) $r['translated_keys'] > (int) $r['total_keys']) { $translatedCapped = false; }
    $expectedPct = $srcCount > 0 ? round((int) $r['translated_keys'] / $srcCount * 100, 2) : 0.00;
    if (abs((float) $r['completion_percentage'] - $expectedPct) > 0.01) { $completionOk = false; }
}
$check($allSameTotal, "every locale's total_keys == source count ({$srcCount}) — no per-locale denominator");
$check($translatedCapped, 'translated_keys never exceeds total_keys for any locale');
$check($completionOk, 'completion_percentage == round(translated/total*100) for every locale');

echo "C. Source locale (Italian) is complete by definition\n";
$check(isset($byCode[Language::SOURCE_LOCALE]), 'source locale present in results');
if (isset($byCode[Language::SOURCE_LOCALE])) {
    $it = $byCode[Language::SOURCE_LOCALE];
    $check((int) $it['translated_keys'] === (int) $it['total_keys'], 'it_IT translated_keys == total_keys');
    $check((float) $it['completion_percentage'] === 100.00, 'it_IT completion is 100.00');
}

echo "D. translatedKeyCount() unit behaviour\n";
$check($translated(Language::SOURCE_LOCALE, $srcCount) === $srcCount, 'source locale counts as fully translated');
$check($translated('en_US', $srcCount) <= $srcCount, 'en_US translated <= source total (capped)');
$check($translated('en_US', $srcCount) > 6000, 'en_US is a well-covered locale (>6000)');
$check($translated('zz_NONEXISTENT', $srcCount) === 0, 'missing locale file → 0 translated');

echo "E. Temp locale: incomplete, empty values, and orphan-key cap\n";
$tmpCode = 'zz_ZZ';
$tmpPath = $root . '/locale/' . $tmpCode . '.json';
try {
    // 3 real translations + 2 empty (must not count) → 3 translated.
    file_put_contents($tmpPath, json_encode(
        ['a' => 'x', 'b' => 'y', 'c' => 'z', 'd' => '', 'e' => ''],
        JSON_UNESCAPED_UNICODE
    ));
    $check($translated($tmpCode, $srcCount) === 3, 'empty values are not counted (3 of 5)');

    // A partial translation is below 100%.
    $partial = $translated($tmpCode, $srcCount);
    $pct = round($partial / $srcCount * 100, 2);
    $check($pct < 100.00, "partial locale completion is below 100% ({$pct}%)");

    // Orphan keys beyond the source total must not push the count past the cap.
    $big = [];
    for ($i = 0; $i < $srcCount + 50; $i++) { $big["k{$i}"] = 'v'; }
    file_put_contents($tmpPath, json_encode($big, JSON_UNESCAPED_UNICODE));
    $check($translated($tmpCode, $srcCount) === $srcCount, 'orphan keys cannot exceed the source total (cap at 100%)');
} finally {
    if (is_file($tmpPath)) { @unlink($tmpPath); }
}

$db->close();
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
