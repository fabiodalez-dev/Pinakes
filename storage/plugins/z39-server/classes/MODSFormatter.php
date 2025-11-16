<?php
/**
 * MODS Formatter
 *
 * Formats bibliographic records in MODS (Metadata Object Description Schema) format.
 * MODS is a rich bibliographic format developed by the Library of Congress.
 *
 * @see https://www.loc.gov/standards/mods/
 */

declare(strict_types=1);

namespace Z39Server;

class MODSFormatter extends RecordFormatter
{
    private const NS_MODS = 'http://www.loc.gov/mods/v3';

    /**
     * Format record as MODS
     *
     * @param array $record Record data
     * @return \DOMElement MODS record element
     */
    public function format(array $record): \DOMElement
    {
        // Create mods root element
        $mods = $this->doc->createElementNS(self::NS_MODS, 'mods');
        $mods->setAttribute('version', '3.6');

        // Title Info
        if (!empty($record['titolo'])) {
            $titleInfo = $this->doc->createElement('titleInfo');
            $mods->appendChild($titleInfo);

            $title = $this->doc->createElement('title', $this->escapeXml($record['titolo']));
            $titleInfo->appendChild($title);

            if (!empty($record['sottotitolo'])) {
                $subTitle = $this->doc->createElement('subTitle', $this->escapeXml($record['sottotitolo']));
                $titleInfo->appendChild($subTitle);
            }
        }

        // Name (Authors)
        if (!empty($record['autori'])) {
            $authors = explode('; ', $record['autori']);
            foreach ($authors as $author) {
                if (!empty($author)) {
                    $name = $this->doc->createElement('name');
                    $name->setAttribute('type', 'personal');
                    $mods->appendChild($name);

                    $namePart = $this->doc->createElement('namePart', $this->escapeXml($author));
                    $name->appendChild($namePart);

                    $role = $this->doc->createElement('role');
                    $name->appendChild($role);

                    $roleTerm = $this->doc->createElement('roleTerm', 'author');
                    $roleTerm->setAttribute('type', 'text');
                    $role->appendChild($roleTerm);
                }
            }
        }

        // Type of Resource
        $typeOfResource = $this->doc->createElement('typeOfResource', 'text');
        $mods->appendChild($typeOfResource);

        // Genre
        if (!empty($record['genere'])) {
            $genre = $this->doc->createElement('genre', $this->escapeXml($record['genere']));
            $mods->appendChild($genre);
        }

        // Origin Info
        $originInfo = $this->doc->createElement('originInfo');
        $mods->appendChild($originInfo);

        if (!empty($record['editore'])) {
            $publisher = $this->doc->createElement('publisher', $this->escapeXml($record['editore']));
            $originInfo->appendChild($publisher);
        }

        if (!empty($record['anno_pubblicazione'])) {
            $dateIssued = $this->doc->createElement('dateIssued', $this->escapeXml((string)$record['anno_pubblicazione']));
            $originInfo->appendChild($dateIssued);
        }

        if (!empty($record['edizione'])) {
            $edition = $this->doc->createElement('edition', $this->escapeXml($record['edizione']));
            $originInfo->appendChild($edition);
        }

        // Language
        if (!empty($record['lingua'])) {
            $language = $this->doc->createElement('language');
            $mods->appendChild($language);

            $languageTerm = $this->doc->createElement('languageTerm', $this->escapeXml($record['lingua']));
            $languageTerm->setAttribute('type', 'text');
            $language->appendChild($languageTerm);
        }

        // Physical Description
        $physicalDescription = $this->doc->createElement('physicalDescription');
        $mods->appendChild($physicalDescription);

        if (!empty($record['formato'])) {
            $form = $this->doc->createElement('form', $this->escapeXml($record['formato']));
            $physicalDescription->appendChild($form);
        }

        if (!empty($record['numero_pagine'])) {
            $extent = $this->doc->createElement('extent', $this->escapeXml($record['numero_pagine'] . ' pages'));
            $physicalDescription->appendChild($extent);
        }

        // Abstract
        if (!empty($record['descrizione'])) {
            $abstract = $this->doc->createElement('abstract', $this->escapeXml($record['descrizione']));
            $mods->appendChild($abstract);
        }

        // Subject
        if (!empty($record['parole_chiave'])) {
            $keywords = explode(',', $record['parole_chiave']);
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword)) {
                    $subject = $this->doc->createElement('subject');
                    $mods->appendChild($subject);

                    $topic = $this->doc->createElement('topic', $this->escapeXml($keyword));
                    $subject->appendChild($topic);
                }
            }
        }

        // Classification (Dewey)
        if (!empty($record['classificazione_dowey'])) {
            $classification = $this->doc->createElement('classification', $this->escapeXml($record['classificazione_dowey']));
            $classification->setAttribute('authority', 'ddc');
            $mods->appendChild($classification);
        }

        // Identifier (ISBN)
        if (!empty($record['isbn13'])) {
            $identifier = $this->doc->createElement('identifier', $this->escapeXml($record['isbn13']));
            $identifier->setAttribute('type', 'isbn');
            $mods->appendChild($identifier);
        } elseif (!empty($record['isbn10'])) {
            $identifier = $this->doc->createElement('identifier', $this->escapeXml($record['isbn10']));
            $identifier->setAttribute('type', 'isbn');
            $mods->appendChild($identifier);
        }

        // EAN
        if (!empty($record['ean'])) {
            $identifier = $this->doc->createElement('identifier', $this->escapeXml($record['ean']));
            $identifier->setAttribute('type', 'ean');
            $mods->appendChild($identifier);
        }

        // Related Item (Series)
        if (!empty($record['collana'])) {
            $relatedItem = $this->doc->createElement('relatedItem');
            $relatedItem->setAttribute('type', 'series');
            $mods->appendChild($relatedItem);

            $seriesTitleInfo = $this->doc->createElement('titleInfo');
            $relatedItem->appendChild($seriesTitleInfo);

            $seriesTitle = $this->doc->createElement('title', $this->escapeXml($record['collana']));
            $seriesTitleInfo->appendChild($seriesTitle);

            if (!empty($record['numero_serie'])) {
                $part = $this->doc->createElement('part');
                $relatedItem->appendChild($part);

                $detail = $this->doc->createElement('detail');
                $detail->setAttribute('type', 'volume');
                $part->appendChild($detail);

                $number = $this->doc->createElement('number', $this->escapeXml($record['numero_serie']));
                $detail->appendChild($number);
            }
        }

        // Location (URL for cover image)
        if (!empty($record['copertina_url'])) {
            $location = $this->doc->createElement('location');
            $mods->appendChild($location);

            $url = $this->doc->createElement('url', $this->escapeXml($record['copertina_url']));
            $url->setAttribute('displayLabel', 'Cover image');
            $url->setAttribute('access', 'preview');
            $location->appendChild($url);
        }

        // Record Info
        $recordInfo = $this->doc->createElement('recordInfo');
        $mods->appendChild($recordInfo);

        $recordIdentifier = $this->doc->createElement('recordIdentifier', $this->escapeXml((string)$record['id']));
        $recordInfo->appendChild($recordIdentifier);

        if (!empty($record['created_at'])) {
            $recordCreationDate = $this->doc->createElement('recordCreationDate', $this->escapeXml($record['created_at']));
            $recordInfo->appendChild($recordCreationDate);
        }

        return $mods;
    }
}
