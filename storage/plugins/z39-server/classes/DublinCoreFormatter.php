<?php
/**
 * Dublin Core Formatter
 *
 * Formats bibliographic records in Dublin Core format.
 * Dublin Core is a simple, widely-used metadata standard.
 *
 * @see https://www.dublincore.org/
 */

declare(strict_types=1);

namespace Z39Server;

class DublinCoreFormatter extends RecordFormatter
{
    private const NS_DC = 'http://purl.org/dc/elements/1.1/';
    private const NS_OAI_DC = 'http://www.openarchives.org/OAI/2.0/oai_dc/';

    /**
     * Format record as Dublin Core
     *
     * @param array $record Record data
     * @return \DOMElement Dublin Core record element
     */
    public function format(array $record): \DOMElement
    {
        // Create dc record element
        $dcRecord = $this->doc->createElementNS(self::NS_OAI_DC, 'oai_dc:dc');
        $dcRecord->setAttribute('xmlns:dc', self::NS_DC);

        // Title - dc:title
        if (!empty($record['titolo'])) {
            $title = $record['titolo'];
            if (!empty($record['sottotitolo'])) {
                $title .= ' : ' . $record['sottotitolo'];
            }
            $dcRecord->appendChild($this->createElement('title', $title));
        }

        // Creator - dc:creator (authors)
        if (!empty($record['autori'])) {
            $authors = explode('; ', $record['autori']);
            foreach ($authors as $author) {
                if (!empty($author)) {
                    $dcRecord->appendChild($this->createElement('creator', $author));
                }
            }
        }

        // Subject - dc:subject
        if (!empty($record['genere'])) {
            $dcRecord->appendChild($this->createElement('subject', $record['genere']));
        }

        // Keywords as subjects
        if (!empty($record['parole_chiave'])) {
            $keywords = explode(',', $record['parole_chiave']);
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword)) {
                    $dcRecord->appendChild($this->createElement('subject', $keyword));
                }
            }
        }

        // Description - dc:description
        if (!empty($record['descrizione'])) {
            $dcRecord->appendChild($this->createElement('description', $record['descrizione']));
        }

        // Publisher - dc:publisher
        if (!empty($record['editore'])) {
            $dcRecord->appendChild($this->createElement('publisher', $record['editore']));
        }

        // Date - dc:date
        if (!empty($record['anno_pubblicazione'])) {
            $dcRecord->appendChild($this->createElement('date', (string) $record['anno_pubblicazione']));
        }

        // Type - dc:type
        $dcRecord->appendChild($this->createElement('type', 'Text'));

        // Format - dc:format
        if (!empty($record['formato'])) {
            $dcRecord->appendChild($this->createElement('format', $record['formato']));
        }

        // Identifier - dc:identifier (ISBN)
        if (!empty($record['isbn13'])) {
            $dcRecord->appendChild($this->createElement('identifier', 'ISBN:' . $record['isbn13']));
        } elseif (!empty($record['isbn10'])) {
            $dcRecord->appendChild($this->createElement('identifier', 'ISBN:' . $record['isbn10']));
        }

        // EAN identifier
        if (!empty($record['ean'])) {
            $dcRecord->appendChild($this->createElement('identifier', 'EAN:' . $record['ean']));
        }

        // Language - dc:language
        if (!empty($record['lingua'])) {
            $dcRecord->appendChild($this->createElement('language', $record['lingua']));
        }

        // Coverage - dc:coverage (Dewey classification)
        if (!empty($record['classificazione_dowey'])) {
            $dcRecord->appendChild($this->createElement('coverage', 'Dewey:' . $record['classificazione_dowey']));
        }

        // Relation - dc:relation (series)
        if (!empty($record['collana'])) {
            $series = $record['collana'];
            if (!empty($record['numero_serie'])) {
                $series .= ' ; ' . $record['numero_serie'];
            }
            $dcRecord->appendChild($this->createElement('relation', $series));
        }

        // Rights - dc:rights (with availability information)
        $rightsText = 'Available for loan';
        if (!empty($record['copies']) && is_array($record['copies'])) {
            $totalCopies = count($record['copies']);
            $availableCopies = 0;
            foreach ($record['copies'] as $copy) {
                if (($copy['stato'] ?? '') === 'disponibile') {
                    $availableCopies++;
                }
            }
            $rightsText .= " ($availableCopies of $totalCopies copies available)";
        }
        $dcRecord->appendChild($this->createElement('rights', $rightsText));

        // Copy inventory numbers as identifiers
        if (!empty($record['copies']) && is_array($record['copies'])) {
            foreach ($record['copies'] as $copy) {
                if (!empty($copy['numero_inventario'])) {
                    $copyInfo = 'Copy:' . $copy['numero_inventario'];
                    if (!empty($copy['stato'])) {
                        $copyInfo .= ' [' . $copy['stato'] . ']';
                    }
                    $dcRecord->appendChild($this->createElement('identifier', $copyInfo));
                }
            }
        }

        // Location information
        if (!empty($record['scaffale']) || !empty($record['mensola'])) {
            $location = [];
            if (!empty($record['scaffale'])) {
                $location[] = 'Shelf: ' . $record['scaffale'];
            }
            if (!empty($record['mensola'])) {
                $location[] = 'Level: ' . $record['mensola'];
            }
            $dcRecord->appendChild($this->createElement('coverage', implode(', ', $location)));
        }

        return $dcRecord;
    }

    /**
     * Create Dublin Core element
     *
     * @param string $name Element name (without dc: prefix)
     * @param string $value Element value
     * @return \DOMElement DC element
     */
    private function createElement(string $name, string $value): \DOMElement
    {
        return $this->doc->createElementNS(self::NS_DC, 'dc:' . $name, $this->escapeXml($value));
    }
}
