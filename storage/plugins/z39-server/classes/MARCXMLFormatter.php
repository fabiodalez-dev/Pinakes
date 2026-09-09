<?php
/**
 * MARCXML Formatter
 *
 * Formats bibliographic records in MARC 21 XML format.
 * MARC (MAchine-Readable Cataloging) is the standard for library catalog records.
 *
 * @see https://www.loc.gov/standards/marcxml/
 */

declare(strict_types=1);

namespace Z39Server;

class MARCXMLFormatter extends RecordFormatter
{
    private const NS_MARC = 'http://www.loc.gov/MARC21/slim';

    /**
     * Format record as MARCXML
     *
     * @param array $record Record data
     * @return \DOMElement MARCXML record element
     */
    public function format(array $record): \DOMElement
    {
        // Create record element
        $recordEl = $this->doc->createElementNS(self::NS_MARC, 'record');

        // Issue #140: periodical mastheads (Emeroteca) travel through the same
        // pipeline as books, flagged by _record_type. Serial-only fields
        // (022/310/362) and the serial bibliographic level are driven by it.
        $isSerial = ($record['_record_type'] ?? '') === 'periodical';

        // Leader (required in MARC) — exactly 24 characters: '00000nam a2200000 a 4500'
        // Positions: 0-4 logical record length, 5 status, 6 type, 7 bibl.level,
        // 8 ctrl type, 9 char encoding, 10-16 data/base offsets, 17 encoding level,
        // 18 desc.cataloging form, 19 multipart, 20-23 entry map.
        // Position 7 (bibliographic level): 'm' monograph, 's' serial.
        $leaderStr = $isSerial
            ? '00000nas a2200000 a 4500'
            : '00000nam a2200000 a 4500'; // strlen === 24
        $leader = $this->doc->createElement('leader', $leaderStr);
        $recordEl->appendChild($leader);

        // Control fields
        // 001 - Control Number
        if (!empty($record['id'])) {
            $recordEl->appendChild($this->createControlField('001', (string) $record['id']));
        }

        // 008 - Fixed-Length Data Elements
        $field008 = $this->generateField008($record);
        $recordEl->appendChild($this->createControlField('008', $field008));

        // ISBN - 020 — one field per available ISBN
        foreach (['isbn13', 'isbn10'] as $isbnField) {
            if (!empty($record[$isbnField])) {
                $recordEl->appendChild($this->createDataField('020', ' ', ' ', [
                    ['a', (string) $record[$isbnField]]
                ]));
            }
        }

        // ISSN - 022 (serials). $a = ISSN of the print manifestation, a second
        // 022 carries the electronic ISSN, $l = linking ISSN (ISSN-L).
        //
        // FIX (issue #140 review): gated on $isSerial. `libri.issn` is a real
        // column (LibraryThing import, migrate_0.4.7) selected by the SRU book
        // query via `SELECT l.*`, so without this guard every monograph that
        // happens to carry an ISSN started shipping an 022 on a record whose
        // leader/07 says 'm'. That is both wrong MARC and a silent behaviour
        // change for existing SRU consumers, which never saw an 022 here
        // before the serials work. An ISSN on a monograph is a series link,
        // not the record's own identifier: it does not belong in 022.
        if ($isSerial && !empty($record['issn'])) {
            $issnSubfields = [['a', (string) $record['issn']]];
            if (!empty($record['issn_l'])) {
                $issnSubfields[] = ['l', (string) $record['issn_l']];
            }
            $recordEl->appendChild($this->createDataField('022', ' ', ' ', $issnSubfields));
        }
        if ($isSerial && !empty($record['e_issn'])) {
            $recordEl->appendChild($this->createDataField('022', ' ', ' ', [
                ['a', (string) $record['e_issn']]
            ]));
        }

        // EAN - 024
        if (!empty($record['ean'])) {
            $recordEl->appendChild($this->createDataField('024', '3', ' ', [
                ['a', $record['ean']]
            ]));
        }

        // Language - 041
        if (!empty($record['lingua'])) {
            $recordEl->appendChild($this->createDataField('041', '0', ' ', [
                ['a', $this->getLanguageCode($record['lingua'])]
            ]));
        }

        // Dewey Classification - 082
        if (!empty($record['classificazione_dewey'])) {
            $recordEl->appendChild($this->createDataField('082', '0', '4', [
                ['a', $record['classificazione_dewey']]
            ]));
        }

        // 100/700 — creators and role-aware contributors.
        $contributors = $this->contributorRows($record);
        $primaryCreatorIndex = null;
        foreach ($contributors as $index => $contributor) {
            if ($contributor['ruolo'] === 'principale') {
                $primaryCreatorIndex = $index;
                break;
            }
            if ($primaryCreatorIndex === null && $contributor['ruolo'] === 'co-autore') {
                $primaryCreatorIndex = $index;
            }
        }
        foreach ($contributors as $index => $contributor) {
            $tag = $index === $primaryCreatorIndex ? '100' : '700';
            $recordEl->appendChild($this->createDataField($tag, '1', ' ', [
                ['a', $contributor['nome']],
                ['e', $this->roleTerm($contributor['ruolo'])],
            ]));
        }

        // Title Statement - 245
        // Indicator 1: '1' when a 1XX field is present (added entry required), '0' otherwise
        $ind1_245 = $primaryCreatorIndex === null ? '0' : '1';
        $titleSubfields = [['a', $record['titolo'] ?? 'Untitled']];
        if (!empty($record['sottotitolo'])) {
            $titleSubfields[] = ['b', $record['sottotitolo']];
        }
        $recordEl->appendChild($this->createDataField('245', $ind1_245, '0', $titleSubfields));

        // Edition - 250
        if (!empty($record['edizione'])) {
            $recordEl->appendChild($this->createDataField('250', ' ', ' ', [
                ['a', $record['edizione']]
            ]));
        }

        // Publication, Distribution, Manufacture, and Copyright Notice - 264
        // FIX 6: field 260 is obsolete; 264 ind2='1' = production/publication
        $pubSubfields = [];
        // $a — place of publication (mastheads carry luogo_pubblicazione).
        if (!empty($record['luogo_pubblicazione'])) {
            $pubSubfields[] = ['a', (string) $record['luogo_pubblicazione']];
        }
        // Repeatable $b — primary publisher plus co-publishers (#143)
        foreach ($this->publisherNames($record) as $publisherName) {
            $pubSubfields[] = ['b', $publisherName];
        }
        if (!empty($record['anno_pubblicazione'])) {
            // Serials state the run, not a single publication year.
            $pubDate = (string) $record['anno_pubblicazione'];
            if ($isSerial) {
                $pubDate = !empty($record['anno_fine'])
                    ? $pubDate . '-' . (string) $record['anno_fine']
                    : $pubDate . '-';
            }
            $pubSubfields[] = ['c', $pubDate];
        }
        if (!empty($pubSubfields)) {
            $recordEl->appendChild($this->createDataField('264', ' ', '1', $pubSubfields));
        }

        // Physical Description - 300
        $physSubfields = [];
        if (!empty($record['numero_pagine'])) {
            $physSubfields[] = ['a', $record['numero_pagine'] . ' p.'];
        }
        if (!empty($record['dimensioni'])) {
            $physSubfields[] = ['c', $record['dimensioni']];
        }
        if (!empty($physSubfields)) {
            $recordEl->appendChild($this->createDataField('300', ' ', ' ', $physSubfields));
        }

        // Current Publication Frequency - 310 (serials only)
        if ($isSerial && !empty($record['periodicita'])) {
            $recordEl->appendChild($this->createDataField('310', ' ', ' ', [
                ['a', (string) $record['periodicita']]
            ]));
        }

        // Numbering Peculiarities / holdings statement - 362 ind1='1'
        // (unformatted note: the holdings string is human-readable, not an
        // ISBD-formatted designation).
        if ($isSerial && !empty($record['numerazione'])) {
            $recordEl->appendChild($this->createDataField('362', '1', ' ', [
                ['a', (string) $record['numerazione']]
            ]));
        }

        // Series - 490
        if (!empty($record['collana'])) {
            $seriesSubfields = [['a', $record['collana']]];
            if (!empty($record['numero_serie'])) {
                $seriesSubfields[] = ['v', $record['numero_serie']];
            }
            $recordEl->appendChild($this->createDataField('490', '0', ' ', $seriesSubfields));
        }

        // Summary - 520
        if (!empty($record['descrizione'])) {
            $recordEl->appendChild($this->createDataField('520', ' ', ' ', [
                ['a', $record['descrizione']]
            ]));
        }

        // Subject - 650
        if (!empty($record['genere'])) {
            $recordEl->appendChild($this->createDataField('650', ' ', '4', [
                ['a', $record['genere']]
            ]));
        }

        // Keywords - 653
        if (!empty($record['parole_chiave'])) {
            $keywords = explode(',', $record['parole_chiave']);
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword)) {
                    $recordEl->appendChild($this->createDataField('653', ' ', ' ', [
                        ['a', $keyword]
                    ]));
                }
            }
        }

        // Electronic Location - 856 ind1='4' (HTTP) ind2='0' (resource itself)
        if (!empty($record['public_url'])) {
            $recordEl->appendChild($this->createDataField('856', '4', '0', [
                ['u', (string) $record['public_url']],
                ['y', 'Catalogue record']
            ]));
        }

        // Electronic Location - 856
        if (!empty($record['copertina_url'])) {
            $recordEl->appendChild($this->createDataField('856', '4', '2', [
                ['u', $this->absoluteCoverUrl((string) $record['copertina_url'])],
                ['y', 'Cover image']
            ]));
        }

        // Holdings Information - 852 (for each copy)
        if (!empty($record['copies']) && is_array($record['copies'])) {
            foreach ($record['copies'] as $copy) {
                $holdingsSubfields = [];

                // Location — resolved from the CURRENT libri.scaffale_id /
                // libri.mensola_id columns (SRU joins scaffali/mensole on those,
                // not the abandoned posizione_id chain). Fall back to the
                // human-readable libri.collocazione when no shelf is assigned.
                if (!empty($record['scaffale'])) {
                    $holdingsSubfields[] = ['b', $record['scaffale']];
                }
                if (!empty($record['mensola'])) {
                    $holdingsSubfields[] = ['c', 'Shelf ' . $record['mensola']];
                }
                if (empty($record['scaffale']) && empty($record['mensola'])
                    && !empty($record['collocazione'])) {
                    $holdingsSubfields[] = ['c', (string) $record['collocazione']];
                }

                // Call number / Inventory number
                if (!empty($copy['numero_inventario'])) {
                    $holdingsSubfields[] = ['j', $copy['numero_inventario']];
                }

                // Copy status
                if (!empty($copy['stato'])) {
                    $statusText = $this->formatCopyStatus($copy['stato']);
                    $holdingsSubfields[] = ['z', 'Status: ' . $statusText];
                }

                // Notes
                if (!empty($copy['note'])) {
                    $holdingsSubfields[] = ['z', 'Note: ' . $copy['note']];
                }

                if (!empty($holdingsSubfields)) {
                    $recordEl->appendChild($this->createDataField('852', ' ', ' ', $holdingsSubfields));
                }
            }
        }

        // Add summary holdings note if copies exist. Total/Available come from
        // the canonical counters libri.copie_totali / libri.copie_disponibili
        // (maintained by App\Support\DataIntegrity, matching web + Mobile API +
        // NCIP): copie_disponibili subtracts active reservations and pending
        // loans, and copie_totali excludes perso/danneggiato/manutenzione/
        // in_restauro/in_trasferimento copies. Counting raw copie rows here
        // over-reports both totals. Fall back to row counts only when the
        // counters are absent from the record.
        if (!empty($record['copies'])) {
            $totalCopies = isset($record['copie_totali'])
                ? (int) $record['copie_totali']
                : count($record['copies']);
            $availableCopies = isset($record['copie_disponibili'])
                ? (int) $record['copie_disponibili']
                : count(array_filter(
                    $record['copies'],
                    static fn (array $copy): bool => ($copy['stato'] ?? '') === 'disponibile'
                ));

            $recordEl->appendChild($this->createDataField('866', ' ', ' ', [
                ['a', "Total copies: $totalCopies, Available: $availableCopies"]
            ]));
        }

        return $recordEl;
    }

    /**
     * Create control field
     *
     * @param string $tag Field tag
     * @param string $value Field value
     * @return \DOMElement Control field element
     */
    private function createControlField(string $tag, string $value): \DOMElement
    {
        $field = $this->doc->createElement('controlfield', $this->escapeXml($value));
        $field->setAttribute('tag', $tag);
        return $field;
    }

    /**
     * Create data field
     *
     * @param string $tag Field tag
     * @param string $ind1 First indicator
     * @param string $ind2 Second indicator
     * @param array $subfields Array of subfields [code, value]
     * @return \DOMElement Data field element
     */
    private function createDataField(string $tag, string $ind1, string $ind2, array $subfields): \DOMElement
    {
        $field = $this->doc->createElement('datafield');
        $field->setAttribute('tag', $tag);
        $field->setAttribute('ind1', $ind1);
        $field->setAttribute('ind2', $ind2);

        foreach ($subfields as $subfield) {
            if (count($subfield) >= 2) {
                $subfieldEl = $this->doc->createElement('subfield', $this->escapeXml($subfield[1]));
                $subfieldEl->setAttribute('code', $subfield[0]);
                $field->appendChild($subfieldEl);
            }
        }

        return $field;
    }

    /**
     * Generate MARC 008 field
     *
     * @param array $record Record data
     * @return string 008 field value
     */
    private function generateField008(array $record): string
    {
        // 008 field is 40 characters
        $field = str_repeat(' ', 40);
        $isSerial = ($record['_record_type'] ?? '') === 'periodical';

        // Date entered (positions 0-5): current date YYMMDD
        $dateEntered = date('ymd');
        $field = substr_replace($field, $dateEntered, 0, 6);

        // Date type (position 6): s = single date. Serials use 'c' (continuing,
        // still published) or 'd' (dead, ceased publication).
        $dateType = 's';
        if ($isSerial) {
            $dateType = !empty($record['anno_fine']) ? 'd' : 'c';
        }
        $field = substr_replace($field, $dateType, 6, 1);

        // Date 1 (positions 7-10): publication year (zero-padded)
        if (!empty($record['anno_pubblicazione'])) {
            $year = str_pad((string) $record['anno_pubblicazione'], 4, '0', STR_PAD_LEFT);
            $field = substr_replace($field, $year, 7, 4);
        }

        // Date 2 (positions 11-14): serials only — closing year, or 9999 while
        // the title is still running.
        if ($isSerial) {
            $date2 = !empty($record['anno_fine'])
                ? str_pad((string) $record['anno_fine'], 4, '0', STR_PAD_LEFT)
                : '9999';
            $field = substr_replace($field, $date2, 11, 4);

            // Continuing resources 008/18 frequency, /19 regularity, /21 type.
            $field = substr_replace($field, $this->frequencyCode((string) ($record['periodicita'] ?? '')), 18, 1);
            $field = substr_replace($field, ($record['periodicita'] ?? '') === 'irregolare' ? 'x' : 'r', 19, 1);
            $field = substr_replace($field, 'p', 21, 1); // p = periodical
        }

        // Place of publication (positions 15-17)
        $field = substr_replace($field, 'xx ', 15, 3);

        // Language (positions 35-37)
        if (!empty($record['lingua'])) {
            $langCode = $this->getLanguageCode($record['lingua']);
            $field = substr_replace($field, $langCode, 35, 3);
        }

        return $field;
    }

    /**
     * MARC 008/18 frequency code for a continuing resource, mapped from the
     * emeroteca_testate.periodicita ENUM. Unknown/empty → '|' (no attempt to
     * code), which is the MARC-sanctioned fill value.
     */
    private function frequencyCode(string $frequency): string
    {
        return match (strtolower(trim($frequency))) {
            'quotidiano'   => 'd',
            'settimanale'  => 'w',
            'quindicinale' => 'e',
            'mensile'      => 'm',
            'bimestrale'   => 'b',
            'trimestrale'  => 'q',
            'semestrale'   => 'f',
            'annuale'      => 'a',
            'irregolare'   => '|',
            default        => '|',
        };
    }

    /**
     * Get MARC language code
     *
     * @param string $language Language name
     * @return string Three-letter language code
     */
    private function getLanguageCode(string $language): string
    {
        $codes = [
            'italiano' => 'ita',
            'italian' => 'ita',
            'inglese' => 'eng',
            'english' => 'eng',
            'francese' => 'fre',
            'french' => 'fre',
            'tedesco' => 'ger',
            'german' => 'ger',
            'spagnolo' => 'spa',
            'spanish' => 'spa',
        ];

        $language = strtolower($language);
        return $codes[$language] ?? 'und'; // und = undetermined
    }
}
