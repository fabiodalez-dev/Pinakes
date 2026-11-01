<?php
declare(strict_types=1);

/**
 * Mobile API 1.7.0 — the plugin tables of the staff area reach every
 * installation, and the idempotency store behaves.
 *
 *   A. Fresh install: PluginManager::activatePlugin() (the production path,
 *      never ensureSchema() alone) creates every 1.7.0 table.
 *   B. Upgrade: an installation recorded at 1.6.0 without them gets them from
 *      the real bundled-plugin sync, and the version moves to the shipped one.
 *   C. Same-version self-heal: a table dropped behind the plugin's back is
 *      rebuilt by the next sync (expectedTables()).
 *   D. StaffIdempotency: key parsing (header, body, malformed), payload hash
 *      independent of key order, remember/find per operator and scope, 24 h
 *      expiry and purge, advisory lock exclusion between two connections.
 *   E. ImportJobStore: job visibility (owner / admin), the session swap
 *      round trip a chunk relies on, chunk persistence, expiry, purge, and
 *      upload removal confined to the importers' own directories.
 *
 * Runs against a DISPOSABLE database ("<db>_mobileapi", override with
 * MOBILEAPI_SANDBOX_DB) and refuses the installation's own. It FAILS rather
 * than skips when that database is unreachable.
 *
 * Run: php tests/mobile-api-staff-tables.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\HookManager;
use App\Support\PluginManager;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// A throwaway key: the sandbox is wiped afterwards, nothing encrypted here survives.
$_ENV['PLUGIN_ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
putenv('PINAKES_DISABLE_CLI_PURGE=1');

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
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

$sandbox = getenv('MOBILEAPI_SANDBOX_DB') ?: ($dbName . '_mobileapi');
if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandbox) !== 1) {
    fwrite(STDERR, "FAIL: invalid sandbox database name '{$sandbox}'\n");
    exit(1);
}
$real = array_map('strtolower', array_filter([(string) $dbName, (string) getenv('E2E_DB_NAME'), (string) ($env['DB_NAME'] ?? '')]));
if (in_array(strtolower($sandbox), $real, true)) {
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
$wipe = static function (mysqli $db): void {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $tables = [];
    $result = $db->query('SHOW FULL TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = [$row[0], $row[1]];
    }
    foreach ($tables as [$table, $type]) {
        $db->query(($type === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . $table . '`');
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
};

$admin = $connect('');
$admin->query("CREATE DATABASE IF NOT EXISTS `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->close();
$prep = $connect($sandbox);
$wipe($prep);
$prep->close();

$fatal = null;
$tables = ['mobile_idempotency_keys', 'mobile_import_jobs'];
try {
    $useSocket = is_string($socket) && $socket !== '' && file_exists($socket);
    $command = $useSocket
        ? sprintf('mysql --default-character-set=utf8mb4 --socket=%s -u %s %s', escapeshellarg($socket), escapeshellarg((string) $dbUser), escapeshellarg($sandbox))
        : sprintf('mysql --default-character-set=utf8mb4 -h %s -P %d --protocol=TCP -u %s %s', escapeshellarg((string) $dbHost), $dbPort, escapeshellarg((string) $dbUser), escapeshellarg($sandbox));
    $command .= ' < ' . escapeshellarg($root . '/installer/database/schema.sql') . ' 2>&1';
    $previous = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . (string) $dbPass);
    try {
        exec($command, $out, $rc);
    } finally {
        $previous === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previous);
    }
    if ($rc !== 0) {
        throw new RuntimeException('could not load schema.sql: ' . implode("\n", $out));
    }

    $db = $connect($sandbox);
    $manager = new PluginManager($db, new HookManager($db));
    $has = static fn (string $t): bool => $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'")->num_rows === 1;
    $dropAll = static function () use ($db, $tables): void {
        foreach ($tables as $t) {
            $db->query("DROP TABLE IF EXISTS `{$t}`");
        }
    };
    $allPresent = static fn (): bool => array_reduce($tables, static fn (bool $c, string $t): bool => $c && $has($t), true);
    $shipped = (string) (json_decode((string) file_get_contents($root . '/storage/plugins/mobile-api/plugin.json'), true)['version'] ?? '');

    echo "A. Fresh install through PluginManager::activatePlugin()\n";
    $manager->autoRegisterBundledPlugins();
    $row = $db->query("SELECT id, is_active FROM plugins WHERE name = 'mobile-api'")->fetch_assoc();
    $check($row !== null, 'mobile-api registered');
    $dropAll();
    $result = $manager->activatePlugin((int) $row['id']);
    $check(($result['success'] ?? false) === true, 'activation succeeds: ' . (string) ($result['message'] ?? ''));
    $check($allPresent(), 'activation creates ' . implode(', ', $tables));
    $check($db->query("SHOW INDEX FROM mobile_idempotency_keys WHERE Key_name = 'uq_mobile_idem' AND Non_unique = 0")->num_rows === 3, 'idempotency key unique on (user_id, scope, idem_key)');

    echo "\nB. Upgrade from 1.6.0 through the real bundled-plugin sync\n";
    $dropAll();
    $db->query("UPDATE plugins SET version = '1.6.0' WHERE name = 'mobile-api'");
    $check(!$has('mobile_idempotency_keys'), 'rewound: a 1.6.0 installation has none of the 1.7.0 tables');
    $manager->autoRegisterBundledPlugins();
    $after = (string) $db->query("SELECT version FROM plugins WHERE name = 'mobile-api'")->fetch_assoc()['version'];
    $check($after === $shipped, "recorded version moves to the shipped {$shipped} (got {$after})");
    $check($allPresent(), 'the upgrade creates the 1.7.0 tables on an installation that already had the plugin');

    echo "\nC. Same-version self-heal\n";
    $dropAll();
    $manager->autoRegisterBundledPlugins();
    $check($allPresent(), 'tables dropped at the same version are rebuilt by the next sync');

    echo "\nD. StaffIdempotency\n";
    require_once $root . '/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';
    require_once $root . '/storage/plugins/mobile-api/src/Support/CirculationOutcome.php';
    require_once $root . '/storage/plugins/mobile-api/src/Support/StaffIdempotency.php';
    $I = \App\Plugins\MobileApi\Support\StaffIdempotency::class;
    $req = static fn (array $headers = []): \Psr\Http\Message\ServerRequestInterface => array_reduce(
        array_keys($headers),
        static fn ($r, $h) => $r->withHeader($h, $headers[$h]),
        (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/v1/staff/loans')
    );
    $check($I::keyFrom($req(['Idempotency-Key' => 'abc-1']), []) === 'abc-1', 'header key read');
    $check($I::keyFrom($req(), ['idempotency_key' => 'body.key:2']) === 'body.key:2', 'body key read');
    $check($I::keyFrom($req(['Idempotency-Key' => 'hdr']), ['idempotency_key' => 'body']) === 'hdr', 'header wins over body');
    $check($I::keyFrom($req(), []) === null, 'no key → null');
    $check($I::keyFrom($req(['Idempotency-Key' => 'bad key']), []) === false, 'spaces → malformed');
    $check($I::keyFrom($req(), ['idempotency_key' => str_repeat('a', 129)]) === false, '129 chars → malformed');
    $check($I::keyFrom($req(), ['idempotency_key' => 5]) === false, 'non-string body key → malformed');
    $check($I::hash(['a' => 1, 'b' => 'x']) === $I::hash(['b' => 'x', 'a' => 1]), 'hash ignores key order');
    $check($I::hash(['a' => 1]) !== $I::hash(['a' => 2]), 'hash changes with the payload');

    $db->query("INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, email_verificata, codice_tessera, privacy_accettata)
                VALUES ('Op', 'One', 'op.one@example.invalid', 'x', 'staff', 'attivo', 1, 'IDEM1', 1),
                       ('Op', 'Two', 'op.two@example.invalid', 'x', 'staff', 'attivo', 1, 'IDEM2', 1)");
    $op1 = (int) $db->query("SELECT id FROM utenti WHERE codice_tessera = 'IDEM1'")->fetch_row()[0];
    $op2 = (int) $db->query("SELECT id FROM utenti WHERE codice_tessera = 'IDEM2'")->fetch_row()[0];
    $store = new $I($db);
    $store->remember($op1, 'loan', 'k1', str_repeat('a', 64), 42);
    $check($store->find($op1, 'loan', 'k1') === ['request_hash' => str_repeat('a', 64), 'resource_id' => 42], 'remembered key found');
    $check($store->find($op2, 'loan', 'k1') === null, "another operator's key is separate");
    $check($store->find($op1, 'reservation', 'k1') === null, 'another scope is separate');
    $db->query("UPDATE mobile_idempotency_keys SET created_at = NOW() - INTERVAL 25 HOUR WHERE user_id = {$op1}");
    $check($store->find($op1, 'loan', 'k1') === null, 'a key older than 24 h is ignored');
    $store->purgeExpired();
    $check((int) $db->query('SELECT COUNT(*) FROM mobile_idempotency_keys')->fetch_row()[0] === 0, 'purgeExpired() drops it');
    $store->remember($op1, 'loan', 'k1', str_repeat('b', 64), 43);
    $store->remember($op1, 'loan', 'k1', str_repeat('c', 64), 44);
    $check($store->find($op1, 'loan', 'k1')['resource_id'] === 44, 'the same key re-remembered replaces the row (no duplicate-key error)');

    $name = $store->lockName('create', 'loan', '7');
    $check(strlen($name) <= 64 && $name === $store->lockName('create', 'loan', '7') && $name !== $store->lockName('create', 'loan', '8'), 'lock names are stable, distinct and ≤ 64 chars');
    $check($store->lock($name), 'first connection takes the lock');
    $other = $connect($sandbox);
    $probe = $other->query("SELECT GET_LOCK('" . $other->real_escape_string($name) . "', 0)")->fetch_row()[0];
    $check((int) $probe === 0, 'a second connection cannot take it meanwhile');
    $store->unlock($name);
    $probe = $other->query("SELECT GET_LOCK('" . $other->real_escape_string($name) . "', 0)")->fetch_row()[0];
    $check((int) $probe === 1, 'released: the second connection gets it');
    $other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string($name) . "')");
    $other->close();

    echo "\nE. ImportJobStore\n";
    require_once $root . '/storage/plugins/mobile-api/src/Support/Delegation.php';
    require_once $root . '/storage/plugins/mobile-api/src/Support/ImportJobStore.php';
    $jobs = new \App\Plugins\MobileApi\Support\ImportJobStore($db);
    $uploads = $root . '/writable/uploads';
    @mkdir($uploads, 0755, true);
    $file = $uploads . '/csv_import_unit_' . bin2hex(random_bytes(4)) . '.csv';
    file_put_contents($file, "titolo\nA\n");
    $state = ['file_path' => $file, 'total_rows' => 1, 'imported' => 0, 'errors' => [], 'note' => "Caffè \u{1F4DA}"];
    $jobId = $jobs->create($op1, null, 'csv', 'x.csv', 1, 10, false, $state);
    $check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $jobId) === 1, 'job id is a UUID v4');
    $own = $jobs->find($jobId, $op1, false);
    $check($own !== null && $own['state'] === $state && $own['status'] === 'ready' && $own['expired'] === false, 'owner reads the job with its state verbatim (UTF-8 intact)');
    $check($jobs->find($jobId, $op2, false) === null, 'another operator does not see it');
    $check($jobs->find($jobId, $op2, true) !== null, 'an admin does');
    $check($jobs->find('not-a-uuid', $op1, true) === null, 'a malformed id is not looked up');

    $_SESSION = ['csv_import_data' => ['web' => 'tab']];
    $out = \App\Plugins\MobileApi\Support\Delegation::run(static function () use ($own): \Psr\Http\Message\ResponseInterface {
        $_SESSION['csv_import_data'] = $own['state'];
        $_SESSION['csv_import_data']['imported']++;
        return new \Slim\Psr7\Response();
    });
    $check(($out->session['csv_import_data']['imported'] ?? null) === 1, 'the chunk sees the job state in the session and its update is captured');
    $check($_SESSION === ['csv_import_data' => ['web' => 'tab']], "the web session's own import is untouched");

    // saveChunk() is a compare-and-set on `running` (the status the chunk
    // sets before delegating): a job cancelled meanwhile is never resurrected.
    $check($jobs->saveChunk($jobId, ['stale' => true], 9, 'ready', []) === false, 'saveChunk() refuses a job that is not running');
    $jobs->setStatus($jobId, 'running');
    $jobs->cancel((array) $jobs->find($jobId, $op1, false));
    $cancelled = $jobs->find($jobId, $op1, false);
    $check($jobs->saveChunk($jobId, ['stale' => true], 9, 'completed', ['complete' => true]) === false, 'a chunk finishing after a cancel loses the race');
    $kept = $jobs->find($jobId, $op1, false);
    $check($kept['status'] === 'cancelled' && $kept['processed'] === 0 && $kept['state'] === $cancelled['state'], 'the cancelled job keeps its status, counters and state');
    $db->query("UPDATE mobile_import_jobs SET status = 'completed' WHERE id = '{$jobId}'");
    $jobs->cancel((array) $jobs->find($jobId, $op1, false));
    $check($jobs->find($jobId, $op1, false)['status'] === 'completed', 'cancel() never turns a completed job into a cancelled one');

    $jobs->setStatus($jobId, 'running');
    $check($jobs->saveChunk($jobId, $out->session['csv_import_data'], 1, 'completed', ['processed' => 1, 'complete' => true]) === true, 'saveChunk() saves a running job');
    $saved = $jobs->find($jobId, $op1, false);
    $check($saved['processed'] === 1 && $saved['status'] === 'completed' && $saved['state']['imported'] === 1 && $saved['summary'] === ['processed' => 1, 'complete' => true], 'chunk outcome persisted');
    $check(count($jobs->listFor($op1, 0, 10)) === 1 && $jobs->listFor($op2, 0, 10) === [], 'listFor() is per operator');

    $db->query("UPDATE mobile_import_jobs SET expires_at = NOW() - INTERVAL 30 MINUTE WHERE id = '{$jobId}'");
    $check($jobs->find($jobId, $op1, false)['expired'] === true, 'expired flag');
    $check($jobs->listFor($op1, 0, 10) === [], 'expired jobs leave the list');
    $jobs->purgeExpired();
    $check($jobs->find($jobId, $op1, true) !== null, 'a just-expired job is kept for an hour (a late chunk answers 410, not 404)');
    $db->query("UPDATE mobile_import_jobs SET expires_at = NOW() - INTERVAL 2 HOUR WHERE id = '{$jobId}'");
    $jobs->purgeExpired();
    $check($jobs->find($jobId, $op1, true) === null && !is_file($file), 'purge drops the job and its saved upload');

    $outside = sys_get_temp_dir() . '/mapi_unit_' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($outside, 'keep');
    \App\Plugins\MobileApi\Support\ImportJobStore::unlinkUpload(['file_path' => $outside]);
    $check(is_file($outside), 'a path outside the importers\' directories is never deleted');
    @unlink($outside);

    $db->query("DELETE FROM utenti WHERE id = {$op1}");
    $check((int) $db->query("SELECT COUNT(*) FROM mobile_idempotency_keys WHERE user_id = {$op1}")->fetch_row()[0] === 0, 'keys follow their operator (ON DELETE CASCADE)');

    echo "\nF. 1.10.0 upgrade widens mobile_import_jobs.kind\n";
    $kindType = static fn (): string => strtolower((string) $db->query(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mobile_import_jobs' AND COLUMN_NAME = 'kind'"
    )->fetch_row()[0]);
    $check($kindType() === 'varchar(32)', 'a fresh activation creates kind as VARCHAR(32)');
    $db->query("DELETE FROM mobile_import_jobs");
    $db->query("ALTER TABLE mobile_import_jobs MODIFY kind ENUM('csv','librarything') NOT NULL");
    $oldJob = $jobs->create($op2, null, 'librarything', 'old.tsv', 3, 10, false, ['legacy' => true]);
    $db->query("UPDATE plugins SET version = '1.9.0' WHERE name = 'mobile-api'");
    $check($kindType() === "enum('csv','librarything')", 'rewound: a 1.9.0 installation has the two-value ENUM and a saved job');
    $manager->autoRegisterBundledPlugins();
    $check($kindType() === 'varchar(32)', 'the real bundled-plugin sync widens kind to VARCHAR(32)');
    $legacy = $jobs->find($oldJob, $op2, false);
    $check($legacy !== null && $legacy['kind'] === 'librarything' && $legacy['state'] === ['legacy' => true], 'the job saved before the upgrade keeps its kind and state');
    $marcJob = $jobs->create($op2, null, 'marc', 'records.mrc', 5, 10, false, []);
    $check(($jobs->find($marcJob, $op2, false)['kind'] ?? null) === 'marc', 'a 1.10.0 job kind (marc) is stored');
    $ids = static fn (array $rows): array => array_map(static fn (array $r): string => (string) $r['id'], $rows);
    $legacyList = $ids($jobs->listFor($op2, 0, 10, ['csv', 'librarything']));
    $check(in_array($oldJob, $legacyList, true) && !in_array($marcJob, $legacyList, true), 'listFor() filtered by kind leaves the marc job out of the CSV/LibraryThing list');
    $allList = $ids($jobs->listFor($op2, 0, 10));
    $check(in_array($oldJob, $allList, true) && in_array($marcJob, $allList, true), 'listFor() without a filter lists every kind');
    $check($jobs->listFor($op2, 0, 10, []) === [], 'an empty kind filter lists nothing');
    $manager->autoRegisterBundledPlugins();
    $check($kindType() === 'varchar(32)' && $jobs->find($oldJob, $op2, false) !== null, 'a second sync is a no-op');

    $db->close();
} catch (\Throwable $error) {
    $fatal = $error;
} finally {
    try {
        $cleanup = $connect($sandbox);
        $wipe($cleanup);
        $cleanup->close();
    } catch (\Throwable $error) {
        fwrite(STDERR, "CLEANUP FAILED: {$error->getMessage()}\n");
        $fail++;
    }
}

if ($fatal !== null) {
    fwrite(STDERR, "\nFAIL: {$fatal->getMessage()}\n{$fatal->getTraceAsString()}\n");
    exit(1);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
