<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for turning a rich-text (HTML) book description into
 * its plain-text projection stored in `libri.descrizione_plain` and folded into
 * the FULLTEXT search_index.
 *
 * Extracted from BookRepository::toPlainTextDescription so that every writer of
 * `descrizione` — the admin book form (BookRepository) AND the CSV / import
 * paths (CsvImportController) — derives `descrizione_plain` identically. When
 * the two diverged, an import would leave a stale plain-text projection behind
 * and the search index would index text that no longer matched the description.
 */
final class DescriptionText
{
    public static function toPlain(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        $text = preg_replace(
            '/<(?:\/?(?:p|div|li|ul|ol|h[1-6]|blockquote|tr|th|td)\b[^>]*|br\b[^>]*\/?)>/i',
            "\n",
            $html
        );
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', (string) $text);
        $text = str_replace("\r", '', (string) $text);
        $text = preg_replace("/[ \t]+/", ' ', (string) $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
        return trim((string) $text);
    }
}
