<?php
declare(strict_types=1);

/**
 * Security regression (PR #536 review of
 * storage/plugins/book-club/src/Repo.php:setMemberStatus).
 *
 * setMemberStatus() rotates the club's calendar token when an ACTIVE member
 * stops being one (the tokenized iCal URL is the only proof of membership for
 * the session-less feed). Two holes remained:
 *   1. the previous status was read without a lock, outside any transaction:
 *      a concurrent activation between that read and the UPDATE left a member
 *      who had just become active (and could fetch the URL) suspended with
 *      the old token still valid;
 *   2. a failed rotation was ignored: the status change was already committed
 *      and the method returned true, so the old URL kept working and a retry
 *      could never fix it (the member was no longer 'active').
 * The read must be SELECT ... FOR UPDATE, the UPDATE and the rotation must
 * share ONE transaction (a SAVEPOINT inside a caller's transaction), and a
 * rotation failure must roll back and return false.
 *
 * Sections A-D drive the REAL Repo against session-local TEMPORARY tables
 * (nothing is written to the database). Section E proves the lock with two
 * connections, which needs the REAL book-club tables (temporary tables are
 * invisible to a second session): it inserts one throw-away club, deletes it
 * in `finally` (members cascade), and prints NOTE when the tables are absent.
 *
 * Run:  php tests/security-audit-bookclub-member-status-atomic.unit.php
 * DB:   E2E_DB_NAME / E2E_DB_USER / E2E_DB_PASS / E2E_DB_SOCKET (or .env).
 *       Without a reachable DB it prints SKIP and exits 0 (a failure under
 *       CI_STRICT_TESTS=1).
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';

use App\Plugins\BookClub\Repo;
use App\Support\DbAtomic;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** mysqli that records the statements and transaction calls Repo issues. */
final class BcLoggingMysqli extends mysqli
{
    /** @var list<string> */
    public array $log = [];
    /** @var (\Closure(string): void)|null */
    public ?\Closure $beforePrepare = null;

    public function prepare(string $query): \mysqli_stmt|false
    {
        $this->log[] = $query;
        if ($this->beforePrepare !== null) {
            ($this->beforePrepare)($query);
        }
        return parent::prepare($query);
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $this->log[] = 'BEGIN';
        return parent::begin_transaction($flags, $name);
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->log[] = 'COMMIT';
        return parent::commit($flags, $name);
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->log[] = 'ROLLBACK';
        return parent::rollback($flags, $name);
    }
}

/** Repo whose token rotation can be forced to fail. */
final class BcRotationProbeRepo extends Repo
{
    public string $mode = 'ok';

    public function rotateIcsToken(int $clubId): bool
    {
        if ($this->mode === 'false') {
            return false;
        }
        if ($this->mode === 'throw') {
            throw new \RuntimeException('forced rotation failure');
        }
        return parent::rotateIcsToken($clubId);
    }
}

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
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
        $v = substr($v, 1, -1);
    }
    $env[$k] = $v;
}
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
if ($name === '' || $user === '') {
    $skip('no database configured (E2E_DB_NAME/E2E_DB_USER or .env)');
}
$connect = static function () use ($name, $user, $dbPass, $socket, $host, $port): BcLoggingMysqli {
    $db = ($socket !== '' && file_exists($socket))
        ? new BcLoggingMysqli(null, $user, $dbPass, $name, 0, $socket)
        : new BcLoggingMysqli($host, $user, $dbPass, $name, $port);
    $db->set_charset('utf8mb4');
    return $db;
};
try {
    $db = $connect();
} catch (\Throwable $e) {
    $skip('database not reachable (' . $e->getMessage() . ')');
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/** Index of the first log entry matching $re at or after $from, or -1. */
$find = static function (array $log, string $re, int $from = 0): int {
    for ($i = $from, $n = count($log); $i < $n; $i++) {
        if (preg_match($re, $log[$i]) === 1) {
            return $i;
        }
    }
    return -1;
};

try {
    // ── Session-local shadows of the tables setMemberStatus() writes ───────
    $db->query("CREATE TEMPORARY TABLE bookclub_clubs (
        id INT NOT NULL AUTO_INCREMENT, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL,
        privacy ENUM('public','private','invite','hidden') NOT NULL DEFAULT 'private',
        ics_token CHAR(32) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, deleted_at DATETIME NULL,
        PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TEMPORARY TABLE bookclub_members (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NOT NULL, user_id INT NOT NULL, role_id INT NOT NULL,
        status ENUM('pending','active','suspended','left','banned') NOT NULL DEFAULT 'active',
        invited_by INT NULL, PRIMARY KEY (id), UNIQUE KEY uq_m (club_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO bookclub_clubs (id, slug, name, ics_token) VALUES (1, 'audit-atomic', 'Audit atomic', '" . str_repeat('0', 32) . "')");

    $repo = new BcRotationProbeRepo($db);
    $add = static function (int $userId, string $status) use ($db): int {
        $db->query("INSERT INTO bookclub_members (club_id, user_id, role_id, status) VALUES (1, {$userId}, 3, '{$status}')");
        return (int) $db->insert_id;
    };
    $statusOf = static fn (int $id): string => (string) $db->query("SELECT status FROM bookclub_members WHERE id = {$id}")->fetch_assoc()['status'];
    $token = static fn (): string => (string) $db->query('SELECT ics_token FROM bookclub_clubs WHERE id = 1')->fetch_assoc()['ics_token'];

    echo "A. Read under lock, write and rotate in one transaction\n";
    $m = $add(601, 'active');
    $db->log = [];
    $ok = $repo->setMemberStatus($m, 'suspended');
    $log = $db->log;
    $begin = $find($log, '/^BEGIN$/');
    $lock = $find($log, '/FROM bookclub_members\b.*\bFOR UPDATE\b/s', max(0, $begin));
    $upd = $find($log, '/^UPDATE bookclub_members SET status/', max(0, $lock));
    $rot = $find($log, '/^UPDATE bookclub_clubs SET ics_token/', max(0, $upd));
    $commit = $find($log, '/^COMMIT$/', max(0, $rot));
    $check($ok && $statusOf($m) === 'suspended', 'active -> suspended applied');
    $check($begin >= 0 && $lock > $begin, 'previous status read with SELECT ... FOR UPDATE inside a transaction');
    $check($upd > $lock && $rot > $upd && $commit > $rot, 'status UPDATE and token rotation committed together after the locked read');
    $check(!DbAtomic::inTransaction($db), 'no transaction left open');

    $db->log = [];
    $ok = $repo->setMemberStatus($m, 'active');
    $log = $db->log;
    $begin = $find($log, '/^BEGIN$/');
    $lock = $find($log, '/FROM bookclub_members\b.*\bFOR UPDATE\b/s', max(0, $begin));
    $commit = $find($log, '/^COMMIT$/', max(0, $lock));
    $check($ok && $statusOf($m) === 'active', 'suspended -> active applied');
    $check($begin >= 0 && $lock > $begin && $commit > $lock, 'activation serializes on the same row lock');
    $check($find($log, '/^UPDATE bookclub_clubs SET ics_token/') === -1, 'activation does not rotate');

    echo "B. A failed rotation rolls the revocation back and reports it\n";
    $uid = 610;
    foreach (['false' => 'rotation returns false', 'throw' => 'rotation throws', 'sql' => 'rotation UPDATE fails in SQL'] as $mode => $label) {
        $m = $add($uid++, 'active');
        $t0 = $token();
        if ($mode === 'sql') {
            $db->query('ALTER TABLE bookclub_clubs RENAME COLUMN ics_token TO ics_token_hidden');
            $repo->mode = 'ok';
        } else {
            $repo->mode = $mode;
        }
        try {
            $ok = $repo->setMemberStatus($m, 'banned');
        } finally {
            if ($mode === 'sql') {
                $db->query('ALTER TABLE bookclub_clubs RENAME COLUMN ics_token_hidden TO ics_token');
            }
        }
        $check($ok === false, "{$label}: setMemberStatus() returns false");
        $check($statusOf($m) === 'active', "{$label}: status change rolled back (still active)");
        $check($token() === $t0, "{$label}: token unchanged");
        $check(!DbAtomic::inTransaction($db), "{$label}: transaction closed");

        $repo->mode = 'ok';
        $check($repo->setMemberStatus($m, 'banned') && $statusOf($m) === 'banned' && $token() !== $t0, "{$label}: a retry revokes and rotates");
    }

    echo "C. Inside a caller's transaction only this change is undone\n";
    $target = $add(701, 'active');
    $other = $add(702, 'active');
    $db->begin_transaction();
    $db->query("UPDATE bookclub_members SET status = 'pending' WHERE id = {$other}");
    $t0 = $token();
    $repo->mode = 'false';
    $ok = $repo->setMemberStatus($target, 'left');
    $check($ok === false && $statusOf($target) === 'active' && $token() === $t0, 'failure: false, status and token untouched');
    $check(DbAtomic::inTransaction($db), "failure: caller's transaction still open");
    $check($statusOf($other) === 'pending', "failure: caller's own earlier write kept");
    $repo->mode = 'ok';
    $ok = $repo->setMemberStatus($target, 'left');
    $check($ok && $statusOf($target) === 'left' && $token() !== $t0, 'success inside the caller transaction');
    $check(DbAtomic::inTransaction($db), "success: caller's transaction NOT committed by setMemberStatus()");
    $db->rollback();
    $check($statusOf($target) === 'active' && $statusOf($other) === 'active' && $token() === $t0, "caller's rollback undoes everything (nothing was committed early)");

    echo "D. Conditional transitions re-check the status under the lock\n";
    $req = $add(801, 'pending');
    $db->query("UPDATE bookclub_members SET status = 'suspended' WHERE id = {$req}");
    $check($repo->setMemberStatus($req, 'active', ['pending']) === false && $statusOf($req) === 'suspended', 'approve of a request suspended meanwhile: refused, suspension kept');
    $check($repo->setMemberStatus($req, 'left', ['active', 'pending']) === false && $statusOf($req) === 'suspended', 'leave after a concurrent suspension: refused, suspension kept');
    $req2 = $add(802, 'pending');
    $check($repo->setMemberStatus($req2, 'active', ['pending']) && $statusOf($req2) === 'active', 'approve of a still-pending request: applied');
    $check($repo->setMemberStatus(999999, 'left') === false, 'unknown member id: false');
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    foreach (['bookclub_members', 'bookclub_clubs'] as $t) {
        try { $db->query("DROP TEMPORARY TABLE IF EXISTS {$t}"); } catch (\Throwable) {}
    }
}

echo "E. A concurrent activation waits for the revocation (two connections, real tables)\n";
$a = null;
$b = null;
$clubId = 0;
try {
    $a = $connect();
    $b = $connect();
    $have = (int) $a->query("SELECT COUNT(*) AS n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('bookclub_clubs','bookclub_members','bookclub_roles')")->fetch_assoc()['n'];
    $userRow = $have === 3 ? $a->query('SELECT id FROM utenti ORDER BY id ASC LIMIT 1')->fetch_assoc() : null;
    $roleRow = $have === 3 ? $a->query("SELECT id FROM bookclub_roles WHERE club_id IS NULL AND slug = 'member' LIMIT 1")->fetch_assoc() : null;
    if ($have !== 3 || $userRow === null || $roleRow === null) {
        echo "  NOTE book-club tables, a user or the member role missing in this database: two-connection check not run\n";
    } else {
        $t0 = bin2hex(random_bytes(16));
        $slug = 'audit-lock-' . bin2hex(random_bytes(4));
        $stmt = $a->prepare("INSERT INTO bookclub_clubs (slug, name, privacy, ics_token, is_active) VALUES (?, 'Audit lock club', 'private', ?, 1)");
        $stmt->bind_param('ss', $slug, $t0);
        $stmt->execute();
        $clubId = (int) $a->insert_id;
        $stmt->close();
        $uid = (int) $userRow['id'];
        $rid = (int) $roleRow['id'];
        $a->query("INSERT INTO bookclub_members (club_id, user_id, role_id, status) VALUES ({$clubId}, {$uid}, {$rid}, 'pending')");
        $memberId = (int) $a->insert_id;

        $b->query('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = null;
        $a->beforePrepare = static function (string $sql) use (&$blocked, $b, $memberId): void {
            if ($blocked !== null || !str_starts_with($sql, 'UPDATE bookclub_members SET status')) {
                return;
            }
            // A has read 'pending' and is about to write 'suspended': another
            // admin approves the same request right now.
            try {
                $b->query("UPDATE bookclub_members SET status = 'active' WHERE id = {$memberId}");
                $blocked = false;
            } catch (\mysqli_sql_exception $e) {
                $blocked = $e->getCode() === 1205;
            }
        };
        $ok = (new Repo($a))->setMemberStatus($memberId, 'suspended');
        $a->beforePrepare = null;
        $row = $a->query("SELECT m.status, c.ics_token FROM bookclub_members m JOIN bookclub_clubs c ON c.id = m.club_id WHERE m.id = {$memberId}")->fetch_assoc();
        $check($blocked === true, 'the concurrent activation waited on the row lock instead of slipping in');
        $check($ok && $row['status'] === 'suspended', 'revocation applied');
        $check($blocked === true || $row['ics_token'] !== $t0, 'no window where the member was active and kept the old token');
    }
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    if ($clubId > 0 && $a !== null) {
        try { $a->query("DELETE FROM bookclub_members WHERE club_id = {$clubId}"); } catch (\Throwable) {}
        try { $a->query("DELETE FROM bookclub_clubs WHERE id = {$clubId}"); } catch (\Throwable) {}
    }
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
