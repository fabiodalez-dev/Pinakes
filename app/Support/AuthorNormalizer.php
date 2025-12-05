<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Author Name Normalizer
 *
 * Normalizes author names from different formats to prevent duplicates.
 * Handles conversion between formats like:
 * - "Levi, Primo" (SBN format: Surname, Name)
 * - "Primo Levi" (Google Books format: Name Surname)
 *
 * @package App\Support
 */
class AuthorNormalizer
{
    /**
     * Normalize an author name to a canonical format: "Name Surname"
     *
     * Examples:
     * - "Levi, Primo" → "Primo Levi"
     * - "Primo Levi" → "Primo Levi" (unchanged)
     * - "Marx, Karl Heinrich" → "Karl Heinrich Marx"
     * - "De Filippo, Eduardo" → "Eduardo De Filippo"
     *
     * @param string $name The author name in any format
     * @return string Normalized name in "Name Surname" format
     */
    public static function normalize(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        // Remove date ranges like <1818-1883> (SBN format)
        $name = preg_replace('/<[^>]+>/', '', $name) ?? $name;
        $name = trim($name);

        // If the name contains a comma, it's likely in "Surname, Name" format
        if (str_contains($name, ',')) {
            $parts = explode(',', $name, 2);
            if (count($parts) === 2) {
                $surname = trim($parts[0]);
                $firstName = trim($parts[1]);

                // Only swap if both parts are non-empty
                if ($surname !== '' && $firstName !== '') {
                    $name = $firstName . ' ' . $surname;
                }
            }
        }

        // Normalize multiple spaces to single space
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        // Normalize case: convert all caps to title case
        if ($name === mb_strtoupper($name, 'UTF-8') && mb_strlen($name, 'UTF-8') > 3) {
            $name = self::toTitleCase($name);
        }

        return trim($name);
    }

    /**
     * Convert a name to its canonical search form for matching
     *
     * This creates a version optimized for searching:
     * - Lowercase
     * - No accents
     * - No extra spaces
     * - Alphabetically sorted words (for flexible matching)
     *
     * @param string $name The author name
     * @return string Canonical search form
     */
    public static function toSearchForm(string $name): string
    {
        // First normalize the name
        $normalized = self::normalize($name);

        if ($normalized === '') {
            return '';
        }

        // Convert to lowercase
        $search = mb_strtolower($normalized, 'UTF-8');

        // Remove accents for more flexible matching
        $search = self::removeAccents($search);

        // Normalize spaces
        $search = preg_replace('/\s+/', ' ', $search) ?? $search;

        return trim($search);
    }

    /**
     * Generate alternative search patterns for an author name
     *
     * Returns both "Name Surname" and "Surname, Name" formats
     * for database searching.
     *
     * @param string $name The author name in any format
     * @return array Array of possible name formats to search
     */
    public static function getSearchVariants(string $name): array
    {
        $normalized = self::normalize($name);

        if ($normalized === '') {
            return [];
        }

        $variants = [$normalized];

        // Generate "Surname, Name" variant
        $parts = explode(' ', $normalized);
        if (count($parts) >= 2) {
            $lastName = array_pop($parts);
            $firstName = implode(' ', $parts);
            $reversed = $lastName . ', ' . $firstName;
            if (!in_array($reversed, $variants, true)) {
                $variants[] = $reversed;
            }
        }

        return $variants;
    }

    /**
     * Check if two author names likely refer to the same person
     *
     * @param string $name1 First author name
     * @param string $name2 Second author name
     * @return bool True if names likely match
     */
    public static function match(string $name1, string $name2): bool
    {
        $search1 = self::toSearchForm($name1);
        $search2 = self::toSearchForm($name2);

        if ($search1 === '' || $search2 === '') {
            return false;
        }

        // Direct match
        if ($search1 === $search2) {
            return true;
        }

        // Check if words are the same (order-independent)
        $words1 = explode(' ', $search1);
        $words2 = explode(' ', $search2);

        sort($words1);
        sort($words2);

        return $words1 === $words2;
    }

    /**
     * Convert string to proper title case (respecting particles)
     *
     * Handles particles like "de", "di", "von", "van", etc.
     *
     * @param string $text Text to convert
     * @return string Title-cased text
     */
    private static function toTitleCase(string $text): string
    {
        // Particles that should remain lowercase (unless at start)
        $particles = ['de', 'del', 'della', 'dei', 'degli', 'di', 'da',
                     'von', 'van', 'der', 'den', 'la', 'le', 'du', 'des'];

        $words = explode(' ', mb_strtolower($text, 'UTF-8'));
        $result = [];

        foreach ($words as $i => $word) {
            // First word or not a particle: capitalize
            if ($i === 0 || !in_array($word, $particles, true)) {
                $result[] = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
            } else {
                $result[] = $word;
            }
        }

        return implode(' ', $result);
    }

    /**
     * Remove accents from a string for flexible matching
     *
     * @param string $text Text with possible accents
     * @return string Text without accents
     */
    private static function removeAccents(string $text): string
    {
        $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
            'ø' => 'o', 'æ' => 'ae', 'œ' => 'oe',
        ];

        return strtr($text, $accents);
    }
}
