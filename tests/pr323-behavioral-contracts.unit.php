<?php
declare(strict_types=1);

/**
 * Reusable, dependency-light behavioural contracts for PR #323.
 *
 * The fixtures are deliberately arrays/data providers rather than snapshots:
 * future formatter, description and circulation changes can add rows without
 * duplicating setup or coupling the assertions to attribute order.
 *
 * Run: php tests/pr323-behavioral-contracts.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/z39-server/classes/RecordFormatter.php';
require_once $root . '/storage/plugins/z39-server/classes/DublinCoreFormatter.php';
require_once $root . '/storage/plugins/z39-server/classes/MODSFormatter.php';
require_once $root . '/storage/plugins/z39-server/classes/MARCXMLFormatter.php';
require_once $root . '/storage/plugins/z39-server/classes/UNIMARCXMLFormatter.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
};

$render = static function (string $formatter, array $record): string {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $node = (new $formatter($doc))->format($record);
    return (string) $doc->saveXML($node);
};

$countText = static fn (string $xml, string $text): int => substr_count(
    html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8'),
    $text
);

echo "A. Shared rich-text projection\n";
$descriptionCases = [
    'null stays null' => [null, null],
    'empty stays empty' => ['', ''],
    'entities and non-breaking spaces decode' => ['<p>Hello&nbsp; world &amp; friends</p>', 'Hello world & friends'],
    'block and line breaks survive' => ['<div>One<br>Two</div><p>Three</p>', "One\nTwo\n\nThree"],
    'inline whitespace collapses' => ["Alpha   Beta\t Gamma", 'Alpha Beta Gamma'],
    'inline markup is removed' => ['<strong>Bold</strong> and <em>clear</em>', 'Bold and clear'],
];
foreach ($descriptionCases as $label => [$input, $expected]) {
    $check(\App\Support\DescriptionText::toPlain($input) === $expected, $label);
}
$bookRepoReflection = new ReflectionClass(\App\Models\BookRepository::class);
$bookRepo = $bookRepoReflection->newInstanceWithoutConstructor();
$bookProjection = $bookRepoReflection->getMethod('toPlainTextDescription');
foreach ($descriptionCases as $label => [$input, $expected]) {
    $check($bookProjection->invoke($bookRepo, $input) === $expected, "BookRepository delegates the {$label} case");
}

echo "\nB. Role-aware interoperable records\n";
$record = [
    'id' => 323,
    'titolo' => 'Interoperability fixture',
    'sottotitolo' => 'Every changed field',
    'autori' => 'Ignored Legacy Author',
    'contributors' => [
        ['nome' => 'Primary Person', 'ruolo' => 'principale'],
        ['nome' => 'Coauthor Person', 'ruolo' => 'co-autore'],
        ['nome' => 'Entity Translator', 'ruolo' => 'traduttore'],
    ],
    // Same role as the entity row must not be exported twice. Other legacy
    // roles remain a compatibility fallback and may contain multiple names.
    'traduttore' => 'Ignored Legacy Translator',
    'illustratore' => 'Illustrator One; Illustrator Two',
    'curatore' => 'Editor Person',
    'colorista' => 'Colorist Person',
    'publishers' => ['Primary House', 'Co-publisher House'],
    'editore' => 'Ignored Legacy Publisher',
    'anno_pubblicazione' => 2026,
    'lingua' => 'Italiano',
    'tipo_media' => 'audiolibro',
    'formato' => 'MP3',
    'isbn13' => '9781234567897',
    'ean' => '1234567890123',
    'classificazione_dewey' => '853.914',
    'scaffale' => 'S-7',
    'mensola' => '3',
    'collocazione' => 'LEGACY-LOCATION',
    'copertina_url' => '/uploads/copertine/pr323.jpg',
    'copie_totali' => 1,
    'copie_disponibili' => 0,
    'copies' => [
        ['numero_inventario' => 'INV-1', 'stato' => 'disponibile'],
        ['numero_inventario' => 'INV-2', 'stato' => 'prenotato'],
        ['numero_inventario' => 'INV-3', 'stato' => 'manutenzione'],
        ['numero_inventario' => 'INV-4', 'stato' => 'in_restauro'],
        ['numero_inventario' => 'INV-5', 'stato' => 'perso'],
        ['numero_inventario' => 'INV-6', 'stato' => 'danneggiato'],
        ['numero_inventario' => 'INV-7', 'stato' => 'in_trasferimento'],
    ],
];

$formatters = [
    'dc' => \Z39Server\DublinCoreFormatter::class,
    'mods' => \Z39Server\MODSFormatter::class,
    'marcxml' => \Z39Server\MARCXMLFormatter::class,
    'unimarcxml' => \Z39Server\UNIMARCXMLFormatter::class,
];
$xml = [];
foreach ($formatters as $format => $class) {
    $doc = new DOMDocument('1.0', 'UTF-8');
    $check(\Z39Server\RecordFormatter::create($format, $doc) instanceof $class, "factory resolves {$format}");
    $xml[$format] = $render($class, $record);
    foreach (['Primary Person', 'Coauthor Person', 'Entity Translator', 'Illustrator One', 'Illustrator Two', 'Editor Person', 'Colorist Person'] as $name) {
        // UNIMARC repeats the first creator in the 200$f statement of
        // responsibility and the controlled 700 access point by design.
        $expectedOccurrences = $format === 'unimarcxml' && $name === 'Primary Person' ? 2 : 1;
        $check($countText($xml[$format], $name) === $expectedOccurrences, "{$format} exports {$name} with the expected cardinality");
    }
    $check(!str_contains($xml[$format], 'Ignored Legacy Translator'), "{$format} suppresses legacy text when that role has entity rows");
    $check(!str_contains($xml[$format], 'Ignored Legacy Publisher'), "{$format} prefers the publisher collection over the legacy scalar");
}

$check(substr_count($xml['dc'], '<dc:creator>') === 2 && substr_count($xml['dc'], '<dc:contributor>') === 5, 'Dublin Core separates two creators from five contributors');
$check($countText($xml['dc'], 'Primary House') === 1 && $countText($xml['dc'], 'Co-publisher House') === 1, 'Dublin Core repeats publishers');
$check(str_contains($xml['dc'], '<dc:type>Sound</dc:type>'), 'Dublin Core maps audiobooks to DCMI Sound');
$check(str_contains($xml['dc'], 'Shelf: S-7, Level: 3') && !str_contains($xml['dc'], 'LEGACY-LOCATION'), 'Dublin Core uses current shelf/level before legacy collocazione');

$check(str_contains($xml['mods'], '<typeOfResource>sound recording-nonmusical</typeOfResource>'), 'MODS maps audiobook resource type');
$check(substr_count($xml['mods'], '<publisher>') === 2, 'MODS repeats primary and co-publisher');
foreach (['Reserved', 'Under maintenance', 'Under restoration', 'Lost', 'Damaged', 'In transit'] as $status) {
    $check(str_contains($xml['mods'], 'Status: ' . $status), "MODS maps copy state to {$status}");
}
$check(str_contains($xml['mods'], '/uploads/copertine/pr323.jpg') && !str_contains($xml['mods'], '<url displayLabel="Cover image" access="preview">/uploads/'), 'MODS exports a resolvable absolute cover URL');

$check(str_contains($xml['marcxml'], 'tag="100"') && substr_count($xml['marcxml'], 'tag="700"') === 6, 'MARCXML assigns main and added responsibility fields by role');
$marcDoc = new DOMDocument();
$marcDoc->loadXML($xml['marcxml']);
$marcXpath = new DOMXPath($marcDoc);
$check((int) $marcXpath->evaluate('count(//*[local-name()="datafield"][@tag="264"]/*[local-name()="subfield"][@code="b"])') === 2, 'MARCXML repeats publisher subfield 264$b');
$check(str_contains($xml['marcxml'], 'Total copies: 1, Available: 0'), 'MARCXML holdings summary trusts canonical book counters');
$check(str_contains($xml['marcxml'], '<subfield code="b">S-7</subfield>') && str_contains($xml['marcxml'], '<subfield code="c">Shelf 3</subfield>'), 'MARCXML holdings use current shelf and level');
foreach (['Reserved', 'Under maintenance', 'Under restoration', 'Lost', 'Damaged', 'In transit'] as $status) {
    $check(str_contains($xml['marcxml'], 'Status: ' . $status), "MARCXML maps copy state to {$status}");
}

$check(substr_count($xml['unimarcxml'], 'tag="210"') === 1 && substr_count($xml['unimarcxml'], '<subfield code="c">') >= 2, 'UNIMARC repeats publisher 210$c');
$check(str_contains($xml['unimarcxml'], 'tag="700"') && str_contains($xml['unimarcxml'], 'tag="701"') && substr_count($xml['unimarcxml'], 'tag="702"') === 5, 'UNIMARC assigns 700/701/702 from contributor roles');
foreach (['730', '440', '340', '410'] as $relator) {
    $check(str_contains($xml['unimarcxml'], '<subfield code="4">' . $relator . '</subfield>'), "UNIMARC exports relator {$relator}");
}

$fallbackRecord = [
    'id' => 324,
    'titolo' => 'Legacy fallback',
    'autori' => 'Legacy Primary; Legacy Coauthor',
    'traduttore' => 'Legacy Translator A; Legacy Translator B',
    'editore' => 'Legacy Publisher',
];
$fallbackDc = $render(\Z39Server\DublinCoreFormatter::class, $fallbackRecord);
$check(substr_count($fallbackDc, '<dc:creator>') === 2 && substr_count($fallbackDc, '<dc:contributor>') === 2, 'legacy strings split into reusable creator/contributor rows when entities are absent');
$check($countText($fallbackDc, 'Legacy Publisher') === 1, 'single legacy publisher remains a formatter fallback');

$unsupportedThrown = false;
try {
    \Z39Server\RecordFormatter::create('not-a-format', new DOMDocument());
} catch (Throwable) {
    $unsupportedThrown = true;
}
$check($unsupportedThrown, 'formatter factory rejects unsupported schemas');

echo "\nC. NCIP status matrix\n";
$ncipReflection = new ReflectionClass(\App\Plugins\NcipServer\NcipServerPlugin::class);
$ncip = $ncipReflection->newInstanceWithoutConstructor();
$buildLookup = $ncipReflection->getMethod('buildLookupItemResponse');
$ncipCases = [
    ['disponibile', 2, 'Available On Shelf'],
    ['disponibile', 0, 'Not Available'],
    ['prestato', 0, 'Checked Out'],
    ['prenotato', 0, 'On Hold'],
    ['perso', 0, 'Lost'],
    ['danneggiato', 0, 'Not Available'],
    ['non_disponibile', 0, 'Not Available'],
    ['unexpected', 1, 'Available On Shelf'],
    ['unexpected', 0, 'Not Available'],
];
foreach ($ncipCases as [$state, $available, $expected]) {
    $responseXml = $buildLookup->invoke($ncip, [
        'id' => 323,
        'titolo' => 'NCIP fixture',
        'stato' => $state,
        'copie_totali' => 2,
        'copie_disponibili' => $available,
    ]);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($responseXml);
    $status = $loaded ? (new DOMXPath($doc))->evaluate('string(//*[local-name()="CirculationStatus"])') : '';
    $check($loaded && $status === $expected, "NCIP maps {$state}/{$available} to {$expected}");
}

echo "\nD. Server-rendered public presentation\n";
$books = [
    ['id' => 1, 'titolo' => 'Available fixture', 'copie_disponibili' => 1, 'stato' => 'prestato'],
    ['id' => 2, 'titolo' => 'Reserved fixture', 'copie_disponibili' => 0, 'stato' => 'prenotato'],
    ['id' => 3, 'titolo' => 'Loaned fixture', 'copie_disponibili' => 0, 'stato' => 'prestato'],
    ['id' => 4, 'titolo' => 'Unavailable fixture', 'copie_disponibili' => 0, 'stato' => 'non_disponibile'],
];
ob_start();
include $root . '/app/Views/frontend/home-books-grid.php';
$gridHtml = (string) ob_get_clean();
foreach (['status-available', 'status-reserved', 'status-borrowed', 'status-unavailable'] as $class) {
    $check(substr_count($gridHtml, 'book-status-badge ' . $class) === 1, "home grid renders exactly one {$class} badge");
}

$section = ['title' => 'Hero fixture', 'subtitle' => 'Subtitle'];
$homeContent = [];
$heroTotalBooks = 42;
$heroAvailableBooks = 17;
ob_start();
include $root . '/app/Views/frontend/home-sections/hero.php';
$heroHtml = (string) ob_get_clean();
$check(substr_count($heroHtml, 'data-server-rendered="1"') === 2
    && preg_match('/>\s*42\s*</', $heroHtml) === 1
    && preg_match('/>\s*17\s*</', $heroHtml) === 1,
    'hero renders cached counters in the first response');

$homeSource = (string) file_get_contents($root . '/app/Views/frontend/home.php');
$layoutSource = (string) file_get_contents($root . '/app/Views/frontend/layout.php');
$latestSource = (string) file_get_contents($root . '/app/Views/frontend/home-sections/latest_books_title.php');
$check(str_contains($homeSource, "'fetchpriority' => 'high'") && str_contains($layoutSource, "'fetchpriority'"), 'home LCP preload survives the layout attribute allow-list');
$check(str_contains($latestSource, 'data-has-more=') && str_contains($homeSource, 'latestGrid.dataset.serverRendered'), 'latest-books SSR hands pagination state to the client fallback');
$check(str_contains($homeSource, "totalBooksEl.dataset.serverRendered === '1'"), 'stats XHR is skipped when server counters are present');

echo "\nE. API projection guards\n";
$publicApi = (string) file_get_contents($root . '/app/Controllers/PublicApiController.php');
$libriApi = (string) file_get_contents($root . '/app/Controllers/LibriApiController.php');
$bookDetail = (string) file_get_contents($root . '/app/Views/frontend/book-detail.php');
$mobileCatalog = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/CatalogController.php');
$check(substr_count($publicApi, "'illustratore' => \$row['illustratore']") === 1 && substr_count($publicApi, "'curatore' => \$row['curatore']") === 1, 'public API serializes illustrator and curator exactly once');
$check(str_contains($libriApi, 'GROUP BY l.id'), 'author-books API collapses duplicate relational joins per title');
$check(!str_contains($bookDetail, '$bookTranslator') && str_contains($bookDetail, 'entity-only policy'), 'book JSON-LD never fabricates one Person from joined legacy contributor text');
$check(str_contains($mobileCatalog, "'on_loan'  => \"SELECT 1 FROM prestiti WHERE libro_id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo')")
    && str_contains($mobileCatalog, "'reserved' => \"SELECT 1 FROM prestiti WHERE libro_id = ? AND attivo = 1 AND stato IN ('prenotato','da_ritirare')"),
    'mobile availability classifies da_ritirare as reserved while checked-out states keep precedence');

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
