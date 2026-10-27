<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * A record a reader could not turn into a MarcRecord. Readers yield it in the
 * record's place and carry on with the next one, so a malformed record never
 * stops the batch it belongs to.
 */
final class MarcReadError
{
    public function __construct(
        /** 1-based position of the record in the file. */
        public readonly int $position,
        public readonly string $message,
        /** Up to 80 printable characters of the record, for the error report. */
        public readonly string $excerpt = ''
    ) {
    }

    public static function excerptOf(string $raw): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) preg_replace('/[\x80-\xFF]/', '?', $text);
        }
        return trim(mb_substr($text, 0, 80));
    }
}
