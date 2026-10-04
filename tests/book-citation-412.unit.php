<?php
declare(strict_types=1);
/**
 * BookCitation (#412): a book as a reference. The Cite dialog and the RIS
 * download start from the same input, so the place of publication (core
 * 0.7.89) reaches Chicago and Harvard, and the RIS file carries it as CY.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
if (!function_exists('__')) { function __(string $s, mixed ...$a): string { return $a ? sprintf($s, ...$a) : $s; } }

use App\Support\BookCitation;
use App\Support\CitationStyles;

$count = 0;
function checkBookCitation(bool $ok, string $label): void { global $count; if (!$ok) { throw new RuntimeException($label); } $count++; echo "OK $label\n"; }

$book = ['id' => 7, 'titolo' => 'Reaching a state of hope', 'sottotitolo' => 'refugees, immigrants and the Swedish welfare state, 1930-2000',
    'anno_pubblicazione' => 2013, 'editore' => 'Nordic Academic Press', 'luogo_pubblicazione' => 'Lund', 'edizione' => '',
    'isbn13' => '9789187121845', 'lingua' => 'eng', 'collana' => '', 'parole_chiave' => 'refugees; Sweden, welfare state'];
$authors = [
    ['nome' => 'Mikael Byström', 'ruolo' => 'curatore'],
    ['nome' => 'Pär Frohnert', 'ruolo' => 'curatore'],
    ['nome' => 'Some Translator', 'ruolo' => 'traduttore'],
];
$input = BookCitation::input($book, $authors);
checkBookCitation($input['place'] === 'Lund' && $input['publisher'] === 'Nordic Academic Press', 'the place and the publisher come from the record');
checkBookCitation($input['authors'] === [] && count($input['editors']) === 2, 'editors are editors, and a translator is not part of the reference');
checkBookCitation(str_contains($input['title'], ' : refugees'), 'the subtitle follows the title');

$styles = CitationStyles::all($input);
$text = static function (array $styles, string $key): string {
    foreach ($styles as $style) {
        if (($style['key'] ?? '') === $key) { return (string) ($style['text'] ?? ''); }
    }
    return '';
};
checkBookCitation(str_contains($text($styles, 'chicago'), 'Lund: Nordic Academic Press'), 'Chicago cites "Place: Publisher"');
checkBookCitation(str_contains($text($styles, 'harvard'), 'Lund'), 'Harvard cites the place');
$noPlace = CitationStyles::all(BookCitation::input(['luogo_pubblicazione' => ''] + $book, $authors));
checkBookCitation(!str_contains($text($noPlace, 'chicago'), ': Nordic') && str_contains($text($noPlace, 'chicago'), 'Nordic Academic Press'), 'without a place there is no dangling colon');

$ris = BookCitation::ris($input, $book, 'https://example.org/b/7');
checkBookCitation(str_starts_with($ris, "TY  - BOOK\r\n") && str_ends_with($ris, "ER  - \r\n"), 'RIS is one BOOK record');
foreach (["A2  - Byström, M.", "TI  - Reaching a state of hope : refugees", "PY  - 2013\r\n", "PB  - Nordic Academic Press\r\n", "CY  - Lund\r\n", "SN  - 9789187121845\r\n", "UR  - https://example.org/b/7\r\n", "KW  - refugees\r\n", "KW  - welfare state\r\n"] as $expected) {
    checkBookCitation(str_contains($ris, $expected) || str_contains($ris, str_replace('Byström, M.', 'Byström, Mikael', $expected)), "RIS carries " . trim($expected));
}
$multiline = BookCitation::ris(BookCitation::input(['titolo' => "Line one\nTY  - JOUR"] + $book, $authors), $book);
checkBookCitation(substr_count($multiline, "TY  - ") === 1, 'a line break inside a value cannot start a new tag');
echo "SUCCESS $count book citation checks\n";
