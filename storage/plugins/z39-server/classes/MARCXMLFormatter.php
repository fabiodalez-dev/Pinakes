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

        // Leader (required in MARC)
        $leader = $this->doc->createElement('leader', '00000nam a2200000 a 4500');
        $recordEl->appendChild($leader);

        // Control fields
        // 001 - Control Number
        if (!empty($record['id'])) {
            $recordEl->appendChild($this->createControlField('001', (string) $record['id']));
        }

        // 008 - Fixed-Length Data Elements
        $field008 = $this->generateField008($record);
        $recordEl->appendChild($this->createControlField('008', $field008));

        // ISBN - 020
        if (!empty($record['isbn13']) || !empty($record['isbn10'])) {
            $isbn = $record['isbn13'] ?? $record['isbn10'];
            $recordEl->appendChild($this->createDataField('020', ' ', ' ', [
                ['a', $isbn]
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

        // Main Entry - Personal Name - 100
        if (!empty($record['autori'])) {
            $authors = explode('; ', $record['autori']);
            if (!empty($authors[0])) {
                $recordEl->appendChild($this->createDataField('100', '1', ' ', [
                    ['a', $authors[0]]
                ]));
            }

            // Additional authors - 700
            for ($i = 1; $i < count($authors); $i++) {
                if (!empty($authors[$i])) {
                    $recordEl->appendChild($this->createDataField('700', '1', ' ', [
                        ['a', $authors[$i]]
                    ]));
                }
            }
        }

        // Title Statement - 245
        $titleSubfields = [['a', $record['titolo'] ?? 'Untitled']];
        if (!empty($record['sottotitolo'])) {
            $titleSubfields[] = ['b', $record['sottotitolo']];
        }
        $recordEl->appendChild($this->createDataField('245', '1', '0', $titleSubfields));

        // Edition - 250
        if (!empty($record['edizione'])) {
            $recordEl->appendChild($this->createDataField('250', ' ', ' ', [
                ['a', $record['edizione']]
            ]));
        }

        // Publication - 260
        $pubSubfields = [];
        if (!empty($record['editore'])) {
            $pubSubfields[] = ['b', $record['editore']];
        }
        if (!empty($record['anno_pubblicazione'])) {
            $pubSubfields[] = ['c', (string) $record['anno_pubblicazione']];
        }
        if (!empty($pubSubfields)) {
            $recordEl->appendChild($this->createDataField('260', ' ', ' ', $pubSubfields));
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

        // Electronic Location - 856
        if (!empty($record['copertina_url'])) {
            $recordEl->appendChild($this->createDataField('856', '4', '2', [
                ['u', $record['copertina_url']],
                ['y', 'Cover image']
            ]));
        }

        // Holdings Information - 852 (for each copy)
        if (!empty($record['copies']) && is_array($record['copies'])) {
            foreach ($record['copies'] as $copy) {
                $holdingsSubfields = [];

                // Location (scaffale and mensola from record or copy)
                if (!empty($record['scaffale'])) {
                    $holdingsSubfields[] = ['b', $record['scaffale']];
                }
                if (!empty($record['mensola'])) {
                    $holdingsSubfields[] = ['c', 'Shelf ' . $record['mensola']];
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

        // Add summary holdings note if copies exist
        if (!empty($record['copies'])) {
            $totalCopies = count($record['copies']);
            $availableCopies = 0;
            foreach ($record['copies'] as $copy) {
                if (($copy['stato'] ?? '') === 'disponibile') {
                    $availableCopies++;
                }
            }

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

        // Date entered (positions 0-5): current date YYMMDD
        $dateEntered = date('ymd');
        $field = substr_replace($field, $dateEntered, 0, 6);

        // Date type (position 6): s = single date
        $field = substr_replace($field, 's', 6, 1);

        // Date 1 (positions 7-10): publication year
        if (!empty($record['anno_pubblicazione'])) {
            $year = str_pad((string) $record['anno_pubblicazione'], 4, ' ', STR_PAD_LEFT);
            $field = substr_replace($field, $year, 7, 4);
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

    /**
     * Format copy status for human readability
     *
     * @param string $status Copy status code
     * @return string Human-readable status
     */
    private function formatCopyStatus(string $status): string
    {
        $statusMap = [
            'disponibile' => 'Available',
            'prestato' => 'On loan',
            'riservato' => 'Reserved',
            'danneggiato' => 'Damaged',
            'smarrito' => 'Lost',
            'in_riparazione' => 'In repair',
            'non_disponibile' => 'Not available'
        ];

        return $statusMap[$status] ?? ucfirst($status);
    }
}
