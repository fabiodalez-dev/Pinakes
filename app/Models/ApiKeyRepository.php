<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;
use RuntimeException;

class ApiKeyRepository
{
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
                api_key VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                is_active TINYINT(1) DEFAULT 1,
                last_used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_api_key (api_key),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Unable to ensure api_keys table: ' . $this->db->error);
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
     * Create a new API key
     */
    public function create(string $name, ?string $description = null): array
    {
        $apiKey = $this->generateApiKey();
        $stmt = $this->db->prepare('INSERT INTO api_keys (api_key, name, description, is_active) VALUES (?, ?, ?, 1)');
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->db->error);
        }
        $stmt->bind_param('sss', $apiKey, $name, $description);
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();

        return [
            'id' => $id,
            'api_key' => $apiKey,
            'name' => $name,
            'description' => $description,
            'is_active' => true
        ];
    }

    /**
     * Get all API keys
     */
    public function getAll(): array
    {
        $result = $this->db->query('SELECT id, api_key, name, description, is_active, last_used_at, created_at FROM api_keys ORDER BY created_at DESC');
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
     * Get active API key by key value
     */
    public function getByKey(string $apiKey): ?array
    {
        $stmt = $this->db->prepare('SELECT id, api_key, name, description, is_active, last_used_at FROM api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1');
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->db->error);
        }
        $stmt->bind_param('s', $apiKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Update last used timestamp
     */
    public function updateLastUsed(string $apiKey): void
    {
        $stmt = $this->db->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE api_key = ?');
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->db->error);
        }
        $stmt->bind_param('s', $apiKey);
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
}
