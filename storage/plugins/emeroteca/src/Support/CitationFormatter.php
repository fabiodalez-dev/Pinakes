<?php
declare(strict_types=1);

namespace App\Plugins\Emeroteca\Support;

require_once __DIR__ . '/../Services/ContributionService.php';

use App\Plugins\Emeroteca\Services\ContributionService;
use App\Support\CitationStyles;

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
     *               abstract:string,isNewspaper:bool,isMagazine:bool,month:int,day:int,
     *               isAnthology:bool,editors:list<string>,publisher:string,place:string,isbn:string}
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
            // An article linked to a catalogued masthead but with no free-text
            // journal title is cited from that masthead (773 $t, RIS JF, ...).
            'container' => self::clean(($row['contenitore_titolo'] ?? '') !== '' ? $row['contenitore_titolo'] : ($row['testata_titolo'] ?? '')),
            'volume' => self::clean($row['volume'] ?? ''),
            'issue' => self::clean($row['numero'] ?? ''),
            'pageStart' => $pageStart,
            'pageEnd' => $pageEnd,
            'doi' => self::clean($row['doi'] ?? ''),
            'issn' => self::clean(($row['issn'] ?? '') !== '' ? $row['issn'] : ($row['testata_issn'] ?? '')),
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
            // A chapter in an anthology: the host is a book, with editors, a
            // publisher and a place instead of a volume and an issue.
            'isAnthology' => ($row['contenitore_tipo'] ?? '') === 'antologia',
            'editors' => ContributionService::authorList(
                isset($row['contenitore_curatori']) ? (string) $row['contenitore_curatori'] : null
            ),
            'publisher' => self::clean($row['contenitore_editore'] ?? ''),
            'place' => self::clean($row['contenitore_luogo'] ?? ''),
            'isbn' => self::clean($row['isbn'] ?? ''),
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
     * The article as the shared citation styles read it (App\Support\CitationStyles):
     * an article, or a chapter when the host is an anthology.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function record(array $row): array
    {
        $p = self::parts($row);

        return [
            'type' => $p['isAnthology'] ? 'chapter' : 'article',
            'authors' => $p['authors'],
            'editors' => $p['editors'],
            'year' => $p['year'],
            'month' => $p['month'],
            'day' => $p['day'],
            'title' => $p['title'],
            'container' => $p['container'],
            'volume' => $p['volume'],
            'issue' => $p['issue'],
            'pageStart' => $p['pageStart'],
            'pageEnd' => $p['pageEnd'],
            'doi' => $p['doi'],
            'publisher' => $p['publisher'],
            'place' => $p['place'],
            'isNewspaper' => $p['isNewspaper'],
            'isMagazine' => $p['isMagazine'],
        ];
    }

    /**
     * Every citation style the catalogue offers, for the "Cite" dialog.
     *
     * @param array<string,mixed> $row
     * @return list<array{key:string,label:string,text:string,html:string}>
     */
    public static function all(array $row): array
    {
        return CitationStyles::all(self::record($row));
    }

    /**
     * APA 7th edition, as plain text.
     *
     * Petersen, H. U. (1988). Title : Subtitle. Arbejderhistorie, 31, 18–38.
     *
     * @param array<string,mixed> $row
     */
    public static function apa(array $row): string
    {
        return CitationStyles::apa(self::record($row))['text'];
    }

    /**
     * Harvard (author–date), as plain text.
     *
     * Petersen, H.U. (1988) 'Title : Subtitle', Arbejderhistorie, (31), pp. 18–38.
     *
     * @param array<string,mixed> $row
     */
    public static function harvard(array $row): string
    {
        return CitationStyles::harvard(self::record($row))['text'];
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
        if ($p['isAnthology']) {
            $type = 'CHAP';
        } elseif ($p['isNewspaper']) {
            $type = 'NEWS';
        } elseif ($p['container'] !== '') {
            $type = 'JOUR';
        }

        $lines = [['TY', $type]];
        foreach ($p['authors'] as $author) {
            $lines[] = ['AU', $author];
        }
        $lines[] = ['TI', $p['title']];
        foreach ($p['editors'] as $editor) {
            $lines[] = ['A2', $editor];
        }
        foreach ([
            'T2' => $p['container'],
            // JF (journal, full name) as well as T2: some importers read only one
            // of them, and danish union records (Uwe's #412 example) use JF.
            'JF' => $type === 'JOUR' || $type === 'NEWS' ? $p['container'] : '',
            'VL' => $p['volume'],
            'IS' => $p['issue'],
            'SP' => $p['pageStart'],
            'EP' => $p['pageEnd'],
            'PY' => $p['year'],
            // RIS DA is YYYY/MM/DD/other: a free-text date ("Nr. 31 (1988)")
            // goes into the "other" part, never in place of the year.
            'DA' => $p['month'] > 0
                ? sprintf('%s/%02d/%s/', $p['year'], $p['month'], $p['day'] > 0 ? sprintf('%02d', $p['day']) : '')
                : ($p['year'] !== '' ? $p['year'] . '///' . self::clean($row['data_pubblicazione_testo'] ?? '') : ''),
            // A chapter's host is a book: its identifier is the ISBN or
            // nothing, never an ISSN left over from a journal record.
            'SN' => $p['isAnthology'] ? $p['isbn'] : $p['issn'],
            'PB' => $p['publisher'],
            'CY' => $p['place'],
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
