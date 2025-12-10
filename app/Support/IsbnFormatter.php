<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ISBN Formatter and Validator
 *
 * Handles ISBN-10 and ISBN-13 validation, conversion, and normalization.
 * ISBN-10 uses modulo-11 checksum with weights 10-2.
 * ISBN-13 uses modulo-10 checksum with alternating weights 1,3.
 *
 * Note: Only 978-prefix ISBN-13s can be converted to ISBN-10.
 * 979-prefix ISBN-13s have no valid ISBN-10 equivalent.
 */
class IsbnFormatter
{
    /**
     * Clean ISBN: remove hyphens, spaces, convert to uppercase
     *
     * @param string $isbn Raw ISBN input
     * @return string Cleaned ISBN (digits and X only)
     */
    public static function clean(string $isbn): string
    {
        return preg_replace('/[^0-9X]/i', '', strtoupper($isbn));
    }

    /**
     * Validate ISBN-10 checksum (modulo 11)
     *
     * ISBN-10 checksum: sum of (digit × weight) where weights are 10,9,8,...,2,1
     * Check digit can be 0-9 or X (representing 10)
     *
     * @param string $isbn ISBN to validate
     * @return bool True if valid ISBN-10
     */
    public static function isValidIsbn10(string $isbn): bool
    {
        $isbn = self::clean($isbn);
        if (strlen($isbn) !== 10) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            if (!ctype_digit($isbn[$i])) {
                return false;
            }
            $sum += (int)$isbn[$i] * (10 - $i);
        }

        $checkChar = $isbn[9];
        $checkValue = ($checkChar === 'X') ? 10 : (ctype_digit($checkChar) ? (int)$checkChar : -1);

        if ($checkValue < 0) {
            return false;
        }

        $sum += $checkValue;
        return ($sum % 11) === 0;
    }

    /**
     * Validate ISBN-13 checksum (modulo 10, weights 1,3)
     *
     * ISBN-13 checksum: sum of (digit × weight) where weights alternate 1,3,1,3,...
     * Check digit is always 0-9 (no X)
     *
     * @param string $isbn ISBN to validate
     * @return bool True if valid ISBN-13
     */
    public static function isValidIsbn13(string $isbn): bool
    {
        $isbn = self::clean($isbn);
        if (strlen($isbn) !== 13 || !ctype_digit($isbn)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$isbn[$i] * (($i % 2) === 0 ? 1 : 3);
        }

        $checkDigit = (10 - ($sum % 10)) % 10;
        return ((int)$isbn[12]) === $checkDigit;
    }

    /**
     * Validate any ISBN format (10 or 13 digits)
     *
     * @param string $isbn ISBN to validate
     * @return bool True if valid ISBN-10 or ISBN-13
     */
    public static function isValid(string $isbn): bool
    {
        $isbn = self::clean($isbn);
        return self::isValidIsbn10($isbn) || self::isValidIsbn13($isbn);
    }

    /**
     * Convert ISBN-10 to ISBN-13 (978 prefix)
     *
     * @param string $isbn10 ISBN-10 to convert
     * @return string|null ISBN-13 or null if invalid input
     */
    public static function isbn10ToIsbn13(string $isbn10): ?string
    {
        $isbn10 = self::clean($isbn10);
        if (strlen($isbn10) !== 10) {
            return null;
        }

        // Take first 9 digits, prepend 978
        $isbn13Base = '978' . substr($isbn10, 0, 9);

        // Calculate ISBN-13 check digit
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$isbn13Base[$i] * (($i % 2) === 0 ? 1 : 3);
        }
        $checkDigit = (10 - ($sum % 10)) % 10;

        return $isbn13Base . $checkDigit;
    }

    /**
     * Convert ISBN-13 to ISBN-10 (only works for 978 prefix)
     *
     * @param string $isbn13 ISBN-13 to convert
     * @return string|null ISBN-10, or null if 979 prefix or invalid
     */
    public static function isbn13ToIsbn10(string $isbn13): ?string
    {
        $isbn13 = self::clean($isbn13);
        if (strlen($isbn13) !== 13) {
            return null;
        }

        // Only 978-prefix ISBNs can be converted to ISBN-10
        if (!str_starts_with($isbn13, '978')) {
            return null; // 979-prefix has no ISBN-10 equivalent
        }

        // Take digits 4-12 (9 digits after 978)
        $isbn10Base = substr($isbn13, 3, 9);

        // Calculate ISBN-10 check digit (modulo 11)
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$isbn10Base[$i] * (10 - $i);
        }
        $checkDigit = (11 - ($sum % 11)) % 11;
        $checkChar = ($checkDigit === 10) ? 'X' : (string)$checkDigit;

        return $isbn10Base . $checkChar;
    }

    /**
     * Get all possible ISBN variants for a given ISBN
     *
     * For ISBN-10: returns both ISBN-10 and converted ISBN-13
     * For ISBN-13 with 978 prefix: returns both ISBN-13 and converted ISBN-10
     * For ISBN-13 with 979 prefix: returns only ISBN-13 (no ISBN-10 possible)
     *
     * @param string $isbn ISBN in any format
     * @return array Associative array with 'isbn10' and/or 'isbn13' keys
     */
    public static function getAllVariants(string $isbn): array
    {
        $isbn = self::clean($isbn);
        $variants = [];

        if (strlen($isbn) === 10 && self::isValidIsbn10($isbn)) {
            $variants['isbn10'] = $isbn;
            $isbn13 = self::isbn10ToIsbn13($isbn);
            if ($isbn13) {
                $variants['isbn13'] = $isbn13;
            }
        } elseif (strlen($isbn) === 13 && self::isValidIsbn13($isbn)) {
            $variants['isbn13'] = $isbn;
            $isbn10 = self::isbn13ToIsbn10($isbn);
            if ($isbn10) {
                $variants['isbn10'] = $isbn10;
            }
        }

        return $variants;
    }

    /**
     * Detect format: 'isbn10', 'isbn13', or null
     *
     * @param string $isbn ISBN to check
     * @return string|null 'isbn10', 'isbn13', or null if unrecognized length
     */
    public static function detectFormat(string $isbn): ?string
    {
        $isbn = self::clean($isbn);
        if (strlen($isbn) === 10) {
            return 'isbn10';
        } elseif (strlen($isbn) === 13) {
            return 'isbn13';
        }
        return null;
    }
}
