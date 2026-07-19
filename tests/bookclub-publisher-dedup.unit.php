<?php
declare(strict_types=1);

/**
 * Behavioural guard for the book-club Repo::findOrCreatePublisher() race fix.
 *
 * The old code did SELECT-then-INSERT in two round-trips (a check-then-act TOCTOU
 * that let two near-simultaneous imports of the same publisher create duplicate
 * `editori` rows). The fix collapses the check + insert into ONE statement:
 *
 *   INSERT INTO editori (nome) SELECT ? FROM DUAL
 *   WHERE NOT EXISTS (SELECT 1 FROM editori WHERE nome = ?)
 *
 * followed by a SELECT that resolves the lowest existing id. (A UNIQUE index on
 * editori.nome would make it fully atomic, but editori is a CORE table that
 * legitimately allows homonyms and may already hold duplicate names, so the
 * plugin must not add one.) This test pins the observable single-thread contract
 * of that statement pair — insert-if-absent + stable id resolution — against a
 * sandbox table shaped like `editori`.
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

const T = 'zz_bc_editori';
$cleanup = static fn () => $db->query('DROP TABLE IF EXISTS `' . T . '`');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/** The exact statement pair Repo::findOrCreatePublisher() now uses. */
$findOrCreate = static function (string $name) use ($db): ?int {
    $ins = $db->prepare('INSERT INTO `' . T . '` (nome) SELECT ? FROM DUAL '
        . 'WHERE NOT EXISTS (SELECT 1 FROM `' . T . '` WHERE nome = ?)');
    $ins->bind_param('ss', $name, $name);
    $ins->execute();
    $ins->close();

    $sel = $db->prepare('SELECT id FROM `' . T . '` WHERE nome = ? ORDER BY id ASC LIMIT 1');
    $sel->bind_param('s', $name);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();
    return $row !== null ? (int) $row['id'] : null;
};
$count = static function (string $name) use ($db): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM `' . T . '` WHERE nome = ?');
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
    $id1 = $findOrCreate('Einaudi');
    $check(is_int($id1) && $id1 > 0, "first call creates the publisher and returns its id ({$id1})");
    $check($count('Einaudi') === 1, 'exactly one row exists after the first call');

    $id2 = $findOrCreate('Einaudi');
    $check($id2 === $id1, 'second call for the same name returns the SAME id (no create)');
    $check($count('Einaudi') === 1, 'still exactly one row — no duplicate created');

    echo "B. Distinct names get distinct rows\n";
    $id3 = $findOrCreate('Adelphi');
    $check(is_int($id3) && $id3 !== $id1, "a different name creates a distinct row ({$id3})");
    $check((int) $db->query('SELECT COUNT(*) FROM `' . T . '`')->fetch_row()[0] === 2, 'two rows total for two distinct names');

    echo "C. Pre-existing row is reused, never re-inserted\n";
    // Simulate a legacy duplicate already present (core tables allow homonyms):
    $db->query("INSERT INTO `" . T . "` (nome) VALUES ('Adelphi')");
    $dupCountBefore = $count('Adelphi');
    $check($dupCountBefore === 2, 'a legacy duplicate can pre-exist (2 rows named Adelphi)');
    $idA = $findOrCreate('Adelphi');
    $check($count('Adelphi') === 2, 'find-or-create does NOT add a third row when the name already exists');
    $check($idA === $id3, 'it returns the LOWEST existing id deterministically');
} finally {
    $cleanup();
    $db->close();
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
