<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * ISO 2709 (".mrc") records, read one at a time from a stream: memory stays
 * flat whatever the size of the file.
 *
 * A record runs to its record terminator (0x1D). Its leader gives the base
 * address of the data; the directory, closed by a field terminator (0x1E),
 * gives each field's tag, length and start. Lengths are trusted only while
 * they stay inside the record: a directory that points outside it is a
 * malformed record, reported as a MarcReadError in its place while the
 * reader moves on to the next record.
 *
 * Character coding (leader/09): 'a' is UTF-8; blank is MARC-8 in MARC 21. A
 * blank position on a record whose bytes are valid UTF-8 (and carry no MARC-8
 * escape sequence) is read as UTF-8,
 * since UNIMARC leaves the position undefined (its 100 $a says the coding)
 * and many exports leave it blank on UTF-8 data. A record decoded from MARC-8
 * comes out with leader/09 'a', which is what its values now are.
 *
 * @implements \IteratorAggregate<int, MarcRecord|MarcReadError>
 */
final class Iso2709Reader implements \IteratorAggregate
{
    public const ENCODING_AUTO = 'auto';
    public const ENCODING_UTF8 = 'utf8';
    public const ENCODING_MARC8 = 'marc8';

    /** Longest record ISO 2709 can describe, plus slack for junk between records. */
    private const MAX_RECORD_BYTES = 200000;

    /** @var resource */
    private $handle;
    private bool $ownsHandle;
    private int $position;
    private int $offset;

    /**
     * @param resource $handle
     * @param int $position 1-based number of the next record, for error reports
     */
    private function __construct($handle, bool $ownsHandle, private string $encoding, int $position, int $offset)
    {
        $this->handle = $handle;
        $this->ownsHandle = $ownsHandle;
        $this->position = $position;
        $this->offset = $offset;
    }

    public function __destruct()
    {
        if ($this->ownsHandle && is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * @param int $offset byte offset to start from (one offset() returned)
     * @param int $position number of records before that offset
     */
    public static function fromPath(string $path, string $encoding = self::ENCODING_AUTO, int $offset = 0, int $position = 0): self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open MARC file');
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        return new self($handle, true, $encoding, $position, $offset);
    }

    public static function fromString(string $data, string $encoding = self::ENCODING_AUTO): self
    {
        $handle = fopen('php://memory', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open memory stream');
        }
        fwrite($handle, $data);
        rewind($handle);
        return new self($handle, true, $encoding, 0, 0);
    }

    /** Byte offset just after the last record read: where a later read can resume. */
    public function offset(): int
    {
        return $this->offset;
    }

    /** @return \Generator<int, MarcRecord|MarcReadError> */
    public function getIterator(): \Generator
    {
        while (!feof($this->handle)) {
            $raw = stream_get_line($this->handle, self::MAX_RECORD_BYTES, Iso2709Writer::RECORD_TERMINATOR);
            if ($raw === false) {
                break;
            }
            $after = ftell($this->handle);
            $this->offset = $after === false ? $this->offset + strlen($raw) + 1 : $after;
            // Line breaks or padding between records are not part of them.
            $raw = ltrim($raw, "\r\n\t \x00\x1A");
            if ($raw === '') {
                continue;
            }
            $this->position++;
            try {
                yield $this->position => self::parse($raw, $this->encoding);
            } catch (MarcParseException $e) {
                yield $this->position => new MarcReadError($this->position, $e->getMessage(), MarcReadError::excerptOf($raw));
            }
        }
    }

    /**
     * One record, without its record terminator.
     *
     * @throws MarcParseException
     */
    public static function parse(string $raw, string $encoding = self::ENCODING_AUTO): MarcRecord
    {
        $length = strlen($raw);
        if ($length < 25) {
            throw new MarcParseException('Record too short to hold a leader and a directory');
        }
        $leader = substr($raw, 0, 24);
        $directoryEnd = strpos($raw, Iso2709Writer::FIELD_TERMINATOR, 24);
        if ($directoryEnd === false) {
            throw new MarcParseException('Record has no directory terminator');
        }
        // The data starts after the directory terminator; a leader base
        // address (12-16) that disagrees is wrong, the terminator is not.
        $base = $directoryEnd + 1;
        $directory = substr($raw, 24, $directoryEnd - 24);
        if ($directory === '' || strlen($directory) % 12 !== 0) {
            throw new MarcParseException('Directory length is not a multiple of 12');
        }

        $utf8 = match ($encoding) {
            self::ENCODING_UTF8 => true,
            self::ENCODING_MARC8 => false,
            // An escape (0x1B) only occurs in MARC-8, where it switches sets.
            default => $leader[9] === 'a' || (!str_contains($raw, "\x1B") && mb_check_encoding($raw, 'UTF-8')),
        };
        if ($encoding === self::ENCODING_UTF8 && !mb_check_encoding($raw, 'UTF-8')) {
            throw new MarcParseException('Record declares UTF-8 but is not valid UTF-8');
        }
        if (!$utf8 && $leader[9] === 'a') {
            throw new MarcParseException('Record declares UTF-8 (leader/09 = a) but is not valid UTF-8');
        }
        $decode = $utf8
            ? static fn (string $s): string => $s
            : static fn (string $s): string => Marc8Decoder::decode($s);
        if (!$utf8) {
            $leader[9] = 'a';
        }

        $record = new MarcRecord($leader);
        $dataLength = $length - $base;
        foreach (str_split($directory, 12) as $entry) {
            $tag = substr($entry, 0, 3);
            $fieldLength = substr($entry, 3, 4);
            $start = substr($entry, 7, 5);
            if (!ctype_digit($fieldLength) || !ctype_digit($start)) {
                throw new MarcParseException("Directory entry for tag {$tag} is not numeric");
            }
            if ((int) $start + (int) $fieldLength > $dataLength) {
                throw new MarcParseException("Field {$tag} runs past the end of the record");
            }
            $data = substr($raw, $base + (int) $start, (int) $fieldLength);
            if (str_ends_with($data, Iso2709Writer::FIELD_TERMINATOR)) {
                $data = substr($data, 0, -1);
            }
            $isControl = ctype_digit($tag) && (int) $tag < 10 && !str_contains($data, Iso2709Writer::SUBFIELD_DELIMITER);
            if ($isControl) {
                $record->addControlField($tag, $decode($data));
                continue;
            }
            if (strlen($data) < 2) {
                throw new MarcParseException("Data field {$tag} has no indicators");
            }
            $ind1 = $data[0];
            $ind2 = $data[1];
            $subfields = [];
            $chunks = explode(Iso2709Writer::SUBFIELD_DELIMITER, substr($data, 2));
            array_shift($chunks); // whatever precedes the first delimiter
            foreach ($chunks as $chunk) {
                if ($chunk === '') {
                    continue;
                }
                $subfields[] = [$chunk[0], $decode(substr($chunk, 1))];
            }
            $record->addDataField($tag, $ind1 === "\x00" ? ' ' : $ind1, $ind2 === "\x00" ? ' ' : $ind2, $subfields);
        }
        return $record;
    }
}
