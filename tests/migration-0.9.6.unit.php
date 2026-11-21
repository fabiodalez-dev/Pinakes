<?php
declare(strict_types=1);

/**
 * migrate_0.9.6.sql — public API keys stored as SHA-256 + display prefix.
 *
 * Runs the REAL migration file, split exactly as the Updater splits it,
 * against a sandbox database seeded with the pre-0.9.6 api_keys table and two
 * keys, then asserts:
 *   - schema changes preserve legacy credentials through installation rollback;
 *   - on first use, the installed app backfills hashes and clears plaintext;
 *   - the old keys still authenticate through ApiKeyRepository::getByKey()
 *     (also in the other letter case, as the old case-insensitive lookup did);
 *   - a second run is a no-op (idempotency);
 *   - the upgraded table matches the fresh-install table from schema.sql;
 *   - a ready table is used without further schema or data maintenance;
 *   - an older database backup can be restored and converted again.
 *
 * Needs a database (E2E_DB_* or the worktree .env); skips cleanly otherwise.
 * Sandbox: <DB_NAME>_apikeys96, dropped at the end.
 *
 * Run: php tests/migration-0.9.6.unit.php
 */

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';

final class ApiKeyMigrationTestConnection extends mysqli
{
    public array $directQueries = [];
    public array $prepared = [];
    /** Simulates a database user without ALTER rights. */
    public bool $denyAlter = false;
    /** Simulates a database that fails the legacy lookup (an outage). */
    public bool $failLegacyLookup = false;

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->directQueries[] = $query;
        if ($this->denyAlter && preg_match('/^\s*ALTER\b/i', $query) === 1) {
            throw new mysqli_sql_exception('ALTER command denied to user', 1142);
        }
        return parent::query($query, $result_mode);
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mysqli_stmt|false
    {
        $this->prepared[] = $query;
        if ($this->failLegacyLookup && str_contains($query, 'FROM api_keys WHERE is_active = 1')) {
            return false;
        }
        return parent::prepare($query);
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

// Credentials: E2E_DB_* first (CI), then the worktree .env, parsed line by line.
$dotenv = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($ROOT . '/.env')) as $line) {
    if (str_contains($line, '=') && !str_starts_with(trim($line), '#')) {
        [$k, $v] = explode('=', $line, 2);
        $dotenv[trim($k)] = trim(trim($v), "\"'");
    }
}
$cfg = static fn (string $e2e, string $plain): string => (string) (getenv($e2e) ?: ($dotenv[$plain] ?? ''));
$dbUser = $cfg('E2E_DB_USER', 'DB_USER');
$dbPass = $cfg('E2E_DB_PASS', 'DB_PASS');
$dbHost = $cfg('E2E_DB_HOST', 'DB_HOST');
$dbPort = (int) ($cfg('E2E_DB_PORT', 'DB_PORT') ?: 3306);
$socket = $cfg('E2E_DB_SOCKET', 'DB_SOCKET');
$baseDb = $cfg('E2E_DB_NAME', 'DB_NAME');
$db = null;
if ($dbUser !== '' && $baseDb !== '') {
    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $db = $socket !== '' && file_exists($socket)
            ? @new ApiKeyMigrationTestConnection('localhost', $dbUser, $dbPass, '', 0, $socket)
            : @new ApiKeyMigrationTestConnection($dbHost !== '' ? $dbHost : '127.0.0.1', $dbUser, $dbPass, '', $dbPort);
        if ($db->connect_errno) { $db = null; }
    } catch (\Throwable $e) {
        $db = null;
    }
}
if (!$db instanceof mysqli) {
    echo "SKIP: no reachable database configured (E2E_DB_* or .env)\n";
    exit(0);
}
$db->set_charset('utf8mb4');

$sandbox = preg_replace('/[^A-Za-z0-9_]/', '', $baseDb) . '_apikeys96';
if ($db->query("CREATE DATABASE IF NOT EXISTS `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") === false) {
    echo "SKIP: cannot create sandbox database {$sandbox}: {$db->error}\n";
    exit(0);
}

// The pre-0.9.6 table (as shipped by schema.sql up to 0.9.5).
$oldTable = "CREATE TABLE `api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `api_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `idx_api_key` (`api_key`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$keyA = bin2hex(random_bytes(32));          // a generated (lowercase hex) key
$keyB = bin2hex(random_bytes(32));          // an inactive one
$seed = static function () use ($db, $oldTable, $keyA, $keyB): void {
    $db->query('DROP TABLE IF EXISTS api_keys');
    $db->query($oldTable);
    $db->query("INSERT INTO api_keys (id, api_key, name, is_active) VALUES (1, '{$keyA}', 'Integration A', 1), (2, '{$keyB}', 'Integration B', 0)");
};

// Split the real file exactly as Updater::runMigrations() does.
// Until the 0.9.6 version bump the file waits in migrations/pending/ (the
// Updater and the "migration <= version.json" gate only scan migrations/);
// the release commit moves it next to the others.
$file = $ROOT . '/installer/database/migrations/migrate_0.9.6.sql';
if (!is_file($file)) {
    $file = $ROOT . '/installer/database/migrations/pending/migrate_0.9.6.sql';
}
$source = (string) file_get_contents($file);
$updRef = new ReflectionClass(\App\Support\Updater::class);
$updater = $updRef->newInstanceWithoutConstructor();
$lines = array_filter(explode("\n", $source), static fn (string $l): bool => !preg_match('/^\s*--/', $l));
/** @var string[] $statements */
$statements = $updRef->getMethod('splitSqlStatements')->invoke($updater, implode("\n", $lines));
$runMigration = static function () use ($db, $statements): array {
    $errors = [];
    foreach ($statements as $sql) {
        if (trim($sql) !== '' && $db->query($sql) === false) {
            $errors[] = $db->errno . ': ' . $db->error;
        }
    }
    return $errors;
};

$columns = static function () use ($db): array {
    $out = [];
    $r = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' ORDER BY ORDINAL_POSITION");
    while ($row = $r->fetch_assoc()) {
        // MariaDB reports a NULL default as the string 'NULL', MySQL as SQL NULL.
        $default = $row['COLUMN_DEFAULT'] === 'NULL' ? null : $row['COLUMN_DEFAULT'];
        $out[$row['COLUMN_NAME']] = $row['COLUMN_TYPE'] . '|' . $row['IS_NULLABLE'] . '|' . var_export($default, true);
    }
    return $out;
};
$indexes = static function () use ($db): array {
    $out = [];
    $r = $db->query("SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' GROUP BY INDEX_NAME, NON_UNIQUE ORDER BY INDEX_NAME");
    while ($row = $r->fetch_assoc()) {
        $out[$row['INDEX_NAME']] = $row['NON_UNIQUE'] . ':' . $row['cols'];
    }
    return $out;
};
$rows = static function () use ($db): array {
    return $db->query('SELECT id, api_key, key_hash, key_prefix, name, is_active FROM api_keys ORDER BY id')->fetch_all(MYSQLI_ASSOC);
};
$lookupBefore096 = static function (string $key) use ($db): ?array {
    $stmt = $db->prepare('SELECT id, api_key, name, description, is_active, last_used_at FROM api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
};

try {
    $db->select_db($sandbox);
    $check(count($statements) >= 10, 'the migration file splits into its statements (' . count($statements) . ')');

    echo "Upgrade of a 0.9.5 api_keys table through the real migration\n";
    $seed();
    $db->query('CREATE TABLE api_keys_backup LIKE api_keys');
    $db->query('INSERT INTO api_keys_backup SELECT * FROM api_keys');
    $check(!array_key_exists('key_hash', $columns()), 'the seeded table is the pre-0.9.6 shape');
    $errors = $runMigration();
    $check($errors === [], 'every statement runs without error' . ($errors ? ' (' . implode('; ', $errors) . ')' : ''));
    $cols = $columns();
    $check(isset($cols['key_hash'], $cols['key_prefix']), 'key_hash and key_prefix are added');
    $check(str_ends_with((string) ($cols['api_key'] ?? ''), '|YES|NULL'), 'the legacy api_key column becomes nullable');
    $check(($indexes()['uniq_key_hash'] ?? '') === '0:key_hash', 'key_hash carries a unique index');
    $check($lookupBefore096($keyA) !== null && $lookupBefore096($keyB) === null,
        'the previous app still accepts the same keys after the additive migration');
    $repo = new \App\Models\ApiKeyRepository($db);
    $hit = $repo->getByKey($keyA);
    $check(is_array($hit) && (int) $hit['id'] === 1 && $hit['name'] === 'Integration A', 'first use after installation converts and authenticates an existing key');
    $after = $rows();
    $check($after[0]['key_hash'] === hash('sha256', $keyA) && $after[1]['key_hash'] === hash('sha256', $keyB), 'each key_hash is the SHA-256 of the old key');
    $check($after[0]['key_prefix'] === substr($keyA, 0, 8) && $after[1]['key_prefix'] === substr($keyB, 0, 8), 'each key_prefix is the first 8 characters');
    $check($after[0]['api_key'] === null && $after[1]['api_key'] === null, 'the plaintext keys are cleared');
    $dump = json_encode($db->query('SELECT * FROM api_keys')->fetch_all(MYSQLI_ASSOC));
    $check(!str_contains((string) $dump, $keyA) && !str_contains((string) $dump, $keyB), 'no row holds a usable key any more');

    $db->directQueries = [];
    $check(is_array($repo->getByKey(strtoupper($keyA))), 'as before, the lookup ignores letter case');
    $check($repo->getByKey($keyB) === null, 'an inactive key still does not authenticate');
    $check($repo->getByKey(hash('sha256', $keyA)) === null, 'the stored hash itself is not a key');
    $check($repo->getByKey(substr($keyA, 0, 63)) === null && $repo->getByKey('') === null, 'a wrong or empty key does not authenticate');
    $check($db->directQueries === [], 'requests on a converted table run neither schema maintenance nor a separate plaintext query');

    echo "Idempotency\n";
    $errors = $runMigration();
    $check($errors === [], 'a second run raises no error' . ($errors ? ' (' . implode('; ', $errors) . ')' : ''));
    $check($rows() === $after && $columns() === $cols, 'a second run changes nothing');

    echo "Downgrade or recovery using the pre-upgrade database backup\n";
    $db->query('RENAME TABLE api_keys TO api_keys_converted, api_keys_backup TO api_keys');
    $check($lookupBefore096($keyA) !== null && $lookupBefore096($keyB) === null,
        'the matching database backup restores the previous app credential state');
    $check($repo->getByKey($keyA) !== null && $repo->getByKey($keyB) === null,
        'the new app also heals the old table on the first request after a database restore');

    echo "Fresh install parity\n";
    $schema = (string) file_get_contents($ROOT . '/installer/database/schema.sql');
    $check(preg_match('/CREATE TABLE `api_keys` \(.*?\) ENGINE=[^;]*;/s', $schema, $m) === 1, 'schema.sql defines api_keys');
    $db->query('DROP TABLE IF EXISTS api_keys');
    $db->query(rtrim($m[0] ?? '', ';'));
    $freshCols = $columns();
    $freshIdx = $indexes();
    $check($freshCols === $cols, 'fresh-install columns match the upgraded table');
    $seed();
    $runMigration();
    $check($freshIdx === $indexes(), 'fresh-install indexes match the upgraded table');
    $db->query('DROP TABLE IF EXISTS api_keys');
    $db->query(rtrim($m[0] ?? '', ';'));
    $errors = $runMigration();
    $check($errors === [] && $columns() === $freshCols, 'the migration is a no-op on a fresh install');

    echo "Repository self-heal of a pre-0.9.6 table (before the migration ran)\n";
    $seed();
    $repo->ensureTable();
    $check($columns() === $cols, 'ensureTable() brings the table to the 0.9.6 columns');
    $healed = $rows();
    $check($healed[0]['key_hash'] === hash('sha256', $keyA) && $healed[0]['api_key'] === null, 'ensureTable() hashes the key and clears the plaintext');
    $check(is_array($repo->getByKey($keyA)), 'the key authenticates after the self-heal');
    $repo->ensureTable();
    $check($rows() === $healed, 'a second ensureTable() is a no-op');
    $errors = $runMigration();
    $check($errors === [] && $rows() === $healed, 'the migration after a self-heal is a no-op');

    echo "A heal interrupted half-way is finished by the next call\n";
    $seed();
    // What a concurrent or crashed first heal leaves: key_hash added, nothing else.
    $db->query('ALTER TABLE api_keys ADD COLUMN key_hash CHAR(64) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER api_key');
    $repo->ensureTable();
    $check($columns() === $cols, 'ensureTable() completes the columns (key_prefix added, api_key nullable)');
    $check(($indexes()['uniq_key_hash'] ?? '') === '0:key_hash', 'ensureTable() adds the missing unique index');
    $half = $rows();
    $check($half[0]['key_hash'] === hash('sha256', $keyA) && $half[0]['api_key'] === null, 'ensureTable() backfills and clears the plaintext');
    $probe = $repo->create('Integration half-heal', null);
    $check(is_array($repo->getByKey((string) $probe['api_key'])), 'a key created afterwards is stored and authenticates');
    $db->query('DELETE FROM api_keys WHERE id = ' . (int) $probe['id']);

    echo "A database user without ALTER rights\n";
    // The failed-conversion backoff writes a flag file: point it at a temp
    // file so the run never touches the checkout's storage/cache.
    $failureFlag = sys_get_temp_dir() . '/pk_apikeys_upgrade_failed_' . bin2hex(random_bytes(4));
    if (property_exists(\App\Models\ApiKeyRepository::class, 'upgradeFailureFlag')) {
        (new ReflectionProperty(\App\Models\ApiKeyRepository::class, 'upgradeFailureFlag'))->setValue(null, $failureFlag);
    }
    $resetIndexMemo = static function () use ($failureFlag): void {
        if (property_exists(\App\Models\ApiKeyRepository::class, 'indexChecked')) {
            (new ReflectionProperty(\App\Models\ApiKeyRepository::class, 'indexChecked'))->setValue(null, []);
        }
        if (property_exists(\App\Models\ApiKeyRepository::class, 'upgradeFailedAt')) {
            (new ReflectionProperty(\App\Models\ApiKeyRepository::class, 'upgradeFailedAt'))->setValue(null, null);
        }
        @unlink($failureFlag);
    };
    $seed(); // pre-0.9.6 plaintext table, no key_hash
    $db->denyAlter = true;
    $resetIndexMemo();
    $threw = null;
    try {
        $hit = $repo->getByKey($keyA);
    } catch (\Throwable $e) {
        $threw = $e;
        $hit = null;
    }
    $check($threw === null, 'a failing conversion does not throw out of getByKey()' . ($threw ? ' (' . $threw->getMessage() . ')' : ''));
    $check(is_array($hit) && (int) $hit['id'] === 1 && $hit['key_prefix'] === substr($keyA, 0, 8),
        'the key still authenticates through the legacy plaintext lookup');
    $check($repo->getByKey(strtoupper($keyA)) !== null, 'the legacy lookup ignores letter case, as before');
    $check($repo->getByKey($keyB) === null && $repo->getByKey(str_repeat('0', 64)) === null,
        'an inactive or unknown key is still refused on the legacy path');
    $check(!array_key_exists('key_hash', $columns()), 'nothing was altered');

    // A conversion that keeps failing is not retried on every request: the
    // next lookups (in this process or another one, through the flag file)
    // go straight to the legacy lookup, with no DDL.
    $db->directQueries = [];
    $repo->getByKey($keyA);
    (new \App\Models\ApiKeyRepository($db))->getByKey($keyA);
    $ddl = array_filter($db->directQueries, static fn (string $q): bool => preg_match('/^\s*(CREATE|ALTER)\b/i', $q) === 1);
    $check($ddl === [], 'a failed conversion is not retried on the next requests (' . count($ddl) . ' DDL statements)');
    $check(is_file($failureFlag), 'the failure is recorded for the other PHP workers');
    if (property_exists(\App\Models\ApiKeyRepository::class, 'upgradeFailedAt')) {
        (new ReflectionProperty(\App\Models\ApiKeyRepository::class, 'upgradeFailedAt'))->setValue(null, null);
    }
    $db->directQueries = [];
    $check(is_array($repo->getByKey($keyA)) && array_filter($db->directQueries, static fn (string $q): bool => str_starts_with(ltrim($q), 'CREATE')) === [],
        'another worker (no in-process memo) also skips the conversion while the flag is fresh');
    touch($failureFlag, time() - 601);
    $db->directQueries = [];
    $repo->getByKey($keyA);
    $check(array_filter($db->directQueries, static fn (string $q): bool => str_starts_with(ltrim($q), 'CREATE')) !== [],
        'after the retry period the conversion is attempted again');

    // An outage on the legacy lookup is an error (503), never "invalid key" (401).
    $db->failLegacyLookup = true;
    $threw = null;
    try {
        $repo->getByKey($keyA);
    } catch (\Throwable $e) {
        $threw = $e;
    }
    $check($threw instanceof RuntimeException, 'a failing legacy lookup throws instead of answering "no such key"');
    $db->failLegacyLookup = false;

    // The middleware answers cleanly on that path: 200 / 401, never 500.
    $db->query('DROP TABLE IF EXISTS system_settings');
    $db->query("CREATE TABLE system_settings LIKE `{$baseDb}`.`system_settings`");
    $db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('api', 'enabled', '1')");
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return new \Slim\Psr7\Response(200);
        }
    };
    $mw = new \App\Middleware\ApiKeyMiddleware($db);
    $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/public/books/search');
    $check($mw->process($req->withHeader('X-API-Key', $keyA), $handler)->getStatusCode() === 200,
        'middleware: a valid legacy key gets through without ALTER rights');
    $check($mw->process($req->withHeader('X-API-Key', $keyB), $handler)->getStatusCode() === 401,
        'middleware: an invalid key gets 401, not 500');
    $db->query('DROP TABLE api_keys');
    $code = $mw->process($req->withHeader('X-API-Key', $keyA), $handler)->getStatusCode();
    $check($code === 401 || $code === 503, "middleware: a table it cannot create answers {$code}, not 500");
    $db->query('DROP TABLE IF EXISTS api_keys');
    $seed();
    $db->failLegacyLookup = true;
    $code = $mw->process($req->withHeader('X-API-Key', $keyA), $handler)->getStatusCode();
    $check($code === 503, "middleware: a database error on the legacy lookup answers 503, not 401 (got {$code})");
    $db->failLegacyLookup = false;
    $db->denyAlter = false;
    $resetIndexMemo();

    echo "A converted table that lost its hash index\n";
    $seed();
    $repo->ensureTable();
    $db->query('ALTER TABLE api_keys DROP INDEX uniq_key_hash');
    $resetIndexMemo();
    $check(is_array($repo->getByKey($keyA)), 'the key authenticates');
    $check(($indexes()['uniq_key_hash'] ?? '') === '0:key_hash', 'the lookup restores the missing unique index');
    // Under PHP-FPM a static memo lives for one request, so a per-request
    // INFORMATION_SCHEMA probe would run on every API call. Each lookup here
    // stands for a fresh request: the memo (if any) is cleared first.
    foreach ([1, 2] as $request) {
        $resetIndexMemo();
        $db->prepared = [];
        $db->directQueries = [];
        $check(is_array($repo->getByKey($keyA)), "request {$request}: the key authenticates");
        $metadataReads = array_filter($db->prepared, static fn (string $q): bool => stripos($q, 'INFORMATION_SCHEMA') !== false);
        $check($metadataReads === [] && $db->directQueries === [] && count($db->prepared) === 1,
            "request {$request}: a converted table costs one statement, no metadata read (" . count($db->prepared) . ' prepared)');
    }

    echo "New keys after the upgrade\n";
    $new = $repo->create('Integration C', null);
    $stored = $db->query('SELECT api_key, key_hash, key_prefix FROM api_keys WHERE id = ' . (int) $new['id'])->fetch_assoc();
    $check($stored['api_key'] === null && $stored['key_hash'] === hash('sha256', $new['api_key']), 'a new key is stored as its hash only');
    $check($stored['key_prefix'] === substr($new['api_key'], 0, 8) && $new['key_prefix'] === $stored['key_prefix'], 'with its 8-character display prefix');
    $check(is_array($repo->getByKey($new['api_key'])), 'the new key authenticates');
    $listed = json_encode($repo->getAll());
    $check(!str_contains((string) $listed, $new['api_key']) && !str_contains((string) $listed, (string) $stored['key_hash']), 'the admin listing carries neither the key nor its hash');
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $db->close();
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
