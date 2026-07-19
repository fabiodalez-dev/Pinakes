<?php
declare(strict_types=1);

/**
 * Behavioural guard for the book-club Repo::findOrCreatePublisher() race fix.
 *
 * `editori.nome` intentionally has no UNIQUE constraint, so a NOT EXISTS insert
 * can either duplicate or deadlock under real concurrency. The repository now
 * serializes publisher resolution with a MySQL advisory lock shared by separate
 * PHP/database connections. This test pins both the ordinary reuse contract and
 * the overlapping two-connection path against a sandbox table shaped like
 * `editori`.
 *
 * Runs against the LIVE local MySQL; touches only the zz_bc_editori table.
 *
 * Run:  php tests/bookclub-publisher-dedup.unit.php
 */

$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
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

try {
    $db2 = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db2->set_charset('utf8mb4');
} catch (\Throwable $e) {
    $db->close();
    echo "SKIP: second database connection not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}

const T = 'zz_bc_editori';
$cleanup = static fn () => $db->query('DROP TABLE IF EXISTS `' . T . '`');
$lockName = 'pinakes:test:bc-publisher:' . bin2hex(random_bytes(6));

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/** The exact locked lookup/insert contract Repo::findOrCreatePublisher() uses. */
$findOrCreateWhileLocked = static function (mysqli $connection, string $name): int {
    $sel = $connection->prepare('SELECT id FROM `' . T . '` WHERE nome = ? ORDER BY id ASC LIMIT 1');
    $sel->bind_param('s', $name);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();
    if ($row !== null) {
        return (int) $row['id'];
    }

    $ins = $connection->prepare('INSERT INTO `' . T . '` (nome) VALUES (?)');
    $ins->bind_param('s', $name);
    $ins->execute();
    $id = $ins->insert_id;
    $ins->close();
    return $id;
};
$acquireLock = static function (mysqli $connection, int $timeout = 5) use ($lockName): bool {
    $stmt = $connection->prepare('SELECT GET_LOCK(?, ?) AS acquired');
    $stmt->bind_param('si', $lockName, $timeout);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['acquired'] ?? 0) === 1;
};
$releaseLock = static function (mysqli $connection) use ($lockName): bool {
    $stmt = $connection->prepare('SELECT RELEASE_LOCK(?) AS released');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['released'] ?? 0) === 1;
};
$findOrCreate = static function (mysqli $connection, string $name) use ($acquireLock, $releaseLock, $findOrCreateWhileLocked): int {
    if (!$acquireLock($connection)) {
        throw new RuntimeException('test publisher lock timed out');
    }
    try {
        return $findOrCreateWhileLocked($connection, $name);
    } finally {
        $releaseLock($connection);
    }
};
$count = static function (mysqli $connection, string $name): int {
    $stmt = $connection->prepare('SELECT COUNT(*) FROM `' . T . '` WHERE nome = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
};

try {
    $cleanup();
    $db->query('CREATE TABLE `' . T . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    echo "A. Insert-if-absent + stable id\n";
    $id1 = $findOrCreate($db, 'Einaudi');
    $check($id1 > 0, "first call creates the publisher and returns its id ({$id1})");
    $check($count($db, 'Einaudi') === 1, 'exactly one row exists after the first call');

    $id2 = $findOrCreate($db, 'Einaudi');
    $check($id2 === $id1, 'second call for the same name returns the SAME id (no create)');
    $check($count($db, 'Einaudi') === 1, 'still exactly one row — no duplicate created');

    echo "B. Distinct names get distinct rows\n";
    $id3 = $findOrCreate($db, 'Adelphi');
    $check($id3 !== $id1, "a different name creates a distinct row ({$id3})");
    $check((int) $db->query('SELECT COUNT(*) FROM `' . T . '`')->fetch_row()[0] === 2, 'two rows total for two distinct names');

    echo "C. Pre-existing row is reused, never re-inserted\n";
    // Simulate a legacy duplicate already present (core tables allow homonyms):
    $db->query("INSERT INTO `" . T . "` (nome) VALUES ('Adelphi')");
    $dupCountBefore = $count($db, 'Adelphi');
    $check($dupCountBefore === 2, 'a legacy duplicate can pre-exist (2 rows named Adelphi)');
    $idA = $findOrCreate($db, 'Adelphi');
    $check($count($db, 'Adelphi') === 2, 'find-or-create does NOT add a third row when the name already exists');
    $check($idA === $id3, 'it returns the LOWEST existing id deterministically');

    echo "D. Overlapping connections serialize instead of racing\n";
    $check($acquireLock($db), 'first connection acquires the shared publisher lock');
    $escapedLockName = $db2->real_escape_string($lockName);
    $db2->query("SELECT GET_LOCK('{$escapedLockName}', 5) AS acquired", MYSQLI_ASYNC);
    usleep(100_000);
    $read = [$db2];
    $error = [$db2];
    $reject = [];
    $check(mysqli_poll($read, $error, $reject, 0, 0) === 0, 'second connection waits while the first owns the lock');

    $idConcurrent1 = $findOrCreateWhileLocked($db, 'Mondadori');
    $check($releaseLock($db), 'first connection releases the lock after inserting');

    $read = [$db2];
    $error = [$db2];
    $reject = [];
    $check(mysqli_poll($read, $error, $reject, 5) === 1, 'second connection resumes after release');
    $result = $db2->reap_async_query();
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('failed to reap asynchronous lock query');
    }
    $lockRow = $result->fetch_assoc();
    $result->free();
    $check((int) ($lockRow['acquired'] ?? 0) === 1, 'second connection acquires the same lock');
    $idConcurrent2 = $findOrCreateWhileLocked($db2, 'Mondadori');
    $check($releaseLock($db2), 'second connection releases the lock');
    $check($idConcurrent2 === $idConcurrent1, 'both overlapping imports resolve the SAME publisher id');
    $check($count($db, 'Mondadori') === 1, 'overlap creates exactly one publisher row');

    // findOrCreatePublisher() operates on the CORE `editori` table via hardcoded
    // SQL, so it can't be driven directly here without polluting real data — the
    // protocol above is proven on a sandbox replica. Bind the sandbox proof to
    // production by asserting the real code keeps the exact protocol invariants:
    // acquire/release AND the per-database scoping (GET_LOCK is server-wide, so a
    // regression that drops DATABASE() would reintroduce cross-tenant contention).
    $repoSource = (string) file_get_contents($root . '/storage/plugins/book-club/src/Repo.php');
    $check(str_contains($repoSource, 'GET_LOCK(CONCAT(') && str_contains($repoSource, 'DATABASE()'),
        'production Repo scopes the advisory lock per database (GET_LOCK(CONCAT(?, DATABASE())))');
    $check(str_contains($repoSource, 'RELEASE_LOCK(CONCAT('),
        'production Repo releases the same per-database-scoped lock');
} finally {
    // Advisory locks are connection-scoped; make cleanup idempotent after a failed assertion/query.
    try { $releaseLock($db); } catch (Throwable) {}
    try { $releaseLock($db2); } catch (Throwable) {}
    $cleanup();
    $db2->close();
    $db->close();
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
