<?php
declare(strict_types=1);
/**
 * SchemaRepair against the damage found on an install upgraded by the 0.3.x
 * updater (comment-led statements dropped) and later past 0.4.6/0.4.7: three
 * tables and 24 columns missing while their migrations are recorded.
 * Requires CREATE DATABASE on a prefix derived from E2E_DB_NAME/DB_NAME.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\SchemaRepair;

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) @file_get_contents($root . '/.env'));
$source = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
if ($source === '' || $user === '') {
    throw new RuntimeException('Configure E2E_DB_NAME and E2E_DB_USER (or .env); this suite never silently skips.');
}
$host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost');
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? '');
mysqli_report(MYSQLI_REPORT_OFF);
$admin = new mysqli($host, $user, $pass, '', $port, $socket ?: null);
$sandbox = substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $source), 0, 38) . '_schemarep_' . bin2hex(random_bytes(4));
$tmpRoot = sys_get_temp_dir() . '/pinakes-schemarep-' . bin2hex(random_bytes(4));

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};

$missingTables = ['user_sessions', 'gdpr_requests', 'consent_log'];
$missingLibri = ['issn', 'original_languages', 'entry_date', 'date_started', 'date_read', 'physical_description', 'value', 'condition_lt', 'lt_fields_visibility', 'source', 'from_where', 'lending_patron', 'lending_status', 'lending_start', 'lending_end', 'dewey_wording', 'lccn', 'lc_classification', 'other_call_number', 'bcid', 'barcode', 'oclc', 'work_id'];

try {
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") or throw new RuntimeException('CREATE DATABASE: ' . $admin->error);
    $command = ['mysql', '--host=' . $host, '--port=' . $port, '--user=' . $user];
    if ($socket !== '') {
        $command[] = '--socket=' . $socket;
    }
    $command[] = $sandbox;
    $process = proc_open($command, [0 => ['file', $root . '/installer/database/schema.sql', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['MYSQL_PWD' => $pass]));
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('schema import failed: ' . $err);
    }
    $db = new mysqli($host, $user, $pass, $sandbox, $port, $socket ?: null);
    $db->set_charset('utf8mb4');

    $shape = static function (string $table) use ($db): array {
        $r = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' ORDER BY ORDINAL_POSITION");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    };
    $tableExists = static fn (string $t): bool => (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'")->fetch_row()[0] === 1;

    $parsed = SchemaRepair::parse((string) file_get_contents($root . '/installer/database/schema.sql'));
    $check(count($parsed) === 78 && array_sum(array_map(static fn ($t) => count($t['columns']), $parsed)) === 823, 'schema.sql parses into the same 78 tables and 823 columns as the JS parser');

    $fresh = SchemaRepair::repair($db, $root);
    $check($fresh === ['tables' => [], 'columns' => [], 'failed' => []], 'a complete database is left untouched');

    // The reference shape, then the damage.
    $libriBefore = $shape('libri');
    $utentiBefore = $shape('utenti');
    $tablesBefore = array_combine($missingTables, array_map($shape, $missingTables));
    $db->query("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, data_accettazione_privacy) VALUES ('SR-A','A','Accepted','a@example.test','x','2025-01-01 10:00:00'),('SR-B','B','Never','b@example.test','x',NULL)") or throw new RuntimeException($db->error);
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($missingTables as $t) {
        $db->query("DROP TABLE `{$t}`") or throw new RuntimeException($db->error);
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $db->query('ALTER TABLE utenti DROP COLUMN privacy_accettata') or throw new RuntimeException($db->error);
    $db->query('ALTER TABLE libri ' . implode(', ', array_map(static fn ($c) => "DROP COLUMN `{$c}`", $missingLibri))) or throw new RuntimeException($db->error);
    $check(!$tableExists('user_sessions') && count($shape('libri')) === count($libriBefore) - 23, 'the damaged install lacks 3 tables and 24 columns');

    $result = SchemaRepair::repair($db, $root);
    sort($result['tables']);
    $expectedTables = $missingTables;
    sort($expectedTables);
    $check($result['failed'] === [], 'the repair reports no failure');
    $check($result['tables'] === $expectedTables, 'the three missing tables are created');
    $check(count($result['columns']) === 24 && in_array('utenti.privacy_accettata', $result['columns'], true), 'the 24 missing columns are added');
    $check($shape('libri') === $libriBefore, 'libri gets back every column with the schema type, default, comment and position');
    $check($shape('utenti') === $utentiBefore, 'utenti gets privacy_accettata back in its place');
    foreach ($missingTables as $t) {
        $check($shape($t) === $tablesBefore[$t], "{$t} is created as schema.sql declares it");
    }
    $flags = $db->query("SELECT email, privacy_accettata FROM utenti WHERE email IN ('a@example.test','b@example.test') ORDER BY email")->fetch_all(MYSQLI_NUM);
    $check($flags === [['a@example.test', '1'], ['b@example.test', '0']], 'a reader with a recorded acceptance date counts as accepted, one without does not');
    $check($db->query("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, privacy_accettata, data_accettazione_privacy, privacy_policy_version) VALUES ('SR-C','C','New','c@example.test','x',1,NOW(),'1.0')") === true, 'registration can write privacy_accettata again');

    $again = SchemaRepair::repair($db, $root);
    $check($again === ['tables' => [], 'columns' => [], 'failed' => []], 'a second run adds nothing');

    // Once per version: a marker under storage/ keyed on version.json.
    mkdir($tmpRoot . '/storage', 0777, true);
    mkdir($tmpRoot . '/installer/database', 0777, true);
    copy($root . '/installer/database/schema.sql', $tmpRoot . '/installer/database/schema.sql');
    file_put_contents($tmpRoot . '/version.json', '{"version":"9.9.1"}');
    $db->query('ALTER TABLE libri DROP COLUMN oclc');
    SchemaRepair::runOncePerVersion($db, $tmpRoot);
    $hasOclc = static fn (): bool => (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'oclc'")->fetch_row()[0] === 1;
    $check($hasOclc() && file_get_contents($tmpRoot . '/storage/.schema-repaired') === '9.9.1', 'the first request of a version repairs and writes the marker');
    $db->query('ALTER TABLE libri DROP COLUMN oclc');
    SchemaRepair::runOncePerVersion($db, $tmpRoot);
    $check(!$hasOclc(), 'later requests of the same version skip the check');
    file_put_contents($tmpRoot . '/version.json', '{"version":"9.9.2"}');
    SchemaRepair::runOncePerVersion($db, $tmpRoot);
    $check($hasOclc(), 'a new version checks again');
    $db->query("SELECT GET_LOCK('pinakes_schema_repair', 0)");
    $other = new mysqli($host, $user, $pass, $sandbox, $port, $socket ?: null);
    $other->query('ALTER TABLE libri DROP COLUMN oclc');
    file_put_contents($tmpRoot . '/version.json', '{"version":"9.9.3"}');
    SchemaRepair::runOncePerVersion($other, $tmpRoot);
    $check(!$hasOclc() && file_get_contents($tmpRoot . '/storage/.schema-repaired') === '9.9.2', 'a request that finds the repair running elsewhere leaves it alone');
    $db->query("SELECT RELEASE_LOCK('pinakes_schema_repair')");
    $other->close();

    echo "SUCCESS {$checks} schema repair checks\n";
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    if (is_dir($tmpRoot)) {
        exec('rm -rf ' . escapeshellarg($tmpRoot));
    }
}
