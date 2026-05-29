<?php

declare(strict_types=1);

namespace App\Support;

use League\Csv\Reader;
use League\Csv\Writer;
use League\Csv\EscapeFormula;

/**
 * Centralized CSV/TSV factory built on league/csv.
 *
 * Replaces hand-rolled fgetcsv/fputcsv loops and manual quote/BOM handling
 * scattered across the import/export controllers. All readers/writers are
 * configured for pure RFC 4180 semantics: double-quote enclosure with the
 * non-standard escape character disabled (setEscape('')), which is what
 * Excel/LibreOffice and league's own Reader expect.
 *
 * BOM handling:
 *  - Readers skip an input UTF-8 BOM by default (league default), so callers
 *    no longer need to fread() the first 3 bytes manually.
 *  - Writers do NOT emit a BOM here; callers that need Excel-friendly output
 *    prepend "\xEF\xBB\xBF" to their stream/string as before, keeping the
 *    streaming + gc optimizations untouched.
 *
 * Formula-injection protection is opt-in via $formulaPrefix and is only
 * enabled where the previous code already escaped leading =,+,-,@ characters,
 * to preserve existing output and round-trip behavior elsewhere.
 */
final class Csv
{
    public const DELIMITER_CSV = ';';
    public const DELIMITER_COMMA = ',';
    public const DELIMITER_TSV = "\t";

    /**
     * Build a Reader from a file path.
     *
     * @param string      $path      Path to the CSV/TSV file
     * @param string      $delimiter Field delimiter
     * @param bool        $useHeader When true, the first row becomes the header
     *                               and records are returned keyed by column name
     */
    public static function readerFromPath(string $path, string $delimiter, bool $useHeader = false): Reader
    {
        $reader = Reader::from($path, 'r');

        return self::configureReader($reader, $delimiter, $useHeader);
    }

    /**
     * Build a Reader from an in-memory string.
     *
     * @param string $content   Raw CSV/TSV content
     * @param string $delimiter Field delimiter
     * @param bool   $useHeader When true, the first row becomes the header
     */
    public static function readerFromString(string $content, string $delimiter, bool $useHeader = false): Reader
    {
        $reader = Reader::fromString($content);

        return self::configureReader($reader, $delimiter, $useHeader);
    }

    /**
     * Build a Writer that appends to an already-open stream resource.
     *
     * Lets callers keep their php://temp/maxmemory stream and any per-row
     * garbage-collection loop while delegating field encoding to league/csv.
     *
     * @param resource    $stream        Open, writable stream resource
     * @param string      $delimiter     Field delimiter
     * @param string|null $formulaPrefix When set, leading formula characters
     *                                   (=,+,-,@) are prefixed to neutralize
     *                                   CSV-injection (e.g. "'" )
     */
    public static function writerToStream($stream, string $delimiter, ?string $formulaPrefix = null): Writer
    {
        $writer = Writer::from($stream);

        return self::configureWriter($writer, $delimiter, $formulaPrefix);
    }

    /**
     * Build a Writer backed by an in-memory string buffer.
     *
     * @param string      $delimiter     Field delimiter
     * @param string|null $formulaPrefix See {@see self::writerToStream()}
     */
    public static function writerToString(string $delimiter, ?string $formulaPrefix = null): Writer
    {
        $writer = Writer::fromString('');

        return self::configureWriter($writer, $delimiter, $formulaPrefix);
    }

    private static function configureReader(Reader $reader, string $delimiter, bool $useHeader): Reader
    {
        $reader->setDelimiter($delimiter);
        $reader->setEnclosure('"');
        $reader->setEscape('');

        if ($useHeader) {
            $reader->setHeaderOffset(0);
        }

        return $reader;
    }

    private static function configureWriter(Writer $writer, string $delimiter, ?string $formulaPrefix): Writer
    {
        $writer->setDelimiter($delimiter);
        $writer->setEnclosure('"');
        $writer->setEscape('');

        if ($formulaPrefix !== null) {
            // escapeRecord() is the non-deprecated replacement for the
            // invokable EscapeFormula (deprecated since league/csv 9.11).
            $writer->addFormatter((new EscapeFormula($formulaPrefix))->escapeRecord(...));
        }

        return $writer;
    }
}
