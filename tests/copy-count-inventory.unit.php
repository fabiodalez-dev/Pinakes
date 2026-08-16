<?php
declare(strict_types=1);

/**
 * Behavioral unit test — physical-copy inventory-code generation (#238).
 *
 * Reproduces Nikola's exact sequence, which used to corrupt the numbering and
 * finally crash with "Duplicate entry '…-C2' for key 'uniq_numero_inventario'":
 *   1 copy  -> LIB-C1        (was: bare base, no suffix)
 *   1 -> 2  -> add LIB-C2    (gap-fill, not count+1)
 *   2 -> 1  -> remove LAST   (LIB-C2), keep LIB-C1  (was: removed the first)
 *   1 -> 2  -> add LIB-C2 again WITHOUT a duplicate-key crash
 *
 * Drives the real CopyRepository::allocateInventoryCodes() /
 * getRemovableCopiesNewestFirst() — the helpers the controller now uses — against
 * the live DB. Touches only data it creates (title ZZ_COPYNUM_%, base ZZCN-%).
 *
 * Run:  php tests/copy-count-inventory.unit.php
 */

use App\Models\CopyRepository;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function ccenv(string $path): array
{
    $env = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) as $line) {
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
    return $env;
}

$env    = ccenv($root . '/.env');
$dbName = $env['DB_NAME'] ?? '';
$dbUser = $env['DB_USER'] ?? '';
$dbPass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

$TESTNO = 0;
$failed = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO, $failed;
    $TESTNO++;
    printf("[%02d] %s: %s\n", $TESTNO, $cond ? 'PASS' : 'FAIL', $desc);
    if (!$cond) {
        $failed++;
    }
}

// Per-run token so concurrent runs (and this run's cleanup) only ever touch
// their own rows — never another run's or unrelated data.
$RUN = bin2hex(random_bytes(6));
$TITLE_PREFIX = "ZZ_COPYNUM_{$RUN}_";

$repo = new CopyRepository($db);

/** Current codes for a book, ordered by creation. @return list<string> */
$codesOf = static function (int $bookId) use ($db): array {
    $res = $db->query("SELECT numero_inventario FROM copie WHERE libro_id = {$bookId} ORDER BY id ASC");
    $out = [];
    while ($row = $res->fetch_row()) {
        $out[] = (string) $row[0];
    }
    return $out;
};

/** Emulate the controller's "add N copies" step with the fixed helper. */
$addCopies = static function (int $bookId, string $base, int $howMany) use ($repo): void {
    foreach ($repo->allocateInventoryCodes($base, $howMany) as $code) {
        $repo->create($bookId, $code, 'disponibile', "Copia {$code}");
    }
};

/** Emulate the controller's "reduce to N" step (trim from the end). */
$removeCopies = static function (int $bookId, int $howMany) use ($repo): void {
    $removed = 0;
    foreach ($repo->getRemovableCopiesNewestFirst($bookId) as $c) {
        if ($removed >= $howMany) {
            break;
        }
        $repo->delete($c['id']);
        $removed++;
    }
};

/* ------------------------------- cleanup -------------------------------- */
$cleanup = static function () use ($db, $TITLE_PREFIX): void {
    $like = $db->real_escape_string($TITLE_PREFIX) . '%';
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '{$like}'");
};
$cleanup();

try {
    $title = $TITLE_PREFIX . 'book';
    $db->query("INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
                VALUES ('" . $db->real_escape_string($title) . "', 0, 0, NOW(), NOW())");
    $bookId = (int) $db->insert_id;
    $base = "ZZCN-{$bookId}";

    // Step 1: create with 1 copy → uniform suffix, never a bare base.
    $addCopies($bookId, $base, 1);
    check($codesOf($bookId) === ["{$base}-C1"], "1 copy -> [{$base}-C1] (uniform suffix, no bare base)");

    // Step 2: 1 -> 2 → gap-fill next free index (C2), not count+1 collision.
    $addCopies($bookId, $base, 1);
    check($codesOf($bookId) === ["{$base}-C1", "{$base}-C2"], "1->2 -> C1 + C2");

    // Step 3: 2 -> 1 → remove the LAST copy (C2), keep C1 (not the first!).
    $removeCopies($bookId, 1);
    check($codesOf($bookId) === ["{$base}-C1"], "2->1 removes the last copy (C2), keeps C1");

    // Step 4: 1 -> 2 again → must re-allocate C2 with NO duplicate-key crash.
    $crashed = false;
    try {
        $addCopies($bookId, $base, 1);
    } catch (\Throwable $e) {
        $crashed = true;
        check(false, "1->2 again must NOT crash — got: " . $e->getMessage());
    }
    if (!$crashed) {
        check($codesOf($bookId) === ["{$base}-C1", "{$base}-C2"], "1->2 again re-fills C2 with no duplicate-key error");
    }

    // Step 5: a mid-sequence gap is filled (remove C1, then add → C1 returns).
    // Remove the newest (C2) then the newest again (C1): book now empty, re-add 2.
    $removeCopies($bookId, 2);
    check($codesOf($bookId) === [], "reduce to 0 removes both copies");
    $addCopies($bookId, $base, 2);
    check($codesOf($bookId) === ["{$base}-C1", "{$base}-C2"], "re-add 2 -> clean C1 + C2 (indices reused)");

    // Step 6: allocateInventoryCodes never returns a code already in the table.
    $existing = $codesOf($bookId);
    $next = $repo->allocateInventoryCodes($base, 3);
    check(count(array_intersect($next, $existing)) === 0, "allocate never collides with existing codes");
    check($next === ["{$base}-C3", "{$base}-C4", "{$base}-C5"], "allocate continues past the highest used index");

    // Step 7: the admin batch helper inserts every row in one call, returns all
    // ids, and treats an operator-entered '%' as literal note text.
    $sharedNote = 'Batch note 100% literal';
    $db->begin_transaction();
    $batchIds = $repo->createManyForBookWithIdsAndNote($bookId, $base, 3, 'disponibile', $sharedNote);
    $db->commit();
    check(count($batchIds) === 3 && count(array_unique($batchIds)) === 3, 'batch helper returns three distinct inserted ids');
    $escapedNote = $db->real_escape_string($sharedNote);
    $noteCount = (int) ($db->query("SELECT COUNT(*) FROM copie WHERE libro_id = {$bookId} AND note = '{$escapedNote}'")->fetch_row()[0] ?? 0);
    check($noteCount === 3, 'batch helper stores the shared note literally on every copy');

} catch (\Throwable $e) {
    $cleanup();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

$cleanup();
$db->close();
echo "\n" . ($failed === 0 ? "ALL {$TESTNO} PASS\n" : "{$failed}/{$TESTNO} FAILED\n");
exit($failed > 0 ? 1 : 0);
