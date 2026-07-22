<?php
declare(strict_types=1);

/**
 * Behavioural coverage for the #138 (Uwe round 3) book-club fixes:
 *
 *  A. External-book reconciliation — when a proposed external book is already
 *     in the catalogue (same ISBN, because the manager bought it and added it
 *     manually), `book.save.after` links it immediately. Scheduled maintenance
 *     backfills import paths that bypass the hook. The explicit "Add to
 *     catalogue" fallback also reuses an existing match.
 *     Edge cases: isbn13/isbn10, idempotency, no match, a duplicate club entry,
 *     no-ISBN create, and an ISBN still owned by a SOFT-DELETED row.
 *
 *  B. Repo::neverChosenProposals() — the "proposed but never chosen" archive.
 *     Edge cases: excluded once it wins a later closed poll; books currently in
 *     voting stay out; an entry-state proposal with no recorded poll is included
 *     for pre-Pinakes history; repeated losses are counted once per poll.
 *
 * Runs against the LIVE local MySQL, seeds rows under a unique token, and
 * cleans everything up (FK-safe) at the end.
 *
 * Run:  php tests/bookclub-acquire-reconcile-poll-history.unit.php
 */

$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';

use App\Plugins\BookClub\BookClubPlugin;
use App\Plugins\BookClub\Repo;
use App\Support\HookManager;

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

// ci-quality imports only the core installer schema. Make this test hermetic:
// install/upgrade the bundled plugin schema before seeding Book Club rows,
// exactly as the real plugin lifecycle does. The operation is idempotent, so
// local databases where the plugin is already active are left intact.
$plugin = new BookClubPlugin($db, new HookManager($db));
$schema = $plugin->ensureSchema();
if ($schema['failed'] !== []) {
    fwrite(STDERR, 'FAIL: Book Club test schema could not be prepared: ' . implode(', ', $schema['failed']) . "\n");
    exit(1);
}

$TOKEN = 'zzbc138_' . bin2hex(random_bytes(4));
$repo  = new Repo($db);

$pass = 0; $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$q = static fn (string $sql): mysqli_result|bool => $db->query($sql);
$scalar = static function (string $sql) use ($db) {
    $r = $db->query($sql);
    $row = $r instanceof mysqli_result ? $r->fetch_row() : null;
    return $row[0] ?? null;
};

// Track created libri ids so we can clean up whatever the acquire create-path made.
$createdLibri = [];

try {
    // ── Seed a sandbox club ──────────────────────────────────────────────────
    $q("INSERT INTO bookclub_clubs (ics_token, name, slug) VALUES ('" . bin2hex(random_bytes(16)) . "', '{$TOKEN} Club', '{$TOKEN}-club')");
    $clubId = (int) $db->insert_id;

    // Existing catalogue books the external proposals will reconcile against.
    $isbn13 = '9780000' . random_int(100000, 999999); // 13 digits
    $isbn10 = (string) random_int(100000000, 999999999) . 'X'; // 9 digits + X
    $externalIsbn10 = substr($isbn10, 0, 3) . '-' . substr($isbn10, 3, 3) . '-' . strtolower(substr($isbn10, 6));
    $autoIsbn13 = '9783333' . random_int(100000, 999999);
    $backfillIsbn13 = '9784444' . random_int(100000, 999999);
    $delIsbn13 = '9781111' . random_int(100000, 999999);

    $q("INSERT INTO libri (titolo, isbn13) VALUES ('{$TOKEN} Cat13', '{$isbn13}')");
    $catLibro13 = (int) $db->insert_id; $createdLibri[] = $catLibro13;
    $q("INSERT INTO libri (titolo, isbn10) VALUES ('{$TOKEN} Cat10', '{$isbn10}')");
    $catLibro10 = (int) $db->insert_id; $createdLibri[] = $catLibro10;
    $q("INSERT INTO libri (titolo, isbn13) VALUES ('{$TOKEN} CatAuto', '{$autoIsbn13}')");
    $catLibroAuto = (int) $db->insert_id; $createdLibri[] = $catLibroAuto;
    $q("INSERT INTO libri (titolo, isbn13) VALUES ('{$TOKEN} CatBackfill', '{$backfillIsbn13}')");
    $catLibroBackfill = (int) $db->insert_id; $createdLibri[] = $catLibroBackfill;
    // A soft-deleted catalogue book whose isbn13 is nulled on delete (per the
    // soft-delete rule) — seed it deleted WITH the isbn kept to prove we still
    // don't reconcile against deleted_at != NULL rows.
    $q("INSERT INTO libri (titolo, isbn13, deleted_at) VALUES ('{$TOKEN} Deleted', '{$delIsbn13}', NOW())");
    $delLibro = (int) $db->insert_id; $createdLibri[] = $delLibro;

    /** Seed an external proposal + its bookclub_books row. Returns [clubBookId, extId]. */
    $seedExternal = static function (string $title, ?string $isbn) use ($db, $clubId): array {
        $isbnSql = $isbn === null ? 'NULL' : "'" . $db->real_escape_string($isbn) . "'";
        $db->query("INSERT INTO bookclub_external_books (club_id, titolo, isbn) VALUES ({$clubId}, '" . $db->real_escape_string($title) . "', {$isbnSql})");
        $extId = (int) $db->insert_id;
        $db->query("INSERT INTO bookclub_books (club_id, external_book_id, state) VALUES ({$clubId}, {$extId}, 'proposed')");
        return [(int) $db->insert_id, $extId];
    };

    echo "A. automatic and explicit external reconciliation (#138 bug)\n";

    // A1: the catalogue-save hook links an existing ISBN immediately and is
    // idempotent if the same save notification is delivered again.
    [$cbAuto, $extAuto] = $seedExternal("{$TOKEN} Prop Auto", $autoIsbn13);
    $libriBeforeAuto = (int) $scalar('SELECT COUNT(*) FROM libri');
    $plugin->onCatalogueBookSaved($catLibroAuto);
    $check((int) $scalar("SELECT libro_id FROM bookclub_books WHERE id={$cbAuto}") === $catLibroAuto, 'A1 book.save.after points the proposal to the existing catalogue book');
    $check($scalar("SELECT external_book_id FROM bookclub_books WHERE id={$cbAuto}") === null, 'A2 book.save.after clears external_book_id');
    $check((int) $scalar("SELECT acquired_libro_id FROM bookclub_external_books WHERE id={$extAuto}") === $catLibroAuto, 'A3 book.save.after stamps the external record');
    $check((int) $scalar('SELECT COUNT(*) FROM libri') === $libriBeforeAuto, 'A4 book.save.after creates no catalogue rows');
    $check($repo->reconcileExternalBooksForCatalogueBook($catLibroAuto) === 0, 'A5 book.save.after reconciliation is idempotent');

    // A6: scheduled maintenance catches catalogue imports that did not fire
    // book.save.after, without relying on a manager opening a GET page.
    [$cbBackfill, $extBackfill] = $seedExternal("{$TOKEN} Prop Backfill", $backfillIsbn13);
    $check($repo->reconcileAllExternalBooksWithCatalogue() === 1, 'A6 maintenance backfill reconciles a hook-bypassing import');
    $check((int) $scalar("SELECT libro_id FROM bookclub_books WHERE id={$cbBackfill}") === $catLibroBackfill, 'A7 maintenance backfill points to the existing catalogue book');
    $check((int) $scalar("SELECT acquired_libro_id FROM bookclub_external_books WHERE id={$extBackfill}") === $catLibroBackfill, 'A8 maintenance backfill stamps the external record');

    // Explicit fallback: isbn13 matches an existing catalogue book → link, no new libri row.
    [$cb1, $ext1] = $seedExternal("{$TOKEN} Prop A1", $isbn13);
    $libriBefore = (int) $scalar('SELECT COUNT(*) FROM libri');
    $res1 = $repo->acquireExternalBook($cb1);
    $libriAfter = (int) $scalar('SELECT COUNT(*) FROM libri');
    $check($res1 === $catLibro13, 'A9 explicit isbn13 match returns the EXISTING catalogue libro id (no duplicate)');
    $check($libriAfter === $libriBefore, 'A10 explicit isbn13 match inserts no new libri row');
    $check((int) $scalar("SELECT libro_id FROM bookclub_books WHERE id={$cb1}") === $catLibro13, 'A11 explicit acquisition repoints the club book');
    $check($scalar("SELECT external_book_id FROM bookclub_books WHERE id={$cb1}") === null, 'A12 explicit acquisition clears external_book_id');
    $check((int) $scalar("SELECT acquired_libro_id FROM bookclub_external_books WHERE id={$ext1}") === $catLibro13, 'A13 explicit acquisition stamps the external record');

    // A2: isbn10 match → link.
    [$cb2] = $seedExternal("{$TOKEN} Prop A2", $externalIsbn10);
    $res2 = $repo->acquireExternalBook($cb2);
    $check($res2 === $catLibro10, 'A14 formatted isbn10 with lowercase x matches the normalized catalogue ISBN');

    // A3: no ISBN → creates a NEW libri row.
    [$cb3] = $seedExternal("{$TOKEN} Prop A3 NoIsbn", null);
    $res3 = $repo->acquireExternalBook($cb3);
    $check(is_int($res3) && $res3 > 0 && !in_array($res3, [$catLibro13, $catLibro10, $catLibroAuto, $catLibroBackfill, $delLibro], true), 'A15 no-ISBN proposal creates a NEW libro');
    if (is_int($res3)) { $createdLibri[] = $res3; }
    $check((int) $scalar("SELECT COUNT(*) FROM copie WHERE libro_id={$res3}") === 1, 'A16 created libro gets one physical copy');

    // A4 (edge): the reconcile query's `deleted_at IS NULL` guard. This
    // intentionally inconsistent fixture keeps its ISBN after soft deletion;
    // production deletion clears unique identifiers, but old/manual data may
    // not. Acquisition must fail safely rather than resurrect/link the deleted
    // row. The external proposal must remain untouched for a manager to repair.
    [$cb4] = $seedExternal("{$TOKEN} Prop A4 Deleted", $delIsbn13);
    $res4 = $repo->acquireExternalBook($cb4);
    $check($res4 === null, 'A17 refuses an ISBN still owned by a soft-deleted book');
    $check((int) $scalar("SELECT external_book_id IS NOT NULL FROM bookclub_books WHERE id={$cb4}") === 1, 'A18 failed acquisition leaves the external proposal intact');

    // A5 (edge): ISBN matches nothing → creates new.
    [$cb5] = $seedExternal("{$TOKEN} Prop A5", '9782222' . random_int(100000, 999999));
    $res5 = $repo->acquireExternalBook($cb5);
    $check(is_int($res5) && !in_array($res5, [$catLibro13, $catLibro10, $catLibroAuto, $catLibroBackfill, $delLibro], true), 'A19 non-matching ISBN creates a new libro');
    if (is_int($res5)) { $createdLibri[] = $res5; }

    // If the same catalogue book is already a distinct row in this club, do
    // not merge ids: either may own polls, votes or reading history.
    [$cbDuplicate, $extDuplicate] = $seedExternal("{$TOKEN} Prop Duplicate", $isbn10);
    $check($repo->reconcileExternalBooksWithCatalogue($clubId) === 0, 'A20 automatic pass refuses an ambiguous same-club duplicate');
    $check((int) $scalar("SELECT external_book_id FROM bookclub_books WHERE id={$cbDuplicate}") === $extDuplicate, 'A21 ambiguous proposal remains external with its history intact');

    echo "B. neverChosenProposals — proposed-but-not-chosen archive (#138 feature)\n";

    // Seed club books (catalogue-side, simplest) to be poll options.
    $mkClubBook = static function (string $title, string $state = 'proposed') use ($db, $clubId, &$createdLibri): int {
        $db->query("INSERT INTO libri (titolo) VALUES ('" . $db->real_escape_string($title) . "')");
        $libroId = (int) $db->insert_id; $createdLibri[] = $libroId;
        $db->query("INSERT INTO bookclub_books (club_id, libro_id, state) VALUES ({$clubId}, {$libroId}, '" . $db->real_escape_string($state) . "')");
        return (int) $db->insert_id;
    };
    $bLoser   = $mkClubBook("{$TOKEN} Loser");       // in closed poll, never wins
    $bWinner  = $mkClubBook("{$TOKEN} Winner");      // wins a closed poll
    $bLateWin = $mkClubBook("{$TOKEN} LateWinner");  // loses one, wins a later one
    $bOpen    = $mkClubBook("{$TOKEN} OpenOnly", 'voting'); // currently in an OPEN poll
    $bTwice   = $mkClubBook("{$TOKEN} TwiceLoser");  // loses two closed polls
    $bLegacy  = $mkClubBook("{$TOKEN} Legacy");      // pre-Pinakes proposal, no poll rows
    $bDeleted = $mkClubBook("{$TOKEN} DeletedClubBook");
    $bDeletedLibro = (int) $scalar("SELECT libro_id FROM bookclub_books WHERE id={$bDeleted}");
    $q("UPDATE libri SET deleted_at=NOW() WHERE id={$bDeletedLibro}");

    $mkPoll = static function (string $status, ?int $winner) use ($db, $clubId): int {
        $w = $winner === null ? 'NULL' : (string) $winner;
        $db->query("INSERT INTO bookclub_polls (club_id, title, mode, status, winner_club_book_id, created_at) VALUES ({$clubId}, 'P', 'simple', '{$status}', {$w}, NOW())");
        return (int) $db->insert_id;
    };
    $opt = static function (int $pollId, int $clubBookId) use ($db): void {
        $db->query("INSERT INTO bookclub_poll_options (poll_id, club_book_id) VALUES ({$pollId}, {$clubBookId})");
    };

    // Closed poll 1: winner=bWinner; options: bWinner, bLoser, bLateWin, bTwice.
    $p1 = $mkPoll('closed', $bWinner);
    $opt($p1, $bWinner); $opt($p1, $bLoser); $opt($p1, $bLateWin); $opt($p1, $bTwice);
    // Closed poll 2: winner=bLateWin; options: bLateWin, bTwice.
    $p2 = $mkPoll('closed', $bLateWin);
    $opt($p2, $bLateWin); $opt($p2, $bTwice);
    // Open poll: options bOpen (must NOT count).
    $p3 = $mkPoll('open', null);
    $opt($p3, $bOpen); $opt($p3, $bLoser);

    $never = $repo->neverChosenProposals($clubId, 'proposed');
    $byId = [];
    foreach ($never as $row) { $byId[(int) $row['club_book_id']] = $row; }

    $check(isset($byId[$bLoser]),    'B1 a book that lost a closed poll and never won appears');
    $check(!isset($byId[$bWinner]),  'B2 the winner is excluded');
    $check(!isset($byId[$bLateWin]), 'B3 a book that lost early but won later is excluded');
    $check(!isset($byId[$bOpen]),    'B4 a book currently in the voting state is excluded');
    $check(isset($byId[$bTwice]),    'B5 a two-time loser appears');
    $check(isset($byId[$bTwice]) && (int) $byId[$bTwice]['times_in_poll'] === 2, 'B6 two-time loser counts times_in_poll=2 (deduped per book)');
    $check(isset($byId[$bLoser]) && (int) $byId[$bLoser]['times_in_poll'] === 1, 'B7 one-time loser counts times_in_poll=1 (open poll not counted)');
    $check(isset($byId[$bLegacy]) && (int) $byId[$bLegacy]['times_in_poll'] === 0, 'B8 an entry-state proposal with no poll history is included for legacy backfill');
    $check(!isset($byId[$bDeleted]), 'B9 a soft-deleted catalogue book is omitted instead of rendering a blank archive row');

    // B10 (edge): a club with no proposals yields nothing.
    $q("INSERT INTO bookclub_clubs (ics_token, name, slug) VALUES ('" . bin2hex(random_bytes(16)) . "', '{$TOKEN} Empty', '{$TOKEN}-empty')");
    $emptyClub = (int) $db->insert_id;
    $check($repo->neverChosenProposals($emptyClub, 'proposed') === [], 'B10 a club with no proposals returns an empty archive');

    echo "C. closed-poll history — discoverability (#138 feature)\n";
    $clubView = (string) file_get_contents($root . '/storage/plugins/book-club/views/public/show.php');
    $pollsView = (string) file_get_contents($root . '/storage/plugins/book-club/views/public/polls.php');
    $check(str_contains($clubView, "__('Votazioni chiuse')") && str_contains($clubView, '$closedPolls'), 'C1 club page exposes an explicit closed-poll history');
    $check(str_contains($pollsView, "__('Votazioni chiuse')") && str_contains($pollsView, '$closedPolls'), 'C2 dedicated polls page exposes closed polls separately from active polls');
    $publicController = (string) file_get_contents($root . '/storage/plugins/book-club/src/PublicController.php');
    $mobileController = (string) file_get_contents($root . '/storage/plugins/book-club/src/MobileApiController.php');
    $pollController = (string) file_get_contents($root . '/storage/plugins/book-club/src/PollController.php');
    $pluginSource = (string) file_get_contents($root . '/storage/plugins/book-club/BookClubPlugin.php');
    $check(!str_contains($publicController, 'reconcileExternalBooksWithCatalogue')
        && !str_contains($publicController, 'closeExpiredForClub'), 'C3 public club GETs contain no reconciliation or poll-close writes');
    $check(!str_contains($mobileController, 'closeExpiredForClub')
        && !str_contains($pollController, 'closeExpiredForClub'), 'C4 web and mobile poll GETs contain no lazy-close writes');
    $check(str_contains($pluginSource, "registerHookInDb('book.save.after', 'onCatalogueBookSaved', 10)")
        && str_contains($pluginSource, 'reconcileExternalBooksForCatalogueBook($bookId)'), 'C5 book.save.after is the event-driven reconciliation path');
    $check(str_contains($pluginSource, 'reconcileAllExternalBooksWithCatalogue()')
        && str_contains($pluginSource, 'closeExpiredPolls()'), 'C6 scheduled maintenance backfills reconciliation and closes expired polls');
    $check(str_contains($pollController, 'first attempted ballot performs the same idempotent close')
        && str_contains($pollController, '$this->resolvePoll($poll, true);'), 'C7 expired vote POST is the immediate poll-close fallback');
} finally {
    // ── Cleanup (FK-safe) ────────────────────────────────────────────────────
    $db->query("DELETE FROM bookclub_poll_options WHERE poll_id IN (SELECT id FROM bookclub_polls WHERE club_id IN (SELECT id FROM bookclub_clubs WHERE slug LIKE '{$TOKEN}-%'))");
    $db->query("DELETE FROM bookclub_polls WHERE club_id IN (SELECT id FROM bookclub_clubs WHERE slug LIKE '{$TOKEN}-%')");
    $db->query("DELETE FROM bookclub_books WHERE club_id IN (SELECT id FROM bookclub_clubs WHERE slug LIKE '{$TOKEN}-%')");
    $db->query("DELETE FROM bookclub_external_books WHERE club_id IN (SELECT id FROM bookclub_clubs WHERE slug LIKE '{$TOKEN}-%')");
    $db->query("DELETE FROM bookclub_clubs WHERE slug LIKE '{$TOKEN}-%'");
    if ($createdLibri !== []) {
        $ids = implode(',', array_map('intval', array_unique($createdLibri)));
        $db->query("DELETE FROM copie WHERE libro_id IN ({$ids})");
        $db->query("DELETE FROM libri_autori WHERE libro_id IN ({$ids})");
        $db->query("DELETE FROM libri_editori WHERE libro_id IN ({$ids})");
        $db->query("DELETE FROM libri WHERE id IN ({$ids})");
    }
    // Orphan authors/publishers seeded by the create path (name-tokened).
    $db->query("DELETE FROM autori WHERE nome LIKE '{$TOKEN}%'");
    $db->query("DELETE FROM editori WHERE nome LIKE '{$TOKEN}%'");
    $db->close();
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
