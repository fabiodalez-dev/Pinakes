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

        // Issue #140: Emeroteca mastheads reuse this formatter, flagged by
        // _record_type; serial-only elements below key off it.
        $isSerial = ($record['_record_type'] ?? '') === 'periodical';

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

        // Names retain their core contributor role.
        foreach ($this->contributorRows($record) as $contributor) {
            $name = $this->doc->createElement('name');
            $name->setAttribute('type', 'personal');
            $mods->appendChild($name);

            $namePart = $this->doc->createElement('namePart', $this->escapeXml($contributor['nome']));
            $name->appendChild($namePart);

            $role = $this->doc->createElement('role');
            $name->appendChild($role);

            $roleTerm = $this->doc->createElement('roleTerm', $this->roleTerm($contributor['ruolo']));
            $roleTerm->setAttribute('type', 'text');
            $roleTerm->setAttribute('authority', 'marcrelator');
            $role->appendChild($roleTerm);
        }

        // Type of Resource (derived from tipo_media, mirroring MediaLabels).
        // A masthead is always textual continuing material.
        $typeOfResource = $this->doc->createElement(
            'typeOfResource',
            $isSerial ? 'text' : $this->typeOfResource($record['tipo_media'] ?? null)
        );
        $mods->appendChild($typeOfResource);

        // Genre — marcgt 'periodical' for serials, on top of the local genre.
        if ($isSerial) {
            $serialGenre = $this->doc->createElement('genre', 'periodical');
            $serialGenre->setAttribute('authority', 'marcgt');
            $mods->appendChild($serialGenre);
        }

        // Genre
        if (!empty($record['genere'])) {
            $genre = $this->doc->createElement('genre', $this->escapeXml($record['genere']));
            $genre->setAttribute('authority', 'local');
            $mods->appendChild($genre);
        }

        // Origin Info
        $originInfo = $this->doc->createElement('originInfo');
        $mods->appendChild($originInfo);

        // Place of publication (mastheads carry luogo_pubblicazione)
        if (!empty($record['luogo_pubblicazione'])) {
            $place = $this->doc->createElement('place');
            $originInfo->appendChild($place);
            $placeTerm = $this->doc->createElement('placeTerm', $this->escapeXml((string) $record['luogo_pubblicazione']));
            $placeTerm->setAttribute('type', 'text');
            $place->appendChild($placeTerm);
        }

        // Publisher (repeatable: primary + co-publishers, #143)
        foreach ($this->publisherNames($record) as $publisherName) {
            $publisher = $this->doc->createElement('publisher', $this->escapeXml($publisherName));
            $originInfo->appendChild($publisher);
        }

        if (!empty($record['anno_pubblicazione'])) {
            // A serial's run is expressed as start/end points, not one date.
            if ($isSerial) {
                $start = $this->doc->createElement('dateIssued', $this->escapeXml((string) $record['anno_pubblicazione']));
                $start->setAttribute('point', 'start');
                $start->setAttribute('encoding', 'marc');
                $originInfo->appendChild($start);
                if (!empty($record['anno_fine'])) {
                    $end = $this->doc->createElement('dateIssued', $this->escapeXml((string) $record['anno_fine']));
                    $end->setAttribute('point', 'end');
                    $end->setAttribute('encoding', 'marc');
                    $originInfo->appendChild($end);
                }
            } else {
                $dateIssued = $this->doc->createElement('dateIssued', $this->escapeXml((string) $record['anno_pubblicazione']));
                $originInfo->appendChild($dateIssued);
            }
        }

        // Continuing-resource statements
        if ($isSerial) {
            // A periodical is 'continuing' whether or not the run has closed —
            // the closing year is carried by dateIssued@point="end" above.
            $originInfo->appendChild($this->doc->createElement('issuance', 'continuing'));
            if (!empty($record['periodicita'])) {
                $frequency = $this->doc->createElement('frequency', $this->escapeXml((string) $record['periodicita']));
                $originInfo->appendChild($frequency);
            }
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

            // Machine-readable ISO 639-2b code
            $langCodeMap = [
                'italiano' => 'ita', 'italian' => 'ita',
                'inglese'  => 'eng', 'english' => 'eng',
                'francese' => 'fre', 'french'  => 'fre',
                'tedesco'  => 'ger', 'german'  => 'ger',
                'spagnolo' => 'spa', 'spanish' => 'spa',
                'portoghese' => 'por', 'portuguese' => 'por',
                'russo'    => 'rus', 'russian'  => 'rus',
                'cinese'   => 'chi', 'chinese'  => 'chi',
                'giapponese' => 'jpn', 'japanese' => 'jpn',
                'arabo'    => 'ara', 'arabic'   => 'ara',
            ];
            $isoCode = $langCodeMap[strtolower(trim($record['lingua']))] ?? 'und';
            $languageTermCode = $this->doc->createElement('languageTerm', $isoCode);
            $languageTermCode->setAttribute('type', 'code');
            $languageTermCode->setAttribute('authority', 'iso639-2b');
            $language->appendChild($languageTermCode);
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
        if (!empty($record['classificazione_dewey'])) {
            $classification = $this->doc->createElement('classification', $this->escapeXml($record['classificazione_dewey']));
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

        // ISSN identifiers — print, electronic and linking (ISSN-L).
        if ($isSerial) {
            foreach ([
                'issn'   => 'issn',
                'e_issn' => 'issn-e',
                'issn_l' => 'issn-l',
            ] as $issnKey => $issnType) {
                $issnValue = trim((string) ($record[$issnKey] ?? ''));
                if ($issnValue === '') {
                    continue;
                }
                $identifier = $this->doc->createElement('identifier', $this->escapeXml($issnValue));
                $identifier->setAttribute('type', $issnType);
                $mods->appendChild($identifier);
            }
        }

        // Holdings / numbering statement of a serial.
        if ($isSerial && !empty($record['numerazione'])) {
            $numbering = $this->doc->createElement('note', $this->escapeXml((string) $record['numerazione']));
            $numbering->setAttribute('type', 'numbering');
            $mods->appendChild($numbering);
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

        // Location (public record URL)
        if (!empty($record['public_url'])) {
            $location = $this->doc->createElement('location');
            $mods->appendChild($location);

            $url = $this->doc->createElement('url', $this->escapeXml((string) $record['public_url']));
            $url->setAttribute('displayLabel', 'Catalogue record');
            $url->setAttribute('access', 'object in context');
            $location->appendChild($url);
        }

        // Location (URL for cover image)
        if (!empty($record['copertina_url'])) {
            $location = $this->doc->createElement('location');
            $mods->appendChild($location);

            $url = $this->doc->createElement('url', $this->escapeXml($this->absoluteCoverUrl((string) $record['copertina_url'])));
            $url->setAttribute('displayLabel', 'Cover image');
            $url->setAttribute('access', 'preview');
            $location->appendChild($url);
        }

        // Record Info
        $recordInfo = $this->doc->createElement('recordInfo');
        $mods->appendChild($recordInfo);

        $recordIdentifier = $this->doc->createElement('recordIdentifier', $this->escapeXml((string) $record['id']));
        $recordInfo->appendChild($recordIdentifier);

        if (!empty($record['created_at'])) {
            $recordCreationDate = $this->doc->createElement('recordCreationDate', $this->escapeXml($record['created_at']));
            $recordInfo->appendChild($recordCreationDate);
        }

        // Holdings Information
        if (!empty($record['copies']) && is_array($record['copies'])) {
            foreach ($record['copies'] as $copy) {
                $location = $this->doc->createElement('location');
                $mods->appendChild($location);

                // Physical location
                if (!empty($record['scaffale']) || !empty($record['mensola'])) {
                    $physicalLocation = [];
                    if (!empty($record['scaffale'])) {
                        $physicalLocation[] = 'Shelf: ' . $record['scaffale'];
                    }
                    if (!empty($record['mensola'])) {
                        $physicalLocation[] = 'Level: ' . $record['mensola'];
                    }
                    $physLoc = $this->doc->createElement('physicalLocation', $this->escapeXml(implode(', ', $physicalLocation)));
                    $location->appendChild($physLoc);
                }

                // Shelf locator (inventory number)
                if (!empty($copy['numero_inventario'])) {
                    $shelfLocator = $this->doc->createElement('shelfLocator', $this->escapeXml($copy['numero_inventario']));
                    $location->appendChild($shelfLocator);
                }

                // Holdings note (status and notes)
                $holdingNotes = [];
                if (!empty($copy['stato'])) {
                    $holdingNotes[] = 'Status: ' . $this->formatCopyStatus($copy['stato']);
                }
                if (!empty($copy['note'])) {
                    $holdingNotes[] = 'Note: ' . $copy['note'];
                }
                if (!empty($holdingNotes)) {
                    $holdingNote = $this->doc->createElement('holdingSimple');
                    $location->appendChild($holdingNote);

                    $copyInfo = $this->doc->createElement('copyInformation');
                    $holdingNote->appendChild($copyInfo);

                    foreach ($holdingNotes as $note) {
                        $noteEl = $this->doc->createElement('note', $this->escapeXml($note));
                        $copyInfo->appendChild($noteEl);
                    }
                }
            }

            // Summary note. Total/Available come from the canonical counters
            // libri.copie_totali / libri.copie_disponibili (App\Support\Data
            // Integrity), matching web + Mobile API + NCIP + MARCXML: they
            // subtract active reservations / pending loans and exclude out-of-
            // circulation copies. Fall back to raw row counts only when absent.
            $totalCopies = isset($record['copie_totali'])
                ? (int) $record['copie_totali']
                : count($record['copies']);
            $availableCopies = isset($record['copie_disponibili'])
                ? (int) $record['copie_disponibili']
                : count(array_filter(
                    $record['copies'],
                    static fn (array $copy): bool => ($copy['stato'] ?? '') === 'disponibile'
                ));

            $note = $this->doc->createElement('note', $this->escapeXml("Total copies: $totalCopies, Available: $availableCopies"));
            $note->setAttribute('type', 'holdings');
            $mods->appendChild($note);
        }

        return $mods;
    }

    /**
     * Map tipo_media to a MODS typeOfResource term.
     *
     * @param string|null $tipoMedia Raw tipo_media value
     * @return string MODS typeOfResource term
     */
    private function typeOfResource(?string $tipoMedia): string
    {
        $resolved = \App\Support\MediaLabels::normalizeTipoMedia($tipoMedia) ?? 'libro';
        return match ($resolved) {
            'disco'      => 'sound recording-musical',
            'audiolibro' => 'sound recording-nonmusical',
            'dvd'        => 'moving image',
            'altro'      => 'mixed material',
            default      => 'text',
        };
    }
}
