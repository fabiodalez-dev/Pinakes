<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Shared materialization for bounded catalog aggregates.
 *
 * QueryCache remains the fast per-worker layer. This table-backed projection
 * lets separate application nodes, SAPIs and file-cache processes reuse the
 * same expensive count/facet payload. Rows carry the canonical catalog
 * generation, so ContentCache invalidation remains O(1) and an older in-flight
 * request can never overwrite a newer projection.
 */
final class CatalogSnapshot
{
    private const TABLE = 'catalog_materialized_snapshots';
    private const LOCK_TIMEOUT_SECONDS = 2;

    private function __construct()
    {
    }

    /**
     * @param callable(): mixed $loader
     */
    public static function remember(
        \mysqli $db,
        string $logicalKey,
        int $generation,
        callable $loader,
        int $ttl = 120
    ): mixed
    {
        // A negative generation means QueryCache could not persist its
        // canonical counter. Persisting a DB snapshot in that degraded state
        // would make invalidation unverifiable, so fail safely to a live load.
        if ($generation <= 0) {
            return $loader();
        }

        try {
            if (!SchemaInfo::hasTable($db, self::TABLE)) {
                return $loader();
            }
        } catch (\Throwable $e) {
            // Schema introspection is part of the optional materialization
            // path as well. A closed/transiently unavailable connection must
            // not turn this optimization into a fatal error.
            SecureLogger::warning('CatalogSnapshot schema probe failed; using live aggregate', [
                'error' => $e->getMessage(),
            ]);
            return $loader();
        }

        $ttl = max(1, $ttl);
        $snapshotKey = hash('sha256', $logicalKey);
        $cached = self::fetch($db, $snapshotKey, $generation, $ttl);
        if ($cached['hit']) {
            return $cached['value'];
        }

        $lockName = self::lockName($db, $snapshotKey);
        if (!self::acquireLock($db, $lockName)) {
            // Never make a public request wait indefinitely for an aggregate.
            // The result stays correct; only this request misses materialization.
            return $loader();
        }

        try {
            // Another worker may have published while this one waited.
            $cached = self::fetch($db, $snapshotKey, $generation, $ttl);
            if ($cached['hit']) {
                return $cached['value'];
            }

            // A newer generation already won. This request belongs to an old
            // QueryCache generation, so compute for itself but never overwrite
            // the newer shared row.
            if ($cached['stored_generation'] > $generation) {
                return $loader();
            }

            $value = $loader();
            try {
                self::storeIfNewer($db, $snapshotKey, $generation, $value);
            } catch (\Throwable $e) {
                // Materialization is an optimization. A successful live query
                // must remain successful even if the projection write fails.
                SecureLogger::warning('CatalogSnapshot write failed; returning live aggregate', [
                    'key' => substr($logicalKey, 0, 120),
                    'generation' => $generation,
                    'error' => $e->getMessage(),
                ]);
            }
            return $value;
        } finally {
            self::releaseLock($db, $lockName);
        }
    }

    /**
     * @return array{hit: bool, value: mixed, stored_generation: int}
     */
    private static function fetch(\mysqli $db, string $snapshotKey, int $generation, int $ttl): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT generation, payload, '
                . 'TIMESTAMPDIFF(SECOND, updated_at, CURRENT_TIMESTAMP) AS age_seconds '
                . 'FROM ' . self::TABLE . ' WHERE cache_key = ? LIMIT 1'
            );
            if ($stmt === false) {
                return ['hit' => false, 'value' => null, 'stored_generation' => 0];
            }
            $stmt->bind_param('s', $snapshotKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row === null) {
                return ['hit' => false, 'value' => null, 'stored_generation' => 0];
            }

            $storedGeneration = (int) ($row['generation'] ?? 0);
            if ($storedGeneration !== $generation) {
                return ['hit' => false, 'value' => null, 'stored_generation' => $storedGeneration];
            }
            // Explicit invalidation is primary, but retain QueryCache's TTL as
            // a safety net for a missed/third-party write path. Without this,
            // a same-generation DB snapshot could remain stale indefinitely.
            if (max(0, (int) ($row['age_seconds'] ?? PHP_INT_MAX)) >= $ttl) {
                return ['hit' => false, 'value' => null, 'stored_generation' => $storedGeneration];
            }

            try {
                $value = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                SecureLogger::warning('CatalogSnapshot ignored malformed payload', [
                    'cache_key' => $snapshotKey,
                    'generation' => $generation,
                    'error' => $e->getMessage(),
                ]);
                return ['hit' => false, 'value' => null, 'stored_generation' => $storedGeneration];
            }

            return ['hit' => true, 'value' => $value, 'stored_generation' => $storedGeneration];
        } catch (\Throwable $e) {
            SecureLogger::warning('CatalogSnapshot read failed', ['error' => $e->getMessage()]);
            return ['hit' => false, 'value' => null, 'stored_generation' => 0];
        }
    }

    private static function storeIfNewer(\mysqli $db, string $snapshotKey, int $generation, mixed $value): void
    {
        $payload = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $db->prepare(
            'INSERT INTO ' . self::TABLE . ' (cache_key, generation, payload, updated_at) '
            . 'VALUES (?, ?, ?, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'payload = IF(VALUES(generation) >= generation, VALUES(payload), payload), '
            . 'updated_at = IF(VALUES(generation) >= generation, CURRENT_TIMESTAMP, updated_at), '
            . 'generation = GREATEST(generation, VALUES(generation))'
        );
        if ($stmt === false) {
            throw new \RuntimeException('unable to prepare catalog snapshot write: ' . $db->error);
        }
        $stmt->bind_param('sis', $snapshotKey, $generation, $payload);
        $stmt->execute();
        $stmt->close();
    }

    private static function lockName(\mysqli $db, string $snapshotKey): string
    {
        $database = '';
        try {
            $result = $db->query('SELECT DATABASE()');
            $database = $result instanceof \mysqli_result ? (string) ($result->fetch_row()[0] ?? '') : '';
        } catch (\Throwable $ignored) {
        }

        // MySQL lock names are limited to 64 characters on supported versions.
        return 'pinakes:cat:' . substr(hash('sha256', $database . ':' . $snapshotKey), 0, 48);
    }

    private static function acquireLock(\mysqli $db, string $lockName): bool
    {
        try {
            $stmt = $db->prepare('SELECT GET_LOCK(?, ?)');
            if ($stmt === false) {
                return false;
            }
            $timeout = self::LOCK_TIMEOUT_SECONDS;
            $stmt->bind_param('si', $lockName, $timeout);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            return isset($row[0]) && (int) $row[0] === 1;
        } catch (\Throwable $e) {
            SecureLogger::warning('CatalogSnapshot lock acquisition failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private static function releaseLock(\mysqli $db, string $lockName): void
    {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            if ($stmt !== false) {
                $stmt->bind_param('s', $lockName);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            SecureLogger::warning('CatalogSnapshot lock release failed', ['error' => $e->getMessage()]);
        }
    }
}
