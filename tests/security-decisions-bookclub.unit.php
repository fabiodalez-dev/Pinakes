<?php
declare(strict_types=1);

/**
 * Security decision [94] of the 2026-11 hardening audit (book-club affinity).
 *
 * A member who left the club, or was suspended or banned, stayed in the other
 * members' affinity lists (name + score) until the daily recompute, and
 * leaving kept the opt-in row for a later re-join. Decision:
 *   - read time: AffinityRepo::affinitiesFor() lists only an ACTIVE, OPTED-IN
 *     counterpart;
 *   - on leave / suspension / ban (Repo::setMemberStatus(), the single write
 *     path behind the bookclub.member.left event, the admin desk and the app
 *     bridge): purge that member's pairs in both directions and their opt-in
 *     row, in the status change's transaction;
 *   - a club whose affinity tables do not exist still changes status.
 *
 * Drives the REAL Repo and AffinityRepo against session-local TEMPORARY
 * tables (nothing reaches the database's own tables).
 *
 * Run:  php tests/security-decisions-bookclub.unit.php
 * DB:   E2E_DB_* or .env; without a reachable DB it prints SKIP and exits 0
 *       (a failure under CI_STRICT_TESTS=1).
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';
require_once $root . '/storage/plugins/book-club/src/AffinityRepo.php';

use App\Plugins\BookClub\AffinityRepo;
use App\Plugins\BookClub\Repo;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$skip = static function (string $why): never {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: {$why} (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "SKIP: {$why}\n";
    exit(0);
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
if ($name === '' || $user === '') {
    $skip('no database configured');
}
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $dbPass, $name, 0, $socket)
        : new mysqli($host, $user, $dbPass, $name, $port);
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    $skip('database not reachable (' . $e->getMessage() . ')');
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$createAffinityTables = static function () use ($db): void {
    $db->query("CREATE TEMPORARY TABLE bookclub_affinity (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NOT NULL, user_a INT NOT NULL, user_b INT NOT NULL,
        score TINYINT UNSIGNED NULL, computed_at DATETIME NOT NULL, PRIMARY KEY (id),
        UNIQUE KEY uq_pair (club_id, user_a, user_b)) ENGINE=InnoDB");
    $db->query("CREATE TEMPORARY TABLE bookclub_affinity_optin (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id),
        UNIQUE KEY uq_opt (club_id, user_id)) ENGINE=InnoDB");
};

try {
    $db->query("CREATE TEMPORARY TABLE bookclub_clubs (
        id INT NOT NULL AUTO_INCREMENT, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL,
        privacy ENUM('public','private','invite','hidden') NOT NULL DEFAULT 'private',
        ics_token CHAR(32) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, deleted_at DATETIME NULL,
        PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TEMPORARY TABLE bookclub_members (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NOT NULL, user_id INT NOT NULL, role_id INT NOT NULL,
        status ENUM('pending','active','suspended','left','banned') NOT NULL DEFAULT 'active',
        invited_by INT NULL, PRIMARY KEY (id), UNIQUE KEY uq_m (club_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // affinitiesFor() reads the counterpart's name from utenti: a session-local
    // shadow keeps the fixture away from the real accounts.
    $db->query("CREATE TEMPORARY TABLE utenti (
        id INT NOT NULL, nome VARCHAR(100) NOT NULL, cognome VARCHAR(100) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $createAffinityTables();
    $db->query("INSERT INTO bookclub_clubs (id, slug, name, ics_token) VALUES (1, 'aff', 'Aff', '" . str_repeat('0', 32) . "'), (2, 'other', 'Other', '" . str_repeat('1', 32) . "')");

    $repo = new Repo($db);
    $affinity = new AffinityRepo($db);

    /** @var array<int, int> $memberIds user id => member row id (club 1) */
    $memberIds = [];
    $seed = static function () use ($db, &$memberIds): void {
        foreach (['bookclub_members', 'bookclub_affinity', 'bookclub_affinity_optin', 'utenti'] as $t) {
            $db->query("DELETE FROM {$t}");
        }
        $memberIds = [];
        foreach ([101 => 'Anna', 102 => 'Bruno', 103 => 'Carla', 104 => 'Dario'] as $uid => $nome) {
            $db->query("INSERT INTO utenti (id, nome, cognome) VALUES ({$uid}, '{$nome}', 'Test')");
            $db->query("INSERT INTO bookclub_members (club_id, user_id, role_id, status) VALUES (1, {$uid}, 3, 'active')");
            $memberIds[$uid] = (int) $db->insert_id;
            $db->query("INSERT INTO bookclub_affinity_optin (club_id, user_id) VALUES (1, {$uid})");
        }
        $ids = array_keys($memberIds);
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $db->query("INSERT INTO bookclub_affinity (club_id, user_a, user_b, score, computed_at) VALUES (1, {$ids[$i]}, {$ids[$j]}, 70, NOW())");
            }
        }
        // The same user in another club keeps everything there.
        $db->query("INSERT INTO bookclub_members (club_id, user_id, role_id, status) VALUES (2, 101, 3, 'active'), (2, 102, 3, 'active')");
        $db->query("INSERT INTO bookclub_affinity_optin (club_id, user_id) VALUES (2, 101), (2, 102)");
        $db->query("INSERT INTO bookclub_affinity (club_id, user_a, user_b, score, computed_at) VALUES (2, 101, 102, 50, NOW())");
    };
    $pairsWith = static fn (int $club, int $uid): int => (int) $db->query("SELECT COUNT(*) FROM bookclub_affinity WHERE club_id = {$club} AND (user_a = {$uid} OR user_b = {$uid})")->fetch_row()[0];
    $optedIn = static fn (int $club, int $uid): bool => (int) $db->query("SELECT COUNT(*) FROM bookclub_affinity_optin WHERE club_id = {$club} AND user_id = {$uid}")->fetch_row()[0] > 0;
    $names = static fn (int $uid): array => array_column($affinity->affinitiesFor(1, $uid), 'name');

    echo "A. Read time: only an active, opted-in counterpart is listed\n";
    $seed();
    $check(count($names(101)) === 3, 'baseline: three counterparts listed');
    $db->query("UPDATE bookclub_members SET status = 'suspended' WHERE club_id = 1 AND user_id = 102");
    $db->query("DELETE FROM bookclub_affinity_optin WHERE club_id = 1 AND user_id = 103");
    $listed = $names(101);
    $check(!in_array('Bruno Test', $listed, true), 'a counterpart suspended since the recompute is not listed (stale pair ignored)');
    $check(!in_array('Carla Test', $listed, true), 'a counterpart without an opt-in row is not listed');
    $check($listed === ['Dario Test'], 'the active, opted-in counterpart still is');

    foreach (['left', 'suspended', 'banned'] as $status) {
        echo "B. {$status}: pairs (both directions) and opt-in purged at once\n";
        $seed();
        $ok = $repo->setMemberStatus($memberIds[102], $status);
        $check($ok, "setMemberStatus(..., '{$status}') succeeds");
        $check($pairsWith(1, 102) === 0, 'no stored pair involves the member any more (as user_a or user_b)');
        $check(!$optedIn(1, 102), 'the opt-in row is gone: a later re-join starts opted out');
        $check($pairsWith(1, 101) === 2 && $optedIn(1, 101), "the other members' pairs and opt-ins stay");
        $check($pairsWith(2, 102) === 1 && $optedIn(2, 102), 'the same user in another club is untouched');
        $check(!in_array('Bruno Test', $names(101), true), 'and nobody sees them listed');
    }

    echo "C. A self-service leave of a pending request and a re-activation\n";
    $seed();
    $db->query("UPDATE bookclub_members SET status = 'pending' WHERE id = {$memberIds[104]}");
    $check($repo->setMemberStatus($memberIds[104], 'left', ['pending']) && !$optedIn(1, 104) && $pairsWith(1, 104) === 0, 'cancelling a pending request purges as well');
    $seed();
    $repo->setMemberStatus($memberIds[103], 'suspended');
    $check($repo->setMemberStatus($memberIds[103], 'active') && !$optedIn(1, 103), 're-activation does not restore the opt-in (the member opts in again)');

    echo "D. A failed purge rolls the status change back\n";
    $seed();
    $db->query('ALTER TABLE bookclub_affinity CHANGE COLUMN user_a user_a_hidden INT NOT NULL');
    try {
        $ok = $repo->setMemberStatus($memberIds[101], 'banned');
    } finally {
        $db->query('ALTER TABLE bookclub_affinity CHANGE COLUMN user_a_hidden user_a INT NOT NULL');
    }
    $status = (string) $db->query("SELECT status FROM bookclub_members WHERE id = {$memberIds[101]}")->fetch_row()[0];
    $check($ok === false && $status === 'active' && $optedIn(1, 101), 'purge failure: false, still active, nothing half-done');
    $check(!\App\Support\DbAtomic::inTransaction($db), 'no transaction left open');

    echo "E. A club without the affinity tables\n";
    $realTable = (int) $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('bookclub_affinity', 'bookclub_affinity_optin')")->fetch_row()[0] > 0;
    if ($realTable) {
        // Not here (the DELETE would reach the real tables): an empty sandbox
        // database stands for an install whose club never enabled the module.
        $emptyDb = $name . '_bcaffinity';
        try {
            $db->query("CREATE DATABASE IF NOT EXISTS `{$emptyDb}`");
            $other = ($socket !== '' && file_exists($socket))
                ? new mysqli(null, $user, $dbPass, $emptyDb, 0, $socket)
                : new mysqli($host, $user, $dbPass, $emptyDb, $port);
            $check((new AffinityRepo($other))->purgeMember(1, 101), 'purgeMember() succeeds when the module never created its tables');
            $other->close();
        } catch (\Throwable $e) {
            echo '  NOTE missing-table check not run (' . $e->getMessage() . ")\n";
        } finally {
            try { $db->query("DROP DATABASE IF EXISTS `{$emptyDb}`"); } catch (\Throwable) {}
        }
    } else {
        $seed();
        $db->query('DROP TEMPORARY TABLE bookclub_affinity');
        $db->query('DROP TEMPORARY TABLE bookclub_affinity_optin');
        $check($repo->setMemberStatus($memberIds[101], 'left'), 'leaving still works when the module never created its tables');
        $createAffinityTables();
    }
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
