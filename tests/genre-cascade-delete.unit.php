<?php
declare(strict_types=1);

/**
 * Regression test for issue #269: deleting a genre group should remove the
 * whole descendant tree and unlink catalog/collocation references.
 *
 * Run: php tests/genre-cascade-delete.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Models\GenereRepository;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $user, $pass, $name, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $pass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}

$testNo = 0;
$check = static function (bool $condition, string $label) use (&$testNo): void {
    if (!$condition) {
        throw new \RuntimeException("assertion failed: {$label}");
    }
    $testNo++;
    printf("[%02d] PASS: %s\n", $testNo, $label);
};

$prefix = 'zz_cascade_' . bin2hex(random_bytes(4));
$repo = new GenereRepository($db);

$cleanup = static function () use ($db, $prefix): void {
    $like = $prefix . '%';
    $stmt = $db->prepare('DELETE FROM libri WHERE titolo LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();

    $stmt = $db->prepare('SELECT id FROM scaffali WHERE codice LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $ids = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    foreach ($ids as $id) {
        $stmt = $db->prepare('DELETE FROM scaffali WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
    }

    $stmt = $db->prepare('DELETE FROM generi WHERE nome LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();
};

set_exception_handler(static function (\Throwable $e) use ($cleanup): void {
    try {
        $cleanup();
    } catch (\Throwable) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
});

$cleanup();

$rootId = $repo->create(['nome' => $prefix . '_root']);
$childId = $repo->create(['nome' => $prefix . '_child', 'parent_id' => $rootId]);
$leafId = $repo->create(['nome' => $prefix . '_leaf', 'parent_id' => $childId]);

$blocked = false;
try {
    $repo->delete($rootId);
} catch (\RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'sottogeneri');
}
$check($blocked, 'non-cascade delete still rejects genre groups');

$stmt = $db->prepare('INSERT INTO libri (titolo, genere_id, sottogenere_id) VALUES (?, ?, ?)');
$bookTitle = $prefix . '_book';
$stmt->bind_param('sii', $bookTitle, $childId, $leafId);
$stmt->execute();
$bookId = $db->insert_id;

$stmt = $db->prepare('INSERT INTO scaffali (codice, nome, lettera) VALUES (?, ?, ?)');
$shelfCode = $prefix . '_shelf';
$shelfName = $prefix . '_Shelf';
$letter = 'Z';
$stmt->bind_param('sss', $shelfCode, $shelfName, $letter);
$stmt->execute();
$scaffaleId = $db->insert_id;

$stmt = $db->prepare('INSERT INTO mensole (scaffale_id, numero_livello, genere_id) VALUES (?, ?, ?)');
$level = 1;
$stmt->bind_param('iii', $scaffaleId, $level, $leafId);
$stmt->execute();
$mensolaId = $db->insert_id;

$check($repo->cascadeDelete($rootId), 'cascade delete succeeds for a deep genre tree');

$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM generi WHERE nome LIKE ?');
$like = $prefix . '%';
$stmt->bind_param('s', $like);
$stmt->execute();
$check((int)$stmt->get_result()->fetch_assoc()['cnt'] === 0, 'root and descendants are deleted');

$stmt = $db->prepare('SELECT genere_id, sottogenere_id FROM libri WHERE id = ?');
$stmt->bind_param('i', $bookId);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();
$check($book !== null && $book['genere_id'] === null && $book['sottogenere_id'] === null, 'book genre references are unlinked');

$stmt = $db->prepare('SELECT genere_id FROM mensole WHERE id = ?');
$stmt->bind_param('i', $mensolaId);
$stmt->execute();
$mensola = $stmt->get_result()->fetch_assoc();
$check($mensola !== null && $mensola['genere_id'] === null, 'shelf genre reference is unlinked');

$cleanup();
printf("\nALL %d PASS\n", $testNo);
