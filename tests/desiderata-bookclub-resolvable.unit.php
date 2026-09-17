<?php
declare(strict_types=1);

/**
 * A reading club must survive the librarian flagging one of its books as a
 * desiderata.
 *
 * The regression this guards against shipped in the first cut of the desiderata
 * feature and was invisible to every existing suite. The filter
 * `is_desiderata = 0` was appended to the JOIN condition over `libri`, on the
 * stated theory that a predicate placed there "only empties book_title". It
 * does not. On the LEFT JOIN in Repo::bookSelect() it nulls the whole `l.*`
 * projection, and the guard beside it —
 * "(l.id IS NOT NULL OR cb.external_book_id IS NOT NULL)", written to hide a
 * SOFT-DELETED book — then reads that as a missing row and drops the entry
 * entirely: clubBook() answered null and every route keyed on it (state
 * transitions, reading, polls, meetings, surveys, discussions, buddy reading,
 * quotes) returned 404. On the sibling repositories, where the same predicate
 * sat on an INNER JOIN, the row simply vanished from the listing.
 *
 * The resolution was to drop the filter rather than relocate it: `/desiderata`
 * is registered with no auth middleware and publishes the entire wish list —
 * titles, authors, publishers, covers — to anonymous visitors by design, so a
 * club showing the title of a book its members chose to read discloses nothing
 * the feature does not already publish itself. These checks therefore assert
 * that the title STAYS, which is the behaviour that was chosen, not the
 * behaviour the original comment promised.
 *
 * Everything the suite writes — the club book and its discussion section —
 * happens inside a transaction that is rolled back, so a development database
 * carrying real demo data is left exactly as it was found, including on a
 * fatal.
 *
 * Run:  php tests/desiderata-bookclub-resolvable.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';

use App\Support\BookVisibility;
use App\Support\HookManager;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

/** .env is parsed by hand: this file must run as a bare `php tests/...` process. */
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$db = is_string($socket) && $socket !== '' && file_exists($socket)
    ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
    : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');

$fatalError = null;
$transactionOpen = false;

try {
    // Built, never skipped. Without the column BookVisibility::catalogue()
    // degrades to the literal 1=1, and every assertion below would pass while
    // proving nothing at all about the filtering.
    (new DesiderataPlugin($db, new HookManager($db)))->ensureSchema();
    if (!BookVisibility::hasDesiderata($db)) {
        throw new RuntimeException('libri.is_desiderata is absent after ensureSchema(): these checks would be vacuous');
    }

    // The club tables are built the same way, through the plugin's own
    // idempotent ensureSchema(), so this suite does not depend on book-club
    // happening to be activated in the database it is pointed at — and does
    // not hand-roll a second copy of a schema that would then drift from the
    // real one.
    (new \App\Plugins\BookClub\BookClubPlugin($db, new HookManager($db)))->ensureSchema();
    foreach (['bookclub_clubs', 'bookclub_books', 'bookclub_sections'] as $table) {
        if ($db->query("SHOW TABLES LIKE '{$table}'")->num_rows === 0) {
            throw new RuntimeException("{$table} is absent after BookClubPlugin::ensureSchema()");
        }
    }

    $repo = new \App\Plugins\BookClub\Repo($db);
    $discussions = new \App\Plugins\BookClub\DiscussionRepo($db);

    $db->begin_transaction();
    $transactionOpen = true;

    // Club, book and entry are all seeded here rather than borrowed from the
    // database this happens to run against. A suite that needs pre-existing
    // demo rows passes on a developer's machine and fails on an empty CI
    // schema for a reason that has nothing to do with the code it guards.
    $db->query("INSERT INTO bookclub_clubs (slug, name, color, privacy, ics_token, is_active)
                VALUES ('zz-probe-club', 'ZZ probe club', '#336699', 'public', REPEAT('0', 32), 1)");
    $clubId = (int) $db->insert_id;

    $title = 'ZZ probe title ' . $clubId;
    $db->query("INSERT INTO libri (titolo, is_desiderata) VALUES ('{$title}', 0)");
    $libroId = (int) $db->insert_id;

    $db->query("INSERT INTO bookclub_books (club_id, libro_id, state, position)
                VALUES ({$clubId}, {$libroId}, 'reading', 1)");
    $clubBookId = (int) $db->insert_id;
    $db->query("INSERT INTO bookclub_sections (club_book_id, title, sort)
                VALUES ({$clubBookId}, 'Regression probe section', 1)");

    echo "A. Baseline — an ordinary holding\n";

    $before = $repo->clubBook($clubBookId);
    $check($before !== null, 'clubBook() resolves the entry');
    $check($before !== null && ($before['titolo'] ?? null) === $title, 'and carries its title');
    $check(count($discussions->clubSections($clubId)) >= 1, 'its discussion section is listed');

    echo "\nB. The librarian flags that same book as wanted rather than held\n";

    $db->query("UPDATE libri SET is_desiderata = 1 WHERE id = {$libroId}");

    $after = $repo->clubBook($clubBookId);
    $check($after !== null, 'clubBook() STILL resolves it — every route keyed on it would 404 otherwise');
    $check($after !== null && ($after['titolo'] ?? null) === $title,
        'the title is still there: the members have to see what they are reading');

    $ids = array_map(static fn(array $r): int => (int) $r['id'], $repo->clubBooks($clubId));
    $check(in_array($clubBookId, $ids, true), 'it is still listed among the club books');

    $sectionIds = array_map(static fn(array $r): int => (int) $r['id'], $discussions->clubSections($clubId));
    $check($sectionIds !== [], 'its discussion section survives the INNER JOIN over libri');

    echo "\nC. Soft deletion is a different matter, and must still hide the row\n";

    // The guard the desiderata filter was mistaken for is a real one. Proving
    // it still bites is what keeps this fix from having quietly removed it.
    $db->query("UPDATE libri SET is_desiderata = 0, deleted_at = NOW() WHERE id = {$libroId}");
    $check($repo->clubBook($clubBookId) === null, 'a soft-deleted catalogue book still makes the entry unresolvable');
} catch (\Throwable $error) {
    $fatalError = $error;
} finally {
    if ($transactionOpen) {
        try {
            $db->rollback();
        } catch (\Throwable $error) {
            fwrite(STDERR, "\nCLEANUP FAILED — fixture rows may still be in the database:\n    " . $error->getMessage() . "\n");
            $fail++;
        }
    }
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
