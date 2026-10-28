<?php
declare(strict_types=1);

/**
 * Run the REAL "feature toggles" section of migrate_0.9.0.sql, the way the
 * Updater does (comment lines dropped, split on ';'), against sandbox
 * databases, and check effect and idempotency.
 *
 *  A. an 0.8.9 database (only system_settings, none of the 0.9.0 tables):
 *     the eleven rows with Features::DEFAULTS, a second run changes nothing;
 *  B. a value the operator already saved is never overwritten;
 *  C. an upgrade from a 0.9.0 release candidate whose library used a module
 *     (sessions, suppliers, visits, RDA overrides, ledger rows, a daily fine)
 *     starts that module ON; legacy-penalty rows alone do not turn fines on;
 *  D. the full migration file, every section, then this one: same defaults.
 *
 * Needs a user allowed to create the sandbox database (E2E_MIG_DB, default
 * "<DB_NAME>_migtest_ft"). Run: php tests/migration-0.9.0-feature-toggles.unit.php
 */

use App\Support\Features;

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null);
$sandbox = getenv('E2E_MIG_DB') ?: preg_replace('/^fabiodal_biblioteca_/', 'fabiodal_', (string) $env['DB_NAME']) . '_migtest_ft';
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

$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0.sql');
if (preg_match('/-- ── feature toggles ──(.*?)-- ── end feature toggles ──/s', $migration, $m) !== 1) {
    fwrite(STDERR, "feature toggles section not found in migrate_0.9.0.sql\n");
    exit(1);
}
$section = $m[1];
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
if (preg_match('/CREATE TABLE `system_settings` \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $ss) !== 1) {
    fwrite(STDERR, "system_settings not found in schema.sql\n");
    exit(1);
}
$settingsTable = $ss[0];

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
$fresh = static function () use ($admin, $sandbox, $host, $env, $port, $socket, $settingsTable): mysqli {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $env['DB_USER'], $env['DB_PASS'] ?? '', $sandbox, $port, $socket);
    $db->set_charset('utf8mb4');
    $db->query($settingsTable);
    return $db;
};
/** @return array<string, string> */
$rows = static function (mysqli $db): array {
    $out = [];
    foreach ($db->query("SELECT setting_key, setting_value FROM system_settings WHERE category = 'features' ORDER BY setting_key")->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[$r['setting_key']] = (string) $r['setting_value'];
    }
    return $out;
};
$sorted = static function (array $a): array {
    ksort($a);
    return $a;
};

$exit = 1;
try {
    // A. 0.8.9: no 0.9.0 table at all.
    $db = $fresh();
    $check($rows($db) === [], 'A: the 0.8.9 database has no features rows');
    $run($db, $section);
    $check($rows($db) === $sorted(Features::DEFAULTS), 'A: one row per module with the upgrade default (Features::DEFAULTS)');
    $run($db, $section);
    $check((int) $db->query("SELECT COUNT(*) FROM system_settings WHERE category = 'features'")->fetch_row()[0] === count(Features::DEFAULTS), 'A: a second run adds no row');
    $check($rows($db) === $sorted(Features::DEFAULTS), 'A: a second run changes no value');

    // B. a saved value survives.
    $db->query("UPDATE system_settings SET setting_value = '1' WHERE category = 'features' AND setting_key = 'fines'");
    $db->query("UPDATE system_settings SET setting_value = '0' WHERE category = 'features' AND setting_key = 'marc'");
    $run($db, $section);
    $after = $rows($db);
    $check($after['fines'] === '1' && $after['marc'] === '0', 'B: values the operator saved are never overwritten');
    $db->close();

    // C. an RC upgrade with data for the modules.
    $db = $fresh();
    $db->query('CREATE TABLE inventario_sessioni (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, nome varchar(255) NOT NULL)');
    $db->query('CREATE TABLE fornitori (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, nome varchar(255) NOT NULL)');
    $db->query('CREATE TABLE presenze (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, tipo varchar(10) NOT NULL)');
    $db->query('CREATE TABLE libri (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, titolo varchar(255) NOT NULL, deleted_at datetime DEFAULT NULL, rda_content_type varchar(10) DEFAULT NULL, rda_media_type varchar(10) DEFAULT NULL, rda_carrier_type varchar(10) DEFAULT NULL)');
    $db->query('CREATE TABLE movimenti_lettore (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, importo decimal(10,2) NOT NULL, chiave_idempotenza varchar(100) DEFAULT NULL)');
    $run($db, $section);
    $empty = $rows($db);
    $check($empty === $sorted(Features::DEFAULTS), 'C: the 0.9.0 tables empty (a plain 0.8.9 upgrade): the upgrade defaults');
    $db->query("DELETE FROM system_settings WHERE category = 'features'");
    $db->query("INSERT INTO inventario_sessioni (nome) VALUES ('Scaffale A')");
    $db->query("INSERT INTO fornitori (nome) VALUES ('Libreria')");
    $db->query("INSERT INTO presenze (tipo) VALUES ('ospite')");
    $db->query("INSERT INTO libri (titolo, rda_content_type) VALUES ('Con override', 'txt')");
    $db->query("INSERT INTO movimenti_lettore (importo, chiave_idempotenza) VALUES (5.00, 'penalty:1')");
    $run($db, $section);
    $used = $rows($db);
    $check($used['stocktake'] === '1', 'C: a stocktake session keeps the stocktake on');
    $check($used['suppliers'] === '1', 'C: a supplier keeps the suppliers on');
    $check($used['visitor_log'] === '1', 'C: a visit keeps the visitor log on');
    $check($used['rda_types'] === '1', 'C: an RDA override keeps the RDA types on');
    $check($used['fines'] === '0', 'C: legacy penalties converted by the migration alone do not turn fines on');
    $db->query("DELETE FROM system_settings WHERE category = 'features' AND setting_key = 'fines'");
    $db->query("INSERT INTO movimenti_lettore (importo, chiave_idempotenza) VALUES (0.50, 'late:7')");
    $run($db, $section);
    $check($rows($db)['fines'] === '1', 'C: a late fine already charged keeps fines on');
    $db->query("DELETE FROM system_settings WHERE category = 'features' AND setting_key = 'fines'");
    $db->query('DELETE FROM movimenti_lettore');
    $db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', 'daily_fine', '0,50')");
    $run($db, $section);
    $check($rows($db)['fines'] === '1', 'C: a configured daily fine keeps fines on');
    $db->query("DELETE FROM system_settings WHERE category = 'features' AND setting_key = 'rda_types'");
    $db->query("UPDATE libri SET deleted_at = NOW()");
    $run($db, $section);
    $check($rows($db)['rda_types'] === '0', 'C: an override on an archived (soft-deleted) title does not count');
    $db->close();

    // D. the whole file in order, then the section again: idempotent with every section.
    $old = shell_exec('git -C ' . escapeshellarg($root) . ' show v0.8.9:installer/database/schema.sql 2>/dev/null')
        ?: shell_exec('git -C ' . escapeshellarg($root) . ' show 12f05750:installer/database/schema.sql 2>/dev/null');
    if (is_string($old) && str_contains($old, 'CREATE TABLE `system_settings`')) {
        $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
        $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db = new mysqli($host, $env['DB_USER'], $env['DB_PASS'] ?? '', $sandbox, $port, $socket);
        $db->set_charset('utf8mb4');
        $db->multi_query($old);
        do {
            if ($r = $db->store_result()) {
                $r->free();
            }
        } while ($db->more_results() && $db->next_result());
        $run($db, $migration);
        $check($rows($db) === $sorted(Features::DEFAULTS), 'D: the full migrate_0.9.0.sql on the 0.8.9 schema seeds the upgrade defaults');
        $run($db, $migration);
        $check($rows($db) === $sorted(Features::DEFAULTS), 'D: the full file a second time changes nothing');
        $db->close();
    } else {
        echo "0.8.9 schema.sql not in the local git history (shallow clone): full-file run skipped, sections A-C cover the effect\n";
    }

    $version = json_decode((string) file_get_contents($root . '/version.json'), true)['version'];
    $check(version_compare('0.9.0', $version, '<='), 'the release version includes the migration');
    echo "SUCCESS {$checks} migration checks\n";
    $exit = 0;
} catch (\Throwable $e) {
    echo $e->getMessage() . "\n";
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}
exit($exit);
