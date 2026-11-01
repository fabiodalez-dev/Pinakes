<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * danMARC2 → Pinakes book data, through the danMARC2 reader the SRU client
 * already uses for the Danish union catalogue ({@see Danmarc2Parser}).
 */
final class Danmarc2LibriMapper implements MarcLibriMapper
{
    public function map(MarcRecord $record): array
    {
        if (!str_contains(strtolower($record->format()), 'danmarc')) {
            $copy = MarcRecord::fromArray($record->toArray());
            $copy->setFormat('danMARC2');
            $record = $copy;
        }
        $doc = new \DOMDocument();
        if (!$doc->loadXML(MarcXmlWriter::recordToXml($record, MarcXmlWriter::NS_MARCXCHANGE), LIBXML_NONET)
            || !$doc->documentElement instanceof \DOMElement
        ) {
            return MarcBookData::empty();
        }
        $book = Danmarc2Parser::parse(
            new \DOMXPath($doc),
            $doc->documentElement,
            static fn (string $code): string => MarcLanguage::nameFor($code)
        );
        $data = MarcBookData::fromScrape($book);
        $data['copertina_url'] = MarcBookData::coverUrlOf($record);
        return $data;
    }
}
