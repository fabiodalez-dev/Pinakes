<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Support;

/**
 * ISSN validation and derivation helpers (review #140).
 *
 * No PSR-4 autoloader scope exists for plugin classes: callers
 * require_once this file directly (same lazy pattern as the admin
 * controllers). Pure functions, no DB access.
 */
final class IssnHelper
{
    /**
     * Loose structural check: 4 digits, optional hyphen, 3 digits and a
     * final digit or X/x (e.g. "0378-5955" or "03785955").
     */
    public static function isValidFormat(string $issn): bool
    {
        return preg_match('/^\d{4}-?\d{3}[\dXx]$/', trim($issn)) === 1;
    }

    /**
     * ISO 3297 mod-11 checksum: the first 7 digits are weighted 8..2,
     * the check digit is (11 - sum % 11) % 11, with 10 rendered as 'X'.
     */
    public static function isValidChecksum(string $issn): bool
    {
        $compact = self::compact($issn);
        if (preg_match('/^\d{7}[\dX]$/', $compact) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += ((int) $compact[$i]) * (8 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;
        $expected = $check === 10 ? 'X' : (string) $check;
        return $compact[7] === $expected;
    }

    /** Canonical form: uppercase with the hyphen ("0378-5955"). */
    public static function normalize(string $issn): string
    {
        $compact = self::compact($issn);
        if (strlen($compact) !== 8) {
            return strtoupper(trim($issn));
        }
        return substr($compact, 0, 4) . '-' . substr($compact, 4);
    }

    /**
     * EAN-13 (GTIN-13) barcode derived from the ISSN, "977" prefix:
     * 977 + the 7 ISSN digits (check digit dropped) + "00" (default
     * price variant) + the computed EAN-13 check digit.
     * Null when the ISSN is not structurally valid or fails its checksum.
     */
    public static function barcodeBase(string $issn): ?string
    {
        if (!self::isValidFormat($issn) || !self::isValidChecksum($issn)) {
            return null;
        }
        $base12 = '977' . substr(self::compact($issn), 0, 7) . '00';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $base12[$i]) * (($i % 2 === 0) ? 1 : 3);
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        return $base12 . $checkDigit;
    }

    /** Digits + final X only, uppercased ("0378-5955" → "03785955"). */
    private static function compact(string $issn): string
    {
        return strtoupper((string) preg_replace('/[^0-9Xx]/', '', trim($issn)));
    }
}
