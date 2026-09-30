<?php
declare(strict_types=1);

namespace App\Plugins\Emeroteca\Support;

require_once __DIR__ . '/../Services/ContributionService.php';

use App\Plugins\Emeroteca\Services\ContributionService;

/**
 * Citations for a standalone article, in the three shapes a researcher asks
 * for: APA 7, Harvard, and RIS for EndNote, Mendeley and Zotero.
 *
 * Why this exists at all: the record these articles produce is a citation.
 * Somebody catalogues one article out of a journal they do not own precisely
 * because they intend to cite it, and until now the catalogue held every part
 * of the citation and would not assemble it — leaving the librarian to retype
 * a string the database already knew, which is how a wrong volume number gets
 * into a bibliography.
 *
 * Every method is static and pure: no database, no session, no request. The
 * only shared decision is parts(), which decomposes the row once so that the
 * page number in the APA line, the SP/EP in the RIS file and the pageStart in
 * the structured data can never disagree with one another.
 *
 * On these records, half the fields are usually absent — that is normal, not
 * an error state. Every rule below therefore states what happens when the
 * author, the year, the container or the pages are missing, because "missing"
 * is the common case and a formatter that only works on complete records
 * would work on almost none of them.
 */
final class CitationFormatter
{
    /**
     * A field as a citation can carry it: no leading or trailing space, and no
     * internal line break.
     *
     * Collapsing whitespace is not cosmetic. A citation is ONE logical line
     * and the reader's window decides where it wraps; a newline surviving out
     * of a textarea would split a RIS record in two, because in RIS a line
     * break starts a new tag.
     */
    private static function clean(mixed $value): string
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));
        if ($text === '') {
            return '';
        }

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * The one decomposition every format reads.
     *
     * @param array<string,mixed> $row
     * @return array{authors:list<string>,year:string,title:string,container:string,
     *               volume:string,issue:string,pageStart:string,pageEnd:string,
     *               doi:string,issn:string,language:string,keywords:list<string>,
     *               abstract:string,isNewspaper:bool,isMagazine:bool,month:int,day:int}
     */
    public static function parts(array $row): array
    {
        $title = self::clean($row['titolo'] ?? '');
        $subtitle = self::clean($row['sottotitolo'] ?? '');
        if ($subtitle !== '') {
            // ISBD punctuation, and what the Royal Library's own record shows:
            // title and subtitle joined by a space-colon-space.
            $title = $title . ' : ' . $subtitle;
        }

        [$pageStart, $pageEnd] = self::pages(self::clean($row['pagine'] ?? ''));
        $year = self::year($row);
        $date = self::date(self::clean($row['data_pubblicazione_testo'] ?? ''));
        // A day and month only count when they belong to the year the record
        // cites: a free date that disagrees with the year column is not
        // grafted onto it.
        if ($date['year'] !== $year) {
            $date = ['year' => '', 'month' => 0, 'day' => 0];
        }

        return [
            'authors' => ContributionService::authorList(
                isset($row['autori']) ? (string) $row['autori'] : null
            ),
            'year' => $year,
            'month' => $date['month'],
            'day' => $date['day'],
            'title' => $title,
            'container' => self::clean($row['contenitore_titolo'] ?? ''),
            'volume' => self::clean($row['volume'] ?? ''),
            'issue' => self::clean($row['numero'] ?? ''),
            'pageStart' => $pageStart,
            'pageEnd' => $pageEnd,
            'doi' => self::clean($row['doi'] ?? ''),
            'issn' => self::clean($row['issn'] ?? ''),
            'language' => self::clean($row['lingua'] ?? ''),
            'keywords' => array_values(array_filter(
                array_map(
                    static fn (string $k): string => self::clean($k),
                    explode(',', (string) ($row['keywords'] ?? ''))
                ),
                static fn (string $k): bool => $k !== ''
            )),
            'abstract' => self::clean($row['abstract'] ?? ''),
            'isNewspaper' => ($row['contenitore_tipo'] ?? '') === 'giornale',
            'isMagazine' => ($row['contenitore_tipo'] ?? '') === 'magazine',
        ];
    }

    /** Month names as a free date may spell them, in the languages Pinakes ships. */
    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6,
        'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'dicembre' => 12,
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'mai' => 5, 'juni' => 6, 'juli' => 7,
        'oktober' => 10, 'dezember' => 12,
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'juin' => 6, 'juillet' => 7,
        'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
        'marts' => 3, 'maj' => 5,
    ];

    /** English month names, as the citation styles print them. */
    private const MONTH_NAMES = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];

    /**
     * Day and month out of the free publication date, when it states them.
     *
     * A newspaper is identified by its day, and APA and Harvard both print it:
     * "(2026, September 28)". The column stays free text — "Nr. 31 (1988)" is a
     * real value — so this recognises the shapes a cataloguer actually types
     * and leaves everything else alone: day-month-year with a hyphen, dot or
     * slash (the European order), ISO year-month-day, and a month written out
     * in any of the application's languages, with or without a day. A numeric
     * date is never read month-first: 03-04-2026 is the 3rd of April.
     *
     * @return array{year:string,month:int,day:int}
     */
    private static function date(string $text): array
    {
        $none = ['year' => '', 'month' => 0, 'day' => 0];
        if ($text === '') {
            return $none;
        }
        $valid = static function (int $y, int $m, int $d) use ($none): array {
            if ($y < 1500 || $y > 2099 || $m < 1 || $m > 12 || ($d !== 0 && !checkdate($m, $d, $y))) {
                return $none;
            }
            return ['year' => (string) $y, 'month' => $m, 'day' => $d];
        };
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $m) === 1) {
            return $valid((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.]\s?(\d{1,2})[-\/.]\s?(\d{4})$/', $text, $m) === 1) {
            return $valid((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        $lower = mb_strtolower($text);
        if (preg_match('/^(?:(\d{1,2})\.?\s+)?(\p{L}+)\s+(?:(\d{1,2}),\s*)?(\d{4})$/u', $lower, $m) === 1
            && isset(self::MONTHS[$m[2]])) {
            $day = $m[1] !== '' ? (int) $m[1] : (int) $m[3];
            return $valid((int) $m[4], self::MONTHS[$m[2]], $day);
        }

        return $none;
    }

    /**
     * The year, from the structured column when there is one and from the free
     * date when there is not.
     *
     * `data_pubblicazione_testo` holds what the librarian wrote — "June 2019",
     * "27 September 2026", "Nr. 31 (1988)" — so a four-digit run inside it is
     * the only year available on records where the numeric column was never
     * filled. The range is bounded so that a page number or a volume cannot be
     * mistaken for a year.
     *
     * @param array<string,mixed> $row
     */
    private static function year(array $row): string
    {
        $year = (int) ($row['anno_pubblicazione'] ?? 0);
        if ($year > 0) {
            return (string) $year;
        }
        if (preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', self::clean($row['data_pubblicazione_testo'] ?? ''), $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * Split a free-text page span into its ends.
     *
     * The column is free text on purpose — "138–148", "S. 18-38", "iv", "12,
     * 14-15" are all things that appear on real articles — so this reads the
     * span when there is one and otherwise hands the whole string back as the
     * start. A hyphen, an en dash and an em dash all separate; anything else
     * is part of the value.
     *
     * @return array{0:string,1:string}
     */
    private static function pages(string $pages): array
    {
        if ($pages === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s*[-–—]\s*/u', $pages, 2);
        if (is_array($parts) && count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
            return [trim($parts[0]), trim($parts[1])];
        }

        return [$pages, ''];
    }

    /**
     * A personal name reduced to initials, or left exactly as written.
     *
     * The comma is the signal. "Petersen, Hans Uwe" is an inverted personal
     * name and becomes "Petersen, H. U."; "Institute of Science and
     * Technology" has no comma, is very likely a corporate author, and is used
     * verbatim. Guessing which word of an uninverted name is the surname is
     * wrong often enough — and invisibly enough — that not guessing is the
     * better rule. The form already recommends the inverted form, and the
     * README documents it.
     */
    private static function initials(string $name, string $gap): string
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
        $letters = array_map(
            static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)) . '.',
            $given
        );

        return $surname . ', ' . implode($gap, $letters);
    }

    /**
     * APA 7th edition, journal article.
     *
     * Petersen, H. U. (1988). Title : Subtitle. Arbejderhistorie, 31, 18–38.
     *
     * With no author the title takes the author slot, which is the APA rule
     * for an anonymous work — not a fallback invented here. With no year
     * anywhere the record says so: "(n.d.)".
     *
     * @param array<string,mixed> $row
     */
    public static function apa(array $row): string
    {
        $p = self::parts($row);
        $year = $p['year'] === '' ? 'n.d.' : $p['year'];
        // APA dates a newspaper or magazine article to the day or month it
        // carries; a journal keeps the year alone.
        if (($p['isNewspaper'] || $p['isMagazine']) && $p['month'] > 0) {
            $year .= ', ' . self::MONTH_NAMES[$p['month']] . ($p['day'] > 0 ? ' ' . $p['day'] : '');
        }

        $names = array_map(static fn (string $n): string => self::initials($n, ' '), $p['authors']);
        $authors = self::joinNames($names, ', ', ', & ', ' & ');

        // An anonymous work is alphabetised by its title, so the title takes
        // the author slot and the year follows it. Leaving the slot empty and
        // opening with "(2020)." would sort every untitled-author record
        // together under the same bracket.
        $out = '';
        if ($authors !== '') {
            $out .= $authors . ' (' . $year . '). ' . self::terminate($p['title']) . ' ';
        } else {
            $out .= self::terminate($p['title']) . ' (' . $year . '). ';
        }

        if ($p['container'] !== '') {
            $out .= $p['container'];
            if ($p['volume'] !== '') {
                $out .= ', ' . $p['volume'];
                if ($p['issue'] !== '') {
                    $out .= '(' . $p['issue'] . ')';
                }
            } elseif ($p['issue'] !== '') {
                $out .= ', ' . $p['issue'];
            }
            $span = self::span($p);
            if ($span !== '') {
                $out .= ', ' . $span;
            }
            $out .= '.';
        }
        if ($p['doi'] !== '') {
            $out .= ' https://doi.org/' . $p['doi'];
        }

        return self::clean($out);
    }

    /**
     * Harvard (author–date), journal article.
     *
     * Petersen, H.U. (1988) 'Title : Subtitle', Arbejderhistorie, (31), pp. 18–38.
     *
     * @param array<string,mixed> $row
     */
    public static function harvard(array $row): string
    {
        $p = self::parts($row);
        $year = $p['year'] === '' ? 'no date' : $p['year'];

        $names = array_map(static fn (string $n): string => self::initials($n, ''), $p['authors']);
        $authors = self::joinNames($names, ', ', ' and ', ' and ');

        // Same rule as APA for an anonymous work: the title leads, and it is
        // not put in quotation marks when it is standing in for the author.
        $out = '';
        if ($authors !== '') {
            $out .= $authors . ' (' . $year . ') ' . "'" . rtrim($p['title'], '.') . "'";
        } else {
            $out .= self::terminate($p['title']) . ' (' . $year . ')';
        }

        if ($p['container'] !== '') {
            $out .= ', ' . $p['container'];
            // Harvard puts the day and month of a newspaper or magazine after
            // its title: "Süddeutsche Zeitung, 28 September, p. 3".
            if (($p['isNewspaper'] || $p['isMagazine']) && $p['month'] > 0) {
                $out .= ', ' . ($p['day'] > 0 ? $p['day'] . ' ' : '') . self::MONTH_NAMES[$p['month']];
            }
            if ($p['volume'] !== '') {
                $out .= ', ' . $p['volume'];
            }
            if ($p['issue'] !== '') {
                $out .= ', (' . $p['issue'] . ')';
            }
            $span = self::span($p);
            if ($span !== '') {
                $out .= ', ' . (str_contains($span, '–') ? 'pp. ' : 'p. ') . $span;
            }
        }
        $out .= '.';
        if ($p['doi'] !== '') {
            $out .= ' doi:' . $p['doi'];
        }

        return self::clean($out);
    }

    /**
     * The page span as a citation prints it, with an en dash between the ends.
     *
     * @param array{pageStart:string,pageEnd:string} $p
     */
    private static function span(array $p): string
    {
        if ($p['pageStart'] === '') {
            return '';
        }

        return $p['pageEnd'] === '' ? $p['pageStart'] : $p['pageStart'] . '–' . $p['pageEnd'];
    }

    /**
     * Join credited names with the separators the style asks for, and cut a
     * very long list the way both styles do rather than printing forty names.
     *
     * @param list<string> $names
     */
    private static function joinNames(array $names, string $sep, string $last, string $pair): string
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
        if (count($names) > 20) {
            $head = array_slice($names, 0, 19);
            $tail = $names[count($names) - 1];

            return implode($sep, $head) . $sep . '…' . $sep . $tail;
        }
        $tail = array_pop($names);

        return implode($sep, $names) . $last . $tail;
    }

    /** A title ends in exactly one full stop, whatever punctuation it arrived with. */
    private static function terminate(string $title): string
    {
        if ($title === '') {
            return '';
        }

        return preg_match('/[.!?]$/u', $title) === 1 ? $title : $title . '.';
    }

    /**
     * RIS, for EndNote, Mendeley and Zotero.
     *
     * Two things about the format that are not optional and are easy to get
     * wrong. Lines are `TAG  - value` — tag, TWO spaces, hyphen, space — and
     * they end with CR LF, not LF: the specification says so and EndNote on
     * Windows is the strict reader. And a value may not contain a line break,
     * because a break starts a new tag; every value therefore goes through
     * clean(), which is also why an abstract pasted out of a PDF cannot
     * silently truncate the record at its first paragraph.
     *
     * @param array<string,mixed> $row
     * @param string $recordUrl the article's own page, when the caller has one
     * @param string $fileUrl   a PDF the reader may actually open, when public
     */
    public static function ris(array $row, string $recordUrl = '', string $fileUrl = ''): string
    {
        $p = self::parts($row);

        // NEWS and JOUR are the two the reference managers map to a periodical
        // article; GEN is what is left when the record does not say what it
        // came out of, and claiming JOUR there would assert a journal nobody
        // recorded.
        $type = 'GEN';
        if ($p['isNewspaper']) {
            $type = 'NEWS';
        } elseif ($p['container'] !== '') {
            $type = 'JOUR';
        }

        $lines = [['TY', $type]];
        foreach ($p['authors'] as $author) {
            $lines[] = ['AU', $author];
        }
        $lines[] = ['TI', $p['title']];
        foreach ([
            'T2' => $p['container'],
            'VL' => $p['volume'],
            'IS' => $p['issue'],
            'SP' => $p['pageStart'],
            'EP' => $p['pageEnd'],
            'PY' => $p['year'],
            'DA' => $p['month'] > 0
                ? sprintf('%s/%02d/%s/', $p['year'], $p['month'], $p['day'] > 0 ? sprintf('%02d', $p['day']) : '')
                : self::clean($row['data_pubblicazione_testo'] ?? ''),
            'SN' => $p['issn'],
            'DO' => $p['doi'],
        ] as $tag => $value) {
            if ($value !== '') {
                $lines[] = [$tag, $value];
            }
        }
        foreach ($p['keywords'] as $keyword) {
            $lines[] = ['KW', $keyword];
        }
        foreach (['AB' => $p['abstract'], 'LA' => $p['language'], 'UR' => self::clean($recordUrl), 'L1' => self::clean($fileUrl)] as $tag => $value) {
            if ($value !== '') {
                $lines[] = [$tag, $value];
            }
        }
        $lines[] = ['ER', ''];

        $out = '';
        foreach ($lines as [$tag, $value]) {
            $out .= $tag . '  - ' . self::clean($value) . "\r\n";
        }

        return $out;
    }

    /** The name the browser saves a RIS download under. */
    public static function fileName(int $id): string
    {
        return 'articolo-' . $id . '.ris';
    }
}
