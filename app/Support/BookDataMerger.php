<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Book Data Merger
 *
 * Intelligently merges book data from multiple scraping sources.
 * Fills empty fields with data from new sources while preserving
 * existing data and selecting the best quality cover image.
 */
class BookDataMerger
{
    /**
     * Cover quality scores for intelligent selection
     * Higher score = better quality (prefer keeping)
     *
     * Based on actual testing (Nov 2024):
     * - Open Library L: ~320x500 px (~162k pixels) - BEST free source
     * - Google Books: ~128x196 px (~25k pixels) - Much smaller
     * - Z39.50/SRU: No covers (metadata only)
     */
    private const COVER_QUALITY_SCORES = [
        'scraping-pro' => 100,      // Highest quality (retailer sources, often 500x700+)
        'scrapingpro' => 100,       // Alternative name
        'api-book-scraper' => 95,   // Custom API - often retailer sources with high-res covers
        'custom-api' => 95,         // Alternative name for API Book Scraper
        'amazon' => 95,             // High quality retailer covers
        'open-library' => 90,       // TESTED: 320x500 px (~162k pixels) - excellent
        'openlibrary' => 90,        // Alternative name
        'goodreads' => 80,          // Good quality when available
        'google-books' => 50,       // TESTED: 128x196 px (~25k pixels) - small
        'googlebooks' => 50,        // Alternative name
        'google' => 50,             // Alternative name
        'z39' => 20,                // No covers - metadata only
        'sru' => 20,                // No covers - metadata only
        'default' => 30,            // Unknown source
    ];

    /**
     * Fields that should be merged as arrays (e.g., multiple authors)
     */
    private const ARRAY_FIELDS = ['authors', 'keywords', 'subjects', 'categories'];

    /**
     * Fields to skip during merge (internal metadata)
     */
    private const SKIP_FIELDS = ['_cover_only', '_openlibrary_edition_key', '_openlibrary_work_key', '_keywords_priority'];

    /**
     * Fields with priority protection (e.g., _keywords_priority means keywords from that source take precedence)
     */
    private const PRIORITY_PROTECTED_FIELDS = ['keywords'];

    /**
     * Fields to track for alternatives (user-visible fields)
     */
    private const ALTERNATIVE_FIELDS = ['title', 'publisher', 'description', 'image', 'cover', 'copertina_url', 'year', 'pages', 'price'];

    /**
     * Merge book data from a new source into existing data
     *
     * @param array|null $existing Existing accumulated data (null if none)
     * @param array|null $new New data from current source
     * @param string $source Source identifier for quality scoring (e.g., 'scraping-pro', 'z39')
     * @return array|null Merged data or null if both are null
     */
    public static function merge(?array $existing, ?array $new, string $source = 'default'): ?array
    {
        // If no new data, return existing
        if ($new === null || empty($new)) {
            return $existing;
        }

        // If no existing data, return new data with source marker
        if ($existing === null || empty($existing)) {
            $new['_primary_source'] = $source;
            // Initialize alternatives with this source's data
            $new['_alternatives'] = [$source => self::extractAlternativeFields($new)];
            return $new;
        }

        // Both have data - merge intelligently
        $merged = $existing;
        $existingSource = $existing['_primary_source'] ?? 'default';

        // Initialize alternatives array if not exists
        if (!isset($merged['_alternatives'])) {
            $merged['_alternatives'] = [];
        }

        // Store this source's data as an alternative (before merging)
        $sourceAlternative = self::extractAlternativeFields($new);
        if (!empty($sourceAlternative)) {
            $merged['_alternatives'][$source] = $sourceAlternative;
        }

        foreach ($new as $key => $newValue) {
            // Skip internal metadata fields
            if (in_array($key, self::SKIP_FIELDS, true)) {
                continue;
            }

            // Skip if new value is empty
            if (self::isEmpty($newValue)) {
                continue;
            }

            $existingValue = $merged[$key] ?? null;

            // Handle cover image specially - select best quality
            if ($key === 'image' || $key === 'cover' || $key === 'copertina_url') {
                $merged[$key] = self::selectBestCover($existingValue, $newValue, $existingSource, $source);
                continue;
            }

            // Handle array fields - check for priority protection first
            if (in_array($key, self::ARRAY_FIELDS, true)) {
                // Check if this field has priority protection
                if (in_array($key, self::PRIORITY_PROTECTED_FIELDS, true)) {
                    $priorityKey = "_{$key}_priority";
                    // If existing data has priority flag set, keep existing value (don't merge)
                    if (!empty($merged[$priorityKey])) {
                        continue;
                    }
                    // If new data has priority flag, use new value exclusively
                    if (!empty($new[$priorityKey])) {
                        $merged[$key] = $newValue;
                        $merged[$priorityKey] = $new[$priorityKey];
                        continue;
                    }
                }
                // Normal merge for array fields
                $merged[$key] = self::mergeArrayField($existingValue, $newValue);
                continue;
            }

            // For regular fields, fill empty values
            if (self::isEmpty($existingValue)) {
                $merged[$key] = $newValue;
            }
        }

        // Track all sources that contributed
        $sources = $merged['_sources'] ?? [];
        if (!in_array($source, $sources, true)) {
            $sources[] = $source;
        }
        $merged['_sources'] = $sources;

        // Clean up alternatives: remove entries that are identical to merged values
        $merged['_alternatives'] = self::filterAlternatives($merged['_alternatives'], $merged);

        return $merged;
    }

    /**
     * Extract fields relevant for alternatives display
     *
     * @param array $data Source data
     * @return array Filtered data with only alternative-worthy fields
     */
    private static function extractAlternativeFields(array $data): array
    {
        $result = [];
        foreach (self::ALTERNATIVE_FIELDS as $field) {
            if (isset($data[$field]) && !self::isEmpty($data[$field])) {
                $result[$field] = $data[$field];
            }
        }
        return $result;
    }

    /**
     * Filter alternatives to only keep entries with different values than merged
     *
     * @param array $alternatives All alternatives by source
     * @param array $merged Merged result
     * @return array Filtered alternatives with only differing values
     */
    private static function filterAlternatives(array $alternatives, array $merged): array
    {
        $filtered = [];

        foreach ($alternatives as $source => $sourceData) {
            $diffFields = [];

            foreach ($sourceData as $field => $value) {
                $mergedValue = $merged[$field] ?? null;

                // Keep if value is different from merged (and not empty)
                if (!self::isEmpty($value) && $value !== $mergedValue) {
                    $diffFields[$field] = $value;
                }
            }

            // Only include source if it has differing values
            if (!empty($diffFields)) {
                $filtered[$source] = $diffFields;
            }
        }

        return $filtered;
    }

    /**
     * Select the best cover image based on source quality
     *
     * @param mixed $existing Existing cover URL
     * @param mixed $new New cover URL
     * @param string $existingSource Source of existing cover
     * @param string $newSource Source of new cover
     * @return string|null Best cover URL
     */
    private static function selectBestCover($existing, $new, string $existingSource, string $newSource): ?string
    {
        // If no existing, use new
        if (self::isEmpty($existing)) {
            return is_string($new) ? $new : null;
        }

        // If no new, keep existing
        if (self::isEmpty($new)) {
            return is_string($existing) ? $existing : null;
        }

        // Both exist - compare quality scores
        $existingScore = self::getCoverQualityScore($existingSource);
        $newScore = self::getCoverQualityScore($newSource);

        // Keep the higher quality cover
        if ($newScore > $existingScore) {
            return is_string($new) ? $new : (is_string($existing) ? $existing : null);
        }

        return is_string($existing) ? $existing : null;
    }

    /**
     * Get quality score for a source
     *
     * @param string $source Source identifier
     * @return int Quality score
     */
    private static function getCoverQualityScore(string $source): int
    {
        $source = strtolower(str_replace(['_', ' '], '-', $source));

        // Check for partial matches
        foreach (self::COVER_QUALITY_SCORES as $key => $score) {
            if (str_contains($source, $key)) {
                return $score;
            }
        }

        return self::COVER_QUALITY_SCORES['default'];
    }

    /**
     * Merge array fields (e.g., authors list)
     *
     * @param mixed $existing Existing array
     * @param mixed $new New array
     * @return array Merged unique values
     */
    private static function mergeArrayField($existing, $new): array
    {
        $existingArray = is_array($existing) ? $existing : [];
        $newArray = is_array($new) ? $new : [];

        // If existing is a string (comma-separated), split it
        if (is_string($existing) && !empty($existing)) {
            $existingArray = array_map('trim', explode(',', $existing));
        }

        // If new is a string (comma-separated), split it
        if (is_string($new) && !empty($new)) {
            $newArray = array_map('trim', explode(',', $new));
        }

        // Merge arrays
        $merged = array_merge($existingArray, $newArray);

        // Handle deduplication for arrays of associative arrays (e.g., authors with 'name' key)
        if (!empty($merged) && is_array($merged[0] ?? null)) {
            $seen = [];
            $merged = array_filter($merged, function ($item) use (&$seen) {
                $key = is_array($item) ? json_encode($item) : (string) $item;
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;
                return true;
            });
        } else {
            // Simple scalar array - use array_unique
            $merged = array_unique($merged);
        }

        $merged = array_filter($merged, fn($v) => !self::isEmpty($v));

        return array_values($merged);
    }

    /**
     * Check if a value is considered empty
     *
     * @param mixed $value Value to check
     * @return bool True if empty
     */
    private static function isEmpty($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return count($value) === 0;
        }

        return false;
    }

    /**
     * Get a list of all fields that have data
     *
     * @param array|null $data Book data
     * @return array List of field names with non-empty values
     */
    public static function getFilledFields(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $filled = [];
        foreach ($data as $key => $value) {
            if (!str_starts_with($key, '_') && !self::isEmpty($value)) {
                $filled[] = $key;
            }
        }

        return $filled;
    }

    /**
     * Get a list of fields that are still missing
     *
     * @param array|null $data Book data
     * @param array $requiredFields List of desired fields
     * @return array List of missing field names
     */
    public static function getMissingFields(?array $data, array $requiredFields = []): array
    {
        if (empty($requiredFields)) {
            $requiredFields = ['title', 'authors', 'publisher', 'year', 'pages', 'description', 'image', 'isbn13'];
        }

        if ($data === null) {
            return $requiredFields;
        }

        $missing = [];
        foreach ($requiredFields as $field) {
            if (self::isEmpty($data[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
