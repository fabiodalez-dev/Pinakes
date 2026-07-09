<?php
declare(strict_types=1);

use App\Controllers\LibraryThingImportController;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function ltSicLoadEnv(string $path): array
{
    $env = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[trim($k)] = $v;
    }
    return $env;
}

$env = ltSicLoadEnv($root . '/.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
$name = $env['DB_NAME'] ?? '';

try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $user, $pass, $name, 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

$hasSearchIndex = $db->query("SHOW COLUMNS FROM libri LIKE 'search_index'");
if (!$hasSearchIndex || $hasSearchIndex->num_rows === 0) {
    echo "SKIP: libri.search_index not present\n";
    exit(0);
}

$tag = 'ZZ_LT_SIC_' . bin2hex(random_bytes(4));
$isbn = '979' . random_int(1000000000, 9999999999);

function ltSicCleanup(mysqli $db, string $tag): void
{
    $like = $tag . '%';
    $stmt = $db->prepare('DELETE FROM libri WHERE titolo LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $stmt->close();
}

set_exception_handler(static function (\Throwable $e) use ($db, $tag): void {
    try {
        ltSicCleanup($db, $tag);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    pass($desc);
}

ltSicCleanup($db, $tag);

$stmt = $db->prepare('INSERT INTO libri (titolo, isbn13, search_index, copie_totali, copie_disponibili) VALUES (?, ?, ?, 1, 1)');
$conflictTitle = $tag . '_conflict';
$conflictIndex = $conflictTitle . ' ' . $isbn;
$stmt->bind_param('sss', $conflictTitle, $isbn, $conflictIndex);
$stmt->execute();
$conflictId = (int) $db->insert_id;
$stmt->close();

$stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 1, 1)');
$targetTitle = $tag . '_target';
$stmt->bind_param('s', $targetTitle);
$stmt->execute();
$targetId = (int) $db->insert_id;
$stmt->close();

$controller = new LibraryThingImportController();
$upsert = new ReflectionMethod($controller, 'upsertBook');
$upsert->setAccessible(true);
$result = $upsert->invoke($controller, $db, [
    'id' => $targetId,
    'titolo' => $targetTitle,
    'isbn13' => $isbn,
    'isbn10' => '',
    'ean' => '',
    'sottotitolo' => '',
    'anno_pubblicazione' => '',
    'lingua' => 'italiano',
    'edizione' => '',
    'numero_pagine' => '',
    'descrizione' => '',
    'descrizione_plain' => '',
    'formato' => 'cartaceo',
    'tipo_media' => 'libro',
    'prezzo' => '',
    'collana' => '',
    'numero_serie' => '',
    'traduttore' => '',
    'parole_chiave' => '',
    'classificazione_dewey' => '',
], null, null);

check(($result['id'] ?? null) === $targetId && ($result['action'] ?? null) === 'updated',
    'upsert updates the explicit target book');

$stmt = $db->prepare('SELECT isbn13, search_index FROM libri WHERE id = ?');
$stmt->bind_param('i', $conflictId);
$stmt->execute();
$conflict = $stmt->get_result()->fetch_assoc();
$stmt->close();

check(($conflict['isbn13'] ?? null) === null, 'conflicting book has ISBN13 cleared');
check(!str_contains((string) ($conflict['search_index'] ?? ''), $isbn),
    'conflicting book search_index is rebuilt without the cleared ISBN13');

$stmt = $db->prepare('SELECT isbn13 FROM libri WHERE id = ?');
$stmt->bind_param('i', $targetId);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();
check(($target['isbn13'] ?? null) === $isbn, 'target book receives the authoritative ISBN13');

ltSicCleanup($db, $tag);
$db->close();
printf("\nALL %d PASS\n", $TESTNO);
