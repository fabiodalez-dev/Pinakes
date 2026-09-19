<?php
declare(strict_types=1);

/**
 * A soft-deleted library request that never reached the catalogue must not be
 * announced to harvesters as a deletion — by ResourceSync or by OAI-PMH.
 *
 * Both protocols owe a tombstone for a record a harvester was GIVEN. The
 * de-listing arms already paired delisted() with everCatalogued(), but the
 * soft-delete tombstones did not: ResourceSync emitted change="deleted" for any
 * soft-deleted libri row, and OAI-PMH's trigger records every soft-deleted book
 * in oai_deleted_records. A request born with is_desiderata = 1 and never
 * catalogued therefore produced a false deletion event that also published its
 * id and timestamps.
 *
 * The guard is "not (request and never catalogued)", deliberately NOT a plain
 * everCatalogued(): that would suppress every soft-delete tombstone on an
 * installation without the plugin (everCatalogued() is then 0=1) and on any
 * ordinary holding created before catalogued_at was stamped on every insert.
 * Part C proves both of those still get their tombstone.
 *
 * Drives the plugins' real query methods (ResourceSync::fetchChangedBooks,
 * OAI-PMH fetchRecordsPage / resolveDeletedIdentifier), with the soft-delete
 * trigger actually installed. Runs only on a disposable sandbox database.
 *
 * Run:  php tests/desiderata-soft-delete-tombstones.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';
require_once $root . '/storage/plugins/resource-sync/ResourceSyncPlugin.php';
require_once $root . '/storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

use App\Support\HookManager;
use App\Plugins\OaiPmhServer\OaiPmhServerPlugin;
use App\Plugins\ResourceSync\ResourceSyncPlugin;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

// Transport resolved exactly as tests/desiderata-catalogued-on-create.unit.php
// does: a socket when one exists, TCP otherwise (CI reaches MySQL over TCP).
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

// ensureSchema() runs DDL and a backfill UPDATE that no rollback can undo, so
// the suite never touches the installation's database: it rebuilds a
// disposable one from schema.sql on every run.
$sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName)) {
    fwrite(STDERR, "FAIL: invalid sandbox database name '{$sandboxName}'\n");
    exit(1);
}
// The sandbox is refused if it names ANY real database this run could mean:
// the installation's (.env DB_NAME) and the E2E one (E2E_DB_NAME). Comparing
// against only one let DESIDERATA_SANDBOX_DB equal to the other slip through,
// and the setup below would then empty every table in it.
if (in_array(strtolower($sandboxName), array_map('strtolower', array_filter([(string) $dbName, (string) getenv('E2E_DB_NAME'), (string) ($env['DB_NAME'] ?? '')])), true)) {
    fwrite(STDERR, "FAIL: the sandbox name is the installation's own database. Name a disposable one.\n");
    exit(1);
}

$connect = static function (string $database) use ($socket, $dbUser, $dbPass, $dbHost, $dbPort): mysqli {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $database, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $database, $dbPort);
    $db->set_charset('utf8mb4');

    return $db;
};

try {
    $admin = $connect('');
    $admin->query("CREATE DATABASE IF NOT EXISTS `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->close();

    $prep = $connect($sandboxName);
    $prep->query('SET FOREIGN_KEY_CHECKS = 0');
    $tables = [];
    $result = $prep->query('SHOW TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    foreach ($tables as $table) {
        $prep->query('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $prep->query('SET FOREIGN_KEY_CHECKS = 1');
    $prep->close();

    $useSocket = is_string($socket) && $socket !== '' && file_exists($socket);
    $command = $useSocket
        ? sprintf('mysql --socket=%s -u %s %s', escapeshellarg($socket), escapeshellarg((string) $dbUser), escapeshellarg($sandboxName))
        : sprintf(
            'mysql -h %s -P %d --protocol=TCP -u %s %s',
            escapeshellarg((string) $dbHost),
            $dbPort,
            escapeshellarg((string) $dbUser),
            escapeshellarg($sandboxName)
        );
    $command .= ' < ' . escapeshellarg($root . '/installer/database/schema.sql') . ' 2>&1';
    $previousPwd = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . (string) $dbPass);
    try {
        exec($command, $out, $rc);
    } finally {
        $previousPwd === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previousPwd);
    }
    if ($rc !== 0) {
        throw new RuntimeException('could not load schema.sql: ' . implode("\n", $out));
    }

    $db = $connect($sandboxName);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: the sandbox database '{$sandboxName}' could not be prepared: {$e->getMessage()}\n");
    exit(1);
}

$fatalError = null;

try {
    // ---- schema: desiderata columns + OAI tombstone table and trigger ----
    (new DesiderataPlugin($db, new HookManager($db)))->ensureSchema();
    $oai = new OaiPmhServerPlugin($db, new HookManager($db));
    $oai->ensureSchema();
    (new ReflectionMethod(OaiPmhServerPlugin::class, 'installTriggers'))->invoke($oai);

    $triggers = (int) $db->query("SELECT COUNT(*) c FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_libri_soft_delete'")->fetch_assoc()['c'];
    $check($triggers === 1, 'the OAI soft-delete trigger is installed (otherwise the OAI half would be vacuous)');

    // Three books, then soft-delete all three.
    $db->query("INSERT INTO libri (titolo, is_desiderata, catalogued_at) VALUES ('Tomb holding', 0, NOW())");
    $holding = (int) $db->insert_id;
    $db->query("INSERT INTO libri (titolo, is_desiderata, catalogued_at) VALUES ('Tomb request', 1, NULL)");
    $request = (int) $db->insert_id;
    // An ordinary holding with no stamp: what a book created before every
    // insert stamped catalogued_at looks like. It WAS published.
    $db->query("INSERT INTO libri (titolo, is_desiderata, catalogued_at) VALUES ('Tomb unstamped holding', 0, NULL)");
    $unstamped = (int) $db->insert_id;
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE id IN ({$holding}, {$request}, {$unstamped})");

    $recorded = (int) $db->query("SELECT COUNT(*) c FROM oai_deleted_records WHERE entity_type = 'book' AND entity_id IN ({$holding}, {$request}, {$unstamped})")->fetch_assoc()['c'];
    $check($recorded === 3, 'the trigger recorded all three deletions — the filtering must happen on read');

    $rs = new ResourceSyncPlugin($db, new HookManager($db));
    $fetchChanged = new ReflectionMethod(ResourceSyncPlugin::class, 'fetchChangedBooks');
    /** @return list<int> ids ResourceSync reports as deleted */
    $rsDeleted = static function (?string $since) use ($fetchChanged, $rs): array {
        $ids = [];
        foreach ($fetchChanged->invoke($rs, $since, 0) as $row) {
            if (!empty($row['deleted_at'])) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    };

    $fetchPage = new ReflectionMethod(OaiPmhServerPlugin::class, 'fetchRecordsPage');
    /** @return list<int> book ids OAI-PMH lists as deleted tombstones */
    $oaiDeleted = static function () use ($fetchPage, $oai): array {
        $ids = [];
        foreach ($fetchPage->invoke($oai, 'books', null, null, 0, 500, 'oai_dc') as $row) {
            // A tombstone row comes back as the oai_deleted_records row itself
            // (entity_type / entity_id / oai_id), tagged _status = deleted.
            if (($row['_status'] ?? '') === 'deleted' && ($row['_entity'] ?? '') === 'book' && isset($row['entity_id'])) {
                $ids[] = (int) $row['entity_id'];
            }
        }
        return $ids;
    };
    $resolve = new ReflectionMethod(OaiPmhServerPlugin::class, 'resolveDeletedIdentifier');

    echo "\nA. ResourceSync\n";
    foreach (['a full listing' => null, 'a ?from= listing' => '2000-01-01 00:00:00'] as $label => $since) {
        $deleted = $rsDeleted($since);
        $check(in_array($holding, $deleted, true), "{$label}: the deleted holding is announced");
        $check(in_array($unstamped, $deleted, true), "{$label}: so is the deleted holding that carries no catalogued_at stamp");
        $check(!in_array($request, $deleted, true), "{$label}: the never-published request is NOT");
    }

    echo "\nB. OAI-PMH\n";
    $deleted = $oaiDeleted();
    $check(in_array($holding, $deleted, true), 'ListRecords: the deleted holding has a tombstone');
    $check(in_array($unstamped, $deleted, true), 'ListRecords: so does the unstamped holding');
    $check(!in_array($request, $deleted, true), 'ListRecords: the never-published request has none');
    $check($resolve->invoke($oai, "oai:pinakes:book:{$holding}", 'example.org') !== null, 'GetRecord: the holding resolves to a deleted record');
    $check($resolve->invoke($oai, "oai:pinakes:book:{$request}", 'example.org') === null, 'GetRecord: the request does not exist, as it never did for a harvester');

    echo "\nC. Without the plugin the guard is inert\n";
    // Drop the plugin's columns and use a FRESH connection: BookVisibility
    // memoises its column probe per connection.
    $db->query('ALTER TABLE libri DROP COLUMN catalogued_at');
    $db->query('ALTER TABLE libri DROP INDEX idx_desiderata');
    $db->query('ALTER TABLE libri DROP COLUMN is_desiderata');
    $bare = $connect($sandboxName);
    $bareRs = new ResourceSyncPlugin($bare, new HookManager($bare));
    $ids = [];
    foreach ($fetchChanged->invoke($bareRs, null, 0) as $row) {
        if (!empty($row['deleted_at'])) { $ids[] = (int) $row['id']; }
    }
    $check(count(array_intersect([$holding, $request, $unstamped], $ids)) === 3,
        'ResourceSync announces every soft-deleted book when the plugin was never installed');
    $bareOai = new OaiPmhServerPlugin($bare, new HookManager($bare));
    $ids = [];
    foreach ($fetchPage->invoke($bareOai, 'books', null, null, 0, 500, 'oai_dc') as $row) {
        if (($row['_status'] ?? '') === 'deleted' && ($row['_entity'] ?? '') === 'book' && isset($row['entity_id'])) {
            $ids[] = (int) $row['entity_id'];
        }
    }
    $check(count(array_intersect([$holding, $request, $unstamped], $ids)) === 3,
        'and so does OAI-PMH');
    $bare->close();
} catch (\Throwable $e) {
    $fatalError = $e;
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFATAL: " . $fatalError->getMessage() . ' @ ' . basename($fatalError->getFile()) . ':' . $fatalError->getLine() . "\n");
    exit(1);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
