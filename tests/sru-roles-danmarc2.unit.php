<?php
declare(strict_types=1);
/**
 * Who did what, read from real catalogue records (saved in tests/fixtures/sru),
 * and danMARC2, the Danish format, read through DBC OpenSearch.
 *
 * - LoC and K10plus describe the same Penguin "Crime and punishment". LoC's 700
 *   names David McDuff with no role at all (the title page says "translated …
 *   by David McDuff"); K10plus gives him "$e Übers. $4 oth". Both used to make
 *   the translator a second author.
 * - DBC's danMARC2 records put the translator in 720 *o ("Hanna Lützen *4 trl"),
 *   the writer of a teaching note in 700 *f, and the reader of an audiobook in
 *   700 *4 dkind. Before this, danMARC2 could not be read at all.
 * Offline: the client's fetch is replaced by the saved responses.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/z39-server/classes/SruClient.php';

use App\Support\EditionStatement;
use App\Support\PublicationPlace;
use Plugins\Z39Server\Classes\RelatorRoles;
use Plugins\Z39Server\Classes\SruClient;

$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL $label\n");
        exit(1);
    }
    $checks++;
    echo "OK $label\n";
}

final class FixtureSruClient extends SruClient
{
    public string $lastUrl = '';
    /** @param string $fixture a file in tests/fixtures/sru, or the XML itself */
    public function __construct(array $servers, private string $fixture)
    {
        parent::__construct($servers);
    }
    protected function fetchUrl(string $url): ?string
    {
        $this->lastUrl = $url;
        return str_starts_with(ltrim($this->fixture), '<')
            ? $this->fixture
            : (string) file_get_contents(__DIR__ . '/fixtures/sru/' . $this->fixture);
    }
}
function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/sru/' . $name);
}
function import(string $syntax, string $fixture, string $isbn, string $url = 'https://example.org/sru', string $index = 'isbn'): array
{
    $client = new FixtureSruClient([['name' => 'T', 'url' => $url, 'syntax' => $syntax, 'enabled' => true, 'indexes' => ['isbn' => $index]]], $fixture);
    $book = $client->searchByIsbn($isbn) ?? [];
    $book['_url'] = $client->lastUrl;
    return $book;
}

// ── Roles: codes, terms in several languages, the statement ─────────────────
check(RelatorRoles::resolve(['oth'], 'Übers.', null, 'McDuff, David', 'marc21') === 'translator', '"oth" says nothing: the German term "Übers." decides');
check(RelatorRoles::resolve(['aut', 'led'], null, null, 'X', 'marc21') === 'author' && RelatorRoles::resolve(['led', 'trl'], null, null, 'X', 'marc21') === 'translator',
    'danMARC2 "led" marks the main responsibility, the other code decides');
foreach (['Hrsg.' => 'editor', 'red.' => 'editor', 'redigeret af' => 'editor', 'traducteur' => 'translator', 'traductor' => 'translator', 'oversætter' => 'translator',
    'översättare' => 'translator', 'vertaler' => 'translator', 'illustrateur' => 'illustrator', 'ilustrador' => 'illustrator', 'Kolorist' => 'colorist',
    'pædagogisk note' => 'other', 'Vorwort' => 'other', 'éditeur scientifique' => 'editor', 'éditeur' => 'other', 'Komponist' => 'author', 'joint author' => 'author'] as $term => $role) {
    check(RelatorRoles::resolve([], $term, null, 'X', 'marc21') === $role, "term \"$term\" → $role");
}
check(RelatorRoles::resolve(['dkind'], null, null, 'X', 'marc21') === 'other' && RelatorRoles::resolve(['clr'], null, null, 'X', 'marc21') === 'colorist'
    && RelatorRoles::resolve(['dkmdt'], null, null, 'X', 'marc21') === 'editor', 'danMARC2 codes: reader of an audiobook, colorist, co-editor');
$stmt = 'Fyodor Dostoyevsky ; translated with an introduction and notes by David McDuff';
check(RelatorRoles::roleInStatement($stmt, 'McDuff, David') === 'translator' && RelatorRoles::roleInStatement($stmt, 'Dostoyevsky, Fyodor') === null,
    'the statement names the translator; the first author has no role word');
check(RelatorRoles::roleInStatement('edited by Anna Rossi, with illustrations by Luca Bianchi', 'Bianchi, Luca') === 'illustrator'
    && RelatorRoles::roleInStatement('edited by Anna Rossi, with illustrations by Luca Bianchi', 'Rossi, Anna') === 'editor',
    'each name takes the role words of its own clause');
check(RelatorRoles::roleInStatement('aus dem Englischen übersetzt von Klaus Fritz', 'Fritz, Klaus') === 'translator'
    && RelatorRoles::roleInStatement('a cura di Antonio Gagliardi', 'Antonio Gagliardi') === 'editor', 'German and Italian statements, inverted or direct names');
check(RelatorRoles::roleInStatement('Marianne Juhl, interview med Hanna Lützen', 'Lützen, Hanna') === null, 'an interview is not one of the roles: the name stays an author');
check(RelatorRoles::roleInStatement('Paolo Rossi ed Enrico Bianchi', 'Bianchi, Enrico') === null
    && RelatorRoles::roleInStatement('ed. by John Smith', 'Smith, John') === 'editor' && RelatorRoles::roleInStatement('tr. by Jane Doe', 'Doe, Jane') === 'translator',
    'in a statement the Italian "ed" ("and") is not an editor; "ed." and "tr." with their period are');
check(RelatorRoles::resolve(['aut', 'trl'], null, null, 'X', 'marc21') === 'author' && RelatorRoles::resolve(['xyz', 'trl'], null, null, 'X', 'marc21') === 'translator'
    && RelatorRoles::resolve(['xyz'], 'translator', null, 'X', 'marc21') === 'author',
    'the first known code decides; an unknown code does not hide a known one, and alone keeps the author');

// ── MARC 21: the same book from two catalogues ─────────────────────────────
$loc = import('marcxml', 'loc-marcxml-9780140449136.xml', '9780140449136');
check($loc['authors'] === ['Dostoyevsky, Fyodor'] && ($loc['translator'] ?? null) === 'McDuff, David', 'LoC: a 700 with no role is the translator the title page names');
check(($loc['edition'] ?? null) === 'Rev. ed.' && ($loc['place'] ?? null) === 'London' && $loc['pages'] === '671', 'LoC: "[Rev. ed.]." is "Rev. ed."; place and pages');
$k10 = import('marcxml', 'k10plus-marcxml-9780140449136.xml', '9780140449136');
check($k10['authors'] === ['Dostoevskij, Fëdor Michajlovič'] && ($k10['translator'] ?? null) === 'McDuff, David', 'K10plus: "$e Übers. $4 oth" is the translator');
check(($k10['place'] ?? null) === 'London' && ($k10['edition'] ?? null) === 'Reissued with revisions', 'K10plus: "London [u.a.]" is "London", the bracketed edition is unwrapped');

// ── danMARC2 through DBC OpenSearch ─────────────────────────────────────────
$dbcUrl = 'https://opensearch.addi.dk/b3.5_5.2/?agency=100200&profile=test';
$hp = import('dbc-opensearch', 'dbc-opensearch-9788702272451.xml', '9788702272451', $dbcUrl, 'term.isbn');
parse_str((string) parse_url($hp['_url'], PHP_URL_QUERY), $q);
check(($q['action'] ?? '') === 'search' && ($q['query'] ?? '') === 'term.isbn=9788702272451' && ($q['objectFormat'] ?? '') === 'marcxchange'
    && ($q['agency'] ?? '') === '100200' && !isset($q['operation']), 'DBC: an OpenSearch request, with the agency and profile of the configured URL');
check($hp['title'] === 'Harry Potter og de vises sten' && $hp['authors'] === ['Rowling, Joanne K.'], 'danMARC2: title from 245 *a, author from 100 *a/*h');
check(($hp['translator'] ?? null) === 'Hanna Lützen', 'danMARC2: the translator of 720 *o *4 trl');
check(!in_array('Haraldsted, Dorte', $hp['authors'], true), 'danMARC2: the writer of a teaching note (700 *f) is not an author');
check(($hp['edition'] ?? null) === '8. udgave' && ($hp['place'] ?? null) === 'Kbh.' && $hp['publisher'] === 'Gyldendal' && $hp['year'] === '2018',
    'danMARC2: edition, place without brackets, publisher and year');
check($hp['isbn13'] === '9788702272451' && $hp['pages'] === '355' && $hp['language'] === 'Dansk', 'danMARC2: ISBN, pages and language');
$audio = import('dbc-opensearch', 'dbc-opensearch-9788702173062.xml', '9788702173062', $dbcUrl, 'term.isbn');
check($audio['authors'] === ['Hoff, Kasper'] && !isset($audio['translator']), 'danMARC2: the reader of an audiobook (*4 dkind) is neither author nor translator');
check($audio['pages'] === '' && ($audio['collana'] ?? null) === 'Zonen' && ($audio['numero_serie'] ?? null) === '2', 'danMARC2: "62 min." is not a page count; series from 440');
$sru = import('danmarc2', 'dbc-opensearch-9788702272451.xml', '9788702272451');
parse_str((string) parse_url($sru['_url'], PHP_URL_QUERY), $q);
check(($q['recordSchema'] ?? '') === 'marcxchange' && ($sru['translator'] ?? null) === 'Hanna Lützen', 'danMARC2 over SRU asks for MARCXchange and reads the same record');
$auto = import('marcxchange', 'dbc-opensearch-9788702272451.xml', '9788702272451');
check(($auto['source'] ?? '') === 'Z39.50/SRU (danMARC2)' && ($auto['translator'] ?? null) === 'Hanna Lützen', 'a MARCXchange record that declares danMARC2 is read as danMARC2, not as UNIMARC');

// ── The searched ISBN, edition and place cleaners ──────────────────────────
// The same record listing another edition's ISBNs first, as many do (hardback, paperback)
$twoEditions = str_replace('<datafield tag="020" ind1=" " ind2=" ">', '<datafield tag="020" ind1=" " ind2=" "><subfield code="a">9780140444179</subfield></datafield><datafield tag="020" ind1=" " ind2=" ">', fixture('k10plus-marcxml-9780140449136.xml'));
$found = import('marcxml', $twoEditions, '9780140449136');
check($found['isbn13'] === '9780140449136' && $found['isbn10'] === '0140449132', 'the ISBN searched for wins over another edition listed first, with its ISBN-10');
$byTen = import('marcxml', $twoEditions, '0140449132');
check($byTen['isbn10'] === '0140449132' && $byTen['isbn13'] === '9780140449136', 'searched as ISBN-10, the ISBN-13 is its own, not the other edition\'s');
$absent = import('marcxml', $twoEditions, '9788807900389');
check($absent['isbn13'] === '9780140444179', 'a record that does not carry the searched ISBN keeps its own identifiers');
check(SruClient::pagesOf('1 vol. (308 p.)') === '308' && SruClient::pagesOf('671 p.') === '671' && SruClient::pagesOf('62 min.', false) === '',
    'pages: the number before "p.", not the volume count; no minutes');
check(EditionStatement::clean('[New ed.]') === 'New ed.' && EditionStatement::clean('2. ed. /') === '2. ed.', 'edition: brackets go, the period of "ed." stays');
check(PublicationPlace::clean('London [u.a.]') === 'London' && PublicationPlace::clean('[Kbh.]') === 'Kbh.' && PublicationPlace::clean('[London?]') === 'London'
    && PublicationPlace::clean('London [u.a.') === 'London' && PublicationPlace::clean('[Kbh.') === 'Kbh.',
    'place: "[u.a.]" and enclosing brackets go, a half bracket is never left');

echo "SUCCESS $checks checks\n";
