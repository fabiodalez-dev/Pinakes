<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Filter applied to admin-supplied translation values (uploaded locale files
 * and the locale/overrides/ files written from them).
 *
 * Views place __() output in double-quoted HTML attributes, in JS strings and
 * in JS template literals, often through a json_encode() that guards only the
 * JS layer. A value is safe in all of them when it carries no markup, no ASCII
 * double quote, no backtick and no "${". Every such sink already copes with
 * those characters when the SOURCE string (the key) contains them — otherwise
 * the shipped translation would break the page — so a translation may use one
 * only when its key does. Elsewhere it is replaced with a typographic
 * equivalent, which reads the same and ends nothing.
 */
final class TranslationSanitizer
{
    /**
     * @param array<mixed> $translations
     * @return array<string, string>
     */
    public static function sanitize(array $translations): array
    {
        $sanitized = [];
        foreach ($translations as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $sanitized[$key] = self::sanitizeValue($key, $value);
        }

        return $sanitized;
    }

    public static function sanitizeValue(string $key, mixed $value): string
    {
        $scalarValue = is_scalar($value) ? (string) $value : '';
        $scalarValue = strip_tags(trim($scalarValue));

        return self::neutralizeContextBreakers($key, $scalarValue);
    }

    public static function neutralizeContextBreakers(string $key, string $value): string
    {
        if (!str_contains($key, '"') && str_contains($value, '"')) {
            $open = true;
            $value = (string) preg_replace_callback('/"/', static function () use (&$open): string {
                $quote = $open ? "\u{201C}" : "\u{201D}";
                $open = !$open;
                return $quote;
            }, $value);
        }
        if (!str_contains($key, '`')) {
            $value = str_replace('`', "\u{2019}", $value);
        }
        if (!str_contains($key, '${')) {
            $value = str_replace('${', '$ {', $value);
        }

        return $value;
    }
}
