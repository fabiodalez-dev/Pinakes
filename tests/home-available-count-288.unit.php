<?php
declare(strict_types=1);

/**
 * Regression for the home "Disponibili" stat (finding F001, PR #288).
 *
 * FrontendController::catalogAPI() runs an extra available-books aggregate
 * (COUNT over 5 LEFT JOINs restricted to loanable copies). Only the home hero
 * consumes it, so the aggregate is now gated behind an explicit `with_stats=1`
 * flag and home.php appends that flag to its loadStats() fetch.
 *
 * This test proves the contract the `?? total_books` fallback in home.php could
 * otherwise silently mask (a mistyped flag name would make available_books
 * always null yet the page would still render total_books):
 *   1. WITH with_stats=1  -> pagination.available_books is an integer <= total
 *   2. WITHOUT the flag   -> pagination.available_books is null
 *   3. seeded set of one loanable + one zero-copy book under a unique publisher
 *      yields total=2, available=1 (coherent: available < total).
 *
 * Runs transactionally and rolls back, so no data survives (soft-delete safe:
 * nothing is ever committed).
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\FrontendController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}\n";
    }
};

$env = [];
foreach (preg_split('/\r?\n/', (string)@file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $value = trim($value);
    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
        $value = substr($value, 1, -1);
    }
    $env[trim($key)] = $value;
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int)($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}

$controller = new FrontendController();
$requestFactory = new ServerRequestFactory();

/** Invoke catalogAPI with the given query params and return the decoded JSON. */
$callCatalog = static function (array $params) use ($controller, $requestFactory, $db): array {
    $request = $requestFactory->createServerRequest('GET', '/api/catalogo')->withQueryParams($params);
    $response = $controller->catalogAPI($request, new Response(), $db);
    $decoded = json_decode((string)$response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
};

$token = bin2hex(random_bytes(5));
$publisher = "ZZ F001 Publisher {$token}";

$db->begin_transaction();
try {
    // Unique publisher so the catalog `editore` filter matches exactly the two
    // seeded books (exact `e.nome = ?` match, no FULLTEXT indexing needed).
    $stmt = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
    $stmt->bind_param('s', $publisher);
    $stmt->execute();
    $publisherId = (int)$db->insert_id;
    $stmt->close();

    // One loanable copy, one with zero available copies. deleted_at defaults NULL.
    $insertBook = static function (mysqli $db, string $titolo, int $publisherId, int $copie): void {
        $stmt = $db->prepare('INSERT INTO libri (titolo, editore_id, copie_totali, copie_disponibili) VALUES (?, ?, ?, ?)');
        $totali = max(1, $copie);
        $stmt->bind_param('siii', $titolo, $publisherId, $totali, $copie);
        $stmt->execute();
        $stmt->close();
    };
    $insertBook($db, "ZZ F001 Available {$token}", $publisherId, 1);
    $insertBook($db, "ZZ F001 Unavailable {$token}", $publisherId, 0);

    // 1 + 3. WITH the flag: real available count, coherent with total.
    $withStats = $callCatalog(['editore' => $publisher, 'with_stats' => '1']);
    $pagWith = $withStats['pagination'] ?? [];
    $totalWith = (int)($pagWith['total_books'] ?? -1);
    $availWith = $pagWith['available_books'] ?? 'MISSING';

    $check($totalWith === 2, "with_stats: total_books counts both seeded books (got {$totalWith})");
    $check(is_int($availWith), 'with_stats: available_books is an integer, not null/missing');
    $check(is_int($availWith) && $availWith <= $totalWith, 'with_stats: available_books <= total_books');
    $check(is_int($availWith) && $availWith === 1, "with_stats: available_books excludes the zero-copy book (got " . var_export($availWith, true) . ")");
    $check(is_int($availWith) && $availWith < $totalWith, 'with_stats: available < total when a zero-copy book is in the filtered set');

    // 2. WITHOUT the flag: aggregate is skipped, field is null.
    $withoutStats = $callCatalog(['editore' => $publisher]);
    $pagWithout = $withoutStats['pagination'] ?? [];
    $totalWithout = (int)($pagWithout['total_books'] ?? -1);
    $availWithout = array_key_exists('available_books', $pagWithout) ? $pagWithout['available_books'] : 'MISSING';

    $check($totalWithout === 2, "no flag: total_books is still returned (got {$totalWithout})");
    $check($availWithout === null, 'no flag: available_books is null (aggregate not computed)');
} finally {
    $db->rollback();
    $db->close();
}

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} passed, {$failed} FAILED\n");
exit($failed === 0 ? 0 : 1);
