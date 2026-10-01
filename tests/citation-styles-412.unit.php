<?php
// Citation styles for books, articles and chapters (#412): the "Cite" dialog.
// Run: php tests/citation-styles-412.unit.php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\CitationStyles;

$n = 0;
function check(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $n++;
    echo "OK {$label}\n";
}
function same(string $got, string $want, string $label): void
{
    check($got === $want, $label . ($got === $want ? '' : "\n   got:  {$got}\n   want: {$want}"));
}

// The book Uwe used to show the Swedish union catalogue's dialog (LIBRIS).
$libris = ['type' => 'book', 'editors' => ['Byström, Mikael', 'Frohnert, Pär'], 'year' => '2013',
    'title' => 'Reaching a state of hope : refugees, immigrants and the Swedish welfare state, 1930-2000',
    'publisher' => 'Nordic Academic Press', 'place' => 'Lund'];
same(CitationStyles::apa($libris)['text'],
    'Byström, M., & Frohnert, P. (Eds.). (2013). Reaching a state of hope : refugees, immigrants and the Swedish welfare state, 1930-2000. Nordic Academic Press.',
    'APA: an edited book, editors in the author slot as LIBRIS prints it');
same(CitationStyles::chicago($libris)['text'],
    'Byström, Mikael, and Pär Frohnert, eds. 2013. Reaching a state of hope : refugees, immigrants and the Swedish welfare state, 1930-2000. Lund: Nordic Academic Press.',
    'Chicago: first editor inverted, the second natural, then "eds."');
same(CitationStyles::mla($libris)['text'],
    'Byström, Mikael, and Pär Frohnert, editors. Reaching a state of hope : refugees, immigrants and the Swedish welfare state, 1930-2000. Nordic Academic Press, 2013.',
    'MLA: "editors", publisher then year');
same(CitationStyles::harvard($libris)['text'],
    'Byström, M. and Frohnert, P. (eds) (2013) Reaching a state of hope : refugees, immigrants and the Swedish welfare state, 1930-2000. Lund: Nordic Academic Press.',
    'Harvard: (eds), place and publisher');
check(str_contains(CitationStyles::apa($libris)['html'], '<i>Reaching a state of hope : refugees, immigrants and the Swedish welfare state, 1930-2000</i>'),
    'a book title is italic in the HTML version, and only the title');

// A book with authors and a second edition.
$book = ['type' => 'book', 'authors' => ['Eco, Umberto'], 'year' => '1980', 'title' => 'Il nome della rosa', 'publisher' => 'Bompiani', 'place' => 'Milano', 'edition' => '2'];
same(CitationStyles::apa($book)['text'], 'Eco, U. (1980). Il nome della rosa (2nd ed.). Bompiani.', 'APA: book with an edition');
same(CitationStyles::chicago($book)['text'], 'Eco, Umberto. 1980. Il nome della rosa. 2nd ed. Milano: Bompiani.', 'Chicago: book with an edition');
same(CitationStyles::mla($book)['text'], 'Eco, Umberto. Il nome della rosa. 2nd ed., Bompiani, 1980.', 'MLA: book with an edition');
same(CitationStyles::harvard($book)['text'], 'Eco, U. (1980) Il nome della rosa. 2nd edn. Milano: Bompiani.', 'Harvard: book with an edition');
check(!str_contains(CitationStyles::apa(['edition' => '1'] + $book)['text'], 'ed.)'), 'a first edition is never cited');
check(!str_contains(CitationStyles::apa(['edition' => 'Prima edizione'] + $book)['text'], 'edizione'),
    'an edition written as text is left out rather than printed in another language');
check(str_contains(CitationStyles::apa(['edition' => '3'] + $book)['text'], '(3rd ed.)')
    && str_contains(CitationStyles::apa(['edition' => '11'] + $book)['text'], '(11th ed.)')
    && str_contains(CitationStyles::apa(['edition' => '21'] + $book)['text'], '(21st ed.)'), 'edition ordinals');

// A journal article.
$article = ['type' => 'article', 'authors' => ['Petersen, Hans Uwe'], 'year' => '1988', 'title' => 'På sporet : faglig solidaritet',
    'container' => 'Arbejderhistorie', 'volume' => '12', 'issue' => '31', 'pageStart' => '18', 'pageEnd' => '38'];
same(CitationStyles::apa($article)['text'], 'Petersen, H. U. (1988). På sporet : faglig solidaritet. Arbejderhistorie, 12(31), 18–38.', 'APA: journal article');
same(CitationStyles::chicago($article)['text'], 'Petersen, Hans Uwe. 1988. “På sporet : faglig solidaritet.” Arbejderhistorie 12 (31): 18–38.', 'Chicago: journal article');
same(CitationStyles::mla($article)['text'], 'Petersen, Hans Uwe. “På sporet : faglig solidaritet.” Arbejderhistorie, vol. 12, no. 31, 1988, pp. 18–38.', 'MLA: journal article');
same(CitationStyles::harvard($article)['text'], "Petersen, H.U. (1988) 'På sporet : faglig solidaritet', Arbejderhistorie, 12(31), pp. 18–38.", 'Harvard: journal article');
same(CitationStyles::apa($article)['html'], 'Petersen, H. U. (1988). På sporet : faglig solidaritet. <i>Arbejderhistorie</i>, <i>12</i>(31), 18–38.',
    'APA HTML: the journal and the volume are italic, the issue is not');

// A newspaper article, dated to the day.
$news = ['type' => 'article', 'authors' => ['Hacke, Axel'], 'year' => '2026', 'month' => 9, 'day' => 28, 'isNewspaper' => true,
    'title' => 'Warum es erhellend sein kann', 'container' => 'Süddeutsche Zeitung', 'pageStart' => '3'];
same(CitationStyles::chicago($news)['text'], 'Hacke, Axel. 2026. “Warum es erhellend sein kann.” Süddeutsche Zeitung, September 28, 2026.', 'Chicago: newspaper, dated to the day');
same(CitationStyles::mla($news)['text'], 'Hacke, Axel. “Warum es erhellend sein kann.” Süddeutsche Zeitung, 28 Sept. 2026, p. 3.', 'MLA: newspaper, abbreviated month');
check(str_starts_with(CitationStyles::apa($news)['text'], 'Hacke, A. (2026, September 28). '), 'APA: newspaper, dated to the day');

// A chapter in an edited book.
$chapter = ['type' => 'chapter', 'authors' => ['Petersen, Hans Uwe'], 'editors' => ['Müller, Anna', 'Jensen, Per'], 'year' => '1991',
    'title' => 'Die Emigration', 'container' => 'Exil in Dänemark', 'pageStart' => '45', 'pageEnd' => '67',
    'publisher' => 'Museum Tusculanum', 'place' => 'København'];
same(CitationStyles::chicago($chapter)['text'],
    'Petersen, Hans Uwe. 1991. “Die Emigration.” In Exil in Dänemark, edited by Anna Müller and Per Jensen, 45–67. København: Museum Tusculanum.',
    'Chicago: chapter, "In Book, edited by"');
same(CitationStyles::mla($chapter)['text'],
    'Petersen, Hans Uwe. “Die Emigration.” Exil in Dänemark, edited by Anna Müller and Per Jensen, Museum Tusculanum, 1991, pp. 45–67.',
    'MLA: chapter, book then "edited by"');

// A chapter whose host volume has no title (#412 review): the editors and the
// pages are still cited in every style, and no punctuation is left dangling.
$untitledHost = ['container' => ''] + $chapter;
same(CitationStyles::chicago($untitledHost)['text'],
    'Petersen, Hans Uwe. 1991. “Die Emigration.” Edited by Anna Müller and Per Jensen, 45–67. København: Museum Tusculanum.',
    'Chicago: chapter without a host title keeps its editors and pages');
same(CitationStyles::harvard($untitledHost)['text'],
    "Petersen, H.U. (1991) 'Die Emigration', in Müller, A. and Jensen, P. (eds). København: Museum Tusculanum, pp. 45–67.",
    'Harvard: no space before the full stop when the host title is missing');
same(CitationStyles::mla($untitledHost)['text'],
    'Petersen, Hans Uwe. “Die Emigration.” Edited by Anna Müller and Per Jensen, Museum Tusculanum, 1991, pp. 45–67.',
    'MLA: "Edited by" is capitalised when it follows the title');
same(CitationStyles::apa($untitledHost)['text'],
    'Petersen, H. U. (1991). Die Emigration. In A. Müller & P. Jensen (Eds.) (pp. 45–67). Museum Tusculanum.',
    'APA: chapter without a host title keeps its editors and pages');
$bareChapter = ['container' => '', 'editors' => []] + $chapter;
same(CitationStyles::apa($bareChapter)['text'], 'Petersen, H. U. (1991). Die Emigration. (pp. 45–67). Museum Tusculanum.',
    'APA: the pages survive with neither a host title nor editors');
same(CitationStyles::chicago($bareChapter)['text'], 'Petersen, Hans Uwe. 1991. “Die Emigration.” 45–67. København: Museum Tusculanum.',
    'Chicago: the pages survive with neither a host title nor editors');
foreach (array_merge(CitationStyles::all($untitledHost), CitationStyles::all($bareChapter)) as $c) {
    check(!preg_match('/ [.,]/u', $c['text']), "{$c['key']}: no space before a full stop or comma");
    check(str_contains($c['text'], '45–67'), "{$c['key']}: the page range is never dropped");
    check(!str_contains($c['html'], '<i></i>'), "{$c['key']}: no empty italic segment");
}

// Names.
$many = static fn (int $count): array => array_map(static fn (int $i): string => "Author{$i}, A.", range(1, $count));
check(str_starts_with(CitationStyles::mla(['authors' => $many(3)] + $article)['text'], 'Author1, A., et al. '), 'MLA: three or more authors become "et al."');
check(str_contains(CitationStyles::chicago(['authors' => $many(3)] + $article)['text'], 'Author1, A., A. Author2, and A. Author3.'),
    'Chicago: one inverted name, the others natural, "and" before the last');
check(str_contains(CitationStyles::chicago(['authors' => $many(11)] + $article)['text'], 'A. Author7, et al.'), 'Chicago: beyond ten, seven and "et al."');
check(str_contains(CitationStyles::apa(['authors' => $many(22)] + $article)['text'], ', …, Author22, A.'), 'APA: beyond twenty, nineteen, an ellipsis and the last');
check(str_starts_with(CitationStyles::apa(['authors' => ['Institute of Science and Technology']] + $article)['text'], 'Institute of Science and Technology (1988)'),
    'a corporate author is never reordered or initialised');
check(str_starts_with(CitationStyles::mla(['authors' => ['Institute of Science and Technology']] + $article)['text'], 'Institute of Science and Technology. '),
    'nor in MLA');

// Missing parts are the normal case.
$bare = ['type' => 'book', 'title' => 'Senza autore'];
same(CitationStyles::apa($bare)['text'], 'Senza autore. (n.d.).', 'APA: no author and no year');
same(CitationStyles::chicago($bare)['text'], 'Senza autore. n.d.', 'Chicago: no author and no year');
same(CitationStyles::mla($bare)['text'], 'Senza autore.', 'MLA: prints what there is and nothing else');
check(str_contains(CitationStyles::chicago(['title' => 'Why?'] + $article)['text'], '“Why?”'), 'a question mark is not followed by a second full stop');

// HTML is escaped; the text is not.
$escaped = CitationStyles::apa(['type' => 'book', 'authors' => ['<b>Evil</b>, A'], 'title' => 'Tom & Jerry <script>', 'year' => '2000']);
check(str_contains($escaped['html'], '&lt;b&gt;Evil&lt;/b&gt;') && str_contains($escaped['html'], '<i>Tom &amp; Jerry &lt;script&gt;</i>'),
    'the HTML version escapes every value and adds only <i>');
check(str_contains($escaped['text'], 'Tom & Jerry <script>'), 'the text version is plain text');
check(CitationStyles::apa(['type' => 'book', 'title' => "Due\nrighe"])['text'] === 'Due righe. (n.d.).', 'a line break inside a field is collapsed');

// all(): every style, in display order, each with text and html.
$all = CitationStyles::all($libris);
check(array_column($all, 'key') === array_keys(CitationStyles::STYLES), 'all() returns every style in display order');
check(array_filter($all, static fn (array $c): bool => $c['text'] === '' || $c['html'] === '') === [], 'and none of them is empty');

echo "SUCCESS {$n} citation checks\n";
