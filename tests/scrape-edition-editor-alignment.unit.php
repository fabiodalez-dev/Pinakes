<?php
declare(strict_types=1);
/**
 * Every book source hands the form the same keys for edition, place of
 * publication and contributors: `edition`, `place`, `editor` (a list),
 * `translator` and `illustrator` (one name each). The book form fills
 * Edizione, Luogo di pubblicazione and the editors picker from them.
 *
 * Before this, an SRU record's 700 entries all became authors, so a translator
 * or an editor was catalogued as an author of the book; SBN, Open Library and
 * the external API dropped edition, place and editors altogether, and the
 * external API sent its cover and date under names the form never read.
 * Offline: every case runs on a record, not on the network.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/z39-server/classes/SruClient.php';
require_once $root . '/storage/plugins/z39-server/classes/SbnClient.php';
require_once $root . '/storage/plugins/open-library/OpenLibraryPlugin.php';
require_once $root . '/storage/plugins/api-book-scraper/ApiBookScraperPlugin.php';

use Plugins\Z39Server\Classes\SruClient;
use Plugins\Z39Server\Classes\SbnClient;

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
function call(object $obj, string $method, mixed ...$args): mixed
{
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invoke($obj, ...$args);
}
function xpathOf(string $xml): DOMXPath
{
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('marc', 'http://www.loc.gov/MARC21/slim');
    $xp->registerNamespace('mxc', 'info:lc/xmlns/marcxchange-v2');
    return $xp;
}

// ── Relator mapping ─────────────────────────────────────────────────────────
check(SruClient::contributorRole('edt', null, 'marc21') === 'editor', 'MARC 21 edt is an editor');
check(SruClient::contributorRole('trl', 'author', 'marc21') === 'translator', 'the relator code wins over the term');
check(SruClient::contributorRole(null, 'editor.', 'marc21') === 'editor', 'the term "editor." is an editor');
check(SruClient::contributorRole(null, 'a cura di', 'marc21') === 'editor', 'the Italian "a cura di" is an editor');
check(SruClient::contributorRole(null, 'traduttore', 'marc21') === 'translator', 'the Italian traduttore is a translator');
check(SruClient::contributorRole(null, 'editore', 'marc21') === 'other', '"editore" (publisher) is not an editor');
check(SruClient::contributorRole(null, 'writer of introduction', 'marc21') === 'other', 'a writer of the introduction is not an author of the book');
check(SruClient::contributorRole(null, null, 'marc21') === 'author', 'no relator keeps the author, as before');
check(SruClient::contributorRole('340', null, 'unimarc') === 'editor' && SruClient::contributorRole('730', null, 'unimarc') === 'translator'
    && SruClient::contributorRole('440', null, 'unimarc') === 'illustrator' && SruClient::contributorRole('070', null, 'unimarc') === 'author',
    'UNIMARC IFLA codes 340/730/440/070');

// ── SRU, MARC 21 ────────────────────────────────────────────────────────────
$sru = new SruClient();
$marc = xpathOf(<<<'XML'
<record xmlns="http://www.loc.gov/MARC21/slim">
  <datafield tag="245" ind1="1" ind2="0"><subfield code="a">Se questo è un uomo /</subfield></datafield>
  <datafield tag="100" ind1="1" ind2=" "><subfield code="a">Levi, Primo,</subfield></datafield>
  <datafield tag="250" ind1=" " ind2=" "><subfield code="a">2. ed. /</subfield></datafield>
  <datafield tag="264" ind1=" " ind2="1"><subfield code="a">Torino :</subfield><subfield code="b">Einaudi,</subfield><subfield code="c">2014</subfield></datafield>
  <datafield tag="700" ind1="1" ind2=" "><subfield code="a">Segre, Cesare,</subfield><subfield code="e">editor.</subfield></datafield>
  <datafield tag="700" ind1="1" ind2=" "><subfield code="a">Rossi, Mario,</subfield><subfield code="4">trl</subfield></datafield>
  <datafield tag="700" ind1="1" ind2=" "><subfield code="a">Bianchi, Anna,</subfield><subfield code="e">illustrator</subfield></datafield>
  <datafield tag="700" ind1="1" ind2=" "><subfield code="a">Verdi, Luca,</subfield></datafield>
</record>
XML);
$book = call($sru, 'parseMarcXml', $marc);
check($book['authors'] === ['Levi, Primo', 'Verdi, Luca'], 'MARC 21: an added entry without a relator is an author, the others are not');
check(($book['editor'] ?? null) === ['Segre, Cesare'], 'MARC 21: 700 $e editor becomes the editor');
check(($book['translator'] ?? null) === 'Rossi, Mario' && ($book['illustrator'] ?? null) === 'Bianchi, Anna', 'MARC 21: translator ($4 trl) and illustrator ($e)');
check(($book['edition'] ?? null) === '2. ed.' && ($book['place'] ?? null) === 'Torino', 'MARC 21: edition from 250 $a, place from 264 $a, ISBD punctuation trimmed');

// ── SRU, UNIMARC (marcxchange) ──────────────────────────────────────────────
$uni = xpathOf(<<<'XML'
<record xmlns="info:lc/xmlns/marcxchange-v2">
  <datafield tag="200" ind1="1" ind2=" "><subfield code="a">Il fu Mattia Pascal</subfield></datafield>
  <datafield tag="205" ind1=" " ind2=" "><subfield code="a">13. ed.</subfield></datafield>
  <datafield tag="210" ind1=" " ind2=" "><subfield code="a">Milano</subfield><subfield code="c">Feltrinelli</subfield><subfield code="d">2013</subfield></datafield>
  <datafield tag="700" ind1=" " ind2="1"><subfield code="a">Pirandello</subfield><subfield code="b">Luigi</subfield><subfield code="4">070</subfield></datafield>
  <datafield tag="702" ind1=" " ind2="1"><subfield code="a">Gagliardi</subfield><subfield code="b">Antonio</subfield><subfield code="4">340</subfield></datafield>
</record>
XML);
$book = call($sru, 'parseMarcxchangeXml', $uni);
check($book['authors'] === ['Pirandello, Luigi'], 'UNIMARC: the editor (702 $4 340) is no longer an author');
check(($book['editor'] ?? null) === ['Gagliardi, Antonio'], 'UNIMARC: 702 $4 340 becomes the editor');
check(($book['edition'] ?? null) === '13. ed.' && ($book['place'] ?? null) === 'Milano', 'UNIMARC: edition from 205 $a, place from 210 $a');

// ── SBN (records as the OPAC API returns them) ──────────────────────────────
$sbn = new SbnClient(5, false);
$contrib = call($sbn, 'extractContributors', ['nomi' => [
    '[Autore]  Pirandello, Luigi <1867-1936>',
    '[Curatore]  Gagliardi, Antonio <1943- >',
    "[Autore dell'introduzione]  Perrella, Silvio",
]]);
check($contrib === ['editor' => ['Antonio Gagliardi']], 'SBN: [Curatore] is the editor, the introduction writer is left out');
$contrib = call($sbn, 'extractContributors', ['nomi' => [
    '[Autore]  Hugo, Victor <1802-1885>',
    '[Traduttore]  Picchi, Mario <1927-1996>, anche introduzione',
]]);
check($contrib === ['translator' => 'Mario Picchi'], 'SBN: [Traduttore] is the translator, without the note after the dates');
$full = call($sbn, 'parseFullRecord', ['titolo' => 'Libro / Autore', 'pubblicazione' => 'Torino : Einaudi, 2014', 'edizione' => '3. ed.',
    'nomi' => ['[Curatore]  Gagliardi, Antonio <1943- >']]);
check(($full['edition'] ?? null) === '3. ed' && ($full['place'] ?? null) === 'Torino' && ($full['editor'] ?? null) === ['Antonio Gagliardi'],
    'SBN: a full record carries edition, place and editor');

// ── Open Library ────────────────────────────────────────────────────────────
$ol = new App\Plugins\OpenLibrary\OpenLibraryPlugin(null, null);
check(call($ol, 'extractPlace', ['publish_places' => ['Torino', ['name' => 'Milano'], ' ']]) === 'Torino, Milano', 'Open Library: places as strings or objects');
check(call($ol, 'extractContributors', ['contributions' => ['Tony Ross (Illustrator)', 'Segre, Cesare, writer of supplementary textual content']])
    === ['illustrator' => 'Tony Ross'], 'Open Library: "Name (Illustrator)" is the illustrator, an unrecognised role is left out');
check(call($ol, 'extractContributors', ['contributors' => [['role' => 'Editor', 'name' => 'Anna Bianchi'], ['role' => 'Translator', 'name' => 'Mario Rossi']]])
    === ['editor' => ['Anna Bianchi'], 'translator' => 'Mario Rossi'], 'Open Library: contributors with a role');

// ── External API (api-book-scraper) ─────────────────────────────────────────
$api = (new ReflectionClass(\ApiBookScraperPlugin::class))->newInstanceWithoutConstructor();
$mapped = call($api, 'mapApiResponse', ['data' => ['titolo' => 'Libro', 'edizione' => '2', 'luogo_pubblicazione' => 'Lund',
    'curatori' => ['Anna Bianchi'], 'traduttore' => 'Mario Rossi', 'copertina_url' => 'https://example.org/c.jpg', 'data_pubblicazione' => '2013']], '9788807900389');
check(($mapped['edition'] ?? null) === '2' && ($mapped['place'] ?? null) === 'Lund' && ($mapped['editor'] ?? null) === ['Anna Bianchi']
    && ($mapped['translator'] ?? null) === 'Mario Rossi', 'External API: edition, place, editors and translator, in Italian or English names');
check(($mapped['image'] ?? null) === 'https://example.org/c.jpg' && ($mapped['pubDate'] ?? null) === '2013', 'External API: cover and date reach the form as image and pubDate');
check(!array_key_exists('illustrator', $mapped), 'External API: an absent field is not sent empty');

// ── The form reads them ─────────────────────────────────────────────────────
$form = (string) file_get_contents($root . '/app/Views/libri/partials/book_form.php');
check(str_contains($form, "['edition', 'edizione'], ['place', 'luogo_pubblicazione']") && str_contains($form, '__contributorPickers.curatori.addName'),
    'the book form fills Edizione, Luogo di pubblicazione and the editors picker');

echo "SUCCESS $checks checks\n";
