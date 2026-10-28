<?php
declare(strict_types=1);

namespace App\Support\Marc;

use App\Support\EditionStatement;
use App\Support\IsbnFormatter;
use App\Support\PublicationPlace;

/**
 * MARC 21 bibliographic → Pinakes book data.
 *
 *   020 $a ISBN-13 / ISBN-10 (qualifiers dropped)   022 $a ISSN
 *   024 $a EAN (when it is not the ISBN-13 again)   041 $a language (else 008/35-37)
 *   050 $a $b LC classification                     082 $a Dewey
 *   100 $a main author; 700 $a added entries, their part read from $4, else $e,
 *       else the 245 $c statement of responsibility (RelatorRoles, the same
 *       reading the SRU client and danMARC2 use): editors, translators,
 *       illustrators and colorists go to their own columns, the rest stay authors
 *   245 $a title, $b rest of title                  250 $a edition
 *   260 / 264 $a place, $b publishers, $c year (else 008/07-10)
 *   300 $a pages, $c dimensions                     490 $a $v series (else 830)
 *   520 $a summary   650 / 651 / 653 $a keywords    856 $u cover image
 *   leader/06 (and 336 $b) media type; 336 / 337 / 338 $b RDA codes the media
 *       type does not imply
 */
final class Marc21LibriMapper implements MarcLibriMapper
{
    private const CONTRIBUTOR_COLUMNS = [
        'editor' => 'curatore',
        'translator' => 'traduttore',
        'illustrator' => 'illustratore',
        'colorist' => 'colorista',
    ];

    public function map(MarcRecord $record): array
    {
        $data = MarcBookData::empty();

        $data['titolo'] = MarcBookData::clean($record->subfield('245', 'a'));
        $data['sottotitolo'] = MarcBookData::clean($record->subfield('245', 'b'));

        $statement = $record->subfield('245', 'c');
        $authors = [];
        $contributors = array_fill_keys(array_values(self::CONTRIBUTOR_COLUMNS), []);
        $file = static function (string $name, string $role) use (&$authors, &$contributors): void {
            if ($name === '') {
                return;
            }
            if ($role === 'author') {
                if (!in_array($name, $authors, true)) {
                    $authors[] = $name;
                }
                return;
            }
            $column = self::CONTRIBUTOR_COLUMNS[$role] ?? null;
            if ($column !== null && !in_array($name, $contributors[$column], true)) {
                $contributors[$column][] = $name;
            }
        };
        foreach ($record->fieldsByTag('100') as $field) {
            $name = MarcBookData::cleanName($field->subfield('a'));
            $codes = $field->subfieldValues('4');
            $term = $field->subfieldValues('e');
            // The main entry is the author unless its own relator says otherwise.
            $role = ($codes === [] && $term === [])
                ? 'author'
                : RelatorRoles::resolve($codes, $term === [] ? null : implode(' ', $term), null, $name, 'marc21');
            $file($name, $role === 'other' ? 'author' : $role);
        }
        foreach ($record->fieldsByTag('700') as $field) {
            if ($field->subfield('t') !== null) {
                continue; // a name-title entry is a work, not a person's part
            }
            $name = MarcBookData::cleanName($field->subfield('a'));
            if ($name === '') {
                continue;
            }
            $term = $field->subfieldValues('e');
            $role = RelatorRoles::resolve($field->subfieldValues('4'), $term === [] ? null : implode(' ', $term), $statement, $name, 'marc21');
            $file($name, $role);
        }
        $data['autori'] = $authors;
        foreach ($contributors as $column => $names) {
            $data[$column] = $names;
        }

        $data['edizione'] = EditionStatement::clean(MarcBookData::clean($record->subfield('250', 'a')));

        // Publication: 260, or the 264 that states publication (ind2 1),
        // else any 264.
        $publication = $record->fieldsByTag('260');
        $published264 = array_values(array_filter($record->fieldsByTag('264'), static fn (MarcField $f): bool => $f->ind2 === '1'));
        $publication = array_merge($publication, $published264 !== [] ? $published264 : $record->fieldsByTag('264'));
        foreach ($publication as $field) {
            if ($data['luogo_pubblicazione'] === '') {
                $data['luogo_pubblicazione'] = PublicationPlace::clean(MarcBookData::clean($field->subfield('a')));
            }
            foreach ($field->subfieldValues('b') as $publisher) {
                $publisher = MarcBookData::clean($publisher);
                if ($publisher !== '' && !in_array($publisher, $data['editori'], true)) {
                    $data['editori'][] = $publisher;
                }
            }
            if ($data['anno_pubblicazione'] === '') {
                $data['anno_pubblicazione'] = MarcBookData::yearOf($field->subfield('c'));
            }
        }
        $f008 = (string) $record->controlField('008');
        if ($data['anno_pubblicazione'] === '' && strlen($f008) >= 11 && ctype_digit(substr($f008, 7, 4))) {
            $data['anno_pubblicazione'] = MarcBookData::yearOf(substr($f008, 7, 4));
        }

        $data['numero_pagine'] = ContributorSorter::pagesOf((string) $record->subfield('300', 'a'));
        $data['dimensioni'] = MarcBookData::clean($record->subfield('300', 'c'), ' /:;=,+');

        $data['collana'] = MarcBookData::clean($record->subfield('490', 'a'));
        $data['numero_serie'] = MarcBookData::clean($record->subfield('490', 'v'));
        if ($data['collana'] === '') {
            $data['collana'] = MarcBookData::clean($record->subfield('830', 'a'), ' /:;=,.');
            $data['numero_serie'] = MarcBookData::clean($record->subfield('830', 'v'));
        }

        $data['descrizione'] = trim(implode("\n\n", array_map(
            static fn (string $s): string => trim($s),
            $record->subfieldValues('520', 'a')
        )));

        $keywords = [];
        foreach (['650', '651', '653'] as $tag) {
            foreach ($record->subfieldValues($tag, 'a') as $term) {
                $term = MarcBookData::clean($term, ' /:;=,.');
                if ($term !== '' && !in_array($term, $keywords, true)) {
                    $keywords[] = $term;
                }
            }
        }
        $data['parole_chiave'] = $keywords;

        $dewey = (string) $record->subfield('082', 'a');
        if (preg_match('/(\d{3}(?:\.\d+)?)/', $dewey, $m) === 1) {
            $data['classificazione_dewey'] = $m[1];
        }
        $lc = trim(MarcBookData::clean($record->subfield('050', 'a')) . ' ' . MarcBookData::clean($record->subfield('050', 'b')));
        $data['lc_classification'] = mb_substr($lc, 0, 100);

        foreach ($record->subfieldValues('020', 'a') as $value) {
            $isbn = MarcBookData::isbnOf($value);
            if (strlen($isbn) === 13 && $data['isbn13'] === '' && IsbnFormatter::isValid($isbn)) {
                $data['isbn13'] = $isbn;
            } elseif (strlen($isbn) === 10 && $data['isbn10'] === '' && IsbnFormatter::isValid($isbn)) {
                $data['isbn10'] = $isbn;
            }
        }
        foreach ($record->fieldsByTag('024') as $field) {
            $ean = (string) preg_replace('/\D/', '', (string) $field->subfield('a'));
            if (strlen($ean) === 13 && ($field->ind1 === '3' || $field->ind1 === ' ') && $ean !== $data['isbn13']) {
                $data['ean'] = $ean;
                break;
            }
        }
        $data['issn'] = MarcBookData::clean($record->subfield('022', 'a'));

        $language = (string) $record->subfield('041', 'a');
        if ($language === '' && strlen($f008) >= 38) {
            $language = substr($f008, 35, 3);
        }
        $data['lingua'] = MarcLanguage::nameFor(substr($language, 0, 3));

        $data['copertina_url'] = MarcBookData::coverUrlOf($record);

        // Media type from leader/06, refined by the RDA content type (336);
        // RDA codes the media type does not imply are kept as the book's own.
        $content = $record->subfield('336', 'b');
        $data['tipo_media'] = MarcMediaType::tipoMediaOf(substr($record->leader() . '      ', 6, 1), $content);
        foreach (MarcMediaType::overrides($data['tipo_media'], $content, $record->subfield('337', 'b'), $record->subfield('338', 'b')) as $column => $code) {
            $data[$column] = $code;
        }

        return $data;
    }
}
