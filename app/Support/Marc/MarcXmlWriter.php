<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * MARC records as XML through XMLWriter: MARCXML (MARC 21 slim) or
 * MARCXchange (ISO 25577, for UNIMARC). The element writers are shared with
 * the OAI-PMH server, which embeds records inside its own envelope.
 *
 * As a collection writer it streams: each record is flushed to the target
 * stream as soon as it is written, so memory stays flat.
 */
final class MarcXmlWriter
{
    public const NS_MARC21 = 'http://www.loc.gov/MARC21/slim';
    public const NS_MARCXCHANGE = 'info:lc/xmlns/marcxchange-v2';
    public const SCHEMA_MARC21 = 'http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd';
    public const SCHEMA_MARCXCHANGE = 'http://www.loc.gov/standards/iso25577/marcxchange-2-0.xsd';

    private \XMLWriter $xw;

    /** @var resource|null */
    private $handle;

    /** @param resource|null $handle stream the collection is flushed to; null keeps it in memory */
    public function __construct(private string $namespace = self::NS_MARC21, $handle = null)
    {
        $this->handle = $handle;
        $this->xw = new \XMLWriter();
        $this->xw->openMemory();
    }

    public function startCollection(): void
    {
        $this->xw->startDocument('1.0', 'UTF-8');
        $this->xw->startElementNs(null, 'collection', $this->namespace);
        $this->xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $this->xw->writeAttributeNs('xsi', 'schemaLocation', null, $this->namespace . ' '
            . ($this->namespace === self::NS_MARCXCHANGE ? self::SCHEMA_MARCXCHANGE : self::SCHEMA_MARC21));
        $this->flush();
    }

    /** @param string $type MARCXchange record type ("Bibliographic"); ignored for MARCXML */
    public function writeRecord(MarcRecord $record, string $type = 'Bibliographic'): void
    {
        $this->xw->startElement('record');
        if ($this->namespace === self::NS_MARCXCHANGE) {
            $this->xw->writeAttribute('type', $type);
            if ($record->format() !== '') {
                $this->xw->writeAttribute('format', $record->format());
            }
        }
        $this->xw->writeElement('leader', $record->leader());
        self::writeFields($this->xw, $record);
        $this->xw->endElement();
        $this->flush();
    }

    public function endCollection(): void
    {
        $this->xw->endElement();
        $this->xw->endDocument();
        $this->flush();
    }

    /** The XML written so far (memory mode), or '' once flushed to a stream. */
    public function output(): string
    {
        return $this->xw->outputMemory(true);
    }

    private function flush(): void
    {
        if (is_resource($this->handle)) {
            fwrite($this->handle, $this->xw->outputMemory(true));
        }
    }

    /** One standalone record as an XML document (MARCXchange records carry their format). */
    public static function recordToXml(MarcRecord $record, string $namespace = self::NS_MARC21): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs(null, 'record', $namespace);
        if ($namespace === self::NS_MARCXCHANGE) {
            $xw->writeAttribute('type', 'Bibliographic');
            if ($record->format() !== '') {
                $xw->writeAttribute('format', $record->format());
            }
        }
        $xw->writeElement('leader', $record->leader());
        self::writeFields($xw, $record);
        $xw->endElement();
        $xw->endDocument();
        return $xw->outputMemory();
    }

    /** The control and data fields of a record, in order. */
    public static function writeFields(\XMLWriter $xw, MarcRecord $record): void
    {
        foreach ($record->fields() as $field) {
            if ($field->data !== null) {
                self::controlField($xw, $field->tag, $field->data);
            } else {
                self::dataField($xw, $field->tag, $field->ind1, $field->ind2, $field->subfields);
            }
        }
    }

    public static function controlField(\XMLWriter $xw, string $tag, string $text): void
    {
        $xw->startElement('controlfield');
        $xw->writeAttribute('tag', $tag);
        $xw->text($text);
        $xw->endElement();
    }

    /**
     * @param list<array{0: string, 1: string}> $subfields
     */
    public static function dataField(\XMLWriter $xw, string $tag, string $ind1, string $ind2, array $subfields): void
    {
        $xw->startElement('datafield');
        $xw->writeAttribute('tag', $tag);
        $xw->writeAttribute('ind1', $ind1);
        $xw->writeAttribute('ind2', $ind2);
        foreach ($subfields as [$code, $value]) {
            $xw->startElement('subfield');
            $xw->writeAttribute('code', $code);
            $xw->text($value);
            $xw->endElement();
        }
        $xw->endElement();
    }
}
