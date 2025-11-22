<?php
/**
 * Record Formatter Factory
 *
 * Creates appropriate formatter for requested record format.
 * Supports: MARCXML, Dublin Core, MODS, OAI Dublin Core
 */

declare(strict_types=1);

namespace Z39Server;

abstract class RecordFormatter
{
    protected \DOMDocument $doc;

    public function __construct(\DOMDocument $doc)
    {
        $this->doc = $doc;
    }

    /**
     * Create formatter for specified format
     *
     * @param string $format Format name (marcxml, dc, mods, oai_dc)
     * @param \DOMDocument $doc DOM document
     * @return RecordFormatter Formatter instance
     * @throws \Exception If format is not supported
     */
    public static function create(string $format, \DOMDocument $doc): RecordFormatter
    {
        switch (strtolower($format)) {
            case 'marcxml':
                return new MARCXMLFormatter($doc);

            case 'dc':
            case 'oai_dc':
                return new DublinCoreFormatter($doc);

            case 'mods':
                return new MODSFormatter($doc);

            default:
                throw new \Exception("Unsupported record format: {$format}");
        }
    }

    /**
     * Format record data as XML element
     *
     * @param array $record Record data from database
     * @return \DOMElement Formatted record element
     */
    abstract public function format(array $record): \DOMElement;

    /**
     * Escape XML text
     *
     * @param string $text Text to escape
     * @return string Escaped text
     */
    protected function escapeXml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
