<?php
declare(strict_types=1);

/**
 * Behavioral upgrade coverage for the 0.7.71 catalog materialization schema.
 * The real migration is retargeted onto isolated zz_* tables so an ordinary
 * local run never alters application data or its canonical schema.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

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

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
} catch (Throwable $e) {
    echo 'SKIP: database not reachable (' . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

const M71_BOOKS = 'zz_m71_libri';
const M71_AUTHORS = 'zz_m71_autori';
const M71_LINKS = 'zz_m71_libri_autori';
const M71_SNAPSHOTS = 'zz_m71_catalog_snapshots';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$cleanup = static function () use ($db): void {
    foreach ([M71_LINKS, M71_BOOKS, M71_AUTHORS, M71_SNAPSHOTS] as $table) {
        $db->query('DROP TABLE IF EXISTS `' . $table . '`');
    }
};

/** @return list<string> */
function m71SplitSql(string $sql): array
{
    $statements = [];
    $current = '';
    $quote = null;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            $current .= $char;
            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $current .= $sql[++$i];
                } elseif ($i === 0 || $sql[$i - 1] !== '\\') {
                    $quote = null;
                }
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if ($char === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }
    return $statements;
}

$applyMigration = static function () use ($db, $root): void {
    $sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.71-rc.1.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $sql = str_replace(
        [
            '`catalog_materialized_snapshots`', "'catalog_materialized_snapshots'",
            '`libri_autori`', "'libri_autori'",
            '`autori`', "'autori'",
            '`libri`', "'libri'",
        ],
        [
            '`' . M71_SNAPSHOTS . '`', "'" . M71_SNAPSHOTS . "'",
            '`' . M71_LINKS . '`', "'" . M71_LINKS . "'",
            '`' . M71_AUTHORS . '`', "'" . M71_AUTHORS . "'",
            '`' . M71_BOOKS . '`', "'" . M71_BOOKS . "'",
        ],
        $sql
    );
    // Safety guard: the rewrite above only covers backtick- and single-quote-
    // wrapped references. If a future migration edit changes the reference
    // form (a bare `libri`, different spacing) the substitution would silently
    // miss it and the DDL below would ALTER the REAL application tables. Refuse
    // to run if any real table name survives as a standalone identifier — the
    // sandbox copies are prefixed (zz_m71_), so they never trip \b<name>\b.
    foreach (['libri_autori', 'catalog_materialized_snapshots', 'libri', 'autori'] as $realTable) {
        if (preg_match('/\b' . preg_quote($realTable, '/') . '\b/', $sql) === 1) {
            throw new \RuntimeException(
                "migration rewrite missed real table '{$realTable}'; refusing to run DDL against production tables"
            );
        }
    }
    foreach (m71SplitSql($sql) as $statement) {
        $db->query($statement);
    }
};

try {
    $cleanup();
    $db->query(
        'CREATE TABLE `' . M71_BOOKS . '` ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
        . 'titolo VARCHAR(255) NOT NULL, search_index MEDIUMTEXT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $db->query(
        'CREATE TABLE `' . M71_AUTHORS . '` ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(255) NOT NULL, pseudonimo VARCHAR(255) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $db->query(
        'CREATE TABLE `' . M71_LINKS . '` ('
        . "libro_id INT NOT NULL, autore_id INT NOT NULL, ruolo VARCHAR(32) NOT NULL, ordine_credito INT NULL, "
        . 'PRIMARY KEY (libro_id, autore_id, ruolo)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $db->query("INSERT INTO `" . M71_BOOKS . "` (id, titolo) VALUES (1, 'Book One'), (2, 'Book Two'), (3, 'No Author')");
    $db->query("INSERT INTO `" . M71_AUTHORS . "` (id, nome, pseudonimo) VALUES
        (1, 'Charles Dodgson', 'Lewis Carroll'),
        (2, 'Zed Coauthor', NULL),
        (3, 'Second Choice', NULL),
        (4, 'First Choice', NULL)");
    $db->query("INSERT INTO `" . M71_LINKS . "` (libro_id, autore_id, ruolo, ordine_credito) VALUES
        (1, 2, 'co-autore', 1),
        (1, 1, 'principale', 99),
        (2, 3, 'co-autore', 2),
        (2, 4, 'co-autore', 1)");

    $columnsBefore = $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
        . "AND TABLE_NAME='" . M71_BOOKS . "' AND COLUMN_NAME LIKE 'catalog_author_%'"
    )->fetch_row();
    $check((int) ($columnsBefore[0] ?? -1) === 0, 'old schema starts without catalog author projection');

    $applyMigration();

    $columnRows = $db->query(
        "SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . M71_BOOKS . "' "
        . "AND COLUMN_NAME LIKE 'catalog_author_%' ORDER BY COLUMN_NAME"
    )->fetch_all(MYSQLI_ASSOC);
    $lengths = array_map(
        static fn (mixed $length): int => (int) $length,
        array_column($columnRows, 'CHARACTER_MAXIMUM_LENGTH', 'COLUMN_NAME')
    );
    $check($lengths === [
        'catalog_author_display' => 512,
        'catalog_author_name' => 255,
        'catalog_author_sort' => 160,
    ], 'migration adds all three projection columns with bounded lengths');

    $index = $db->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
        . "AND TABLE_NAME='" . M71_BOOKS . "' AND INDEX_NAME='idx_libri_catalog_author_sort'"
    )->fetch_row();
    $check((int) ($index[0] ?? 0) === 2, 'author sort index contains sort key and deterministic id tie-break');

    $rows = $db->query(
        'SELECT id, catalog_author_display, catalog_author_name, catalog_author_sort FROM `'
        . M71_BOOKS . '` ORDER BY id'
    )->fetch_all(MYSQLI_ASSOC);
    $check(($rows[0]['catalog_author_display'] ?? null) === 'Lewis Carroll (Charles Dodgson)', 'principal role wins and pseudonym display matches AuthorName');
    $check(($rows[0]['catalog_author_name'] ?? null) === 'Charles Dodgson', 'canonical principal name is materialized');
    $check(($rows[0]['catalog_author_sort'] ?? null) === 'Carroll', 'preferred pseudonym surname becomes sort key');
    $check(($rows[1]['catalog_author_name'] ?? null) === 'First Choice', 'credit order deterministically selects the first co-author');
    $check(array_key_exists('catalog_author_display', $rows[2]) && $rows[2]['catalog_author_display'] === null, 'books without creators keep nullable projection fields');

    $snapshotTable = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
        . "AND TABLE_NAME='" . M71_SNAPSHOTS . "'"
    )->fetch_row();
    $check((int) ($snapshotTable[0] ?? 0) === 1, 'migration creates the shared catalog snapshot table');

    $beforeSecondRun = json_encode($rows, JSON_THROW_ON_ERROR);
    $applyMigration();
    $afterSecondRun = json_encode($db->query(
        'SELECT id, catalog_author_display, catalog_author_name, catalog_author_sort FROM `'
        . M71_BOOKS . '` ORDER BY id'
    )->fetch_all(MYSQLI_ASSOC), JSON_THROW_ON_ERROR);
    $check($afterSecondRun === $beforeSecondRun, 'migration is idempotent and preserves the projection on a second run');

    $frontend = (string) file_get_contents($root . '/app/Controllers/FrontendController.php');
    $searchBuilder = (string) file_get_contents($root . '/app/Support/SearchIndexBuilder.php');
    $check(str_contains($frontend, 'CatalogSnapshot::remember('), 'bounded count/facet path uses the shared materialization layer');
    $check(str_contains($frontend, 'l.catalog_author_display AS autore'), 'catalog SELECT has the direct projection path');
    $check(str_contains($searchBuilder, 'CatalogAuthorProjection::rebuildMany($db, $ids);'), 'existing search-index write funnel also rebuilds the author projection');
    $check(version_compare('0.7.71-rc.1', '0.7.71', '<'), 'version_compare keeps the release candidate below stable');
    $check(
        \App\Support\Updater::shouldRunMigration('0.7.71-rc.1', '0.7.70-rc.1', '0.7.71-rc.1'),
        'updater runs the migration when upgrading to this release candidate'
    );
    $check(
        \App\Support\Updater::shouldRunMigration('0.7.71-rc.1', '0.7.70', '0.7.71'),
        'updater runs the RC migration when an installation jumps directly to stable'
    );
    $check(
        !\App\Support\Updater::shouldRunMigration('0.7.71-rc.1', '0.7.71-rc.1', '0.7.71'),
        'updater does not rerun the migration during the RC-to-stable upgrade'
    );
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL exception: ' . $e->getMessage() . PHP_EOL;
} finally {
    $cleanup();
    $db->close();
}

echo PHP_EOL;
if ($failed > 0) {
    echo "FAILED: {$failed} check(s) failed, {$passed} passed\n";
    exit(1);
}
echo "ALL {$passed} PASS\n";
