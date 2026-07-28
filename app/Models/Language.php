<?php

declare(strict_types=1);

namespace App\Models;

use mysqli;
use App\Support\I18n;

/**
 * Language Model
 *
 * Manages multilingual support with database-driven language configurations.
 * Handles CRUD operations, default language management, and translation statistics.
 */
class Language
{
    /**
     * Source language. Every __() key IS the Italian string, so Italian is the
     * translation source: it has no lookup file and is, by definition, complete.
     * Its key count is the canonical denominator for every locale's completion.
     */
    public const SOURCE_LOCALE = 'it_IT';

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Number of translatable keys defined by the source language (Italian).
     * Derived live from locale/it_IT.json so it can never drift from the actual
     * string set — no migration is needed when keys are added or removed.
     *
     * @return int
     */
    public function sourceKeyCount(): int
    {
        static $count = null;
        if ($count !== null) {
            return $count;
        }
        $path = __DIR__ . '/../../locale/' . self::SOURCE_LOCALE . '.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $count = is_array($decoded) ? count($decoded) : 0;
        return $count;
    }

    /**
     * Count of non-empty translations actually present in a locale's file,
     * capped at the source total (orphan keys never push completion past 100%).
     * The source language is complete by definition.
     *
     * @param string $code        Locale code
     * @param int    $sourceTotal Source key count (denominator)
     * @return int
     */
    private function translatedKeyCount(string $code, int $sourceTotal): int
    {
        if ($code === self::SOURCE_LOCALE) {
            return $sourceTotal;
        }
        $path = __DIR__ . '/../../locale/' . $code . '.json';
        if (!is_file($path)) {
            return 0;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return 0;
        }
        $translated = count(array_filter($decoded, static fn($value) => is_string($value) && $value !== ''));
        return min($translated, $sourceTotal);
    }

    /**
     * Same as getAll(), but with translation stats DERIVED from the source
     * language instead of the stored (often stale) columns: total_keys is the
     * source key count for every locale, translated_keys is what that locale's
     * file actually covers, and completion_percentage is recomputed. This keeps
     * the admin figures linked to Italian without a migration per key change.
     *
     * @param bool $activeOnly
     * @return array<int, array<string, mixed>>
     */
    public function getAllWithDerivedStats(bool $activeOnly = false): array
    {
        $total = $this->sourceKeyCount();
        $languages = $this->getAll($activeOnly);
        foreach ($languages as &$lang) {
            $translated = $this->translatedKeyCount((string) ($lang['code'] ?? ''), $total);
            $lang['total_keys'] = $total;
            $lang['translated_keys'] = $translated;
            $lang['completion_percentage'] = $total > 0 ? round($translated / $total * 100, 2) : 0.00;
        }
        unset($lang);
        return $languages;
    }

    /**
     * Get all languages
     *
     * @param bool $activeOnly If true, return only active languages
     * @return array Array of language records
     */
    public function getAll(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM languages";

        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= " ORDER BY is_default DESC, code ASC";

        $result = $this->db->query($sql);

        if (!$result) {
            return [];
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get language by code
     *
     * @param string $code Language code (e.g., 'it_IT', 'en_US')
     * @return array|null Language record or null if not found
     */
    public function getByCode(string $code): ?array
    {
        $code = $this->normalizeAndAssert($code);

        $stmt = $this->db->prepare("SELECT * FROM languages WHERE code = ?");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $language = $result->fetch_assoc();
        $stmt->close();

        return $language ?: null;
    }

    /**
     * Get default language
     *
     * @return array|null Default language record or null if not set
     */
    public function getDefault(): ?array
    {
        $result = $this->db->query("SELECT * FROM languages WHERE is_default = 1 LIMIT 1");

        if (!$result) {
            return null;
        }

        $language = $result->fetch_assoc();
        return $language ?: null;
    }

    /**
     * Get active languages only
     *
     * @return array Array of active language records
     */
    public function getActive(): array
    {
        return $this->getAll(true);
    }

    /**
     * Create new language
     *
     * @param array $data Language data [code, name, native_name, flag_emoji, translation_file, etc.]
     * @return int Inserted language ID
     * @throws \Exception If creation fails
     */
    public function create(array $data): int
    {
        // Validate required fields
        if (empty($data['code']) || empty($data['name']) || empty($data['native_name'])) {
            throw new \Exception("Required fields missing: code, name, native_name");
        }

        $code = $this->normalizeAndAssert((string)$data['code']);
        $data['code'] = $code;

        // Check if code already exists
        if ($this->getByCode($code)) {
            throw new \Exception("Language code '{$code}' already exists");
        }

        // Prepare values with defaults
        $name = $data['name'];
        $nativeName = $data['native_name'];
        $flagEmoji = $data['flag_emoji'] ?? '🌐';
        $isDefault = isset($data['is_default']) ? (int)$data['is_default'] : 0;
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        $translationFile = $data['translation_file'] ?? null;
        $totalKeys = $data['total_keys'] ?? 0;
        $translatedKeys = $data['translated_keys'] ?? 0;
        $completionPercentage = $totalKeys > 0 ? ($translatedKeys / $totalKeys) * 100 : 0.00;

        $stmt = $this->db->prepare("
            INSERT INTO languages
            (code, name, native_name, flag_emoji, is_default, is_active, translation_file, total_keys, translated_keys, completion_percentage)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'ssssiiisid',
            $code,
            $name,
            $nativeName,
            $flagEmoji,
            $isDefault,
            $isActive,
            $translationFile,
            $totalKeys,
            $translatedKeys,
            $completionPercentage
        );

        $success = $stmt->execute();
        $insertId = $this->db->insert_id;
        $stmt->close();

        if (!$success) {
            throw new \Exception("Failed to create language: " . $this->db->error);
        }

        // If this is set as default, unset other defaults
        if ($isDefault) {
            $this->setDefault($code);
        }

        return $insertId;
    }

    /**
     * Update existing language
     *
     * @param string $code Language code to update
     * @param array $data New data for language
     * @return bool True on success
     * @throws \Exception If update fails
     */
    public function update(string $code, array $data): bool
    {
        $code = $this->normalizeAndAssert($code);

        // Check if language exists
        if (!$this->getByCode($code)) {
            throw new \Exception("Language code '{$code}' not found");
        }

        // Build dynamic UPDATE query based on provided fields
        $allowedFields = ['name', 'native_name', 'flag_emoji', 'is_default', 'is_active', 'translation_file', 'total_keys', 'translated_keys', 'completion_percentage'];
        $updateParts = [];
        $types = '';
        $values = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateParts[] = "`{$field}` = ?";

                // Determine type
                if (in_array($field, ['is_default', 'is_active', 'total_keys', 'translated_keys'])) {
                    $types .= 'i'; // integer
                    $values[] = (int)$data[$field];
                } elseif ($field === 'completion_percentage') {
                    $types .= 'd'; // double
                    $values[] = (float)$data[$field];
                } else {
                    $types .= 's'; // string
                    $values[] = $data[$field];
                }
            }
        }

        if (empty($updateParts)) {
            return true; // Nothing to update
        }

        $sql = "UPDATE languages SET " . implode(', ', $updateParts) . " WHERE code = ?";
        $types .= 's'; // for code parameter
        $values[] = $code;

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            throw new \Exception("Failed to update language: " . $this->db->error);
        }

        // If is_default was set to 1, unset other defaults
        if (!empty($data['is_default']) && $data['is_default'] == 1) {
            $this->setDefault($code);
        }

        return true;
    }

    /**
     * Delete language
     *
     * @param string $code Language code to delete
     * @return bool True on success
     * @throws \Exception If deletion fails or language is default
     */
    public function delete(string $code): bool
    {
        $code = $this->normalizeAndAssert($code);

        $language = $this->getByCode($code);

        if (!$language) {
            throw new \Exception("Language code '{$code}' not found");
        }

        // Prevent deletion of default language
        if ($language['is_default']) {
            throw new \Exception("Cannot delete default language. Set another language as default first.");
        }

        $stmt = $this->db->prepare("DELETE FROM languages WHERE code = ?");
        $stmt->bind_param('s', $code);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            throw new \Exception("Failed to delete language: " . $this->db->error);
        }

        return true;
    }

    /**
     * Set language as default (and unset others)
     *
     * Note: Promoting an inactive language to default re-activates it.
     * A default language cannot be inactive — the SQL update forces
     * `is_active = 1` on the language being promoted. Callers that care
     * about the previous activation state should read `is_active` from
     * the row BEFORE calling this method (e.g. to surface a flash
     * message informing the user that the language was auto-activated).
     *
     * @param string $code Language code to set as default
     * @return bool True on success
     * @throws \Exception If operation fails
     */
    public function setDefault(string $code): bool
    {
        $code = $this->normalizeAndAssert($code);

        // Check if language exists
        if (!$this->getByCode($code)) {
            throw new \Exception("Language code '{$code}' not found");
        }

        // Start transaction
        $this->db->begin_transaction();

        try {
            // Unset all defaults
            $this->db->query("UPDATE languages SET is_default = 0");

            // Set new default and ensure it is active (can't default to inactive language)
            $stmt = $this->db->prepare("UPDATE languages SET is_default = 1, is_active = 1 WHERE code = ?");
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $stmt->close();

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw new \Exception("Failed to set default language: " . $e->getMessage());
        }
    }

    /**
     * Toggle active status of language
     *
     * @param string $code Language code to toggle
     * @return bool New active status (true = active, false = inactive)
     * @throws \Exception If operation fails or language is default
     */
    public function toggleActive(string $code): bool
    {
        $code = $this->normalizeAndAssert($code);

        $language = $this->getByCode($code);

        if (!$language) {
            throw new \Exception("Language code '{$code}' not found");
        }

        // Prevent deactivation of default language
        if ($language['is_default'] && $language['is_active']) {
            throw new \Exception("Cannot deactivate default language. Set another language as default first.");
        }

        $newStatus = $language['is_active'] ? 0 : 1;

        $stmt = $this->db->prepare("UPDATE languages SET is_active = ? WHERE code = ?");
        $stmt->bind_param('is', $newStatus, $code);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            throw new \Exception("Failed to toggle language status: " . $this->db->error);
        }

        return (bool)$newStatus;
    }

    /**
     * Update translation statistics for language
     *
     * @param string $code Language code
     * @param int $total Total translation keys in system
     * @param int $translated Number of translated keys
     * @return bool True on success
     * @throws \Exception If update fails
     */
    public function updateStats(string $code, int $total, int $translated): bool
    {
        // Check if language exists
        if (!$this->getByCode($code)) {
            throw new \Exception("Language code '{$code}' not found");
        }

        // Calculate completion percentage
        $percentage = $total > 0 ? ($translated / $total) * 100 : 0.00;

        $stmt = $this->db->prepare("
            UPDATE languages
            SET total_keys = ?,
                translated_keys = ?,
                completion_percentage = ?
            WHERE code = ?
        ");

        $stmt->bind_param('iids', $total, $translated, $percentage, $code);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            throw new \Exception("Failed to update language stats: " . $this->db->error);
        }

        return true;
    }

    /**
     * Get available locales for use in I18n system
     * Returns associative array: ['it_IT' => 'Italiano', 'en_US' => 'English']
     *
     * @param bool $activeOnly If true, return only active languages
     * @return array Associative array of code => native_name
     */
    public function getAvailableLocales(bool $activeOnly = true): array
    {
        $languages = $this->getAll($activeOnly);
        $locales = [];

        foreach ($languages as $lang) {
            $code = I18n::normalizeLocaleCode((string)$lang['code']);
            if (!I18n::isValidLocaleCode($code)) {
                continue;
            }

            $locales[$code] = $lang['native_name'];
        }

        return $locales;
    }

    private function normalizeAndAssert(string $code): string
    {
        $normalized = I18n::normalizeLocaleCode($code);

        if (!I18n::isValidLocaleCode($normalized)) {
            throw new \InvalidArgumentException('Invalid locale code provided');
        }

        return $normalized;
    }
}
