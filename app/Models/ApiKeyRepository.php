<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;
use RuntimeException;

/**
 * Public API keys.
 *
 * Only a SHA-256 of each key (`key_hash`) and a short display prefix
 * (`key_prefix`) are stored. The full key exists in clear only in the return
 * value of create(), which the settings page shows once. A database or backup
 * read therefore yields nothing that authenticates against /api/public.
 *
 * The schema upgrade keeps legacy keys intact until this release serves API
 * traffic or the API settings page. Conversion then clears the old column.
 * A downgrade after conversion must restore the pre-upgrade database backup.
 */
class ApiKeyRepository
{
    /** Characters of the key kept in clear for display ("abcd1234…"). */
    public const PREFIX_LENGTH = 8;

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function ensureTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS api_keys (
                id INT PRIMARY KEY AUTO_INCREMENT,
                api_key VARCHAR(64) NULL DEFAULT NULL,
                key_hash CHAR(64) NULL DEFAULT NULL,
                key_prefix VARCHAR(16) NULL DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                is_active TINYINT(1) DEFAULT 1,
                last_used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY api_key (api_key),
                UNIQUE KEY uniq_key_hash (key_hash),
                INDEX idx_api_key (api_key),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Unable to ensure api_keys table: ' . $this->db->error);
        }

        // A table created before 0.9.6 whose migration has not run yet (the
        // short window of an upgrade, or a database restored from an old
        // backup): bring it to the hashed shape here, mirroring
        // migrate_0.9.6.sql, so existing keys keep authenticating. Every step
        // is checked on its own, so a heal interrupted half-way (a concurrent
        // request, a crash) is finished by the next call instead of leaving a
        // table with key_hash but a NOT NULL api_key that rejects new keys.
        $pending = $this->pendingLegacySteps();
        if ($pending !== []) {
            $this->upgradeLegacyTable($pending);
            return;
        }
        // Schema already in shape, but a heal that stopped before its backfill
        // can leave plaintext keys behind: finish it.
        $left = $this->db->query('SELECT 1 FROM api_keys WHERE api_key IS NOT NULL LIMIT 1');
        if ($left === false) {
            throw new RuntimeException('Unable to check legacy API keys: ' . $this->db->error);
        }
        $hasLegacyKeys = $left->num_rows > 0;
        $left->free();
        if ($hasLegacyKeys) {
            $this->backfillHashes();
        }
    }

    /**
     * Generate a new API key
     */
    public function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The stored lookup hash of a presented key. Keys used to be compared
     * under a case-insensitive collation, so the hash covers the lowercase
     * form (generated keys are lowercase hex already).
     */
    public static function hashKey(string $apiKey): string
    {
        return hash('sha256', strtolower($apiKey));
    }

    /**
     * Create a new API key.
     *
     * The returned 'api_key' is the only time the full key is available: it is
     * not stored and cannot be retrieved later.
     *
     * @return array{id:int, api_key:string, key_prefix:string, name:string, description:?string, is_active:bool}
     */
    public function create(string $name, ?string $description = null): array
    {
        $apiKey = $this->generateApiKey();
        $hash = self::hashKey($apiKey);
        $prefix = substr($apiKey, 0, self::PREFIX_LENGTH);
        $stmt = $this->db->prepare('INSERT INTO api_keys (key_hash, key_prefix, name, description, is_active) VALUES (?, ?, ?, ?, 1)');
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->db->error);
        }
        $stmt->bind_param('ssss', $hash, $prefix, $name, $description);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Failed to create API key: ' . $error);
        }
        $id = (int)$this->db->insert_id;
        $stmt->close();

        return [
            'id' => $id,
            'api_key' => $apiKey,
            'key_prefix' => $prefix,
            'name' => $name,
            'description' => $description,
            'is_active' => true
        ];
    }

    /**
     * Get all API keys (display data only: never the key itself).
     */
    public function getAll(): array
    {
        $result = $this->db->query('SELECT id, key_prefix, name, description, is_active, last_used_at, created_at FROM api_keys ORDER BY created_at DESC');
        if ($result === false) {
            throw new RuntimeException('Failed to fetch API keys: ' . $this->db->error);
        }

        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = $row;
        }
        $result->free();

        return $keys;
    }

    /**
     * Connections (by spl_object_id) whose api_keys table was already checked
     * for the uniq_key_hash index in this request: the check runs once.
     *
     * @var array<int, true>
     */
    private static array $indexChecked = [];

    /** Seconds a failed conversion is not retried by the public API path. */
    private const UPGRADE_RETRY_AFTER = 600;

    /** Flag file of the last failed conversion; '' = the install's default (tests point it elsewhere). */
    private static string $upgradeFailureFlag = '';

    /** Last failed conversion in this process (when the flag cannot be written). */
    private static ?int $upgradeFailedAt = null;

    private static function upgradeFailureFlag(): string
    {
        return self::$upgradeFailureFlag !== ''
            ? self::$upgradeFailureFlag
            : dirname(__DIR__, 2) . '/storage/cache/api_keys_upgrade_failed';
    }

    /**
     * True while a conversion failed less than UPGRADE_RETRY_AFTER seconds
     * ago, in this process or (through the flag file) in any other: a
     * database user without ALTER/UPDATE rights must not cost every API
     * request the DDL attempts and an error log line.
     */
    private static function upgradeRecentlyFailed(): bool
    {
        $now = time();
        if (self::$upgradeFailedAt !== null && $now - self::$upgradeFailedAt < self::UPGRADE_RETRY_AFTER) {
            return true;
        }
        $flag = self::upgradeFailureFlag();
        clearstatcache(true, $flag);
        $mtime = @filemtime($flag);
        return $mtime !== false && $now - $mtime < self::UPGRADE_RETRY_AFTER && $mtime <= $now + 60;
    }

    private static function recordUpgradeOutcome(bool $failed): void
    {
        $flag = self::upgradeFailureFlag();
        if ($failed) {
            self::$upgradeFailedAt = time();
            @touch($flag);
            return;
        }
        self::$upgradeFailedAt = null;
        if (is_file($flag)) {
            @unlink($flag);
        }
    }

    /**
     * Get the active API key matching a presented key, looked up by its hash.
     *
     * A table still holding plaintext keys, or missing a column or the hash
     * index, is converted first. When that conversion fails (typically a
     * database user without ALTER rights) the failure is logged and the key
     * is still checked with whatever the table offers, so the public API keeps
     * answering instead of failing every request.
     */
    public function getByKey(string $apiKey): ?array
    {
        if ($apiKey === '') {
            return null;
        }
        $hash = self::hashKey($apiKey);
        $needsUpgrade = false;
        try {
            $row = $this->lookup($hash);
            $needsUpgrade = !empty($row['has_plaintext']) || !$this->hashIndexChecked();
        } catch (RuntimeException $e) {
            // A database restored from an older backup may still lack the table
            // or either added column. Other SQL failures must surface normally.
            if (!in_array($e->getCode(), [1054, 1146], true)) {
                throw $e;
            }
            $row = [];
            $needsUpgrade = true;
        }
        if ($needsUpgrade) {
            if (self::upgradeRecentlyFailed()) {
                // Retried after UPGRADE_RETRY_AFTER (or at once by the API
                // settings page, which calls ensureTable() itself).
                return $this->fallbackLookup($apiKey, $hash);
            }
            try {
                $this->ensureTable();
                self::$indexChecked[spl_object_id($this->db)] = true;
                self::recordUpgradeOutcome(false);
                $row = $this->lookup($hash);
            } catch (\Throwable $e) {
                self::$indexChecked[spl_object_id($this->db)] = true;
                self::recordUpgradeOutcome(true);
                \App\Support\SecureLogger::error('[ApiKeyRepository] could not convert the api_keys table; using the legacy lookup', [
                    'error' => $e->getMessage(),
                ]);
                return $this->fallbackLookup($apiKey, $hash);
            }
        }
        unset($row['has_plaintext']);
        return isset($row['id']) ? $row : null;
    }

    /**
     * True once this request has seen the uniq_key_hash index on api_keys.
     * The first call per connection reads INFORMATION_SCHEMA; a missing
     * index returns false so the caller runs ensureTable().
     */
    private function hashIndexChecked(): bool
    {
        $id = spl_object_id($this->db);
        if (isset(self::$indexChecked[$id])) {
            return true;
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys'
                AND INDEX_NAME = 'uniq_key_hash' LIMIT 1"
        );
        if ($stmt === false) {
            return true; // cannot tell: do not attempt DDL on every request
        }
        try {
            $stmt->execute();
            $result = $stmt->get_result();
            $present = $result instanceof \mysqli_result && $result->num_rows > 0;
        } finally {
            $stmt->close();
        }
        if ($present) {
            self::$indexChecked[$id] = true;
        }
        return $present;
    }

    /**
     * Lookup on a table the conversion could not bring up to date: by hash
     * when key_hash exists, by the legacy plaintext column when api_key does.
     *
     * A database error is thrown, never answered as "no such key": the
     * middleware turns it into 503, so an outage does not read as 401
     * "invalid key" to API clients.
     */
    private function fallbackLookup(string $apiKey, string $hash): ?array
    {
        try {
            $columns = [];
            $stmt = $this->db->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys'
                    AND COLUMN_NAME IN ('api_key', 'key_hash', 'key_prefix')"
            );
            if ($stmt === false) {
                throw new RuntimeException('Failed to prepare the api_keys column probe: ' . $this->db->error);
            }
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Failed to probe the api_keys columns: ' . $error);
            }
            $res = $stmt->get_result();
            while ($res instanceof \mysqli_result && ($c = $res->fetch_row())) {
                $columns[(string) $c[0]] = true;
            }
            $stmt->close();

            $where = [];
            $types = '';
            $params = [];
            if (isset($columns['key_hash'])) {
                $where[] = 'key_hash = ?';
                $types .= 's';
                $params[] = $hash;
            }
            if (isset($columns['api_key'])) {
                $where[] = 'api_key = ?';
                $types .= 's';
                $params[] = $apiKey;
            }
            if ($where === []) {
                return null;
            }
            $prefix = isset($columns['key_prefix'])
                ? (isset($columns['api_key']) ? 'COALESCE(key_prefix, LEFT(api_key, ' . self::PREFIX_LENGTH . '))' : 'key_prefix')
                : 'LEFT(api_key, ' . self::PREFIX_LENGTH . ')';
            $stmt = $this->db->prepare(
                'SELECT id, ' . $prefix . ' AS key_prefix, name, description, is_active, last_used_at
                   FROM api_keys WHERE is_active = 1 AND (' . implode(' OR ', $where) . ') LIMIT 1'
            );
            if ($stmt === false) {
                throw new RuntimeException('Failed to prepare the legacy API key lookup: ' . $this->db->error);
            }
            try {
                $stmt->bind_param($types, ...$params);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Failed to run the legacy API key lookup: ' . $stmt->error);
                }
                $res = $stmt->get_result();
                $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            } finally {
                $stmt->close();
            }
            return is_array($row) && isset($row['id']) ? $row : null;
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[ApiKeyRepository] legacy API key lookup failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Legacy API key lookup failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** One indexed lookup and a legacy-row probe in the same statement. */
    private function lookup(string $hash): array
    {
        $stmt = $this->db->prepare('
            SELECT k.id, k.key_prefix, k.name, k.description, k.is_active, k.last_used_at, legacy.has_plaintext
            FROM (SELECT EXISTS(SELECT 1 FROM api_keys WHERE api_key IS NOT NULL) AS has_plaintext) legacy
            LEFT JOIN api_keys k ON k.key_hash = ? AND k.is_active = 1
            LIMIT 1
        ');
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare API key lookup: ' . $this->db->error, $this->db->errno);
        }
        try {
            $stmt->bind_param('s', $hash);
            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to look up API key: ' . $stmt->error, $stmt->errno);
            }
            $result = $stmt->get_result();
            return $result instanceof \mysqli_result ? ($result->fetch_assoc() ?: []) : [];
        } finally {
            $stmt->close();
        }
    }

    /**
     * Update last used timestamp of the key with this id.
     */
    public function updateLastUsed(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?');
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->db->error);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Toggle API key active status
     */
    public function toggleActive(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE api_keys SET is_active = NOT is_active WHERE id = ?');
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->db->error);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete API key
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM api_keys WHERE id = ?');
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->db->error);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Check if any API keys exist
     */
    public function hasApiKeys(): bool
    {
        $result = $this->db->query('SELECT COUNT(*) as count FROM api_keys');
        if ($result === false) {
            return false;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return ((int)($row['count'] ?? 0)) > 0;
    }

    /**
     * Same steps as migrate_0.9.6.sql, for a table still in the pre-0.9.6
     * plaintext shape. Each step tolerates having already been applied.
     */

    /** @return string[] the ALTER statements still needed, in order */
    private function pendingLegacySteps(): array
    {
        $columns = [];
        $res = $this->db->query(
            "SELECT COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys'
                AND COLUMN_NAME IN ('api_key', 'key_hash', 'key_prefix')"
        );
        if ($res === false) {
            throw new RuntimeException('Unable to inspect api_keys table: ' . $this->db->error);
        }
        while ($row = $res->fetch_assoc()) {
            $columns[(string) $row['COLUMN_NAME']] = (string) $row['IS_NULLABLE'];
        }
        $res->free();

        $idx = $this->db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys'
                AND INDEX_NAME = 'uniq_key_hash' LIMIT 1"
        );
        if ($idx === false) {
            throw new RuntimeException('Unable to inspect api_keys indexes: ' . $this->db->error);
        }
        $hasIndex = $idx->num_rows > 0;
        $idx->free();

        $steps = [];
        if (!isset($columns['key_hash'])) {
            $steps[] = "ALTER TABLE api_keys ADD COLUMN key_hash CHAR(64) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER api_key";
        }
        if (!isset($columns['key_prefix'])) {
            $steps[] = "ALTER TABLE api_keys ADD COLUMN key_prefix VARCHAR(16) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER key_hash";
        }
        if (($columns['api_key'] ?? 'YES') !== 'YES') {
            $steps[] = "ALTER TABLE api_keys MODIFY COLUMN api_key VARCHAR(64) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL";
        }
        if (!$hasIndex) {
            $steps[] = "ALTER TABLE api_keys ADD UNIQUE KEY uniq_key_hash (key_hash)";
        }
        return $steps;
    }

    /** @param string[] $steps */
    private function upgradeLegacyTable(array $steps): void
    {
        // 1060 duplicate column, 1061 duplicate key name: a concurrent request
        // (or the migration) applied the step first. mysqli may report that
        // either as false or as an exception, depending on its report mode.
        foreach ($steps as $sql) {
            try {
                $ok = $this->db->query($sql) !== false;
                $errno = $this->db->errno;
                $error = $this->db->error;
            } catch (\mysqli_sql_exception $e) {
                $ok = false;
                $errno = (int) $e->getCode();
                $error = $e->getMessage();
            }
            if (!$ok && !in_array($errno, [1060, 1061], true)) {
                throw new RuntimeException('Unable to upgrade api_keys table: ' . $error);
            }
        }
        $this->backfillHashes();
    }

    /**
     * Hash any key still held only in plaintext, then clear the plaintext.
     */
    private function backfillHashes(): void
    {
        // Hash and clear in one statement. An interrupted conversion cannot
        // leave new hashes committed alongside their plaintext values.
        if ($this->db->query("
            UPDATE api_keys
            SET key_hash = COALESCE(key_hash, SHA2(LOWER(NULLIF(api_key, '')), 256)),
                key_prefix = COALESCE(key_prefix, LEFT(LOWER(NULLIF(api_key, '')), " . self::PREFIX_LENGTH . ")),
                api_key = NULL
            WHERE api_key IS NOT NULL
        ") === false) {
            throw new RuntimeException('Unable to convert legacy API keys: ' . $this->db->error);
        }
    }
}
