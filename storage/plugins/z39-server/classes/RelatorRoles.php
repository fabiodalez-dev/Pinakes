<?php
declare(strict_types=1);

namespace Plugins\Z39Server\Classes;

/**
 * The part a name played in a book, read from a catalogue record.
 *
 * A record says it in up to three ways, tried in this order:
 *
 *  1. a relator code: MARC 21 and danMARC2 `$4`/`*4` (the Library of Congress
 *     list, plus the Danish dk… codes of danMARC2 appendix J), UNIMARC `$4`
 *     (IFLA numeric codes);
 *  2. a relator term: MARC 21 `$e`, danMARC2 `*b` ("red.", "redigeret af"),
 *     in any of the languages below;
 *  3. the statement of responsibility (MARC 21 245 `$c`, danMARC2 245 `*e`/`*f`,
 *     UNIMARC 200 `$f`/`$g`), for a record that only names the person in an
 *     added entry and says what they did on the title page:
 *     "translated with an introduction and notes by David McDuff".
 *
 * Only roles recognised with certainty take a name out of the authors: an
 * editor, a translator, an illustrator, a colorist, the writer of part of the
 * book (an introduction, a preface, notes) and the publisher. A joint author, a
 * composer, a compiler or a code not listed here stays an author, as every
 * added entry was before roles were read.
 */
final class RelatorRoles
{
    /** Roles the book form has a field for. */
    public const CONTRIBUTOR_ROLES = ['editor', 'translator', 'illustrator', 'colorist'];

    /**
     * Codes that say nothing about the part played: "lead" (danMARC2 marks the
     * main responsibility with it, next to the real code), "other",
     * "contributor", "associated name". The term or the statement decides.
     */
    private const NEUTRAL_CODES = ['led', 'oth', 'ctb', 'asn'];

    /** MARC 21 / danMARC2 codes. */
    private const LETTER_CODES = [
        'edt' => 'editor', 'edc' => 'editor', 'dkmdt' => 'editor',
        'trl' => 'translator',
        'ill' => 'illustrator',
        'clr' => 'colorist', 'dkfvl' => 'colorist',
        // Part of the book, or not its making at all
        'aui' => 'other', 'aft' => 'other', 'win' => 'other', 'wpr' => 'other', 'wfw' => 'other',
        'wac' => 'other', 'wst' => 'other', 'wat' => 'other', 'cwt' => 'other', 'cmm' => 'other',
        'dktek' => 'other', 'pbl' => 'other', 'isb' => 'other', 'prt' => 'other', 'bnd' => 'other',
        'nrt' => 'other', 'dkind' => 'other', 'cov' => 'other', 'bjd' => 'other', 'bkd' => 'other',
        'bdd' => 'other', 'pfr' => 'other', 'crr' => 'other', 'hnr' => 'other', 'dte' => 'other',
        'fmo' => 'other', 'dnr' => 'other', 'rev' => 'other', 'sad' => 'other', 'ths' => 'other',
    ];

    /** UNIMARC (IFLA) codes. */
    private const NUMERIC_CODES = [
        '340' => 'editor',
        '730' => 'translator',
        '440' => 'illustrator',
        '080' => 'other', '075' => 'other', '650' => 'other', '610' => 'other',
        '390' => 'other', '320' => 'other',
    ];

    /**
     * Terms, in English, Italian, German, French, Spanish, Danish, Norwegian,
     * Swedish, Dutch and Polish; full words and the usual abbreviations.
     * Tried in this order, so "translated with an introduction" is a
     * translation and "edited and translated" an edition.
     */
    private const TERMS = [
        'editor' => '(editor|editors|eds?|edited|edit|hrsg|herausgeber\w*|herausgegeben|red|redaktør|redaktor|redaktör|redacteur|rédacteur|redactor|redigeret|redigerad|redigert|curatore|curatrice|curat[oa]|a cura|cura di|éditeur scientifique|editeur scientifique|edición|edicion|ed lit)',
        'translator' => '(translat\w*|trans|tr|trad|traduttor\w*|tradott\w*|traduzione|traducteur|traductrice|traduit\w*|traduction|traductor\w*|traducid\w*|traducción|übers\w*|ubers\w*|oversætter\w*|oversat\w*|oversetter\w*|oversatt\w*|översätt\w*|översatt\w*|översättning|vertaler|vertaald|vertaling|tłumacz\w*|przeł\w*)',
        'illustrator' => '(illustrat\w*|ill|illus|ilustrad\w*|ilustraci\w*|illustrateur|illustratrice|illustratore|illustré\w*|illustriert\w*|illustreret|illustrerad|illustrert|illustratør|illustratör|tegninger|tegnet af|drawings)',
        'colorist' => '(colou?rist\w*|colou?red by|colou?rs by|kolorist\w*|colorista|coloriste|farvelægger|farvelagt)',
        'other' => '(introduction|introduced|preface|foreword|afterword|postface|commentary|supplementary|notes?|noter|efterskrift|annotat\w*|publisher|einleitung|vorwort|nachwort|kommentar\w*|anmerkungen|forord|efterord|indledning|kommenteret|förord|inledning|préface|présentation|introducción|prólogo|epílogo|introduzione|prefazione|postfazione|commento|editore|verlag|forlag|förlag|éditeur)',
    ];

    /**
     * The role of a name, from its codes, its term and the statement of
     * responsibility of the record, in that order. 'author' when none of them
     * says otherwise.
     *
     * @param list<string> $codes
     */
    public static function resolve(array $codes, ?string $term, ?string $statement, string $name, string $scheme): string
    {
        foreach ($codes as $code) {
            $code = self::normaliseCode($code);
            if ($code === '' || in_array($code, self::NEUTRAL_CODES, true)) {
                continue;
            }
            if ($scheme === 'unimarc' && isset(self::NUMERIC_CODES[$code])) {
                return self::NUMERIC_CODES[$code];
            }
            return self::LETTER_CODES[$code] ?? 'author';
        }
        $term = self::normaliseText((string) $term);
        if ($term !== '') {
            return self::roleOfText($term) ?? 'author';
        }
        if ($statement !== null && $name !== '') {
            return self::roleInStatement($statement, $name) ?? 'author';
        }
        return 'author';
    }

    /**
     * The role a statement of responsibility gives a name: the role words
     * between the name and the punctuation before it ("…; translated with an
     * introduction by David McDuff" → translator). Null when the statement
     * does not mention the name, or mentions it with no role word, as the
     * first author usually is ("Fyodor Dostoyevsky; …").
     */
    public static function roleInStatement(string $statement, string $name): ?string
    {
        $surname = self::surnameOf($name);
        if (mb_strlen($surname) < 2) {
            return null;
        }
        $text = self::normaliseText($statement, false);
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($surname), '/') . '(?![\p{L}\p{N}])/u', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $before = substr($text, 0, $m[0][1]);
        $segment = (string) preg_replace('/^.*[;,:\/=]/su', '', $before);
        return self::roleOfText($segment);
    }

    /** A relator code as the lists above spell it: "edt" from "Edt." or from a relator URI. */
    private static function normaliseCode(string $code): string
    {
        $code = (string) preg_replace('#^.*/#', '', trim($code));
        return strtolower(trim($code, " .\t"));
    }

    private static function normaliseText(string $text, bool $trim = true): string
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        // "hrsg." and "hrsg" alike; brackets and parentheses are not part of a role
        $text = (string) preg_replace('/[()\[\].]/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return $trim ? trim($text, ' ,;:') : $text;
    }

    private static function roleOfText(string $text): ?string
    {
        $text = self::normaliseText($text);
        if ($text === '') {
            return null;
        }
        foreach (self::TERMS as $role => $pattern) {
            if (preg_match('/(?<![\p{L}\p{N}])' . $pattern . '(?![\p{L}\p{N}])/u', $text) === 1) {
                return $role;
            }
        }
        return null;
    }

    /** "McDuff" from "McDuff, David" (inverted) or from "David McDuff" (direct). */
    private static function surnameOf(string $name): string
    {
        $name = trim($name, " ,.\t");
        if (str_contains($name, ',')) {
            return trim(explode(',', $name, 2)[0]);
        }
        $words = preg_split('/\s+/u', $name) ?: [];
        return (string) end($words);
    }
}
