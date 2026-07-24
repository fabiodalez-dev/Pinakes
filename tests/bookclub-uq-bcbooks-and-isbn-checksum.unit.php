<?php
declare(strict_types=1);

/**
 * Behavioural coverage for two #138 (Uwe round 3) CodeRabbit fixes:
 *
 *  A. Repo::normalizedIsbnParts() now validates the ISBN CHECKSUM, not just the
 *     digit length. A 13/10-length run of arbitrary digits used to become a
 *     reconcile match key, so two unrelated books that merely shared a
 *     malformed code could be linked. The parts must now be null unless the
 *     checksum is valid (IsbnFormatter::isValidIsbn13 / isValidIsbn10).
 *
 *  B. BookClubPlugin::ensureBookclubBooksExternalSupport() now self-heals the
 *     `uq_bcbooks (club_id, libro_id)` unique key on the upgrade path (it only
 *     lived in CREATE TABLE before). Crucially it REFUSES to add the key when
 *     duplicate (club_id, libro_id) rows exist — dropping a bookclub_books row
 *     cascades into its polls/votes/discussions/reading history — logging and
 *     leaving the rows intact instead of destroying data.
 *
 * This exercises the REAL private methods (via reflection) against the LIVE
 * local MySQL. Part B temporarily drops uq_bcbooks on the real bookclub_books
 * table and ALWAYS restores it in a finally block, even on failure. Everything
 * seeded is namespaced by a unique token and cleaned up FK-safe.
 *
 * Run:  php tests/bookclub-uq-bcbooks-and-isbn-checksum.unit.php
 */

$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';

use App\Plugins\BookClub\BookClubPlugin;
use App\Plugins\BookClub\Repo;
use App\Support\HookManager;
use App\Support\IsbnFormatter;

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k); $v = trim($v);
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
        $v = substr($v, 1, -1);
    }
    $env[$k] = $v;
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$password = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$database = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $password, $database, 0, $socket)
        : new mysqli($host, $user, $password, $database, $port);
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

// Make the test hermetic: install/upgrade the bundled plugin schema exactly as
// the real plugin lifecycle does. Idempotent, so an already-active local DB is
// left intact and ends with uq_bcbooks present.
$plugin = new BookClubPlugin($db, new HookManager($db));
$schema = $plugin->ensureSchema();
if ($schema['failed'] !== []) {
    fwrite(STDERR, 'FAIL: Book Club test schema could not be prepared: ' . implode(', ', $schema['failed']) . "\n");
    exit(1);
}

$TOKEN = 'zzuqbc_' . bin2hex(random_bytes(4));
$pass = 0; $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else { $fail++; echo "  FAIL {$label}\n"; }
};

$indexExists = static function (string $index) use ($db): bool {
    $r = $db->query(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookclub_books'
            AND INDEX_NAME = '" . $db->real_escape_string($index) . "' LIMIT 1"
    );
    return ($r instanceof mysqli_result) && $r->num_rows > 0;
};

// ── Part A. ISBN checksum gate in normalizedIsbnParts ────────────────────────
echo "A. normalizedIsbnParts validates the checksum\n";
$repo = new Repo($db);
$rp = new ReflectionMethod(Repo::class, 'normalizedIsbnParts');
$rp->setAccessible(true);
$parts = static fn(string $isbn): array => $rp->invoke($repo, $isbn);

// Sanity-check the fixtures against the validator so the assertions below are
// grounded in genuinely (in)valid codes, not my hand-computed guesses.
$check(IsbnFormatter::isValidIsbn13('9780306406157'), 'fixture 9780306406157 is a valid ISBN-13');
$check(IsbnFormatter::isValidIsbn10('0306406152'), 'fixture 0306406152 is a valid ISBN-10');
$check(!IsbnFormatter::isValidIsbn13('1111111111111'), 'fixture 1111111111111 is NOT a valid ISBN-13');
$check(!IsbnFormatter::isValidIsbn10('1234567890'), 'fixture 1234567890 is NOT a valid ISBN-10');

$check($parts('9780306406157') === ['9780306406157', null], 'valid ISBN-13 kept in the 13 slot');
$check($parts('978-0-306-40615-7') === ['9780306406157', null], 'valid ISBN-13 kept after stripping hyphens/spaces');
$check($parts('1111111111111') === [null, null], 'garbage 13-digit run rejected (bug fixed)');
$check($parts('0306406152') === [null, '0306406152'], 'valid ISBN-10 kept in the 10 slot');
$check($parts('1234567890') === [null, null], 'garbage 10-digit run rejected (bug fixed)');
$check($parts('') === [null, null], 'empty string yields no parts');
$check($parts('978030640615') === [null, null], '12-digit near-miss yields no parts');

// ── Part B. uq_bcbooks self-heal + duplicate-safe refusal ────────────────────
echo "B. ensureBookclubBooksExternalSupport self-heals uq_bcbooks\n";
$rm = new ReflectionMethod(BookClubPlugin::class, 'ensureBookclubBooksExternalSupport');
$rm->setAccessible(true);
$runMigration = static function () use ($rm, $plugin): void { $rm->invoke($plugin); };

$dupClubId = null; $dupLibroId = null;

// Guarantee the live table is restored to a correct state no matter what.
$restore = static function () use ($db, $indexExists, &$dupClubId, &$dupLibroId): void {
    // Remove any duplicate seed rows first so ADD UNIQUE can succeed.
    if ($dupClubId !== null) {
        $db->query("DELETE FROM bookclub_books WHERE club_id = " . (int) $dupClubId);
    }
    if (!$indexExists('uq_bcbooks')) {
        $db->query("ALTER TABLE bookclub_books ADD UNIQUE KEY uq_bcbooks (club_id, libro_id)");
    }
    if ($dupLibroId !== null) {
        $db->query("DELETE FROM libri WHERE id = " . (int) $dupLibroId);
    }
    if ($dupClubId !== null) {
        $db->query("DELETE FROM bookclub_clubs WHERE id = " . (int) $dupClubId);
    }
};

try {
    $check($indexExists('uq_bcbooks'), 'baseline: uq_bcbooks present after ensureSchema()');

    // B1 — add path: drop the key (no dupes can exist, it was enforcing them),
    // run the real migration, expect it re-added.
    $db->query("ALTER TABLE bookclub_books DROP INDEX uq_bcbooks");
    $check(!$indexExists('uq_bcbooks'), 'setup: uq_bcbooks dropped');
    $runMigration();
    $check($indexExists('uq_bcbooks'), 'migration re-adds uq_bcbooks when missing and no dupes');
    $check(count($schema['failed']) === 0, 'migration ran without touching failed[]');

    // B2 — refuse path: drop again, seed a club + libro, insert TWO rows with
    // the same (club_id, libro_id), run migration, expect it to REFUSE (key
    // stays absent) and to PRESERVE both rows.
    $db->query("ALTER TABLE bookclub_books DROP INDEX uq_bcbooks");
    $db->query("INSERT INTO bookclub_clubs (ics_token, name, slug) VALUES ('" . bin2hex(random_bytes(16)) . "', '{$TOKEN} Club', '{$TOKEN}-club')");
    $dupClubId = (int) $db->insert_id;
    $db->query("INSERT INTO libri (titolo) VALUES ('" . $db->real_escape_string($TOKEN . ' Book') . "')");
    $dupLibroId = (int) $db->insert_id;
    $db->query("INSERT INTO bookclub_books (club_id, libro_id, state) VALUES ({$dupClubId}, {$dupLibroId}, 'proposed')");
    $db->query("INSERT INTO bookclub_books (club_id, libro_id, state) VALUES ({$dupClubId}, {$dupLibroId}, 'proposed')");
    $before = (int) $db->query("SELECT COUNT(*) c FROM bookclub_books WHERE club_id = {$dupClubId}")->fetch_assoc()['c'];
    $check($before === 2, 'setup: two duplicate (club_id, libro_id) rows inserted');

    $runMigration();
    $check(!$indexExists('uq_bcbooks'), 'migration REFUSES to add uq_bcbooks while duplicates exist');
    $after = (int) $db->query("SELECT COUNT(*) c FROM bookclub_books WHERE club_id = {$dupClubId}")->fetch_assoc()['c'];
    $check($after === 2, 'both duplicate rows preserved — no destructive dedupe');
} finally {
    $restore();
}

$check($indexExists('uq_bcbooks'), 'teardown: uq_bcbooks restored on the live table');
// Confirm the seed rows are gone (FK-safe cleanup succeeded).
$leftBooks = $dupClubId !== null
    ? (int) $db->query("SELECT COUNT(*) c FROM bookclub_books WHERE club_id = " . (int) $dupClubId)->fetch_assoc()['c']
    : 0;
$check($leftBooks === 0, 'teardown: seeded bookclub_books rows removed');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
