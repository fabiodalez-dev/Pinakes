<?php
declare(strict_types=1);

namespace Plugins\Z39Server\Classes;

use DOMNode;
use DOMXPath;

/**
 * A danMARC2 record, as Danish catalogues deliver it inside MARCXchange
 * (`<record format="danMARC2">`), turned into the book data the form reads.
 *
 * danMARC2 shares tag numbers with MARC 21 but not its subfields, so neither
 * the MARC 21 nor the UNIMARC reader can read it (kat-format.dk, 2nd edition):
 *
 *   021 *e ISBN-13, *a ISBN-10
 *   008 *a year, *l language (ISO 639-2/B)
 *   100 / 700 *a surname, *h forenames, *k forenames in full, *4 function
 *       codes (appendix J: the Library of Congress list plus dk… codes; "led"
 *       only marks the main responsibility), *b function term ("red.")
 *   720 *o a name in direct order ("Hanna Lützen"), *4 function code
 *   245 *a title, *b rest of title, *c subtitle, *e / *f statement of
 *       responsibility
 *   250 *a edition   260 *a place, *b publisher, *c year   300 *a extent
 *   440 *a series, *v number   504 *a summary   666 *s / *o subject terms
 */
final class Danmarc2Parser
{
    /** A MARCXchange record that declares itself danMARC2. */
    public static function isDanmarc2(DOMNode $record): bool
    {
        return $record instanceof \DOMElement && stripos($record->getAttribute('format'), 'danmarc') !== false;
    }

    /**
     * The danMARC2 record of the response that carries the ISBN searched for,
     * else the first one. A Danish catalogue answers an ISBN with every
     * manifestation it groups with it (the audiobook, the e-book, other
     * printings), not only the edition asked about.
     */
    public static function selectRecord(DOMXPath $xpath, string $isbn): ?DOMNode
    {
        $records = $xpath->query("//*[local-name()='record'][@format]");
        if ($records === false) {
            return null;
        }
        $first = null;
        $isbn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', $isbn));
        foreach ($records as $record) {
            if (!self::isDanmarc2($record)) {
                continue;
            }
            $first ??= $record;
            if ($isbn === '') {
                break;
            }
            foreach (array_merge(self::all($xpath, $record, '021', 'e'), self::all($xpath, $record, '021', 'a')) as $value) {
                if (strtoupper((string) preg_replace('/[^0-9Xx]/', '', $value)) === $isbn) {
                    return $record;
                }
            }
        }
        return $first;
    }

    /** @return array<string, mixed> */
    public static function parse(DOMXPath $xpath, DOMNode $record, callable $languageName): array
    {
        $get = static fn(string $tag, string $code): ?string => self::all($xpath, $record, $tag, $code)[0] ?? null;

        $book = [
            'title' => '',
            'subtitle' => '',
            'authors' => [],
            'publisher' => '',
            'pubDate' => '',
            'year' => '',
            'isbn13' => '',
            'isbn10' => '',
            'language' => '',
            'pages' => '',
            'description' => '',
            'classificazione_dewey' => '',
            'source' => 'Z39.50/SRU (danMARC2)',
        ];

        $title = trim(implode(' ', array_filter([$get('245', 'a'), $get('245', 'b')])));
        $book['title'] = self::clean($title);
        $book['subtitle'] = self::clean((string) $get('245', 'c'));

        // Names: 100 is the main author; 700 and 720 follow their function
        // codes, their term or the statement of responsibility.
        $statement = implode(' ; ', array_merge(self::all($xpath, $record, '245', 'e'), self::all($xpath, $record, '245', 'f')));
        $contributors = ['editor' => [], 'translator' => [], 'illustrator' => [], 'colorist' => []];
        foreach (self::fields($xpath, $record, '100') as $field) {
            $name = self::invertedName($field);
            if ($name !== '') {
                SruClient::sortContributor($book, $contributors, $name, 'author');
            }
        }
        foreach (['700', '720'] as $tag) {
            foreach (self::fields($xpath, $record, $tag) as $field) {
                $subs = self::subfields($field);
                $name = $tag === '720' && isset($subs['o']) ? self::clean($subs['o'][0]) : self::invertedName($field);
                if ($name === '' || isset($subs['t'])) {
                    // *t makes the field a name-title entry: a work, not a person's part
                    continue;
                }
                // *b is the function term ("red."); *f, the addition to the
                // name, often says it too ("pædagogisk note")
                $terms = array_merge($subs['b'] ?? [], $subs['f'] ?? []);
                $term = $terms !== [] ? implode(' ', $terms) : null;
                $role = RelatorRoles::resolve($subs['4'] ?? [], $term, $statement !== '' ? $statement : null, $name, 'marc21');
                SruClient::sortContributor($book, $contributors, $name, $role);
            }
        }
        SruClient::applyContributors($book, $contributors);

        $edition = \App\Support\EditionStatement::clean(self::clean((string) $get('250', 'a')));
        if ($edition !== '') {
            $book['edition'] = $edition;
        }
        $place = \App\Support\PublicationPlace::clean(self::clean((string) $get('260', 'a')));
        if ($place !== '') {
            $book['place'] = $place;
        }
        $book['publisher'] = self::clean((string) $get('260', 'b'));

        $year = $get('260', 'c') ?? $get('008', 'a');
        if ($year !== null && preg_match('/\d{4}/', $year, $m) === 1) {
            $book['year'] = $m[0];
            $book['pubDate'] = $m[0] . '-01-01';
        }

        // Every ISBN of the record, for the client to tell whether the one
        // searched for is among them
        foreach (array_merge(self::all($xpath, $record, '021', 'e'), self::all($xpath, $record, '021', 'a')) as $value) {
            $book['_isbns'][] = strtoupper((string) preg_replace('/[^0-9Xx]/', '', $value));
        }
        foreach (self::all($xpath, $record, '021', 'e') as $value) {
            $value = (string) preg_replace('/[^0-9Xx]/', '', $value);
            if (strlen($value) === 13) {
                $book['isbn13'] = $value;
                break;
            }
        }
        foreach (self::all($xpath, $record, '021', 'a') as $value) {
            $value = strtoupper((string) preg_replace('/[^0-9Xx]/', '', $value));
            if (strlen($value) === 10) {
                $book['isbn10'] = $value;
                break;
            }
        }

        // Extent: pages only ("355 sider", "288 s."), never the minutes of an audiobook
        $pages = SruClient::pagesOf((string) $get('300', 'a'), false);
        if ($pages !== '') {
            $book['pages'] = $pages;
        }

        $language = $get('008', 'l');
        if ($language !== null && $language !== '') {
            $book['language'] = $languageName(strtolower(substr($language, 0, 3)));
        }

        $book['description'] = self::clean((string) $get('504', 'a'));

        $series = $get('440', 'a');
        if ($series !== null && trim($series) !== '') {
            $book['collana'] = self::clean($series);
            $number = $get('440', 'v');
            if ($number !== null && trim($number) !== '') {
                $book['numero_serie'] = self::clean($number);
            }
        }

        $subjects = [];
        foreach (['s', 'o'] as $code) {
            foreach (self::all($xpath, $record, '666', $code) as $value) {
                $value = self::clean($value);
                if ($value !== '' && !in_array($value, $subjects, true)) {
                    $subjects[] = $value;
                }
            }
        }
        if ($subjects !== []) {
            $book['keywords'] = implode(', ', $subjects);
        }

        if ($book['authors'] !== []) {
            $book['author'] = implode(', ', $book['authors']);
        }
        return $book;
    }

    /** "Lützen, Hanna" from *a Lützen *h Hanna; the full forenames of *k when given. */
    private static function invertedName(DOMNode $field): string
    {
        $subs = self::subfields($field);
        $surname = self::clean($subs['a'][0] ?? '');
        $forenames = self::clean($subs['k'][0] ?? $subs['h'][0] ?? '');
        if ($surname === '') {
            return $forenames;
        }
        return $forenames !== '' ? $surname . ', ' . $forenames : $surname;
    }

    /** @return list<DOMNode> */
    private static function fields(DOMXPath $xpath, DOMNode $record, string $tag): array
    {
        $nodes = $xpath->query("./*[local-name()='datafield'][@tag='$tag']", $record);
        return $nodes === false ? [] : iterator_to_array($nodes, false);
    }

    /** @return list<string> */
    private static function all(DOMXPath $xpath, DOMNode $record, string $tag, string $code): array
    {
        $nodes = $xpath->query("./*[local-name()='datafield'][@tag='$tag']/*[local-name()='subfield'][@code='$code']", $record);
        $out = [];
        foreach ($nodes === false ? [] : $nodes as $node) {
            $value = trim((string) $node->nodeValue);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    /** @return array<array-key, list<string>> */
    private static function subfields(DOMNode $field): array
    {
        $out = [];
        foreach ($field->childNodes as $sub) {
            if ($sub instanceof \DOMElement && $sub->localName === 'subfield') {
                $out[$sub->getAttribute('code')][] = trim((string) $sub->nodeValue);
            }
        }
        return $out;
    }

    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x{0080}-\x{009F}]/u', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return rtrim($text, ' /:;=,');
    }
}
