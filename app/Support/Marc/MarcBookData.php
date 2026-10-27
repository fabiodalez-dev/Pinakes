<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * The book data a MARC record maps to, flavour-independent, and its two
 * conversions: from the book shape the catalogue scrapers return (the SRU
 * client, danMARC2) and to the canonical import row the CSV pipeline reads.
 */
final class MarcBookData
{
    /** Keys holding lists of names or terms; every other key holds a string. */
    public const LIST_KEYS = ['autori', 'curatore', 'traduttore', 'illustratore', 'colorista', 'editori', 'parole_chiave'];

    /** @return array<string, string|list<string>> */
    public static function empty(): array
    {
        return [
            'titolo' => '', 'sottotitolo' => '',
            'autori' => [], 'curatore' => [], 'traduttore' => [], 'illustratore' => [], 'colorista' => [],
            'editori' => [], 'anno_pubblicazione' => '', 'lingua' => '', 'edizione' => '',
            'luogo_pubblicazione' => '', 'numero_pagine' => '', 'dimensioni' => '',
            'collana' => '', 'numero_serie' => '', 'descrizione' => '', 'parole_chiave' => [],
            'classificazione_dewey' => '', 'lc_classification' => '',
            'isbn13' => '', 'isbn10' => '', 'ean' => '', 'issn' => '', 'copertina_url' => '',
        ];
    }

    /**
     * Collapse whitespace, drop MARC-8 C1 leftovers and the ISBD punctuation a
     * record leaves at the end of a value (" /", " :", " ;", " =", ",").
     */
    public static function clean(?string $value, string $trailing = ' /:;=,'): string
    {
        if ($value === null) {
            return '';
        }
        $value = (string) preg_replace('/[\x{0080}-\x{009F}]/u', '', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return trim(rtrim($value, $trailing));
    }

    /** A name as the record gives it, without the closing comma or period ("Eco, Umberto,"). */
    public static function cleanName(?string $value): string
    {
        $name = self::clean($value, ' /:;=,');
        // A final period closes the field, unless it ends an initial ("Tolkien, J.R.R.")
        if (str_ends_with($name, '.') && preg_match('/(?:^|[\s.])\p{Lu}\.$/u', $name) !== 1) {
            $name = rtrim($name, '.');
        }
        return trim($name);
    }

    /** The first year a date statement names ("c1998", "[2004?]", "1980-1983"). */
    public static function yearOf(?string $value): string
    {
        if ($value !== null && preg_match('/(1[0-9]{3}|20[0-9]{2})/', $value, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * The ISBN of an 020 $a / 010 $a value: its leading digits (and final X),
     * without the qualifier a record may add ("9788845292613 (pbk.)").
     */
    public static function isbnOf(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        $value = (string) preg_replace('/^([0-9X\- ]+).*$/', '$1', $value);
        return (string) preg_replace('/[^0-9X]/', '', $value);
    }

    /**
     * Book data from the book shape the catalogue readers return (title,
     * subtitle, authors, publisher, year, language, editor, translator, ...).
     *
     * @param array<string, mixed> $book
     * @return array<string, string|list<string>>
     */
    public static function fromScrape(array $book): array
    {
        $data = self::empty();
        $string = static fn (string $key): string => is_scalar($book[$key] ?? null) ? trim((string) $book[$key]) : '';
        $list = static function (mixed $value): array {
            if (is_string($value)) {
                $value = $value === '' ? [] : [$value];
            }
            if (!is_array($value)) {
                return [];
            }
            return array_values(array_filter(array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $value), static fn (string $v): bool => $v !== ''));
        };
        $data['titolo'] = $string('title');
        $data['sottotitolo'] = $string('subtitle');
        $data['autori'] = $list($book['authors'] ?? []);
        $data['curatore'] = $list($book['editor'] ?? []);
        $data['traduttore'] = $list($book['translator'] ?? []);
        $data['illustratore'] = $list($book['illustrator'] ?? []);
        $data['colorista'] = $list($book['colorist'] ?? []);
        $data['editori'] = $list($book['publisher'] ?? []);
        $data['anno_pubblicazione'] = self::yearOf($string('year'));
        $data['lingua'] = $string('language');
        $data['edizione'] = $string('edition');
        $data['luogo_pubblicazione'] = $string('place');
        $data['numero_pagine'] = ctype_digit($string('pages')) ? $string('pages') : '';
        $data['descrizione'] = $string('description');
        $data['collana'] = $string('collana');
        $data['numero_serie'] = $string('numero_serie');
        $keywords = $book['keywords'] ?? [];
        $data['parole_chiave'] = is_string($keywords)
            ? array_values(array_filter(array_map('trim', explode(',', $keywords)), static fn (string $k): bool => $k !== ''))
            : $list($keywords);
        $data['classificazione_dewey'] = $string('classificazione_dewey');
        $data['isbn13'] = $string('isbn13');
        $data['isbn10'] = $string('isbn10');
        $data['ean'] = $string('ean');
        $data['copertina_url'] = $string('image') !== '' ? $string('image') : $string('cover');
        return $data;
    }

    /**
     * The canonical import row (the column names of the CSV import) the
     * shared row pipeline normalises: names joined with ";", keywords with
     * ", ". A contributor column is present only when the record names
     * someone in that role, so a record never clears contributors it says
     * nothing about.
     *
     * @param array<string, string|list<string>> $data
     * @return array<string, string>
     */
    public static function toImportRow(array $data): array
    {
        $join = static fn (string $key, string $glue = ';'): string => implode($glue, (array) ($data[$key] ?? []));
        $row = [
            'titolo' => (string) ($data['titolo'] ?? ''),
            'sottotitolo' => (string) ($data['sottotitolo'] ?? ''),
            'autori' => $join('autori'),
            'editore' => $join('editori'),
            'anno_pubblicazione' => (string) ($data['anno_pubblicazione'] ?? ''),
            'lingua' => (string) ($data['lingua'] ?? ''),
            'edizione' => (string) ($data['edizione'] ?? ''),
            'numero_pagine' => (string) ($data['numero_pagine'] ?? ''),
            'descrizione' => (string) ($data['descrizione'] ?? ''),
            'collana' => (string) ($data['collana'] ?? ''),
            'numero_serie' => (string) ($data['numero_serie'] ?? ''),
            'parole_chiave' => $join('parole_chiave', ', '),
            'classificazione_dewey' => (string) ($data['classificazione_dewey'] ?? ''),
            'isbn10' => (string) ($data['isbn10'] ?? ''),
            'isbn13' => (string) ($data['isbn13'] ?? ''),
            'ean' => (string) ($data['ean'] ?? ''),
        ];
        foreach (['traduttore', 'illustratore', 'curatore', 'colorista'] as $role) {
            if (((array) ($data[$role] ?? [])) !== []) {
                $row[$role] = $join($role);
            }
        }
        return $row;
    }

    /**
     * The cover image URL among a record's 856 fields: one labelled as a
     * cover ($3 / $y / $z), typed as an image ($q), or pointing at an image file.
     */
    public static function coverUrlOf(MarcRecord $record): string
    {
        foreach ($record->fieldsByTag('856') as $field) {
            $url = trim((string) $field->subfield('u'));
            if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            $label = implode(' ', array_merge($field->subfieldValues('3'), $field->subfieldValues('y'), $field->subfieldValues('z')));
            $type = (string) $field->subfield('q');
            if (preg_match('/cover|copertin|umschlag|couverture|omslag|portada|thumbnail|image de couverture/iu', $label) === 1
                || str_starts_with(strtolower($type), 'image/')
                || preg_match('#\.(jpe?g|png|gif|webp)(\?.*)?$#i', $url) === 1
            ) {
                return $url;
            }
        }
        return '';
    }
}
