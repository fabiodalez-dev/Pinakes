<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lightweight, per-request schema introspection cache.
 *
 * Several queries reference the multi-publisher junction `libri_editori`
 * (issue #143), which only exists after the 0.7.15 migration has run. On a
 * pre-migration install the table is absent, so referencing it unconditionally
 * makes mysqli::prepare() return false and the subsequent bind_param() fatal
 * (HTTP 500). Call {@see hasLibriEditori()} to gate the `OR EXISTS (...)`
 * fragment and bind the extra parameter only when the table is present —
 * matching the defensive pattern already used by
 * BookRepository::syncPublishers() and PublisherRepository::mergePublishers().
 *
 * The result is cached per table name for the lifetime of the request (one
 * PHP-FPM worker process serves one request), so the `SHOW TABLES` probe runs
 * at most once per table regardless of how many queries gate on it.
 */
final class SchemaInfo
{
    /** @var array<string, bool> */
    private static array $tableCache = [];

    public static function hasTable(\mysqli $db, string $table): bool
    {
        if (!array_key_exists($table, self::$tableCache)) {
            // Cross-request cache first: schema only changes on migrations
            // (which call resetCache()), so a long TTL is safe and saves the
            // INFORMATION_SCHEMA probe on every request.
            $cached = QueryCache::get('schema_table_' . $table);
            if (is_bool($cached)) {
                self::$tableCache[$table] = $cached;
                return $cached;
            }

            // Exact match via INFORMATION_SCHEMA — `SHOW TABLES LIKE` would treat
            // `_` / `%` in the table name as wildcards and risk a false positive.
            $exists = false;
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('s', $table);
                if ($stmt->execute()) {
                    $stmt->store_result();
                    $exists = $stmt->num_rows > 0;
                }
                $stmt->close();
            }
            self::$tableCache[$table] = $exists;
            // Only cache positive results across requests: nothing invalidates
            // this key when a migration creates the table, and a stale `false`
            // would hide the table for up to an hour after an update. A stale
            // `true` cannot happen (tables are never dropped at runtime).
            if ($exists) {
                QueryCache::set('schema_table_' . $table, $exists, 3600);
            }
        }

        return self::$tableCache[$table];
    }

    public static function hasLibriEditori(\mysqli $db): bool
    {
        return self::hasTable($db, 'libri_editori');
    }

    /**
     * Reset the cache. Intended for tests and for code paths that create the
     * table mid-request (e.g. the installer/updater running a migration).
     */
    public static function resetCache(): void
    {
        self::$tableCache = [];
        QueryCache::clearByPrefix('schema_table_');
    }

    private function __construct()
    {
    }
}
