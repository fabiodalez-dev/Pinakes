<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bibliographic citations in five styles, for books, periodical articles and
 * chapters in edited books (#412).
 *
 * A catalogue record holds every part of a citation; this class assembles
 * them, so a reader copies the reference instead of retyping it. The Emeroteca
 * article page and the book page both use it, and the plugin's own
 * CitationFormatter delegates to it, so an article and a book cited in the
 * same bibliography follow the same rules.
 *
 * Input is a normalised record (see record()): names already in citation
 * form ("Surname, Forename"; a name without a comma is corporate and is never
 * reordered or initialised), a year that may be empty, and every other part
 * optional — on catalogue records missing parts are the normal case, so each
 * style states what it prints when a part is absent.
 *
 * Every style returns the same citation twice: plain text, for a text field or
 * a RIS-minded reader, and HTML with the titles the style italicises in <i>,
 * for a word processor. Both come from one list of segments, so they cannot
 * disagree.
 *
 * Pure and static: no database, no session, no request.
 */
final class CitationStyles
{
    /** Style key => label, an Italian source string the view passes through __(). */
    public const STYLES = [
        'apa' => 'APA (7ª edizione)',
        'chicago' => 'Chicago (17ª edizione, autore-data)',
        'mla' => 'MLA (9ª edizione)',
        'harvard' => 'Harvard',
        'oxford' => 'Oxford (Umeå)',
    ];

    private const MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];

    /** MLA abbreviates every month longer than four letters. */
    private const MLA_MONTHS = [1 => 'Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'June', 'July',
        'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];

    private function __construct()
    {
    }

    /**
     * A complete record with every key present, from whatever subset the
     * caller has. Strings are trimmed and collapsed to one line: a citation is
     * one logical line and a stray newline out of a textarea would break it.
     *
     * @param array<string,mixed> $input
     * @return array{type:string,authors:list<string>,editors:list<string>,year:string,month:int,day:int,
     *               title:string,container:string,volume:string,issue:string,pageStart:string,pageEnd:string,
     *               doi:string,publisher:string,place:string,edition:string,isNewspaper:bool,isMagazine:bool}
     */
    public static function record(array $input): array
    {
        $names = static function (mixed $list): array {
            if (!is_array($list)) {
                return [];
            }
            return array_values(array_filter(array_map(
                static fn ($n): string => self::clean($n),
                $list
            ), static fn (string $n): bool => $n !== ''));
        };
        $type = (string) ($input['type'] ?? 'article');

        return [
            'type' => in_array($type, ['book', 'article', 'chapter'], true) ? $type : 'article',
            'authors' => $names($input['authors'] ?? []),
            'editors' => $names($input['editors'] ?? []),
            'year' => self::clean($input['year'] ?? ''),
            'month' => (int) ($input['month'] ?? 0),
            'day' => (int) ($input['day'] ?? 0),
            'title' => self::clean($input['title'] ?? ''),
            'container' => self::clean($input['container'] ?? ''),
            'volume' => self::clean($input['volume'] ?? ''),
            'issue' => self::clean($input['issue'] ?? ''),
            'pageStart' => self::clean($input['pageStart'] ?? ''),
            'pageEnd' => self::clean($input['pageEnd'] ?? ''),
            'doi' => self::clean($input['doi'] ?? ''),
            'publisher' => self::clean($input['publisher'] ?? ''),
            'place' => self::clean($input['place'] ?? ''),
            'edition' => self::clean($input['edition'] ?? ''),
            'isNewspaper' => !empty($input['isNewspaper']),
            'isMagazine' => !empty($input['isMagazine']),
        ];
    }

    /**
     * Every style, in display order.
     *
     * @param array<string,mixed> $record
     * @return list<array{key:string,label:string,text:string,html:string}>
     */
    public static function all(array $record): array
    {
        $out = [];
        foreach (self::STYLES as $key => $label) {
            $citation = self::$key($record);
            $out[] = ['key' => $key, 'label' => $label, 'text' => $citation['text'], 'html' => $citation['html']];
        }
        return $out;
    }

    // ── APA 7 ────────────────────────────────────────────────────────────────

    /**
     * APA 7th edition.
     *
     *   Petersen, H. U. (1988). Title : Subtitle. Arbejderhistorie, 12(31), 18–38.
     *   Byström, M., & Frohnert, P. (Eds.). (2013). Reaching a state of hope. Nordic Academic Press.
     *   Petersen, H. U. (1991). Chapter. In A. Müller (Ed.), Book (pp. 45–67). Publisher.
     *
     * With no author the title takes the author slot (the APA rule for an
     * anonymous work); with no year the record says "n.d.". A newspaper or
     * magazine is dated to the day or month it carries; a journal keeps the year.
     *
     * @param array<string,mixed> $record
     * @return array{text:string,html:string}
     */
    public static function apa(array $record): array
    {
        $r = self::record($record);
        $s = [];
        $date = $r['year'] === '' ? 'n.d.' : $r['year'];
        if ($r['type'] === 'article' && ($r['isNewspaper'] || $r['isMagazine']) && $r['month'] > 0) {
            $date .= ', ' . self::MONTHS[$r['month']] . ($r['day'] > 0 ? ' ' . $r['day'] : '');
        }
        $italicTitle = $r['type'] === 'book';
        $authors = self::join(array_map(static fn (string $n): string => self::initials($n, ' '), $r['authors']), ', ', ', & ', ', & ', 20);
        $editorsLead = $r['type'] === 'book' && $authors === '' && $r['editors'] !== [];

        if ($authors !== '' || $editorsLead) {
            if ($editorsLead) {
                $editors = self::join(array_map(static fn (string $n): string => self::initials($n, ' '), $r['editors']), ', ', ', & ', ', & ', 20);
                self::add($s, $editors . (count($r['editors']) > 1 ? ' (Eds.).' : ' (Ed.).'));
            } else {
                self::add($s, $authors);
            }
            self::add($s, ' (' . $date . '). ');
            if ($r['title'] !== '') {
                self::add($s, $italicTitle ? $r['title'] : self::terminate($r['title']), $italicTitle);
                if ($italicTitle) {
                    self::add($s, self::afterTitle($r['title'], self::apaEdition($r['edition'])));
                }
                self::add($s, ' ');
            }
        } elseif ($r['title'] !== '') {
            self::add($s, $italicTitle ? $r['title'] : self::terminate($r['title']), $italicTitle);
            if ($italicTitle) {
                self::add($s, self::afterTitle($r['title'], self::apaEdition($r['edition'])));
            }
            self::add($s, ' (' . $date . '). ');
        } else {
            self::add($s, '(' . $date . '). ');
        }

        if ($r['type'] === 'book') {
            if ($r['publisher'] !== '') {
                self::add($s, self::terminate($r['publisher']));
            }
        } elseif ($r['type'] === 'chapter') {
            $span = self::span($r);
            $pages = $span !== '' ? '(' . (str_contains($span, '–') ? 'pp. ' : 'p. ') . $span . ')' : '';
            if ($r['container'] !== '' || $r['editors'] !== []) {
                self::add($s, 'In ');
                if ($r['editors'] !== []) {
                    $editors = self::join(array_map(static fn (string $n): string => self::initialsFirst($n), $r['editors']), ', ', ', & ', ' & ', 20);
                    self::add($s, $editors . (count($r['editors']) > 1 ? ' (Eds.)' : ' (Ed.)') . ($r['container'] !== '' ? ', ' : ''));
                }
                self::add($s, $r['container'], true);
                if ($pages !== '') {
                    self::add($s, ' ' . $pages);
                }
                self::add($s, '. ');
            } elseif ($pages !== '') {
                // No host data to hang them on, but the pages are still cited.
                self::add($s, $pages . '. ');
            }
            if ($r['publisher'] !== '') {
                self::add($s, self::terminate($r['publisher']));
            }
        } elseif ($r['container'] !== '') {
            self::add($s, $r['container'], true);
            if ($r['volume'] !== '') {
                self::add($s, ', ');
                self::add($s, $r['volume'], true);
                if ($r['issue'] !== '') {
                    self::add($s, '(' . $r['issue'] . ')');
                }
            } elseif ($r['issue'] !== '') {
                self::add($s, ', ' . $r['issue']);
            }
            $span = self::span($r);
            if ($span !== '') {
                self::add($s, ', ' . $span);
            }
            self::add($s, '.');
        }
        if ($r['doi'] !== '') {
            self::add($s, ' https://doi.org/' . $r['doi']);
        }

        return self::render($s);
    }

    /** "2" → " (2nd ed.)"; a first edition, or one that is not a number, is not cited. */
    private static function apaEdition(string $edition): string
    {
        $ordinal = self::ordinalEdition($edition);
        return $ordinal === '' ? '' : ' (' . $ordinal . ' ed.)';
    }

    // ── Harvard ──────────────────────────────────────────────────────────────

    /**
     * Harvard (Cite Them Right).
     *
     *   Petersen, H.U. (1988) 'Title', Arbejderhistorie, 12(31), pp. 18–38.
     *   Byström, M. and Frohnert, P. (eds) (2013) Reaching a state of hope. Lund: Nordic Academic Press.
     *
     * @param array<string,mixed> $record
     * @return array{text:string,html:string}
     */
    public static function harvard(array $record): array
    {
        $r = self::record($record);
        $s = [];
        $year = $r['year'] === '' ? 'no date' : $r['year'];
        $authors = self::join(array_map(static fn (string $n): string => self::initials($n, ''), $r['authors']), ', ', ' and ', ' and ', 20);
        if ($authors === '' && $r['type'] === 'book' && $r['editors'] !== []) {
            $authors = self::join(array_map(static fn (string $n): string => self::initials($n, ''), $r['editors']), ', ', ' and ', ' and ', 20)
                . (count($r['editors']) > 1 ? ' (eds)' : ' (ed.)');
        }

        if ($r['type'] === 'book') {
            if ($authors !== '') {
                self::add($s, $authors . ' (' . $year . ') ');
                self::add($s, $r['title'], true);
            } else {
                self::add($s, $r['title'], true);
                self::add($s, ' (' . $year . ')');
            }
            // The title only ends the sentence when it is the last thing
            // printed: an author-less book has the year after it.
            $last = $authors !== '' ? $r['title'] : '';
            $edition = self::ordinalEdition($r['edition']);
            if ($edition !== '') {
                self::add($s, self::separatorAfter($last) . $edition . ' edn');
                $last = '';
            }
            $imprint = self::imprint($r['place'], $r['publisher'], ': ');
            self::add($s, $imprint !== '' ? self::separatorAfter($last) . $imprint . '.' : self::afterTitle($last, ''));
            return self::render($s);
        }

        // An anonymous work: the title leads, and it is not put in quotation
        // marks when it stands in for the author.
        if ($authors !== '') {
            self::add($s, $authors . ' (' . $year . ') ' . "'" . rtrim($r['title'], '.') . "'");
        } else {
            self::add($s, self::terminate($r['title']) . ' (' . $year . ')');
        }

        if ($r['type'] === 'chapter') {
            if ($r['container'] !== '' || $r['editors'] !== []) {
                self::add($s, ', in ');
                if ($r['editors'] !== []) {
                    self::add($s, self::join(array_map(static fn (string $n): string => self::initials($n, ''), $r['editors']), ', ', ' and ', ' and ', 20)
                        . (count($r['editors']) > 1 ? ' (eds)' : ' (ed.)')
                        // The space belongs to the title that follows; with
                        // none, it would sit before the full stop.
                        . ($r['container'] !== '' ? ' ' : ''));
                }
                self::add($s, $r['container'], true);
            }
            $imprint = self::imprint($r['place'], $r['publisher'], ': ');
            if ($imprint !== '') {
                self::add($s, '. ' . $imprint);
            }
            $span = self::span($r);
            if ($span !== '') {
                self::add($s, ', ' . (str_contains($span, '–') ? 'pp. ' : 'p. ') . $span);
            }
            self::add($s, '.');
        } else {
            if ($r['container'] !== '') {
                self::add($s, ', ');
                self::add($s, $r['container'], true);
                // Harvard puts the day and month of a newspaper or magazine
                // after its title: "Süddeutsche Zeitung, 28 September, p. 3".
                if (($r['isNewspaper'] || $r['isMagazine']) && $r['month'] > 0) {
                    self::add($s, ', ' . ($r['day'] > 0 ? $r['day'] . ' ' : '') . self::MONTHS[$r['month']]);
                }
                if ($r['volume'] !== '') {
                    self::add($s, ', ' . $r['volume'] . ($r['issue'] !== '' ? '(' . $r['issue'] . ')' : ''));
                } elseif ($r['issue'] !== '') {
                    self::add($s, ', (' . $r['issue'] . ')');
                }
                $span = self::span($r);
                if ($span !== '') {
                    self::add($s, ', ' . (str_contains($span, '–') ? 'pp. ' : 'p. ') . $span);
                }
            }
            self::add($s, '.');
        }
        if ($r['doi'] !== '') {
            self::add($s, ' doi:' . $r['doi']);
        }

        return self::render($s);
    }

    // ── Chicago author-date ──────────────────────────────────────────────────

    /**
     * Chicago Manual of Style, 17th edition, author-date.
     *
     *   Petersen, Hans Uwe. 1988. “Title.” Arbejderhistorie 12 (31): 18–38.
     *   Byström, Mikael, and Pär Frohnert, eds. 2013. Reaching a State of Hope. Lund: Nordic Academic Press.
     *   Petersen, Hans Uwe. 1991. “Chapter.” In Book, edited by Anna Müller, 45–67. København: Publisher.
     *
     * Only the first name is inverted; the others read in natural order.
     *
     * @param array<string,mixed> $record
     * @return array{text:string,html:string}
     */
    public static function chicago(array $record): array
    {
        $r = self::record($record);
        $s = [];
        $year = $r['year'] === '' ? 'n.d.' : $r['year'];
        $lead = self::chicagoNames($r['authors']);
        if ($lead === '' && $r['type'] === 'book' && $r['editors'] !== []) {
            $lead = self::chicagoNames($r['editors']) . (count($r['editors']) > 1 ? ', eds' : ', ed');
        }

        if ($lead !== '') {
            self::add($s, self::terminate($lead) . ' ' . self::terminate($year) . ' ');
            self::chicagoTitle($s, $r);
        } else {
            // Anonymous: the title leads, then the year.
            self::chicagoTitle($s, $r);
            self::add($s, self::terminate($year) . ' ');
        }

        if ($r['type'] === 'book') {
            $edition = self::ordinalEdition($r['edition']);
            if ($edition !== '') {
                self::add($s, $edition . ' ed. ');
            }
            $imprint = self::imprint($r['place'], $r['publisher'], ': ');
            if ($imprint !== '') {
                self::add($s, $imprint . '.');
            }
        } elseif ($r['type'] === 'chapter') {
            // The host's title, editors and pages are each cited when known:
            // a chapter whose book title is missing keeps its editors and pages.
            $span = self::span($r);
            if ($r['container'] !== '') {
                self::add($s, 'In ');
                self::add($s, $r['container'], true);
                if ($r['editors'] !== []) {
                    self::add($s, ', edited by ' . self::naturalList($r['editors']));
                }
                self::add($s, ($span !== '' ? ', ' . $span : '') . '. ');
            } elseif ($r['editors'] !== []) {
                self::add($s, 'Edited by ' . self::naturalList($r['editors']) . ($span !== '' ? ', ' . $span : '') . '. ');
            } elseif ($span !== '') {
                self::add($s, $span . '. ');
            }
            $imprint = self::imprint($r['place'], $r['publisher'], ': ');
            if ($imprint !== '') {
                self::add($s, $imprint . '.');
            }
        } elseif ($r['container'] !== '') {
            self::add($s, $r['container'], true);
            if (($r['isNewspaper'] || $r['isMagazine']) && $r['month'] > 0) {
                self::add($s, ', ' . self::MONTHS[$r['month']] . ($r['day'] > 0 ? ' ' . $r['day'] : '') . ($r['year'] !== '' ? ', ' . $r['year'] : '') . '.');
            } else {
                if ($r['volume'] !== '') {
                    self::add($s, ' ' . $r['volume'] . ($r['issue'] !== '' ? ' (' . $r['issue'] . ')' : ''));
                } elseif ($r['issue'] !== '') {
                    self::add($s, ', no. ' . $r['issue']);
                }
                $span = self::span($r);
                self::add($s, ($span !== '' ? ': ' . $span : '') . '.');
            }
        }
        if ($r['doi'] !== '') {
            self::add($s, ' https://doi.org/' . $r['doi'] . '.');
        }

        return self::render($s);
    }

    /**
     * A title in quotation marks (article, chapter) or italics (book), with the
     * full stop inside the closing mark unless the title ends in ? or !.
     *
     * @param list<array{0:string,1:bool}> $s
     * @param array<string,mixed> $r
     */
    private static function chicagoTitle(array &$s, array $r): void
    {
        if ($r['title'] === '') {
            return;
        }
        if ($r['type'] === 'book') {
            self::add($s, rtrim($r['title'], '.'), true);
            self::add($s, preg_match('/[?!]$/u', $r['title']) === 1 ? ' ' : '. ');
            return;
        }
        self::add($s, '“' . self::terminate($r['title']) . '” ');
    }

    /**
     * "Petersen, Hans Uwe, Anna Müller, and Per Jensen": the first name
     * inverted, the others natural; seven and "et al." beyond ten.
     *
     * @param list<string> $names
     */
    private static function chicagoNames(array $names): string
    {
        if ($names === []) {
            return '';
        }
        if (count($names) > 10) {
            $names = array_slice($names, 0, 7);
            $parts = [$names[0], ...array_map(static fn (string $n): string => self::natural($n), array_slice($names, 1))];
            return implode(', ', $parts) . ', et al.';
        }
        $parts = [$names[0], ...array_map(static fn (string $n): string => self::natural($n), array_slice($names, 1))];
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ', and ' . $last;
    }

    // ── MLA 9 ────────────────────────────────────────────────────────────────

    /**
     * MLA Handbook, 9th edition.
     *
     *   Petersen, Hans Uwe. “Title.” Arbejderhistorie, vol. 12, no. 31, 1988, pp. 18–38.
     *   Byström, Mikael, and Pär Frohnert, editors. Reaching a State of Hope. Nordic Academic Press, 2013.
     *   Petersen, Hans Uwe. “Chapter.” Book, edited by Anna Müller, Publisher, 1991, pp. 45–67.
     *
     * One author inverted; two as "A, and B"; three or more as "A, et al.".
     *
     * @param array<string,mixed> $record
     * @return array{text:string,html:string}
     */
    public static function mla(array $record): array
    {
        $r = self::record($record);
        $s = [];
        $lead = self::mlaNames($r['authors']);
        if ($lead === '' && $r['type'] === 'book' && $r['editors'] !== []) {
            $lead = self::mlaNames($r['editors']) . (count($r['editors']) > 1 ? ', editors' : ', editor');
        }
        if ($lead !== '') {
            self::add($s, self::terminate($lead) . ' ');
        }
        if ($r['title'] !== '') {
            if ($r['type'] === 'book') {
                self::add($s, rtrim($r['title'], '.'), true);
                self::add($s, preg_match('/[?!]$/u', $r['title']) === 1 ? ' ' : '. ');
            } else {
                self::add($s, '“' . self::terminate($r['title']) . '” ');
            }
        }

        // The containers: each element after the first is joined by a comma,
        // and the last closes with a full stop.
        $elements = [];
        if ($r['type'] === 'book') {
            $edition = self::ordinalEdition($r['edition']);
            if ($edition !== '') {
                $elements[] = [$edition . ' ed.', false];
            }
            if ($r['publisher'] !== '') {
                $elements[] = [$r['publisher'], false];
            }
            if ($r['year'] !== '') {
                $elements[] = [$r['year'], false];
            }
        } else {
            if ($r['container'] !== '') {
                $elements[] = [$r['container'], true];
            }
            if ($r['type'] === 'chapter') {
                if ($r['editors'] !== []) {
                    // First element after the title's full stop: capitalised.
                    $elements[] = [($elements === [] ? 'Edited by ' : 'edited by ') . self::naturalList($r['editors']), false];
                }
                if ($r['publisher'] !== '') {
                    $elements[] = [$r['publisher'], false];
                }
            } else {
                if ($r['volume'] !== '') {
                    $elements[] = ['vol. ' . $r['volume'], false];
                }
                if ($r['issue'] !== '') {
                    $elements[] = ['no. ' . $r['issue'], false];
                }
            }
            $date = $r['year'];
            if ($r['type'] === 'article' && ($r['isNewspaper'] || $r['isMagazine']) && $r['month'] > 0 && $r['year'] !== '') {
                $date = ($r['day'] > 0 ? $r['day'] . ' ' : '') . self::MLA_MONTHS[$r['month']] . ' ' . $r['year'];
            }
            if ($date !== '') {
                $elements[] = [$date, false];
            }
            $span = self::span($r);
            if ($span !== '') {
                $elements[] = [(str_contains($span, '–') ? 'pp. ' : 'p. ') . $span, false];
            }
        }
        foreach ($elements as $i => [$text, $italic]) {
            if ($i > 0) {
                self::add($s, ', ');
            }
            self::add($s, $text, $italic);
        }
        if ($elements !== []) {
            self::add($s, '.');
        }
        if ($r['doi'] !== '') {
            self::add($s, ' https://doi.org/' . $r['doi'] . '.');
        }

        return self::render($s);
    }

    /** @param list<string> $names */
    private static function mlaNames(array $names): string
    {
        if ($names === []) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        if (count($names) === 2) {
            return $names[0] . ', and ' . self::natural($names[1]);
        }
        return $names[0] . ', et al.';
    }

    /**
     * Oxford, Umeå University Library bibliography variant (not a footnote).
     * https://www.umu.se/bibliotek/soka-skriva-studera/skriva-referenser/oxford-skriva-referenslista/
     * @param array<string,mixed> $record
     * @return array{text:string,html:string}
     */
    public static function oxford(array $record): array
    {
        $r = self::record($record);
        $s = [];
        $year = $r['year'] !== '' ? $r['year'] : 'n.d.';
        $names = static fn (array $list): string => self::join(
            array_map(static fn (string $name): string => self::initials($name, ''), $list),
            ', ', ' & ', ' & ', 10000
        );
        $lead = $names($r['authors']);
        if ($lead === '' && $r['type'] === 'book' && $r['editors'] !== []) {
            $lead = $names($r['editors']) . (count($r['editors']) > 1 ? ' (eds.)' : ' (ed.)');
        }
        if ($lead !== '') { self::add($s, self::terminate($lead) . ' '); }
        if ($r['title'] !== '') {
            self::add($s, $r['title'], $r['type'] === 'book');
            self::add($s, self::afterTitle($r['title'], '') . ' ');
        }
        $span = self::span($r);
        if ($r['type'] === 'book' || $r['type'] === 'chapter') {
            if ($r['type'] === 'chapter' && ($r['editors'] !== [] || $r['container'] !== '')) {
                self::add($s, 'In ');
                if ($r['editors'] !== []) {
                    self::add($s, self::terminate($names($r['editors']) . (count($r['editors']) > 1 ? ' (eds.)' : ' (ed.)')) . ' ');
                }
                if ($r['container'] !== '') {
                    self::add($s, $r['container'], true);
                    self::add($s, self::afterTitle($r['container'], '') . ' ');
                }
            }
            $edition = self::ordinalEdition($r['edition']);
            if ($edition !== '') { self::add($s, $edition . ' ed. '); }
            self::add($s, '(' . ($r['publisher'] !== '' ? $r['publisher'] . ', ' : '') . $year . ')');
            if ($r['type'] === 'chapter' && $span !== '') {
                self::add($s, ', ' . (str_contains($span, '–') ? 'pp. ' : 'p. ') . $span);
            }
            self::add($s, '.');
        } else {
            if ($r['container'] !== '') {
                self::add($s, $r['container'], true);
                self::add($s, self::afterTitle($r['container'], '') . ' ');
            }
            if ($r['isNewspaper'] || $r['isMagazine']) {
                $date = ($r['day'] > 0 && $r['month'] > 0 ? $r['day'] . '/' : '')
                    . ($r['month'] > 0 ? $r['month'] . ' ' : '') . $year;
                self::add($s, '(' . $date . ')');
                if ($span !== '') { self::add($s, ', ' . (str_contains($span, '–') ? 'pp. ' : 'p. ') . $span); }
            } else {
                $enumeration = $r['volume'] . ($r['issue'] !== '' ? ($r['volume'] !== '' ? ': ' : '') . $r['issue'] : '');
                self::add($s, ($enumeration !== '' ? $enumeration . ' ' : '') . '(' . $year . ')');
                if ($span !== '') { self::add($s, ': ' . (str_contains($span, '–') ? 'pp. ' : 'p. ') . $span); }
            }
            self::add($s, '.');
        }
        if ($r['doi'] !== '') { self::add($s, ' https://doi.org/' . $r['doi']); }
        return self::render($s);
    }

    // ── shared pieces ────────────────────────────────────────────────────────

    /**
     * Append a segment, merging it into the previous one when both share
     * the same style so the HTML carries no empty or split tags.
     *
     * @param list<array{0:string,1:bool}> $s
     */
    private static function add(array &$s, string $text, bool $italic = false): void
    {
        if ($text === '') {
            return;
        }
        $last = count($s) - 1;
        if ($last >= 0 && $s[$last][1] === $italic) {
            $s[$last][0] .= $text;
            return;
        }
        $s[] = [$text, $italic];
    }

    /**
     * @param list<array{0:string,1:bool}> $s
     * @return array{text:string,html:string}
     */
    private static function render(array $s): array
    {
        $text = '';
        $html = '';
        foreach ($s as [$part, $italic]) {
            $text .= $part;
            $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            $html .= $italic ? '<i>' . $escaped . '</i>' : $escaped;
        }
        // Collapsing runs of spaces in the HTML is safe: the only markup is
        // <i>, whose tags hold no spaces.
        return ['text' => self::clean($text), 'html' => trim((string) preg_replace('/ {2,}/', ' ', $html))];
    }

    private static function clean(mixed $value): string
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));
        return $text === '' ? '' : (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * What follows a title that may already end the sentence: "Who Moved My
     * Cheese?" takes no full stop after it, and neither does a title ending in
     * one. Anything in $suffix (an edition statement) still closes with one.
     */
    private static function afterTitle(string $title, string $suffix): string
    {
        if ($suffix === '' && preg_match('/[.!?]$/u', $title) === 1) {
            return '';
        }
        return $suffix . '.';
    }

    /** The separator before the next element: ". " unless the title already ended the sentence. */
    private static function separatorAfter(string $title): string
    {
        return preg_match('/[.!?]$/u', $title) === 1 ? ' ' : '. ';
    }

    /** A title ends in exactly one full stop, whatever punctuation it arrived with. */
    private static function terminate(string $text): string
    {
        if ($text === '') {
            return '';
        }
        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text . '.';
    }

    /**
     * An inverted personal name reduced to initials; a corporate name (no
     * comma) exactly as written — guessing its "surname" would be wrong often
     * enough, and invisibly enough, that not guessing is the better rule.
     */
    public static function initials(string $name, string $gap): string
    {
        if (!str_contains($name, ',')) {
            return $name;
        }
        [$surname, $rest] = explode(',', $name, 2);
        $surname = trim($surname);
        $given = preg_split('/[\s.]+/u', trim($rest), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($given) || $given === []) {
            return $surname;
        }
        $letters = array_map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)) . '.', $given);

        return $surname . ', ' . implode($gap, $letters);
    }

    /** "Petersen, Hans Uwe" → "H. U. Petersen", as APA prints editors. */
    private static function initialsFirst(string $name): string
    {
        if (!str_contains($name, ',')) {
            return $name;
        }
        [$surname, $letters] = array_map('trim', explode(',', self::initials($name, ' '), 2));
        return $letters === '' ? $surname : $letters . ' ' . $surname;
    }

    /** "Petersen, Hans Uwe" → "Hans Uwe Petersen"; a corporate name stays. */
    private static function natural(string $name): string
    {
        if (!str_contains($name, ',')) {
            return $name;
        }
        [$surname, $given] = array_map('trim', explode(',', $name, 2));
        return $given === '' ? $surname : $given . ' ' . $surname;
    }

    /** @param list<string> $names "Anna Müller and Per Jensen", "A, B, and C". */
    private static function naturalList(array $names): string
    {
        $natural = array_map(static fn (string $n): string => self::natural($n), $names);
        if (count($natural) <= 2) {
            return implode(' and ', $natural);
        }
        $last = array_pop($natural);
        return implode(', ', $natural) . ', and ' . $last;
    }

    /**
     * Join names with the separators a style asks for, and cut a very long
     * list rather than printing forty names: the first ($cap - 1), an
     * ellipsis, and the last, as APA does beyond twenty.
     *
     * @param list<string> $names
     */
    private static function join(array $names, string $sep, string $last, string $pair, int $cap): string
    {
        if ($names === []) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        if (count($names) === 2) {
            return $names[0] . $pair . $names[1];
        }
        if (count($names) > $cap) {
            return implode($sep, array_slice($names, 0, $cap - 1)) . $sep . '…' . $sep . $names[count($names) - 1];
        }
        $tail = array_pop($names);
        return implode($sep, $names) . $last . $tail;
    }

    /** @param array<string,mixed> $r */
    private static function span(array $r): string
    {
        if ($r['pageStart'] === '') {
            return '';
        }
        return $r['pageEnd'] === '' ? $r['pageStart'] : $r['pageStart'] . '–' . $r['pageEnd'];
    }

    private static function imprint(string $place, string $publisher, string $sep): string
    {
        if ($place !== '' && $publisher !== '') {
            return $place . $sep . $publisher;
        }
        return $place . $publisher;
    }

    /**
     * "2" → "2nd", "3" → "3rd", "11" → "11th". A first edition is never cited,
     * and an edition written as text ("Prima edizione", "rev.") is left out
     * rather than printed in a language the style does not use.
     */
    private static function ordinalEdition(string $edition): string
    {
        if (preg_match('/^\s*(\d{1,3})\s*$/', $edition, $m) !== 1 || (int) $m[1] < 2) {
            return '';
        }
        $n = (int) $m[1];
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
        return $n . $suffix;
    }
}
