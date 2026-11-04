<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * MARC-8 to UTF-8, for the character sets a Western catalogue uses: Basic
 * Latin (ASCII) in G0 and Extended Latin (ANSEL, ANSI/NISO Z39.47) in G1,
 * spacing characters and combining diacritics alike.
 *
 * MARC-8 writes a combining diacritic BEFORE the letter it sits on ("\xE2e"
 * is é), Unicode after it: the marks are held until their base character
 * arrives, then the result is composed to NFC when ext-intl is available.
 *
 * Any other character set (Greek, Cyrillic, Hebrew, Arabic, CJK, selected by
 * an escape sequence) and any byte ANSEL leaves undefined throw a
 * MarcParseException: the record is reported as an error, never imported
 * with mojibake.
 */
final class Marc8Decoder
{
    /** ANSEL spacing characters, G1 (0xA1-0xC8). */
    private const SPACING = [
        0xA1 => 0x0141, 0xA2 => 0x00D8, 0xA3 => 0x0110, 0xA4 => 0x00DE, 0xA5 => 0x00C6,
        0xA6 => 0x0152, 0xA7 => 0x02B9, 0xA8 => 0x00B7, 0xA9 => 0x266D, 0xAA => 0x00AE,
        0xAB => 0x00B1, 0xAC => 0x01A0, 0xAD => 0x01AF, 0xAE => 0x02BC, 0xB0 => 0x02BB,
        0xB1 => 0x0142, 0xB2 => 0x00F8, 0xB3 => 0x0111, 0xB4 => 0x00FE, 0xB5 => 0x00E6,
        0xB6 => 0x0153, 0xB7 => 0x02BA, 0xB8 => 0x0131, 0xB9 => 0x00A3, 0xBA => 0x00F0,
        0xBC => 0x01A1, 0xBD => 0x01B0, 0xC0 => 0x00B0, 0xC1 => 0x2113, 0xC2 => 0x2117,
        0xC3 => 0x00A9, 0xC4 => 0x266F, 0xC5 => 0x00BF, 0xC6 => 0x00A1, 0xC7 => 0x00DF,
        0xC8 => 0x20AC,
    ];

    /** ANSEL combining diacritics, G1 (0xE0-0xFE). */
    private const COMBINING = [
        0xE0 => 0x0309, 0xE1 => 0x0300, 0xE2 => 0x0301, 0xE3 => 0x0302, 0xE4 => 0x0303,
        0xE5 => 0x0304, 0xE6 => 0x0306, 0xE7 => 0x0307, 0xE8 => 0x0308, 0xE9 => 0x030C,
        0xEA => 0x030A, 0xEB => 0xFE20, 0xEC => 0xFE21, 0xED => 0x0315, 0xEE => 0x030B,
        0xEF => 0x0310, 0xF0 => 0x0327, 0xF1 => 0x0328, 0xF2 => 0x0323, 0xF3 => 0x0324,
        0xF4 => 0x0325, 0xF5 => 0x0333, 0xF6 => 0x0332, 0xF7 => 0x0326, 0xF8 => 0x031C,
        0xF9 => 0x032E, 0xFA => 0xFE22, 0xFB => 0xFE23, 0xFE => 0x0313,
    ];

    /**
     * Non-sorting begin/end (0x88/0x89) and the joiners (0x8D/0x8E): markup,
     * not text. Dropped.
     */
    private const DROPPED = [0x88, 0x89, 0x8D, 0x8E];

    public static function decode(string $bytes): string
    {
        $out = '';
        $pending = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            if ($byte === 0x1B) {
                $i = self::escape($bytes, $i);
                continue;
            }
            if ($byte < 0x80) {
                $char = chr($byte);
                $out .= $char . $pending;
                $pending = '';
                continue;
            }
            if (in_array($byte, self::DROPPED, true)) {
                continue;
            }
            if (isset(self::COMBINING[$byte])) {
                $pending .= mb_chr(self::COMBINING[$byte], 'UTF-8');
                continue;
            }
            if (isset(self::SPACING[$byte])) {
                $out .= mb_chr(self::SPACING[$byte], 'UTF-8') . $pending;
                $pending = '';
                continue;
            }
            throw new MarcParseException(sprintf('MARC-8: byte 0x%02X is not a character of Basic or Extended Latin', $byte));
        }
        // A diacritic with nothing after it stays, on its own.
        $out .= $pending;
        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($out, \Normalizer::FORM_C);
            if (is_string($normalised)) {
                return $normalised;
            }
        }
        return $out;
    }

    /**
     * Skip an escape sequence that selects Basic or Extended Latin; any other
     * set cannot be decoded here.
     *
     * @return int index of the last byte of the sequence
     */
    private static function escape(string $bytes, int $i): int
    {
        $next = $bytes[$i + 1] ?? '';
        // ESC s: back to ASCII (after a technique-1 switch)
        if ($next === 's') {
            return $i + 1;
        }
        // ESC ( B / ESC , B: ASCII as G0; ESC ) E / ESC - E: ANSEL as G1;
        // ESC ( ! E: ANSEL designated with an intermediate
        if (in_array($next, ['(', ',', ')', '-', '$'], true)) {
            $final = $bytes[$i + 2] ?? '';
            if ($final === '!') {
                $final = $bytes[$i + 3] ?? '';
                if ($final === 'E') {
                    return $i + 3;
                }
            } elseif ($final === 'B' || $final === 'E') {
                return $i + 2;
            }
            throw new MarcParseException('MARC-8: character set "' . self::printable($final) . '" (Greek, Cyrillic, Hebrew, Arabic or CJK) is not supported');
        }
        throw new MarcParseException('MARC-8: character set "' . self::printable($next) . '" is not supported');
    }

    private static function printable(string $char): string
    {
        return $char !== '' && ctype_print($char) ? $char : sprintf('0x%02X', $char === '' ? 0 : ord($char));
    }
}
