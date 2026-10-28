<?php
declare(strict_types=1);
/**
 * Run the real patron-cards-import section of migrate_0.9.0.sql against a
 * scoped copy of the 0.8.9 import_logs table, twice.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$table = 'zz_mig090_pci_' . bin2hex(random_bytes(4));
$file = (string) file_get_contents(dirname(__DIR__) . '/installer/database/migrations/migrate_0.9.0.sql');
if (!preg_match('/-- ── patron-cards-import ──(.*?)-- ── end patron-cards-import ──/s', $file, $m)) {
    throw new RuntimeException('FAIL the patron-cards-import section is missing from migrate_0.9.0.sql');
}
$source = preg_replace('/^--.*$/m', '', $m[1]);
$source = preg_replace('/\bimport_logs\b/', $table, $source);
$run = static function () use ($db, $source): void {
    foreach (explode(';', $source) as $sql) { if (trim($sql) !== '') { $db->query($sql); } }
};
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $checks++; echo "OK {$label}\n";
};
$type = static function () use ($db, $table): array {
    $r = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'import_type'");
    return $r->fetch_assoc() ?? [];
};
try {
    // The 0.8.9 shape of the column.
    $db->query("CREATE TABLE {$table} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, import_id VARCHAR(36) NOT NULL, import_type ENUM('csv','librarything') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type of import', file_name VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO {$table} (import_id, import_type, file_name) VALUES ('a','csv','books.csv'),('b','librarything','lt.tsv')");
    $check(!str_contains($type()['COLUMN_TYPE'] ?? '', "'users'"), 'the pre-upgrade enum has no users value');
    $run();
    $after = $type();
    $check(($after['COLUMN_TYPE'] ?? '') === "enum('csv','librarything','users')", 'the migration appends users and keeps the existing values in order');
    $check(($after['IS_NULLABLE'] ?? '') === 'NO' && ($after['COLUMN_COMMENT'] ?? '') === 'Type of import', 'NOT NULL and the comment survive the change');
    $db->query("INSERT INTO {$table} (import_id, import_type, file_name) VALUES ('c','users','patrons.csv')");
    $check($db->query("SELECT GROUP_CONCAT(CONCAT(import_id,':',import_type) ORDER BY id) FROM {$table}")->fetch_row()[0] === 'a:csv,b:librarything,c:users', 'existing rows are preserved and a users row can be stored');
    $run();
    $check(($type()['COLUMN_TYPE'] ?? '') === "enum('csv','librarything','users')", 'a second run is a no-op');
    // A stacked section that already added another value must not lose it.
    $db->query("DELETE FROM {$table} WHERE import_type = 'users'");
    $db->query("ALTER TABLE {$table} MODIFY COLUMN import_type ENUM('csv','librarything','marc') NOT NULL COMMENT 'Type of import'");
    $run();
    $check(($type()['COLUMN_TYPE'] ?? '') === "enum('csv','librarything','marc','users')", 'a value added by another section is kept');
    $check(str_contains((string) file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql'), "`import_type` enum('csv','librarything','users')"), 'fresh install includes the users value');
    $version = json_decode((string) file_get_contents(dirname(__DIR__) . '/version.json'), true)['version'];
    $check(version_compare('0.9.0', $version, '<='), 'release includes the migration');
    echo "SUCCESS {$checks} migration checks\n";
} finally {
    $db->query("DROP TABLE IF EXISTS {$table}");
}
