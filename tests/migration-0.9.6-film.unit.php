<?php
declare(strict_types=1);

/**
 * migrate_0.9.6.sql, film block (#539): the five film columns on `libri`.
 *
 * Runs the REAL migration file, split exactly as the Updater splits it
 * (comment lines dropped, split on ';'), against a sandbox database whose
 * `libri` table is the schema.sql table without the five film columns, then
 * asserts:
 *   - the five columns exist with the fresh-install type, default, collation,
 *     comment and position (compared against a `libri` built from schema.sql);
 *   - an existing row survives and reads NULL in every new column;
 *   - a second run is a no-op that keeps stored film data (idempotency);
 *   - a database that SchemaRepair already patched is left as it is.
 *
 * Needs a user allowed to create the sandbox database (E2E_MIG_DB, default
 * "<DB_NAME>_migtest_film"), dropped at the end.
 * Run: php tests/migration-0.9.6-film.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null);
$sandbox = getenv('E2E_MIG_DB') ?: preg_replace('/^fabiodal_biblioteca_/', 'fabiodal_', (string) $env['DB_NAME']) . '_migtest_film';
if (preg_match('/^[A-Za-z0-9_]+$/', $sandbox) !== 1) {
    fwrite(STDERR, "invalid sandbox name\n");
    exit(1);
}

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};

// Until the 0.9.6 version bump the file waits in migrations/pending/.
$file = $root . '/installer/database/migrations/migrate_0.9.6.sql';
if (!is_file($file)) {
    $file = $root . '/installer/database/migrations/pending/migrate_0.9.6.sql';
}
$migration = (string) file_get_contents($file);
$filmColumns = ['durata_minuti', 'interpreti', 'sottotitoli', 'classificazione_eta', 'codice_regione'];

$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
if (preg_match('/CREATE TABLE `libri` \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $m) !== 1) {
    fwrite(STDERR, "libri not found in schema.sql\n");
    exit(1);
}
$freshLibri = $m[0];
// The pre-0.9.6 table: the same definition without the five film columns.
$oldLibri = preg_replace('/^\s*`(' . implode('|', $filmColumns) . ')` [^\n]*\n/m', '', $freshLibri);
$check(substr_count($freshLibri, "\n") - substr_count((string) $oldLibri, "\n") === 5, 'the old libri table is schema.sql minus exactly five lines');

$host = $env['DB_HOST'] ?? 'localhost';
$port = (int) ($env['DB_PORT'] ?? 3306);
$admin = new mysqli($host, $env['DB_USER'], $env['DB_PASS'] ?? '', '', $port, $socket);

/** Execute SQL the way the Updater does. */
$run = static function (mysqli $db, string $sql): void {
    $sql = implode("\n", array_filter(explode("\n", $sql), static fn (string $l): bool => !str_starts_with(trim($l), '--')));
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $result = $db->query($statement);
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        }
    }
};
$fresh = static function (string $libri) use ($admin, $sandbox, $host, $env, $port, $socket): mysqli {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $env['DB_USER'], $env['DB_PASS'] ?? '', $sandbox, $port, $socket);
    $db->set_charset('utf8mb4');
    // libri references authors/publishers/genres that the sandbox does not need.
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $db->query($libri);
    return $db;
};
/** @return array<string, array<string, mixed>> */
$describe = static function (mysqli $db) use ($filmColumns): array {
    $in = "'" . implode("','", $filmColumns) . "'";
    $rows = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, COLUMN_COMMENT,
        (SELECT c2.COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS c2 WHERE c2.TABLE_SCHEMA = c.TABLE_SCHEMA AND c2.TABLE_NAME = c.TABLE_NAME AND c2.ORDINAL_POSITION = c.ORDINAL_POSITION - 1) AS PREV
        FROM INFORMATION_SCHEMA.COLUMNS c WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME IN ({$in})")->fetch_all(MYSQLI_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        // MariaDB reports a NULL default as the string 'NULL', MySQL as SQL NULL.
        if ($row['COLUMN_DEFAULT'] === 'NULL') {
            $row['COLUMN_DEFAULT'] = null;
        }
        $out[(string) $row['COLUMN_NAME']] = $row;
    }
    ksort($out);
    return $out;
};
$createTable = static fn (mysqli $db): string => (string) $db->query('SHOW CREATE TABLE `libri`')->fetch_row()[1];

try {
    // Reference: the fresh-install table.
    $db = $fresh($freshLibri);
    $expected = $describe($db);
    $db->close();
    $check(count($expected) === 5, 'schema.sql defines the five film columns');

    // A. 0.9.5 database: libri without the film columns, one book on file.
    $db = $fresh((string) $oldLibri);
    $db->query("INSERT INTO libri (titolo, tipo_media, formato) VALUES ('Old book', 'libro', 'cartaceo')");
    $bookId = (int) $db->insert_id;
    $check($describe($db) === [], 'the pre-upgrade libri table has no film column');

    $run($db, $migration);
    $after = $describe($db);
    $check(array_keys($after) === array_keys($expected), 'the migration adds the five film columns');
    foreach ($expected as $name => $def) {
        $check($after[$name] === $def, "{$name} matches schema.sql (" . $def['COLUMN_TYPE'] . ', default ' . var_export($def['COLUMN_DEFAULT'], true) . ', after ' . $def['PREV'] . ')');
    }
    $check($after['durata_minuti']['COLUMN_TYPE'] === 'smallint unsigned' && $after['interpreti']['COLUMN_TYPE'] === 'text'
        && $after['sottotitoli']['COLUMN_TYPE'] === 'varchar(255)' && $after['classificazione_eta']['COLUMN_TYPE'] === 'varchar(50)'
        && $after['codice_regione']['COLUMN_TYPE'] === 'varchar(20)', 'column types are smallint unsigned / text / varchar(255) / varchar(50) / varchar(20)');
    $row = $db->query("SELECT titolo, durata_minuti, interpreti, sottotitoli, classificazione_eta, codice_regione FROM libri WHERE id = {$bookId}")->fetch_assoc();
    $check($row === ['titolo' => 'Old book', 'durata_minuti' => null, 'interpreti' => null, 'sottotitoli' => null, 'classificazione_eta' => null, 'codice_regione' => null], 'the existing book survives with NULL film fields');

    // Idempotency: a second run changes neither the table nor the stored data.
    $db->query("UPDATE libri SET durata_minuti = 142, interpreti = 'Tim Robbins; Morgan Freeman', sottotitoli = 'ita, eng', classificazione_eta = 'VM14', codice_regione = '2' WHERE id = {$bookId}");
    $before = $createTable($db);
    $run($db, $migration);
    $check($createTable($db) === $before, 'a second run leaves the table definition unchanged');
    $row = $db->query("SELECT CAST(durata_minuti AS CHAR) AS durata_minuti, interpreti, sottotitoli, classificazione_eta, codice_regione FROM libri WHERE id = {$bookId}")->fetch_assoc();
    $check($row === ['durata_minuti' => '142', 'interpreti' => 'Tim Robbins; Morgan Freeman', 'sottotitoli' => 'ita, eng', 'classificazione_eta' => 'VM14', 'codice_regione' => '2'], 'a second run keeps the stored film data');
    $db->close();

    // B. a database SchemaRepair already brought to the fresh-install shape.
    $db = $fresh($freshLibri);
    $before = $createTable($db);
    $run($db, $migration);
    $check($createTable($db) === $before, 'an already repaired libri table is left untouched');
    $db->close();

    echo "SUCCESS {$checks} migration checks\n";
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}
