<?php
/**
 * Database Handler - SQLite
 */
class Database
{
    private static ?PDO $pdo = null;

    /**
     * Get PDO instance (singleton)
     */
    public static function getInstance(): PDO
    {
        if (self::$pdo === null) {
            $dbPath = $_ENV['DB_PATH'] ?? __DIR__ . '/../data/api_keys.db';

            // Ensure directory exists
            $dbDir = dirname($dbPath);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            self::$pdo = new PDO('sqlite:' . $dbPath);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Initialize schema
            self::initSchema();
        }

        return self::$pdo;
    }

    /**
     * Initialize database schema
     */
    private static function initSchema(): void
    {
        $schema = file_get_contents(__DIR__ . '/../data/schema.sql');
        self::$pdo->exec($schema);
    }

    /**
     * Validate API key
     */
    public static function validateApiKey(string $apiKey): ?array
    {
        $stmt = self::getInstance()->prepare(
            "SELECT * FROM api_keys WHERE api_key = ? AND is_active = 1"
        );
        $stmt->execute([$apiKey]);
        $key = $stmt->fetch();

        if ($key) {
            // Update last used timestamp and request count
            $update = self::getInstance()->prepare(
                "UPDATE api_keys SET last_used_at = datetime('now'), requests_count = requests_count + 1 WHERE api_key = ?"
            );
            $update->execute([$apiKey]);
        }

        return $key ?: null;
    }

    /**
     * Log statistics
     */
    public static function logStats(string $apiKey, string $isbn, bool $success, ?string $scraperUsed, int $responseTimeMs): void
    {
        $stmt = self::getInstance()->prepare(
            "INSERT INTO stats (api_key, isbn, success, scraper_used, response_time_ms) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$apiKey, $isbn, $success ? 1 : 0, $scraperUsed, $responseTimeMs]);
    }

    /**
     * Get statistics
     */
    public static function getStats(int $limit = 100, int $offset = 0): array
    {
        $stmt = self::getInstance()->prepare(
            "SELECT s.*, k.name as api_key_name
             FROM stats s
             LEFT JOIN api_keys k ON s.api_key = k.api_key
             ORDER BY s.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Get API keys
     */
    public static function getApiKeys(): array
    {
        $stmt = self::getInstance()->query(
            "SELECT * FROM api_keys ORDER BY created_at DESC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Create new API key
     */
    public static function createApiKey(string $name, ?string $notes = null): string
    {
        $apiKey = bin2hex(random_bytes(32));
        $stmt = self::getInstance()->prepare(
            "INSERT INTO api_keys (api_key, name, notes) VALUES (?, ?, ?)"
        );
        $stmt->execute([$apiKey, $name, $notes]);
        return $apiKey;
    }

    /**
     * Delete API key
     */
    public static function deleteApiKey(string $apiKey): bool
    {
        $stmt = self::getInstance()->prepare("DELETE FROM api_keys WHERE api_key = ?");
        return $stmt->execute([$apiKey]);
    }

    /**
     * Toggle API key active status
     */
    public static function toggleApiKey(string $apiKey): bool
    {
        $stmt = self::getInstance()->prepare(
            "UPDATE api_keys SET is_active = NOT is_active WHERE api_key = ?"
        );
        return $stmt->execute([$apiKey]);
    }
}
