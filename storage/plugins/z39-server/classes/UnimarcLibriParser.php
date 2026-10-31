<?php

declare(strict_types=1);

namespace Z39Server;

/**
 * Bidirectional UNIMARC ↔ Pinakes `libri` mapper — issue #133 (REICAT/SBN).
 *
 * Two directions:
 *   • toUnimarcXml(): serialises a libri record to a standalone UNIMARC
 *     document in the MARCXchange container (ISO 25577). Reuses
 *     {@see UNIMARCXMLFormatter} for the bibliographic core, then appends the
 *     REICAT/SBN-specific fields (676 Dewey, 606 Nuovo Soggettario subjects).
 *   • fromUnimarcXml(): parses a UNIMARC/MARCXchange record into the libri
 *     field shape — inherited from the core reader
 *     ({@see \App\Support\Marc\UnimarcLibriParser}), which the MARC bulk
 *     importer shares.
 */
class UnimarcLibriParser extends \App\Support\Marc\UnimarcLibriParser
{
    /**
     * Serialise a libri record to a standalone UNIMARC/MARCXchange document.
     *
     * @param array<string,mixed> $record  Fields expected by UNIMARCXMLFormatter:
     *        id, titolo, sottotitolo, autori ("Name; Name"), edizione, editore,
     *        anno_pubblicazione, numero_pagine, collana, numero_serie,
     *        descrizione, genere, parole_chiave, lingua, isbn13, isbn10.
     * @param list<array{termine:string,bncf_id?:?string,uri?:?string}> $subjects
     *        Nuovo Soggettario subject headings to emit as 606 fields.
     */
    public function toUnimarcXml(array $record, array $subjects = []): string
    {
        require_once __DIR__ . '/RecordFormatter.php';
        require_once __DIR__ . '/UNIMARCXMLFormatter.php';

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $collection = $doc->createElementNS(self::NS_MARCXCHANGE, 'collection');
        $doc->appendChild($collection);

        $formatter = new UNIMARCXMLFormatter($doc);
        $recordEl = $formatter->format($record);

        // 676 — Dewey Decimal Classification (not emitted by the base formatter).
        $dewey = trim((string) ($record['classificazione_dewey'] ?? ''));
        if ($dewey !== '') {
            $recordEl->appendChild($this->datafield($doc, '676', ' ', ' ', [['a', $dewey]]));
        }

        // 606 — Topical name used as subject (Nuovo Soggettario BNCF).
        // $2 carries the thesaurus code, $3 the BNCF authority identifier.
        foreach ($subjects as $subject) {
            $termine = trim($subject['termine']);
            if ($termine === '') {
                continue;
            }
            $subs = [['a', $termine], ['2', 'BNCF']];
            $bncfId = trim((string) ($subject['bncf_id'] ?? ''));
            if ($bncfId !== '') {
                $subs[] = ['3', $bncfId];
            }
            $recordEl->appendChild($this->datafield($doc, '606', ' ', ' ', $subs));
        }

        $collection->appendChild($recordEl);

        $xml = $doc->saveXML();
        return $xml !== false ? $xml : '';
    }
}
