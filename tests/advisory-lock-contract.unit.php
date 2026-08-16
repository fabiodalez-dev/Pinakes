<?php
declare(strict_types=1);

/**
 * Cross-component advisory-lock regression guards.
 *
 * These checks are intentionally database-free: they exercise the pure book
 * identifier name builder and bind it to the production acquire/release call
 * sites, while guarding the best-effort cleanup contracts used during upgrade.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$controllerClass = new ReflectionClass(\App\Controllers\LibriController::class);
$lockNames = $controllerClass->getMethod('bookIdentifierLockNames');

/** @return list<string> */
$namesFor = static function (string $database, array $codes) use ($lockNames): array {
    /** @var list<string> $names */
    $names = $lockNames->invoke(null, $database, $codes);
    return $names;
};

echo "A. Book identifier lock identity and overlap\n";
$isbnOnly = $namesFor('pinakes_' . str_repeat('x', 55), ['isbn13' => '9781234567897']);
$eanOnly = $namesFor('pinakes_' . str_repeat('x', 55), ['ean' => '9781234567897']);
$lowercaseCheckDigit = $namesFor('pinakes_' . str_repeat('x', 55), ['isbn10' => '123456789x']);
$uppercaseCheckDigit = $namesFor('pinakes_' . str_repeat('x', 55), ['isbn10' => '123456789X']);
$mixed = $namesFor('pinakes_' . str_repeat('x', 55), [
    'isbn10' => '123456789X',
    'ean' => '9781234567897',
]);
$otherSchema = $namesFor('another_schema', ['isbn13' => '9781234567897']);

$check($isbnOnly === $eanOnly, 'the same value maps to the same lock regardless of ISBN-13/EAN field');
$check($lowercaseCheckDigit === $uppercaseCheckDigit, 'ISBN-10 x/X variants share the database-equivalent lock');
$check(count(array_intersect($isbnOnly, $mixed)) === 1, 'partially overlapping identifier sets share a lock');
$check($isbnOnly !== $otherSchema, 'independent schemas use different lock names');
$check(
    $mixed !== [] && count($mixed) === count(array_unique($mixed))
        && max(array_map('strlen', $mixed)) <= 64,
    'every per-identifier lock is unique and within the MySQL 64-character limit'
);

echo "B. Production create/update protocol\n";
$libriSource = (string) file_get_contents($root . '/app/Controllers/LibriController.php');
$check(
    substr_count($libriSource, '$this->acquireBookIdentifierLocks($db, $codes)') === 2
        && !str_contains($libriSource, "'book_create_'")
        && !str_contains($libriSource, "'book_update_'"),
    'store and update use the same per-identifier acquire protocol'
);
$check(
    str_contains($libriSource, 'sort($values, SORT_STRING)')
        && str_contains($libriSource, "'pinakes-book-id:' . md5(\$databaseName . \"\\0\" . \$value)"),
    'multi-lock acquisition has a deterministic order and schema-scoped bounded names'
);
$check(
    str_contains($libriSource, '$this->releaseBookIdentifierLocks($db, $acquired)')
        && substr_count($libriSource, '$this->releaseBookIdentifierLocks($db, $lockKeys)') >= 4,
    'partial acquisition failures and every store/update exit release held locks'
);

echo "C. Upgrade and plugin cleanup contracts\n";
$backfillSource = (string) file_get_contents($root . '/app/Support/ContributorBackfill.php');
$runPos = strpos($backfillSource, 'public static function run(');
$tryPos = $runPos === false ? false : strpos($backfillSource, 'try {', $runPos);
$databasePos = $runPos === false ? false : strpos($backfillSource, "query('SELECT DATABASE()')", $runPos);
$catchPos = $runPos === false ? false : strpos($backfillSource, 'catch (\\Throwable $e)', $runPos);
$check(
    $tryPos !== false && $databasePos !== false && $catchPos !== false
        && $tryPos < $databasePos && $databasePos < $catchPos,
    'ContributorBackfill database-name lookup is inside its never-throws boundary'
);
$check(
    str_contains($backfillSource, 'catch (\\Throwable $releaseError)')
        && str_contains($backfillSource, 'ContributorBackfill lock release failed'),
    'ContributorBackfill release cleanup cannot escape its finally block'
);

$bookClubSource = (string) file_get_contents($root . '/storage/plugins/book-club/src/Repo.php');
$check(
    substr_count($bookClubSource, '$this->scopedPublisherLockName()') === 1
        && str_contains($bookClubSource, '$this->acquirePublisherLock($lockName)')
        && str_contains($bookClubSource, '$this->releasePublisherLock($lockName)'),
    'Book Club computes one lock name and passes it unchanged to acquire/release'
);

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
