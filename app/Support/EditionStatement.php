<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The edition statement as a book source gives it, ready for the "Edizione"
 * field: "[New ed.]" and "[Rev. ed.]." become "New ed." and "Rev. ed.".
 *
 * Square brackets in ISBD only say that the cataloguer supplied the words
 * instead of copying them from the book; the reader of a record wants the
 * words. The period of an abbreviation stays: "2. ed.", "13. ed.".
 */
final class EditionStatement
{
    public static function clean(?string $raw): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));
        // ISBD punctuation that introduces the next element: " /", " =", " ;"
        $text = trim((string) preg_replace('/[\s\/:;=,]+$/u', '', $text));
        // "[Rev. ed.]." : the period after the bracket closes the area
        $text = (string) preg_replace('/\]\.$/u', ']', $text);
        if (preg_match('/^\[([^\[\]]*)\]$/u', $text, $m) === 1) {
            $text = trim($m[1]);
        }
        return $text;
    }
}
