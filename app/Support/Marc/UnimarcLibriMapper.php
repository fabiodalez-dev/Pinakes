<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * UNIMARC bibliographic → Pinakes book data, through the UNIMARC reader the
 * SBN/REICAT import already uses ({@see UnimarcLibriParser}), plus the fields
 * that reader leaves out:
 *
 *   011 $a ISSN (else 225 $x)   073 $a EAN   210 / 214 $a place, every $c publisher
 *   215 $d dimensions   330 $a summary   856 $u cover image
 *   label/06 media type, refined by the 181 $c RDA content type
 *
 * The reader's roles (from the IFLA $4 relator codes) sort translators,
 * editors, illustrators and colorists out of the authors.
 */
final class UnimarcLibriMapper implements MarcLibriMapper
{
    private const ROLE_COLUMNS = [
        'traduttore' => 'traduttore',
        'curatore' => 'curatore',
        'illustratore' => 'illustratore',
        'colorista' => 'colorista',
    ];

    public function map(MarcRecord $record): array
    {
        $parsed = (new UnimarcLibriParser())->fromUnimarcXml(
            MarcXmlWriter::recordToXml($record, MarcXmlWriter::NS_MARCXCHANGE)
        );
        $data = MarcBookData::empty();
        $data['titolo'] = MarcBookData::clean((string) ($parsed['titolo'] ?? ''));
        $data['sottotitolo'] = MarcBookData::clean((string) ($parsed['sottotitolo'] ?? ''));
        $data['isbn13'] = (string) ($parsed['isbn13'] ?? '');
        $data['isbn10'] = (string) ($parsed['isbn10'] ?? '');
        $data['anno_pubblicazione'] = isset($parsed['anno_pubblicazione']) ? (string) $parsed['anno_pubblicazione'] : '';
        $data['edizione'] = \App\Support\EditionStatement::clean(MarcBookData::clean((string) ($parsed['edizione'] ?? '')));
        $data['numero_pagine'] = isset($parsed['numero_pagine']) ? (string) $parsed['numero_pagine'] : '';
        $data['collana'] = MarcBookData::clean((string) ($parsed['collana'] ?? ''));
        $data['numero_serie'] = MarcBookData::clean((string) ($parsed['numero_serie'] ?? ''));
        $data['classificazione_dewey'] = (string) ($parsed['classificazione_dewey'] ?? '');
        // An unknown code stays empty, as in the MARC 21 mapper: the reader's
        // own fallback would store the raw code ("swa") as a language name.
        // Every 101 $a, one per language, as the MARC 21 mapper reads 041.
        $languages = [];
        foreach ($record->subfieldValues('101', 'a') as $code) {
            $name = MarcLanguage::nameFor(trim($code));
            if ($name !== '' && !in_array($name, $languages, true)) {
                $languages[] = $name;
            }
        }
        $data['lingua'] = implode(', ', $languages);

        // The genre: a 608 form / genre heading the exporting catalogue marked
        // as its own term ($2 local, as BookMarcBuilder writes it), like the
        // MARC 21 655. Every 606 is a topical subject, so a keyword, whatever
        // its $2: another catalogue's local subjects are not taken as a genre.
        foreach ($record->fieldsByTag('608') as $field) {
            if (strtolower(trim((string) $field->subfield('2'))) === 'local') {
                $data['genere'] = MarcBookData::clean($field->subfield('a'), ' /:;=,.');
                if ($data['genere'] !== '') {
                    break;
                }
            }
        }
        $subjects = [];
        foreach ($record->fieldsByTag('606') as $field) {
            $term = MarcBookData::clean($field->subfield('a'), ' /:;=,.');
            if ($term !== '' && !in_array($term, $subjects, true)) {
                $subjects[] = $term;
            }
        }
        $data['parole_chiave'] = $subjects;

        foreach ((array) ($parsed['authors'] ?? []) as $author) {
            if (!is_array($author)) {
                continue;
            }
            $name = MarcBookData::cleanName((string) ($author['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $column = self::ROLE_COLUMNS[(string) ($author['ruolo'] ?? '')] ?? 'autori';
            if (!in_array($name, $data[$column], true)) {
                $data[$column][] = $name;
            }
        }

        foreach (array_merge($record->fieldsByTag('210'), $record->fieldsByTag('214')) as $field) {
            if ($data['luogo_pubblicazione'] === '') {
                $data['luogo_pubblicazione'] = \App\Support\PublicationPlace::clean(MarcBookData::clean($field->subfield('a')));
            }
            foreach ($field->subfieldValues('c') as $publisher) {
                $publisher = MarcBookData::clean($publisher);
                if ($publisher !== '' && !in_array($publisher, $data['editori'], true)) {
                    $data['editori'][] = $publisher;
                }
            }
        }
        $data['dimensioni'] = MarcBookData::clean($record->subfield('215', 'd'));
        $data['descrizione'] = trim(implode("\n\n", array_map('trim', $record->subfieldValues('330', 'a'))));
        $ean = (string) preg_replace('/\D/', '', (string) $record->subfield('073', 'a'));
        if (strlen($ean) === 13 && $ean !== $data['isbn13']) {
            $data['ean'] = $ean;
        }
        // 011: the ISSN of the resource itself; on a monograph the series
        // ISSN travels in 225 $x, as the export writes it.
        $data['issn'] = MarcBookData::clean($record->subfield('011', 'a'));
        if ($data['issn'] === '') {
            $data['issn'] = MarcBookData::clean($record->subfield('225', 'x'));
        }
        $data['copertina_url'] = MarcBookData::coverUrlOf($record);
        // Media type from label/06, refined by the RDA content type of a 181
        // $c whose $2 says rdacontent (an "altro" is language material with
        // content "other").
        $content = null;
        foreach ($record->fieldsByTag('181') as $field) {
            if (strtolower(trim((string) $field->subfield('2'))) === 'rdacontent' && trim((string) $field->subfield('c')) !== '') {
                $content = trim((string) $field->subfield('c'));
                break;
            }
        }
        $data['tipo_media'] = MarcMediaType::tipoMediaOf(substr($record->leader() . '      ', 6, 1), $content);

        return $data;
    }
}
