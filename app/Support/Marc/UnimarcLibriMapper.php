<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * UNIMARC bibliographic → Pinakes book data, through the UNIMARC reader the
 * SBN/REICAT import already uses ({@see UnimarcLibriParser}), plus the fields
 * that reader leaves out:
 *
 *   011 $a ISSN   073 $a EAN   210 / 214 $a place, every $c publisher
 *   215 $d dimensions   330 $a summary   856 $u cover image
 *   label/06 media type
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
        $data['lingua'] = MarcLanguage::nameFor((string) $record->subfield('101', 'a'));

        // 606 subjects are keywords, except the one the exporting catalogue
        // marked as its own genre ($2 local, as BookMarcBuilder writes it).
        $subjects = [];
        foreach ($record->fieldsByTag('606') as $field) {
            $term = MarcBookData::clean($field->subfield('a'), ' /:;=,.');
            if ($term === '') {
                continue;
            }
            if (strtolower(trim((string) $field->subfield('2'))) === 'local') {
                if ($data['genere'] === '') {
                    $data['genere'] = $term;
                }
                continue;
            }
            if (!in_array($term, $subjects, true)) {
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
        $data['issn'] = MarcBookData::clean($record->subfield('011', 'a'));
        $data['copertina_url'] = MarcBookData::coverUrlOf($record);
        $data['tipo_media'] = MarcMediaType::tipoMediaOf(substr($record->leader() . '      ', 6, 1));

        return $data;
    }
}
