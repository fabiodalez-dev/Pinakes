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
        $dcRecord->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::NS_DC);

        // Title - dc:title
        if (!empty($record['titolo'])) {
            $title = $record['titolo'];
            if (!empty($record['sottotitolo'])) {
                $title .= ' : ' . $record['sottotitolo'];
            }
            $dcRecord->appendChild($this->createElement('title', $title));
        }

        // Keep intellectual creators distinct from role-aware contributors.
        foreach ($this->contributorRows($record) as $contributor) {
            $element = $this->isCreatorRole($contributor['ruolo']) ? 'creator' : 'contributor';
            $dcRecord->appendChild($this->createElement($element, $contributor['nome']));
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

        // Publisher - dc:publisher (repeatable: primary + co-publishers, #143)
        foreach ($this->publisherNames($record) as $publisher) {
            $dcRecord->appendChild($this->createElement('publisher', $publisher));
        }

        // Date - dc:date
        if (!empty($record['anno_pubblicazione'])) {
            $dcRecord->appendChild($this->createElement('date', (string) $record['anno_pubblicazione']));
        }

        // Type - dc:type (DCMI Type derived from tipo_media, mirroring how
        // MediaLabels maps media type for the web/Schema.org)
        $dcRecord->appendChild($this->createElement('type', $this->dcmiType($record['tipo_media'] ?? null)));

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
        if (!empty($record['classificazione_dewey'])) {
            $dcRecord->appendChild($this->createElement('coverage', 'Dewey:' . $record['classificazione_dewey']));
        }

        // Relation - dc:relation (series)
        if (!empty($record['collana'])) {
            $series = $record['collana'];
            if (!empty($record['numero_serie'])) {
                $series .= ' ; ' . $record['numero_serie'];
            }
            $dcRecord->appendChild($this->createElement('relation', $series));
        }

        // Rights - dc:rights (rights/copyright statement only — no availability data)
        if (!empty($record['diritti'])) {
            $dcRecord->appendChild($this->createElement('rights', (string) $record['diritti']));
        }

        // Location information — resolved from the CURRENT libri.scaffale_id /
        // libri.mensola_id columns (SRU joins scaffali/mensole on those, not the
        // abandoned posizione_id chain). Fall back to the human-readable
        // libri.collocazione string when no shelf is assigned.
        if (!empty($record['scaffale']) || !empty($record['mensola'])) {
            $location = [];
            if (!empty($record['scaffale'])) {
                $location[] = 'Shelf: ' . $record['scaffale'];
            }
            if (!empty($record['mensola'])) {
                $location[] = 'Level: ' . $record['mensola'];
            }
            $dcRecord->appendChild($this->createElement('coverage', implode(', ', $location)));
        } elseif (!empty($record['collocazione'])) {
            $dcRecord->appendChild($this->createElement('coverage', (string) $record['collocazione']));
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

    /**
     * Map tipo_media to a DCMI Type Vocabulary term.
     *
     * @param string|null $tipoMedia Raw tipo_media value
     * @return string DCMI Type term
     */
    private function dcmiType(?string $tipoMedia): string
    {
        $resolved = \App\Support\MediaLabels::normalizeTipoMedia($tipoMedia) ?? 'libro';
        return match ($resolved) {
            'disco'      => 'Sound',
            'audiolibro' => 'Sound',
            'dvd'        => 'MovingImage',
            'altro'      => 'Text',
            default      => 'Text',
        };
    }
}
