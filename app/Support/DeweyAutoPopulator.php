<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Dewey Auto-Populator
 *
 * Automatically adds missing Dewey classification entries to JSON files
 * when they are discovered from external sources like SBN.
 *
 * Language-aware: Only updates JSON files matching the source language.
 */
class DeweyAutoPopulator
{
    /**
     * Map of data sources to their languages
     */
    private const SOURCE_LANGUAGES = [
        'sbn' => 'it',      // SBN is Italian
        'loc' => 'en',      // Library of Congress is English
        'k10plus' => 'de',  // K10plus is German
    ];

    /**
     * Map of locale codes to JSON file names
     */
    private const LOCALE_FILES = [
        'it' => 'dewey_completo_it.json',
        'en' => 'dewey_completo_en.json',
    ];

    private static string $dataDir = '';

    /**
     * Add or update a Dewey entry if missing
     *
     * @param string $code Dewey code (e.g., "808.81")
     * @param string $name Classification name
     * @param string $source Data source (e.g., "sbn")
     * @return bool True if entry was added/updated, false otherwise
     */
    public static function addIfMissing(string $code, string $name, string $source = 'sbn'): bool
    {
        // Validate inputs
        if (empty($code) || empty($name)) {
            return false;
        }

        // Determine source language
        $sourceLanguage = self::SOURCE_LANGUAGES[$source] ?? null;
        if ($sourceLanguage === null) {
            error_log("[DeweyAutoPopulator] Unknown source: $source");
            return false;
        }

        // Get current app locale
        $appLocale = self::getAppLocaleShort();

        // Only update if source language matches app locale
        if ($sourceLanguage !== $appLocale) {
            // Don't update - language mismatch
            return false;
        }

        // Get JSON file for this locale
        $jsonFile = self::getJsonFilePath($appLocale);
        if (!$jsonFile || !file_exists($jsonFile)) {
            error_log("[DeweyAutoPopulator] JSON file not found for locale: $appLocale");
            return false;
        }

        // Load existing data
        $data = self::loadJson($jsonFile);
        if ($data === null) {
            return false;
        }

        // Check if code exists and has a name
        $existing = self::findEntry($data, $code);
        if ($existing !== null && !empty($existing['name'])) {
            // Entry exists with name - don't overwrite
            return false;
        }

        // Add or update the entry
        $updated = self::addOrUpdateEntry($data, $code, $name);
        if (!$updated) {
            return false;
        }

        // Save back to file
        return self::saveJson($jsonFile, $data);
    }

    /**
     * Process book data and auto-populate Dewey if present
     *
     * @param array $bookData Book data from scraping
     * @return bool True if Dewey was added
     */
    public static function processBookData(array $bookData): bool
    {
        $code = $bookData['classificazione_dewey'] ?? null;
        $name = $bookData['_dewey_name_sbn'] ?? null;
        $source = $bookData['_source'] ?? 'sbn';

        if (empty($code) || empty($name)) {
            return false;
        }

        return self::addIfMissing($code, $name, $source);
    }

    /**
     * Get short locale code (it, en, etc.)
     */
    private static function getAppLocaleShort(): string
    {
        if (class_exists('\App\Support\I18n')) {
            $locale = I18n::getLocale();
            return substr($locale, 0, 2);
        }

        // Fallback to ENV
        $locale = $_ENV['APP_LOCALE'] ?? 'it_IT';
        return substr($locale, 0, 2);
    }

    /**
     * Get JSON file path for a locale
     */
    private static function getJsonFilePath(string $locale): ?string
    {
        $file = self::LOCALE_FILES[$locale] ?? null;
        if (!$file) {
            return null;
        }

        if (empty(self::$dataDir)) {
            // Determine base path
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
            self::$dataDir = $basePath . '/data/dewey';
        }

        return self::$dataDir . '/' . $file;
    }

    /**
     * Load JSON file
     */
    private static function loadJson(string $path): ?array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            error_log("[DeweyAutoPopulator] Failed to read: $path");
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[DeweyAutoPopulator] JSON parse error: " . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * Save JSON file with pretty printing
     */
    private static function saveJson(string $path, array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            error_log("[DeweyAutoPopulator] JSON encode error: " . json_last_error_msg());
            return false;
        }

        $result = file_put_contents($path, $json, LOCK_EX);
        if ($result === false) {
            error_log("[DeweyAutoPopulator] Failed to write: $path");
            return false;
        }

        error_log("[DeweyAutoPopulator] Updated $path with new entry");
        return true;
    }

    /**
     * Find an entry by code in the hierarchical structure
     *
     * @param array $data Full Dewey data
     * @param string $code Code to find
     * @return array|null Entry if found
     */
    private static function findEntry(array $data, string $code): ?array
    {
        foreach ($data as $entry) {
            if (($entry['code'] ?? '') === $code) {
                return $entry;
            }

            if (!empty($entry['children'])) {
                $found = self::findEntry($entry['children'], $code);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Add or update an entry in the hierarchical structure
     *
     * @param array &$data Full Dewey data (modified by reference)
     * @param string $code Code to add/update
     * @param string $name Name for the entry
     * @return bool True if modified
     */
    private static function addOrUpdateEntry(array &$data, string $code, string $name): bool
    {
        // Calculate parent code and level
        $parentCode = self::getParentCode($code);
        $level = self::calculateLevel($code);

        // If top-level (main class), add directly
        if ($parentCode === null) {
            foreach ($data as &$entry) {
                if (($entry['code'] ?? '') === $code) {
                    if (empty($entry['name'])) {
                        $entry['name'] = $name;
                        return true;
                    }
                    return false; // Already has name
                }
            }
            // Add new top-level entry
            $data[] = [
                'code' => $code,
                'name' => $name,
                'level' => $level,
                'children' => []
            ];
            self::sortByCode($data);
            return true;
        }

        // Find or create parent, then add this entry
        return self::addToParent($data, $parentCode, $code, $name, $level);
    }

    /**
     * Add entry to a parent node (recursively creating parents if needed)
     */
    private static function addToParent(array &$data, string $parentCode, string $code, string $name, int $level): bool
    {
        // First, ensure parent exists
        $parentExists = self::findEntry($data, $parentCode);
        if ($parentExists === null) {
            // Create parent first (with empty name - will be populated later if found)
            $grandParentCode = self::getParentCode($parentCode);
            $parentLevel = self::calculateLevel($parentCode);

            if ($grandParentCode === null) {
                // Parent is top-level
                $data[] = [
                    'code' => $parentCode,
                    'name' => '', // Unknown name
                    'level' => $parentLevel,
                    'children' => []
                ];
                self::sortByCode($data);
            } else {
                // Recursively create grandparent
                self::addToParent($data, $grandParentCode, $parentCode, '', $parentLevel);
            }
        }

        // Now add the actual entry to its parent
        return self::addToParentRecursive($data, $parentCode, $code, $name, $level);
    }

    /**
     * Recursively find parent and add child entry
     */
    private static function addToParentRecursive(array &$data, string $parentCode, string $code, string $name, int $level): bool
    {
        foreach ($data as &$entry) {
            if (($entry['code'] ?? '') === $parentCode) {
                // Found parent - check if child already exists
                if (!isset($entry['children'])) {
                    $entry['children'] = [];
                }

                foreach ($entry['children'] as &$child) {
                    if (($child['code'] ?? '') === $code) {
                        if (empty($child['name']) && !empty($name)) {
                            $child['name'] = $name;
                            return true;
                        }
                        return false; // Already exists with name
                    }
                }

                // Add new child
                $entry['children'][] = [
                    'code' => $code,
                    'name' => $name,
                    'level' => $level,
                    'children' => []
                ];
                self::sortByCode($entry['children']);
                return true;
            }

            // Search in children
            if (!empty($entry['children'])) {
                if (self::addToParentRecursive($entry['children'], $parentCode, $code, $name, $level)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get parent code for a Dewey code
     * 808.81 -> 808.8 -> 808 -> 800 -> null
     */
    private static function getParentCode(string $code): ?string
    {
        // Main class (e.g., "800") has no parent
        if (preg_match('/^\d00$/', $code)) {
            return null;
        }

        // Division (e.g., "810") -> main class (e.g., "800")
        if (preg_match('/^\d\d0$/', $code)) {
            return substr($code, 0, 1) . '00';
        }

        // Section (e.g., "813") -> division (e.g., "810")
        if (preg_match('/^\d{3}$/', $code)) {
            return substr($code, 0, 2) . '0';
        }

        // Decimal: remove last digit or last decimal portion
        if (str_contains($code, '.')) {
            $parts = explode('.', $code);
            $decimal = $parts[1];

            if (strlen($decimal) > 1) {
                // 808.81 -> 808.8
                return $parts[0] . '.' . substr($decimal, 0, -1);
            } else {
                // 808.8 -> 808
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * Calculate level for a Dewey code
     */
    private static function calculateLevel(string $code): int
    {
        // Main class (e.g., 800) = level 1
        if (preg_match('/^\d00$/', $code)) {
            return 1;
        }

        // Division (e.g., 810) = level 2
        if (preg_match('/^\d\d0$/', $code)) {
            return 2;
        }

        // Section (e.g., 813) = level 3
        if (preg_match('/^\d{3}$/', $code)) {
            return 3;
        }

        // Decimal levels
        if (str_contains($code, '.')) {
            $decimal = explode('.', $code)[1];
            return 3 + strlen($decimal);
        }

        return 3;
    }

    /**
     * Sort array by code
     */
    private static function sortByCode(array &$data): void
    {
        usort($data, function ($a, $b) {
            return strcmp($a['code'] ?? '', $b['code'] ?? '');
        });
    }
}
