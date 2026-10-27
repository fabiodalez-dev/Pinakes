<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * MARC records to ISO 2709 (the ".mrc" exchange format): a 24-byte leader, a
 * directory of 12-byte entries (tag, field length, start position) closed by
 * a field terminator, the fields each closed by a field terminator, and a
 * record terminator. The leader's record length (00-04) and base address of
 * data (12-16) are computed; every other leader position is the record's own,
 * so the character-coding scheme (09) is whatever the record says.
 *
 * Writes to an open stream one record at a time (write()), or returns one
 * record as a string (serialize()). Lengths count bytes, not characters.
 */
final class Iso2709Writer
{
    public const FIELD_TERMINATOR = "\x1E";
    public const RECORD_TERMINATOR = "\x1D";
    public const SUBFIELD_DELIMITER = "\x1F";

    /** Longest field the 4-digit directory length can describe. */
    private const MAX_FIELD_LENGTH = 9999;
    /** Longest record the 5-digit leader length can describe. */
    private const MAX_RECORD_LENGTH = 99999;

    /** @var resource|null */
    private $handle;

    /** @param resource|null $handle an open writable stream */
    public function __construct($handle = null)
    {
        $this->handle = $handle;
    }

    public function write(MarcRecord $record): void
    {
        if (!is_resource($this->handle)) {
            throw new \LogicException('Iso2709Writer has no stream to write to');
        }
        fwrite($this->handle, self::serialize($record));
    }

    public static function serialize(MarcRecord $record): string
    {
        $directory = '';
        $fieldData = '';
        $pos = 0;
        foreach ($record->fields() as $field) {
            if ($field->data !== null) {
                $content = $field->data;
            } else {
                $content = $field->ind1 . $field->ind2;
                foreach ($field->subfields as [$code, $value]) {
                    $content .= self::SUBFIELD_DELIMITER . $code . $value;
                }
            }
            $content = self::fitField($content) . self::FIELD_TERMINATOR;
            $length = strlen($content);
            $directory .= str_pad(substr($field->tag, 0, 3), 3, '0', STR_PAD_LEFT)
                . sprintf('%04d', $length)
                . sprintf('%05d', $pos);
            $fieldData .= $content;
            $pos += $length;
        }
        $directory .= self::FIELD_TERMINATOR;

        $baseAddress = 24 + strlen($directory);
        $recordLength = $baseAddress + strlen($fieldData) + 1;
        if ($recordLength > self::MAX_RECORD_LENGTH) {
            throw new \OverflowException(sprintf('ISO 2709 record of %d bytes exceeds the %d-byte limit', $recordLength, self::MAX_RECORD_LENGTH));
        }

        $leader = $record->leader();
        $leader = sprintf('%05d', $recordLength)
            . substr($leader, 5, 7)
            . sprintf('%05d', $baseAddress)
            . substr($leader, 17);

        return $leader . $directory . $fieldData . self::RECORD_TERMINATOR;
    }

    /**
     * A field longer than the directory can describe is cut on a UTF-8
     * character boundary (a long summary is the usual case) rather than
     * producing a record no reader can open.
     */
    private static function fitField(string $content): string
    {
        $limit = self::MAX_FIELD_LENGTH - 1; // the field terminator counts
        if (strlen($content) <= $limit) {
            return $content;
        }
        return mb_strcut($content, 0, $limit, 'UTF-8');
    }
}
