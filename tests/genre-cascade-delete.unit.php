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

$prefix = 'zc_' . bin2hex(random_bytes(4));
$repo = new GenereRepository($db);

$check(strlen($prefix . '_shelf') <= 20, 'fixture shelf code fits scaffali.codice VARCHAR(20)');

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

$stmt = $db->prepare('INSERT INTO posizioni (scaffale_id, mensola_id, genere_id, descrizione) VALUES (?, ?, ?, ?)');
$positionDescription = $prefix . '_position';
$stmt->bind_param('iiis', $scaffaleId, $mensolaId, $leafId, $positionDescription);
$stmt->execute();
$positionId = $db->insert_id;

$stmt = $db->prepare('UPDATE libri SET posizione_id = ?, scaffale_id = ?, mensola_id = ? WHERE id = ?');
$stmt->bind_param('iiii', $positionId, $scaffaleId, $mensolaId, $bookId);
$stmt->execute();

$check($repo->cascadeDelete($rootId), 'cascade delete succeeds for a deep genre tree');

$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM generi WHERE nome LIKE ?');
$like = $prefix . '%';
$stmt->bind_param('s', $like);
$stmt->execute();
$check((int)$stmt->get_result()->fetch_assoc()['cnt'] === 0, 'root and descendants are deleted');

$stmt = $db->prepare('SELECT genere_id, sottogenere_id, posizione_id FROM libri WHERE id = ?');
$stmt->bind_param('i', $bookId);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();
$check($book !== null && $book['genere_id'] === null && $book['sottogenere_id'] === null, 'book genre references are unlinked');
$check($book !== null && $book['posizione_id'] === null, 'book is unshelved when its deleted genre defined the physical position');

$stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM posizioni WHERE id = ?');
$stmt->bind_param('i', $positionId);
$stmt->execute();
$check((int)$stmt->get_result()->fetch_assoc()['cnt'] === 0, 'subtree positions are explicitly removed inside cascade transaction');

$stmt = $db->prepare('SELECT genere_id FROM mensole WHERE id = ?');
$stmt->bind_param('i', $mensolaId);
$stmt->execute();
$mensola = $stmt->get_result()->fetch_assoc();
$check($mensola !== null && $mensola['genere_id'] === null, 'shelf genre reference is unlinked');

// A plain delete must be conservative: even a genre unused as genere_id or
// sottogenere_id can define a physical position used by an otherwise unrelated
// book. Deleting it would silently unshelve that book through FK SET NULL.
$physicalGenreId = $repo->create(['nome' => $prefix . '_physical_only']);
$stmt = $db->prepare('INSERT INTO scaffali (codice, nome, lettera) VALUES (?, ?, ?)');
$physicalShelfCode = $prefix . '_phys';
$physicalShelfName = $prefix . '_Physical';
$stmt->bind_param('sss', $physicalShelfCode, $physicalShelfName, $letter);
$stmt->execute();
$physicalShelfId = $db->insert_id;

$stmt = $db->prepare('INSERT INTO mensole (scaffale_id, numero_livello, genere_id) VALUES (?, ?, NULL)');
$physicalLevel = 2;
$stmt->bind_param('ii', $physicalShelfId, $physicalLevel);
$stmt->execute();
$physicalMensolaId = $db->insert_id;

$stmt = $db->prepare('INSERT INTO posizioni (scaffale_id, mensola_id, genere_id, descrizione) VALUES (?, ?, ?, ?)');
$physicalDescription = $prefix . '_physical_position';
$stmt->bind_param('iiis', $physicalShelfId, $physicalMensolaId, $physicalGenreId, $physicalDescription);
$stmt->execute();
$physicalPositionId = $db->insert_id;

$stmt = $db->prepare('INSERT INTO libri (titolo, posizione_id, scaffale_id, mensola_id) VALUES (?, ?, ?, ?)');
$physicalBookTitle = $prefix . '_other_genre_book';
$stmt->bind_param('siii', $physicalBookTitle, $physicalPositionId, $physicalShelfId, $physicalMensolaId);
$stmt->execute();
$physicalBookId = $db->insert_id;

$physicalDeleteBlocked = false;
try {
    $repo->delete($physicalGenreId);
} catch (\RuntimeException $e) {
    $physicalDeleteBlocked = str_contains($e->getMessage(), 'posizioni fisiche');
}
$check($physicalDeleteBlocked, 'plain delete rejects a genre that defines physical positions');

$stmt = $db->prepare('SELECT posizione_id FROM libri WHERE id = ?');
$stmt->bind_param('i', $physicalBookId);
$stmt->execute();
$check((int)$stmt->get_result()->fetch_column() === $physicalPositionId, 'blocked plain delete leaves unrelated book shelving untouched');

$stmt = $db->prepare('SELECT COUNT(*) FROM generi WHERE id = ?');
$stmt->bind_param('i', $physicalGenreId);
$stmt->execute();
$check((int)$stmt->get_result()->fetch_column() === 1, 'blocked plain delete preserves the physical genre');

// Explicit begin_transaction() leaves @@autocommit at 1. The repository must
// still detect that caller-owned transaction and must not implicitly commit it.
$transactionGenreId = $repo->create(['nome' => $prefix . '_outer_transaction']);
$db->begin_transaction();
$repo->delete($transactionGenreId);
$stmt = $db->prepare('SELECT COUNT(*) FROM generi WHERE id = ?');
$stmt->bind_param('i', $transactionGenreId);
$stmt->execute();
$check((int)$stmt->get_result()->fetch_column() === 0, 'plain delete participates in an explicit caller transaction');
$db->rollback();
$stmt = $db->prepare('SELECT COUNT(*) FROM generi WHERE id = ?');
$stmt->bind_param('i', $transactionGenreId);
$stmt->execute();
$check((int)$stmt->get_result()->fetch_column() === 1, 'caller rollback restores a genre deleted inside its transaction');

$cleanup();
printf("\nALL %d PASS\n", $testNo);
