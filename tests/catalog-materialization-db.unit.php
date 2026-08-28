<?php
declare(strict_types=1);

/**
 * Runtime behavior for the 0.7.71 catalog projections against the canonical
 * schema. All rows use a unique test marker and are removed in finally.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\CatalogSnapshot;
use App\Support\QueryCache;
use App\Support\SearchIndexBuilder;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

// In CI the schema/DB gate provides a seeded database, so a missing
// connection or an unapplied migration is a real failure that must turn the
// pipeline red — never a silent green. Locally (no CI) the same conditions are
// an acceptable skip so the test can be run ad hoc without a full DB.
$requireDb = in_array(strtolower((string) getenv('CI')), ['1', 'true', 'yes'], true);
$skipOrFail = static function (string $message) use ($requireDb): void {
    if ($requireDb) {
        fwrite(STDERR, 'FAIL: ' . $message . " (CI requires a seeded database)\n");
        exit(1);
    }
    echo 'SKIP: ' . $message . "\n";
    exit(0);
};

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
} catch (Throwable $e) {
    $skipOrFail('database not reachable (' . $e->getMessage() . ')');
}
$db->set_charset('utf8mb4');

$required = $db->query(
    "SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
    . "AND TABLE_NAME='libri' AND COLUMN_NAME LIKE 'catalog_author_%') AS cols, "
    . "(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
    . "AND TABLE_NAME='catalog_materialized_snapshots') AS snapshots"
)->fetch_assoc();
if ((int) ($required['cols'] ?? 0) !== 3 || (int) ($required['snapshots'] ?? 0) !== 1) {
    $skipOrFail('0.7.71 catalog materialization migration is not applied');
}

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$run = bin2hex(random_bytes(6));
$title = 'ZZ071CAT ' . $run;
$authorPrefix = 'ZZ071CAT-' . $run . '-';
$bookId = 0;
$authorIds = [];
$logicalSnapshotKey = 'catalog_count_zz071_' . $run;
$snapshotKey = hash('sha256', $logicalSnapshotKey);

$cleanup = static function () use ($db, &$bookId, &$authorIds, $snapshotKey): void {
    $stmt = $db->prepare('DELETE FROM catalog_materialized_snapshots WHERE cache_key = ?');
    $stmt->bind_param('s', $snapshotKey);
    $stmt->execute();
    $stmt->close();
    if ($bookId > 0) {
        $db->query('DELETE FROM libri_autori WHERE libro_id = ' . $bookId);
        $db->query('DELETE FROM libri WHERE id = ' . $bookId);
    }
    foreach ($authorIds as $authorId) {
        $db->query('DELETE FROM autori WHERE id = ' . (int) $authorId);
    }
};

try {
    $cleanup();

    $stmt = $db->prepare('INSERT INTO autori (nome, pseudonimo) VALUES (?, ?)');
    $realName = $authorPrefix . 'Charles Dodgson';
    $pseudonym = 'Lewis Carroll';
    $stmt->bind_param('ss', $realName, $pseudonym);
    $stmt->execute();
    $principalId = (int) $db->insert_id;
    $authorIds[] = $principalId;
    $coauthorName = $authorPrefix . 'Backup Writer';
    $emptyPseudonym = null;
    $stmt->bind_param('ss', $coauthorName, $emptyPseudonym);
    $stmt->execute();
    $coauthorId = (int) $db->insert_id;
    $authorIds[] = $coauthorId;
    $stmt->close();

    $stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 1, 1)');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare('INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, ?, ?)');
    $role = 'co-autore';
    $order = 1;
    $stmt->bind_param('iisi', $bookId, $coauthorId, $role, $order);
    $stmt->execute();
    $role = 'principale';
    $order = 99;
    $stmt->bind_param('iisi', $bookId, $principalId, $role, $order);
    $stmt->execute();
    $stmt->close();

    SearchIndexBuilder::rebuild($db, $bookId);
    $row = $db->query(
        'SELECT catalog_author_display, catalog_author_name, catalog_author_sort FROM libri WHERE id = ' . $bookId
    )->fetch_assoc();
    $check(($row['catalog_author_display'] ?? '') === 'Lewis Carroll (' . $realName . ')', 'runtime rebuild selects the principal and preserves pseudonym display');
    $check(($row['catalog_author_name'] ?? '') === $realName, 'runtime rebuild stores the canonical principal name');
    $check(($row['catalog_author_sort'] ?? '') === 'Carroll', 'runtime rebuild stores the preferred surname sort key');

    // Read-path completeness gate (findings C + D). isReadable() must reject the
    // materialized columns while any linked book is missing its sort key — the
    // migration backfill window (C) or a row nulled by a failed rebuild (D) —
    // so the catalog falls back to the always-correct live subqueries. Probed on
    // fresh connections because isReadable() caches its positive result per
    // connection; tolerant of the baseline so a partially-backfilled fixture DB
    // cannot make the transition assertions flaky.
    $freshDb = static function () use ($socket, $env): \mysqli {
        $conn = $socket !== '' && file_exists($socket)
            ? new \mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
            : new \mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
        $conn->set_charset('utf8mb4');
        return $conn;
    };
    $probeReadable = static function (\mysqli $conn): bool {
        try {
            return \App\Support\CatalogAuthorProjection::isReadable($conn);
        } finally {
            $conn->close();
        }
    };
    $baseReadable = $probeReadable($freshDb());
    $db->query("UPDATE libri SET catalog_author_sort = NULL WHERE id = {$bookId}");
    $check($probeReadable($freshDb()) === false, 'isReadable() is false while a linked book has an author but no sort key');
    SearchIndexBuilder::rebuild($db, $bookId);
    $check($probeReadable($freshDb()) === $baseReadable, 'isReadable() returns to baseline once the projection is repaired');

    $newPseudonym = 'Carroll Updated';
    $stmt = $db->prepare('UPDATE autori SET pseudonimo = ? WHERE id = ?');
    $stmt->bind_param('si', $newPseudonym, $principalId);
    $stmt->execute();
    $stmt->close();
    SearchIndexBuilder::rebuildForAuthor($db, $principalId);
    $row = $db->query('SELECT catalog_author_display, catalog_author_sort FROM libri WHERE id = ' . $bookId)->fetch_assoc();
    $check(($row['catalog_author_display'] ?? '') === $newPseudonym . ' (' . $realName . ')', 'author edits refresh every linked catalog projection');
    $check(($row['catalog_author_sort'] ?? '') === 'Updated', 'author edit refreshes the sort key');

    $db->query("DELETE FROM libri_autori WHERE libro_id = {$bookId} AND ruolo = 'principale'");
    SearchIndexBuilder::rebuild($db, $bookId);
    $row = $db->query('SELECT catalog_author_name FROM libri WHERE id = ' . $bookId)->fetch_assoc();
    $check(($row['catalog_author_name'] ?? '') === $coauthorName, 'projection falls back to the first co-author after principal removal');

    $db->query('DELETE FROM libri_autori WHERE libro_id = ' . $bookId);
    SearchIndexBuilder::rebuild($db, $bookId);
    $row = $db->query('SELECT catalog_author_display FROM libri WHERE id = ' . $bookId)->fetch_assoc();
    $check(array_key_exists('catalog_author_display', $row) && $row['catalog_author_display'] === null, 'projection clears stale fields when the last creator is removed');

    $generation = QueryCache::namespaceGeneration('catalog_');
    $loads = 0;
    $first = CatalogSnapshot::remember($db, $logicalSnapshotKey, $generation, static function () use (&$loads): array {
        $loads++;
        return ['total' => 11, 'facets' => ['book' => 7]];
    });
    $second = CatalogSnapshot::remember($db, $logicalSnapshotKey, $generation, static function () use (&$loads): array {
        $loads++;
        return ['total' => 999];
    });
    $check($first === $second && $loads === 1, 'same-generation workers reuse one materialized aggregate');

    $next = CatalogSnapshot::remember($db, $logicalSnapshotKey, $generation + 1, static function () use (&$loads): array {
        $loads++;
        return ['total' => 12];
    });
    $check(($next['total'] ?? 0) === 12 && $loads === 2, 'new catalog generation rebuilds the shared aggregate');

    $old = CatalogSnapshot::remember($db, $logicalSnapshotKey, $generation, static function () use (&$loads): array {
        $loads++;
        return ['total' => 10];
    });
    $stored = $db->query(
        "SELECT generation, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.total')) AS total "
        . "FROM catalog_materialized_snapshots WHERE cache_key = '" . $db->real_escape_string($snapshotKey) . "'"
    )->fetch_assoc();
    $check(($old['total'] ?? 0) === 10 && $loads === 3, 'an old in-flight generation receives a correct live value');
    $check((int) ($stored['generation'] ?? 0) === $generation + 1 && (int) ($stored['total'] ?? 0) === 12, 'old generation cannot overwrite the newer shared snapshot');

    $db->query(
        "UPDATE catalog_materialized_snapshots SET updated_at = CURRENT_TIMESTAMP - INTERVAL 5 SECOND "
        . "WHERE cache_key = '" . $db->real_escape_string($snapshotKey) . "'"
    );
    $expired = CatalogSnapshot::remember($db, $logicalSnapshotKey, $generation + 1, static function () use (&$loads): array {
        $loads++;
        return ['total' => 13];
    }, 1);
    $check(($expired['total'] ?? 0) === 13 && $loads === 4, 'expired same-generation snapshot rebuilds instead of staying stale forever');
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL exception: ' . $e->getMessage() . PHP_EOL;
} finally {
    try {
        $cleanup();
    } catch (Throwable $e) {
        $failed++;
        echo '  FAIL cleanup: ' . $e->getMessage() . PHP_EOL;
    }
    $db->close();
}

echo PHP_EOL;
if ($failed > 0) {
    echo "FAILED: {$failed} check(s) failed, {$passed} passed\n";
    exit(1);
}
echo "ALL {$passed} PASS\n";
