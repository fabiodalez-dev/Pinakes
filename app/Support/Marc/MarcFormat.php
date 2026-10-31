<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * Which MARC a file holds: its container (ISO 2709 or XML), told from its
 * first bytes, and each record's flavour (MARC 21, UNIMARC, danMARC2), told
 * from the record itself.
 */
final class MarcFormat
{
    public const CONTAINER_ISO2709 = 'iso2709';
    public const CONTAINER_XML = 'xml';

    public const MARC21 = 'marc21';
    public const UNIMARC = 'unimarc';
    public const DANMARC2 = 'danmarc2';
    public const AUTO = 'auto';

    /** @var list<string> */
    public const FLAVOURS = [self::MARC21, self::UNIMARC, self::DANMARC2];

    /** The container of a file from its first bytes; null when it is neither. */
    public static function detectContainer(string $head): ?string
    {
        $head = (string) preg_replace('/^\xEF\xBB\xBF/', '', $head);
        $head = ltrim($head, " \t\r\n");
        if ($head === '') {
            return null;
        }
        if ($head[0] === '<') {
            return self::CONTAINER_XML;
        }
        // An ISO 2709 record opens with its 5-digit length and, at 10-11,
        // the indicator and subfield-code counts ("22").
        if (strlen($head) >= 24 && ctype_digit(substr($head, 0, 5)) && str_contains($head, Iso2709Writer::FIELD_TERMINATOR)) {
            return self::CONTAINER_ISO2709;
        }
        return null;
    }

    public static function containerOfFile(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 4096);
        fclose($handle);
        return self::detectContainer($head);
    }

    /**
     * The flavour of one record. A MARCXchange record that says it is
     * danMARC2, or whose 001 is a data field (danMARC2 carries the record
     * number in 001 *a), is danMARC2; a record with a 200 title and no 245
     * is UNIMARC; anything else is MARC 21.
     */
    public static function detectFlavour(MarcRecord $record): string
    {
        $declared = strtolower($record->format());
        if (str_contains($declared, 'danmarc')) {
            return self::DANMARC2;
        }
        if (str_contains($declared, 'unimarc')) {
            return self::UNIMARC;
        }
        $first001 = $record->first('001');
        if ($first001 !== null && !$first001->isControl()) {
            return self::DANMARC2;
        }
        if (!$record->hasField('245') && $record->hasField('200')) {
            return self::UNIMARC;
        }
        return self::MARC21;
    }

    public static function mapperFor(string $flavour): MarcLibriMapper
    {
        return match ($flavour) {
            self::UNIMARC => new UnimarcLibriMapper(),
            self::DANMARC2 => new Danmarc2LibriMapper(),
            default => new Marc21LibriMapper(),
        };
    }
}
