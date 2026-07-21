<?php
declare(strict_types=1);

/**
 * Behavioural coverage for the #138 (Uwe round 3) book-club fixes:
 *
 *  A. Repo::acquireExternalBook() reconcile-on-acquire — when a proposed
 *     external book is already in the catalogue (same ISBN, because the manager
 *     bought it and added it manually), "Add to catalogue" must LINK to the
 *     existing row instead of inserting a duplicate that violates libri's UNIQUE
 *     isbn13/isbn10 key (the "Acquisition failed" bug). Edge cases: isbn13 match,
 *     isbn10 match, no-ISBN → creates new, ISBN matching only a SOFT-DELETED row
 *     → creates new (never reuse a deleted book).
 *
 *  B. Repo::neverChosenProposals() — the "proposed but never chosen" archive.
 *     Edge cases: excluded once it wins a later closed poll; open polls don't
 *     count; a book in two closed polls appears once with times_in_poll=2; the
 *     winner is excluded; a club with no closed polls yields nothing.
 *
 * Runs against the LIVE local MySQL, seeds rows under a unique token, and
 * cleans everything up (FK-safe) at the end.
 *
 * Run:  php tests/bookclub-acquire-reconcile-poll-history.unit.php
 */

$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/book-club/src/Repo.php';

use App\Plugins\BookClub\Repo;

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
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

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
    $isbn10 = '0000' . random_int(100000, 999999);     // 10 digits
    $delIsbn13 = '9781111' . random_int(100000, 999999);

    $q("INSERT INTO libri (titolo, isbn13) VALUES ('{$TOKEN} Cat13', '{$isbn13}')");
    $catLibro13 = (int) $db->insert_id; $createdLibri[] = $catLibro13;
    $q("INSERT INTO libri (titolo, isbn10) VALUES ('{$TOKEN} Cat10', '{$isbn10}')");
    $catLibro10 = (int) $db->insert_id; $createdLibri[] = $catLibro10;
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

    echo "A. acquireExternalBook — reconcile-on-acquire (#138 bug)\n";

    // A1: isbn13 matches an existing catalogue book → link, no new libri row.
    [$cb1, $ext1] = $seedExternal("{$TOKEN} Prop A1", $isbn13);
    $libriBefore = (int) $scalar('SELECT COUNT(*) FROM libri');
    $res1 = $repo->acquireExternalBook($cb1);
    $libriAfter = (int) $scalar('SELECT COUNT(*) FROM libri');
    $check($res1 === $catLibro13, 'A1 isbn13 match returns the EXISTING catalogue libro id (no duplicate)');
    $check($libriAfter === $libriBefore, 'A1 no new libri row inserted (reconciled, not duplicated)');
    $check((int) $scalar("SELECT libro_id FROM bookclub_books WHERE id={$cb1}") === $catLibro13, 'A1 club book repointed to the existing libro');
    $check($scalar("SELECT external_book_id FROM bookclub_books WHERE id={$cb1}") === null, 'A1 club book external_book_id cleared');
    $check((int) $scalar("SELECT acquired_libro_id FROM bookclub_external_books WHERE id={$ext1}") === $catLibro13, 'A1 external record acquired_libro_id set to existing');

    // A2: isbn10 match → link.
    [$cb2] = $seedExternal("{$TOKEN} Prop A2", $isbn10);
    $res2 = $repo->acquireExternalBook($cb2);
    $check($res2 === $catLibro10, 'A2 isbn10 match returns the existing catalogue libro id');

    // A3: no ISBN → creates a NEW libri row.
    [$cb3] = $seedExternal("{$TOKEN} Prop A3 NoIsbn", null);
    $res3 = $repo->acquireExternalBook($cb3);
    $check(is_int($res3) && $res3 > 0 && !in_array($res3, [$catLibro13, $catLibro10, $delLibro], true), 'A3 no-ISBN proposal creates a NEW libro');
    if (is_int($res3)) { $createdLibri[] = $res3; }
    $check((int) $scalar("SELECT COUNT(*) FROM copie WHERE libro_id={$res3}") === 1, 'A3 created libro gets one physical copy');

    // A4 (edge): the reconcile query's `deleted_at IS NULL` guard. The
    // soft-deleted book keeps its isbn13 here so the query WOULD match it
    // without the guard — proving the guard is what keeps acquire from linking
    // to a deleted row (a live book with that isbn is the only valid target).
    [$cb4] = $seedExternal("{$TOKEN} Prop A4 Deleted", $delIsbn13);
    $res4 = $repo->acquireExternalBook($cb4);
    $check($res4 !== $delLibro, 'A4 does NOT reconcile to a soft-deleted book (deleted_at IS NULL guard)');
    if (is_int($res4)) { $createdLibri[] = $res4; }

    // A5 (edge): ISBN matches nothing → creates new.
    [$cb5] = $seedExternal("{$TOKEN} Prop A5", '9782222' . random_int(100000, 999999));
    $res5 = $repo->acquireExternalBook($cb5);
    $check(is_int($res5) && !in_array($res5, [$catLibro13, $catLibro10, $delLibro], true), 'A5 non-matching ISBN creates a new libro');
    if (is_int($res5)) { $createdLibri[] = $res5; }

    echo "B. neverChosenProposals — proposed-but-not-chosen archive (#138 feature)\n";

    // Seed club books (catalogue-side, simplest) to be poll options.
    $mkClubBook = static function (string $title) use ($db, $clubId, &$createdLibri): int {
        $db->query("INSERT INTO libri (titolo) VALUES ('" . $db->real_escape_string($title) . "')");
        $libroId = (int) $db->insert_id; $createdLibri[] = $libroId;
        $db->query("INSERT INTO bookclub_books (club_id, libro_id, state) VALUES ({$clubId}, {$libroId}, 'proposed')");
        return (int) $db->insert_id;
    };
    $bLoser   = $mkClubBook("{$TOKEN} Loser");       // in closed poll, never wins
    $bWinner  = $mkClubBook("{$TOKEN} Winner");      // wins a closed poll
    $bLateWin = $mkClubBook("{$TOKEN} LateWinner");  // loses one, wins a later one
    $bOpen    = $mkClubBook("{$TOKEN} OpenOnly");    // only in an OPEN poll
    $bTwice   = $mkClubBook("{$TOKEN} TwiceLoser");  // loses two closed polls

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

    $never = $repo->neverChosenProposals($clubId);
    $byId = [];
    foreach ($never as $row) { $byId[(int) $row['club_book_id']] = $row; }

    $check(isset($byId[$bLoser]),    'B1 a book that lost a closed poll and never won appears');
    $check(!isset($byId[$bWinner]),  'B2 the winner is excluded');
    $check(!isset($byId[$bLateWin]), 'B3 a book that lost early but won later is excluded');
    $check(!isset($byId[$bOpen]),    'B4 a book only in an OPEN poll is excluded');
    $check(isset($byId[$bTwice]),    'B5 a two-time loser appears');
    $check(isset($byId[$bTwice]) && (int) $byId[$bTwice]['times_in_poll'] === 2, 'B6 two-time loser counts times_in_poll=2 (deduped per book)');
    $check(isset($byId[$bLoser]) && (int) $byId[$bLoser]['times_in_poll'] === 1, 'B7 one-time loser counts times_in_poll=1 (open poll not counted)');

    // B8 (edge): a club with no closed polls yields nothing.
    $q("INSERT INTO bookclub_clubs (ics_token, name, slug) VALUES ('" . bin2hex(random_bytes(16)) . "', '{$TOKEN} Empty', '{$TOKEN}-empty')");
    $emptyClub = (int) $db->insert_id;
    $check($repo->neverChosenProposals($emptyClub) === [], 'B8 a club with no closed polls returns an empty archive');
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
