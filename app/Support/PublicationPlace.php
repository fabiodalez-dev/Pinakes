<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The place of publication as a book source gives it, ready for the
 * "Luogo di pubblicazione" field.
 *
 * A catalogue that does not know the place still fills the subfield: "[S.l.]"
 * (sine loco) under AACR2 and ISBD, "[Place of publication not identified]"
 * under RDA, "[Luogo di pubblicazione non identificato]" in Italian records.
 * Those are statements that the place is unknown, not a place, and must not
 * reach the record, its Chicago or Harvard citation or its RIS file.
 */
final class PublicationPlace
{
    private const UNKNOWN = '/^(s\.?\s*l\.?|sine\s+loco|place\s+of\s+publication\s+not\s+identified|luogo\s+(di\s+pubblicazione\s+)?non\s+identificato|lieu\s+de\s+publication\s+non\s+identifi[ée]|o\.\s*O\.?|ohne\s+Ort)$/iu';

    /**
     * The place without ISBD brackets and trailing punctuation, or '' when the
     * source only says that the place is unknown.
     */
    public static function clean(?string $raw): string
    {
        $place = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));
        $place = trim((string) preg_replace('/[\s:;,\/=]+$/u', '', $place));
        // "London [u.a.]", "Paris [etc.]": the other places are not named
        $place = trim((string) preg_replace('/\s*\[(u\.\s*a\.?|etc\.?|et al\.?|usw\.?|m\.\s*fl\.?|o\.\s*a\.?|ecc\.?|e altri|a\.\s*o\.?)\]$/iu', '', $place));
        // "[Kbh.]", "[London?]": supplied by the cataloguer; brackets only
        // when they enclose the whole place, so "London [u.a." is never made
        if (preg_match('/^\[([^\[\]]*)\]$/u', $place, $m) === 1) {
            $place = trim($m[1]);
        } elseif (substr_count($place, '[') !== substr_count($place, ']')) {
            $place = trim(str_replace(['[', ']'], '', $place));
        }
        $place = trim((string) preg_replace('/[\s:;,?]+$/u', '', $place));
        if ($place === '' || preg_match(self::UNKNOWN, $place) === 1) {
            return '';
        }
        return $place;
    }

    /**
     * Several places, as a source lists them (strings, or objects with a
     * "name"), joined once each: "Roma, Bari".
     *
     * @param array<mixed> $places
     */
    public static function fromList(array $places): string
    {
        $out = [];
        foreach ($places as $p) {
            $place = self::clean(is_array($p) ? (string) ($p['name'] ?? '') : (is_scalar($p) ? (string) $p : ''));
            if ($place !== '' && !in_array($place, $out, true)) {
                $out[] = $place;
            }
        }
        return implode(', ', $out);
    }
}
