<?php
declare(strict_types=1);
/**
 * Run the real MARC section of migrate_0.9.0.sql against a scoped copy of the
 * 0.8.9 import_logs table, twice: import_type gains 'marc', existing rows and
 * the other values survive, and a second run changes nothing. A second table
 * that another 0.9.0 section already extended ('users') proves the enum is
 * built from the current values, so the order of the sections does not matter.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$table = 'zz_mig090_marc_' . bin2hex(random_bytes(4));
$file = (string) file_get_contents(dirname(__DIR__) . '/installer/database/migrations/migrate_0.9.0.sql');
$start = strpos($file, '-- ── MARC bulk import / copy cataloguing ──');
$end = strpos($file, '-- ── end MARC bulk import / copy cataloguing ──');
if ($start === false || $end === false) {
    fwrite(STDERR, "FAIL the MARC section is not delimited in migrate_0.9.0.sql\n");
    exit(1);
}
$source = substr($file, $start, $end - $start);
$section = (string) preg_replace('/^--.*$/m', '', $source);
$run = static function (?string $target = null) use ($db, $section, $table): void {
    $scoped = (string) preg_replace('/\bimport_logs\b/', $target ?? $table, $section);
    foreach (explode(';', $scoped) as $sql) {
        if (trim($sql) !== '') {
            $db->query($sql);
        }
    }
};
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};
$type = static fn (?string $t = null): string => (string) $db->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . ($t ?? $table) . "' AND COLUMN_NAME = 'import_type'")->fetch_row()[0];
$other = $table . '_u';
try {
    // The 0.8.9 shape of the column the migration touches.
    $db->query("CREATE TABLE {$table} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, import_id VARCHAR(36) NOT NULL, import_type ENUM('csv','librarything') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type of import', file_name VARCHAR(255) DEFAULT NULL, KEY idx_import_type (import_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO {$table} (import_id, import_type, file_name) VALUES ('a','csv','libri.csv'),('b','librarything','lt.tsv')");
    $check($type() === "enum('csv','librarything')", 'the pre-upgrade column has no marc value');
    $run();
    $check($type() === "enum('csv','librarything','marc')", 'the migration adds marc after the existing values');
    $col = $db->query("SELECT IS_NULLABLE, COLLATION_NAME, COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'import_type'")->fetch_assoc();
    $check($col['IS_NULLABLE'] === 'NO' && $col['COLLATION_NAME'] === 'utf8mb4_unicode_ci' && $col['COLUMN_COMMENT'] === 'Type of import', 'NOT NULL, collation and comment are kept');
    $check($db->query("SELECT GROUP_CONCAT(CONCAT(import_id,':',import_type) ORDER BY id) FROM {$table}")->fetch_row()[0] === 'a:csv,b:librarything', 'existing rows keep their type');
    $db->query("INSERT INTO {$table} (import_id, import_type, file_name) VALUES ('c','marc','books.mrc')");
    $check($db->query("SELECT import_type FROM {$table} WHERE import_id='c'")->fetch_row()[0] === 'marc', 'a MARC import can be logged');
    $run();
    $check($type() === "enum('csv','librarything','marc')" && (int) $db->query("SELECT COUNT(*) FROM {$table}")->fetch_row()[0] === 3, 'a second run is a no-op');
    // Another section ran first and added its own value: marc goes after it
    // and the existing value and rows survive.
    $db->query("CREATE TABLE {$other} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, import_type ENUM('csv','librarything','users') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type of import') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO {$other} (import_type) VALUES ('users'),('csv')");
    $run($other);
    $check($type($other) === "enum('csv','librarything','users','marc')", 'a value added by an earlier section is kept, marc is appended');
    $check($db->query("SELECT GROUP_CONCAT(import_type ORDER BY id) FROM {$other}")->fetch_row()[0] === 'users,csv', 'rows of the earlier value keep their type');
    $run($other);
    $check($type($other) === "enum('csv','librarything','users','marc')", 'a second run on the extended column is a no-op');
    // Other 0.9.0 sections may add their own values after marc.
    $check(preg_match("/`import_type` enum\\('csv','librarything','marc'[,)]/", (string) file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql')) === 1, 'fresh install has the marc value');
    $version = json_decode((string) file_get_contents(dirname(__DIR__) . '/version.json'), true)['version'];
    $check(version_compare('0.9.0', $version, '<='), 'the release includes the migration');
    echo "SUCCESS {$checks} migration checks\n";
} finally {
    $db->query("DROP TABLE IF EXISTS {$table}");
    $db->query("DROP TABLE IF EXISTS {$other}");
}
