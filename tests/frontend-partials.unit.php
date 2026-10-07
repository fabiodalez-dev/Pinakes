<?php
declare(strict_types=1);

/**
 * The shared public-page partials in app/Views/frontend/partials/ render the
 * markup (and accessibility attributes) their callers rely on.
 *
 * Static: no database. Each partial is included with minimal inputs and the
 * emitted HTML is inspected with DOM/XPath rather than the inputs.
 *
 * Run:  php tests/frontend-partials.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/app/helpers.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

$partials = $root . '/app/Views/frontend/partials/';

/** Render a partial with the given variables in an isolated scope. */
$render = static function (string $file, array $vars) use ($partials): string {
    $render = static function (string $__file, array $__vars): string {
        extract($__vars, EXTR_SKIP);
        ob_start();
        include $__file;
        return (string) ob_get_clean();
    };
    return $render($partials . $file, $vars);
};

/** @return DOMXPath */
$xp = static function (string $html): DOMXPath {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>');
    libxml_clear_errors();
    return new DOMXPath($doc);
};
$count = static fn(string $html, string $query): int => $xp($html)->query($query)->length;
$attr = static function (string $html, string $query, string $name) use ($xp): array {
    $out = [];
    foreach ($xp($html)->query($query) as $node) {
        $out[] = $node->getAttribute($name);
    }
    return $out;
};

echo "A. breadcrumb\n";

$items = [
    ['label' => 'Home', 'href' => '/'],
    ['label' => 'Emeroteca', 'href' => '/emeroteca'],
    ['label' => 'Pagina corrente', 'href' => '/ignored'],
];
foreach (['hero', 'book'] as $variant) {
    $html = $render('breadcrumb.php', ['breadcrumbItems' => $items, 'breadcrumbVariant' => $variant]);
    $check($count($html, '//li[@aria-current="page"]') === 1, "[{$variant}] exactly one aria-current=\"page\" item");
    $check($count($html, '//li[@aria-current="page"]/a') === 0, "[{$variant}] the current item is not a link");
    $check(str_contains((string) ($xp($html)->query('//li[@aria-current="page"]')->item(0)?->textContent ?? ''), 'Pagina corrente'),
        "[{$variant}] and it is the LAST item");
    $check($count($html, '//li/a') === 2, "[{$variant}] the earlier items are links");
    $check($attr($html, '//li/a', 'href') === ['/', '/emeroteca'], "[{$variant}] with their own hrefs");
    $check($count($html, '//nav[@aria-label]') === 1, "[{$variant}] the nav has an accessible name");
}
$hero = $render('breadcrumb.php', ['breadcrumbItems' => $items]);
$book = $render('breadcrumb.php', ['breadcrumbItems' => $items, 'breadcrumbVariant' => 'book']);
$check(!str_contains($hero, 'book-breadcrumb'), 'the default variant is not the book one');
$check($count($book, '//nav[contains(concat(" ", normalize-space(@class), " "), " book-breadcrumb ")]') === 1,
    'the book variant uses class "book-breadcrumb"');
$check(trim($render('breadcrumb.php', ['breadcrumbItems' => []])) === '', 'no items renders nothing');

echo "\nB. pagination\n";

$url = static fn(int $p): string => '/lista?page=' . $p;
$check(trim($render('pagination.php', ['paginationPage' => 1, 'paginationPages' => 1, 'paginationUrl' => $url])) === '',
    'a single page renders nothing');

$html = $render('pagination.php', ['paginationPage' => 3, 'paginationPages' => 10, 'paginationUrl' => $url]);
$numbers = [];
foreach ($xp($html)->query('//li[contains(@class,"page-item")]/a[not(@rel)]') as $a) {
    $numbers[] = trim($a->textContent);
}
$check($numbers === ['1', '2', '3', '4', '5'], 'page 3 of 10 shows numbers 1-5 (got ' . implode(',', $numbers) . ')');
$check($attr($html, '//a[@aria-current="page"]', 'href') === ['/lista?page=3'], 'the active number carries aria-current="page"');
$check($count($html, '//li[contains(@class,"active")]') === 1, 'and exactly one item is active');
$check($attr($html, '//a[@rel="prev"]', 'href') === ['/lista?page=2'], 'prev link has rel="prev" and points at page 2');
$check($attr($html, '//a[@rel="next"]', 'href') === ['/lista?page=4'], 'next link has rel="next" and points at page 4');
$check($count($html, '//a[@rel="prev"][@aria-label!=""]') === 1 && $count($html, '//a[@rel="next"][@aria-label!=""]') === 1,
    'prev and next have aria-labels');
$allHrefs = $attr($html, '//a', 'href');
$fromCallback = array_filter($allHrefs, static fn(string $h): bool => str_starts_with($h, '/lista?page='));
$check(count($allHrefs) === count($fromCallback) && count($allHrefs) === 7, 'every href (7) comes from the callback');

$first = $render('pagination.php', ['paginationPage' => 1, 'paginationPages' => 10, 'paginationUrl' => $url]);
$check($count($first, '//a[@rel="prev"]') === 0 && $count($first, '//a[@rel="next"]') === 1, 'page 1 has no prev, has next');
$last = $render('pagination.php', ['paginationPage' => 10, 'paginationPages' => 10, 'paginationUrl' => $url]);
$check($count($last, '//a[@rel="next"]') === 0 && $count($last, '//a[@rel="prev"]') === 1, 'the last page has no next, has prev');
$check(str_contains($last, '>10<') && str_contains($last, '>6<') && !str_contains($last, '>5<'), 'the window slides to 6-10 at the end');

echo "\nC. filters-sidebar\n";

$html = $render('filters-sidebar.php', [
    'filterSearch' => ['action' => '/emeroteca', 'value' => 'abc', 'hidden' => ['tipo' => 'rivista', 'genere' => '', 'editore' => '0']],
    'filterSections' => [
        ['title' => 'Vuota', 'icon' => 'fa-x', 'options' => []],
        ['title' => 'Tipo', 'icon' => 'fa-tag', 'options' => [
            ['label' => 'Rivista', 'count' => 4, 'href' => '/e?tipo=rivista', 'active' => true],
            ['label' => 'Giornale', 'count' => 2, 'href' => '/e?tipo=giornale'],
        ]],
        ['title' => 'Iniziale', 'icon' => 'fa-font', 'grid' => true, 'options' => [
            ['label' => 'A', 'count' => 9, 'href' => '/e?lettera=A'],
            ['label' => 'B', 'count' => 1, 'href' => '/e?lettera=B', 'active' => true],
        ]],
    ],
    'filterClearHref' => '/emeroteca',
]);
$check(!str_contains($html, 'Vuota'), 'a section without options is skipped');
$check(str_contains($html, 'Tipo') && str_contains($html, 'Iniziale'), 'sections with options are rendered');
$check($count($html, '//form[@method="get"]') === 1, 'the search form method is get');
$check($attr($html, '//form', 'action') === ['/emeroteca'], 'and it posts to the given action');
$check($attr($html, '//form//input[@type="hidden"]', 'name') === ['tipo', 'editore'],
    'hidden inputs with empty values are omitted (the string "0" is kept)');
$check($count($html, '//a[contains(@class,"active")]') === 2 && $count($html, '//a[@aria-current="true"]') === 2,
    'active options carry class active and aria-current="true"');
$check($count($html, '//a[@aria-current="true"][contains(@class,"active")][@href="/e?tipo=rivista"]') === 1,
    'the active option is the one flagged');
$check($count($html, '//a[@href="/e?tipo=giornale"][not(@aria-current)]') === 1, 'an inactive option has no aria-current');
$check($count($html, '//div[contains(@class,"filter-options--grid")]') === 1, 'the grid section has class filter-options--grid');
$check($count($html, '//div[contains(@class,"filter-options--grid")]//*[contains(@class,"count-badge")]') === 0,
    'and shows no count badge');
$check($count($html, '//div[@class="filter-options"]//*[contains(@class,"count-badge")]') === 2,
    'list sections do show count badges');
$check($count($html, '//a[@class="clear-all-btn"][@href="/emeroteca"]') === 2, 'clear-all link is rendered when given, at the top and at the bottom');
$noClear = $render('filters-sidebar.php', ['filterSections' => []]);
$check($count($noClear, '//a[contains(@class,"clear-all-btn")]') === 0 && $count($noClear, '//form') === 0,
    'without clear href and search, neither is rendered');

echo "\nD. results-header\n";

$plain = $render('results-header.php', ['resultsCount' => 12, 'resultsLabel' => 'testate']);
$check($count($plain, '//*[contains(@class,"active-filters")]') === 0, 'no chips block without active filters');
$check(str_contains($plain, '<strong>12</strong>') && str_contains($plain, 'testate'), 'the count and label are shown');
$check($count($plain, '//a[contains(@class,"clear-filters-top-btn")]') === 0, 'no clear shortcut without a href');

$chips = $render('results-header.php', [
    'resultsCount' => 3, 'resultsLabel' => 'articoli', 'filterClearHref' => '/emeroteca',
    'activeFilters' => [
        ['label' => 'Tipo', 'value' => 'Rivista', 'removeHref' => '/e'],
        ['label' => 'Lettera', 'value' => 'A', 'removeHref' => '/e?tipo=rivista'],
    ],
]);
$check($count($chips, '//div[@class="active-filters"]') === 1, 'the chips block is rendered with active filters');
$check($count($chips, '//a[contains(@class,"filter-tag-remove")]') === 2, 'one remove link per chip');
$check($count($chips, '//a[contains(@class,"filter-tag-remove")][@aria-label!=""]') === 2, 'each remove link has an aria-label');
$check($attr($chips, '//a[contains(@class,"filter-tag-remove")]', 'href') === ['/e', '/e?tipo=rivista'], 'with the supplied hrefs');
$check($count($chips, '//a[contains(@class,"clear-filters-top-btn")][@href="/emeroteca"]') === 1, 'the clear shortcut appears when given');

echo "\nE. article-card\n";

$base = ['id' => 7, 'url' => '/emeroteca/articolo/7', 'title' => 'Un <titolo>', 'cover' => ''];
$html = $render('article-card.php', ['articleCard' => $base]);
$check($count($html, '//img') === 0 && $count($html, '//div[contains(@class,"pk-book__blank-title")]') === 1, "an empty cover renders the blank book with its title");
$check(str_contains($html, 'Un &lt;titolo&gt;') && !str_contains($html, 'Un <titolo>'), 'the title is escaped');
$check($attr($html, '//article', 'data-article-id') === ['7'], 'the card carries the article id');

$withCover = $render('article-card.php', ['articleCard' => ['cover' => '/uploads/emeroteca/c.jpg'] + $base]);
$check($attr($withCover, '//img', 'src') === ['/uploads/emeroteca/c.jpg'], 'a given cover is used instead of the placeholder');

$html = $render('article-card.php', ['articleCard' => $base + [
    'authors' => [
        ['name' => 'Anna Rossi', 'href' => '/autore/1'],
        ['name' => 'Bruno Neri', 'href' => null],
        ['name' => 'Carla Blu'],
    ],
    'meta' => [
        ['label' => 'La Rivista', 'href' => '/emeroteca/3'],
        ['label' => 'n. 4 (1999)', 'href' => '/emeroteca/fascicolo/9'],
        ['label' => 'pp. 3-20'],
    ],
]]);
$authorLinks = $attr($html, '//p[contains(@class,"book-author")]/a', 'href');
$check($authorLinks === ['/autore/1'], 'authors with an href become links, those without do not');
$check(str_contains((string) ($xp($html)->query('//p[contains(@class,"book-author")]')->item(0)?->textContent ?? ''), 'Bruno Neri'),
    'an author without href is still shown as text');
$check($attr($html, '//p[contains(@class,"book-meta")]/a', 'href') === ['/emeroteca/3', '/emeroteca/fascicolo/9'], 'meta parts with href are links');
$check(trim((string) ($xp($html)->query('//p[contains(@class,"book-meta")]')->item(0)?->textContent ?? '')) === 'La Rivista · n. 4 (1999) · pp. 3-20',
    "meta parts are joined with ' · '");
$check(!str_contains($html, 'onclick='), 'no inline onclick handler');

$text = $render('article-card.php', ['articleCard' => $base + ['authorsText' => 'A. Uno, B. Due', 'badge' => 'Articolo']]);
$check(str_contains($text, 'A. Uno, B. Due'), 'authorsText is shown when there are no linked authors');
$check($count($text, '//span[contains(@class,"status-article")]') === 1, 'the badge is rendered');
$check($count($text, '//p[contains(@class,"book-meta")]') === 0, 'no meta paragraph without meta');

echo "\nF. resource-hero\n";

$items2 = [['label' => 'Home', 'href' => '/'], ['label' => 'Titolo']];
$plain = $render('resource-hero.php', ['resourceCover' => '', 'resourceTitle' => 'T', 'breadcrumbItems' => $items2]);
$check($count($plain, '//img') === 0, 'without a cover there is no img');
$check($count($plain, '//section[contains(@class,"resource-hero--plain")]') === 1, 'the section has class resource-hero--plain');
$check($count($plain, '//section[@style]') === 0, 'and no style attribute');

$blur = $render('resource-hero.php', ['resourceCover' => '/uploads/x.jpg', 'resourceTitle' => 'T', 'breadcrumbItems' => $items2]);
$style = $attr($blur, '//section', 'style')[0] ?? '';
$check(str_contains($style, '--book-hero-cover') && str_contains($style, '/uploads/x.jpg'), 'with a cover the section style sets --book-hero-cover');
$check($count($blur, '//img') === 1 && $count($blur, '//section[contains(@class,"resource-hero--plain")]') === 0, 'with a cover there is an img and the section is not plain');

$noBlur = $render('resource-hero.php', ['resourceCover' => '/uploads/x.jpg', 'resourceCoverBlur' => false, 'resourceTitle' => 'T', 'breadcrumbItems' => $items2]);
$check($count($noBlur, '//section[@style]') === 0, 'with $resourceCoverBlur=false there is no style attribute');
$check($count($noBlur, '//img') === 1, 'but the image is still shown');

$quote = $render('resource-hero.php', ['resourceCover' => "/u/a'b.jpg", 'resourceTitle' => 'T', 'breadcrumbItems' => $items2]);
$check(!str_contains($attr($quote, '//section', 'style')[0] ?? '', "a'b"), 'a quote in the cover URL cannot break out of the style attribute value');
$check($count($blur, '//nav[contains(@class,"book-breadcrumb")]') === 1, 'the hero embeds the book-variant breadcrumb');

echo "\nG. catalog-hero, empty-state, resource-pager\n";

$html = $render('catalog-hero.php', ['heroTitle' => 'Emeroteca', 'heroSubtitle' => 'Sub', 'breadcrumbItems' => $items2]);
$check($count($html, '//h1[contains(@class,"catalog-title")]') === 1 && $count($html, '//li[@aria-current="page"]') === 1,
    'catalog-hero renders title and breadcrumb');
$html = $render('empty-state.php', ['emptyTitle' => 'Niente', 'emptyCtaHref' => '/x', 'emptyCtaLabel' => 'Torna']);
$check($count($html, '//a[@href="/x"]') === 1, 'empty-state renders its call to action when both href and label are given');
$check($count($render('empty-state.php', ['emptyTitle' => 'Niente', 'emptyCtaHref' => '/x']), '//a') === 0, 'and omits it without a label');
$check(trim($render('resource-pager.php', ['pagerUp' => ['href' => '/u', 'label' => 'Su']])) === '', 'resource-pager renders nothing without prev/next');
$html = $render('resource-pager.php', [
    'pagerPrev' => ['href' => '/p', 'label' => 'P'], 'pagerNext' => ['href' => '/n', 'label' => 'N'], 'pagerUp' => ['href' => '/u', 'label' => 'U'],
]);
$check($attr($html, '//a[@rel="prev"]', 'href') === ['/p'] && $attr($html, '//a[@rel="next"]', 'href') === ['/n'], 'resource-pager has rel prev/next links');

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
