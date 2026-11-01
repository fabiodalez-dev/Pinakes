<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use mysqli;

/**
 * Durable store of the CSV / LibraryThing import jobs started from the app
 * (Mobile API 1.7.0, table `mobile_import_jobs`).
 *
 * The web import keeps its whole job in the PHP session (`csv_import_data`,
 * `librarything_import`) and the browser drives it chunk by chunk. A bearer
 * token has no session, so the job state — the core's own session array,
 * verbatim — lives here between requests: before each delegated chunk it is
 * put into `$_SESSION` (Delegation isolates the key), read back afterwards
 * and stored again. Jobs expire 24 h after their last activity; the uploaded
 * file the core saved is removed with them.
 */
final class ImportJobStore
{
    public const TTL_HOURS = 24;
    public const SESSION_KEYS = ['csv' => 'csv_import_data', 'librarything' => 'librarything_import'];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @param array<string, mixed> $state
     */
    public function create(int $userId, ?int $tokenId, string $kind, string $fileName, int $totalRows, int $chunkSize, bool $scraping, array $state): string
    {
        $id = self::uuid();
        $json = self::encode($state);
        $ttl = self::TTL_HOURS;
        $flag = $scraping ? 1 : 0;
        $stmt = $this->db->prepare(
            "INSERT INTO mobile_import_jobs (id, user_id, token_id, kind, file_name, total_rows, processed, chunk_size, enable_scraping, status, state, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'ready', ?, NOW() + INTERVAL ? HOUR)"
        );
        if ($stmt === false) {
            throw new \RuntimeException('import job insert prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('siissiiisi', $id, $userId, $tokenId, $kind, $fileName, $totalRows, $chunkSize, $flag, $json, $ttl);
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    /**
     * A job visible to this operator (own jobs; an admin sees every job).
     *
     * @return array<string, mixed>|null row + `expired` (bool) + decoded `state`/`summary`
     */
    public function find(string $id, int $userId, bool $isAdmin): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $id) !== 1) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT j.*, (j.expires_at < NOW()) AS expired FROM mobile_import_jobs j WHERE j.id = ? LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('import job lookup prepare failed');
        }
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($row === null || (!$isAdmin && (int) $row['user_id'] !== $userId)) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * Unexpired jobs of this operator, newest first.
     *
     * @param list<string>|null $kinds only these job kinds (null: every kind)
     * @return list<array<string, mixed>>
     */
    public function listFor(int $userId, int $offset, int $limit, ?array $kinds = null): array
    {
        // Mobile API 1.10.0: the store also holds MARC, patron CSV and copy
        // cataloguing jobs; each endpoint lists only the kinds it drives.
        $kindFilter = '';
        $types = 'i';
        $params = [$userId];
        if ($kinds !== null) {
            if ($kinds === []) {
                return [];
            }
            $kindFilter = ' AND j.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
            $types .= str_repeat('s', count($kinds));
            array_push($params, ...array_map('strval', $kinds));
        }
        $stmt = $this->db->prepare(
            'SELECT j.*, 0 AS expired FROM mobile_import_jobs j WHERE j.user_id = ? AND j.expires_at >= NOW()' . $kindFilter . '
              ORDER BY j.created_at DESC, j.id LIMIT ? OFFSET ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('import job list prepare failed');
        }
        $fetch = $limit + 1;
        $types .= 'ii';
        array_push($params, $fetch, $offset);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $rows[] = self::hydrate($r);
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Persist the outcome of a chunk — compare-and-set on `running`, the
     * status the chunk set before delegating. A job cancelled meanwhile is
     * left alone (a late chunk must never resurrect it): false is returned.
     *
     * @param array<string, mixed>|null $state  core session array after the chunk (null: keep)
     * @param array<string, mixed>      $summary last chunk response
     */
    public function saveChunk(string $id, ?array $state, int $processed, string $status, array $summary): bool
    {
        $ttl = self::TTL_HOURS;
        $summaryJson = self::encode($summary);
        if ($state !== null) {
            $stateJson = self::encode($state);
            $stmt = $this->db->prepare(
                'UPDATE mobile_import_jobs SET state = ?, processed = ?, status = ?, summary = ?, expires_at = NOW() + INTERVAL ? HOUR WHERE id = ? AND status = \'running\''
            );
            if ($stmt === false) {
                throw new \RuntimeException('import job save prepare failed');
            }
            $stmt->bind_param('sissis', $stateJson, $processed, $status, $summaryJson, $ttl, $id);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE mobile_import_jobs SET processed = ?, status = ?, summary = ?, expires_at = NOW() + INTERVAL ? HOUR WHERE id = ? AND status = \'running\''
            );
            if ($stmt === false) {
                throw new \RuntimeException('import job save prepare failed');
            }
            $stmt->bind_param('issis', $processed, $status, $summaryJson, $ttl, $id);
        }
        $stmt->execute();
        // The status always changes (running → ready/completed), so a matched
        // row is a changed row.
        $saved = $stmt->affected_rows > 0;
        $stmt->close();

        return $saved;
    }

    public function setStatus(string $id, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE mobile_import_jobs SET status = ? WHERE id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('import job status prepare failed');
        }
        $stmt->bind_param('ss', $status, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Cancel a job: status `cancelled` and its saved upload removed.
     *
     * @param array<string, mixed> $job hydrated row
     */
    public function cancel(array $job): void
    {
        self::unlinkUpload(is_array($job['state']) ? $job['state'] : []);
        // Never turn a job that finished meanwhile into a cancelled one.
        $stmt = $this->db->prepare("UPDATE mobile_import_jobs SET status = 'cancelled' WHERE id = ? AND status NOT IN ('completed', 'cancelled')");
        if ($stmt === false) {
            throw new \RuntimeException('import job cancel prepare failed');
        }
        $id = (string) $job['id'];
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Drop jobs expired for a while (their uploads too). Bounded per call.
     */
    public function purgeExpired(): void
    {
        $result = $this->db->query(
            'SELECT id, state FROM mobile_import_jobs WHERE expires_at < NOW() - INTERVAL 1 HOUR ORDER BY expires_at LIMIT 50'
        );
        if (!$result instanceof \mysqli_result) {
            return;
        }
        $ids = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $state = json_decode((string) $row['state'], true);
            self::unlinkUpload(is_array($state) ? $state : []);
            $ids[] = (string) $row['id'];
        }
        $result->free();
        foreach ($ids as $id) {
            $stmt = $this->db->prepare('DELETE FROM mobile_import_jobs WHERE id = ?');
            if ($stmt !== false) {
                $stmt->bind_param('s', $id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    /**
     * Remove the file the core saved for this job, only inside the two
     * directories the core writes to.
     *
     * @param array<string, mixed> $state
     */
    public static function unlinkUpload(array $state): void
    {
        $path = is_string($state['file_path'] ?? null) ? $state['file_path'] : '';
        if ($path === '' || !is_file($path)) {
            return;
        }
        $real = realpath($path);
        if ($real === false) {
            return;
        }
        $allowed = array_filter([
            realpath(dirname(__DIR__, 5) . '/writable/uploads'),
            realpath(sys_get_temp_dir() . '/librarything_imports'),
        ]);
        foreach ($allowed as $dir) {
            if (str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
                @unlink($real);
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $state = json_decode((string) ($row['state'] ?? ''), true);
        $summary = json_decode((string) ($row['summary'] ?? ''), true);
        $row['state'] = is_array($state) ? $state : [];
        $row['summary'] = is_array($summary) ? $summary : null;
        $row['expired'] = (int) ($row['expired'] ?? 0) === 1;

        return $row;
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('import job state not encodable');
        }

        return $json;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
