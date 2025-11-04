<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;
use mysqli_stmt;
use RuntimeException;

class SettingsRepository
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function ensureTables(): void
    {
        $systemSettingsSql = "
            CREATE TABLE IF NOT EXISTS system_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                category VARCHAR(50) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_setting (category, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if ($this->db->query($systemSettingsSql) === false) {
            throw new RuntimeException('Unable to ensure system_settings table: ' . $this->db->error);
        }

        $emailTemplatesSql = "
            CREATE TABLE IF NOT EXISTS email_templates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL UNIQUE,
                subject VARCHAR(255) NOT NULL,
                body LONGTEXT NOT NULL,
                description TEXT,
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if ($this->db->query($emailTemplatesSql) === false) {
            throw new RuntimeException('Unable to ensure email_templates table: ' . $this->db->error);
        }
    }

    public function get(string $category, string $key, ?string $default = null): ?string
    {
        $stmt = $this->prepare('SELECT setting_value FROM system_settings WHERE category = ? AND setting_key = ? LIMIT 1');
        $stmt->bind_param('ss', $category, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $value = $result->fetch_column();
        $stmt->close();

        if ($value === null) {
            return $default;
        }

        return (string)$value;
    }

    /**
     * @return array<string,string>
     */
    public function getCategory(string $category): array
    {
        $stmt = $this->prepare('SELECT setting_key, setting_value FROM system_settings WHERE category = ?');
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $result = $stmt->get_result();

        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = (string)$row['setting_value'];
        }
        $stmt->close();

        return $settings;
    }

    public function set(string $category, string $key, ?string $value): void
    {
        $stmt = $this->prepare('INSERT INTO system_settings (category, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->bind_param('sss', $category, $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    public function delete(string $category, string $key): void
    {
        $stmt = $this->prepare('DELETE FROM system_settings WHERE category = ? AND setting_key = ?');
        $stmt->bind_param('ss', $category, $key);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param array<string, array{subject:string, body:string, description?:string}> $templates
     */
    public function ensureEmailTemplates(array $templates): void
    {
        foreach ($templates as $name => $template) {
            $existing = $this->getEmailTemplate($name);
            if ($existing !== null) {
                continue;
            }
            $this->saveEmailTemplate(
                $name,
                $template['subject'],
                $template['body'],
                $template['description'] ?? null,
                true
            );
        }
    }

    public function getEmailTemplate(string $name): ?array
    {
        $stmt = $this->prepare('SELECT name, subject, body, description, active FROM email_templates WHERE name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @param string[] $names
     * @return array<string, array{name:string,subject:string,body:string,description:?string,active:int}>
     */
    public function getEmailTemplates(array $names = []): array
    {
        $stmt = null;
        if (empty($names)) {
            $sql = 'SELECT name, subject, body, description, active FROM email_templates ORDER BY name';
            $result = $this->db->query($sql);
        } else {
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $sql = 'SELECT name, subject, body, description, active FROM email_templates WHERE name IN (' . $placeholders . ') ORDER BY name';
            $stmt = $this->prepare($sql);
            $types = str_repeat('s', count($names));
            $stmt->bind_param($types, ...$names);
            $stmt->execute();
            $result = $stmt->get_result();
        }

        $templates = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $templates[$row['name']] = $row;
            }
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
        }

        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }

        return $templates;
    }

    public function saveEmailTemplate(string $name, string $subject, string $body, ?string $description = null, bool $active = true): void
    {
        $stmt = $this->prepare('INSERT INTO email_templates (name, subject, body, description, active) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), description = VALUES(description), active = VALUES(active)');
        $activeInt = $active ? 1 : 0;
        $stmt->bind_param('ssssi', $name, $subject, $body, $description, $activeInt);
        $stmt->execute();
        $stmt->close();
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare SQL: ' . $this->db->error);
        }
        return $stmt;
    }
}
