<?php
declare(strict_types=1);

/**
 * Regression test for search relevance weighting.
 *
 * The denormalized `libri.search_index` FULLTEXT column folds title, subtitle,
 * authors, publisher, ISBN/EAN, keywords AND the description into one blob, so a
 * plain MATCH(search_index) ranks a term found only in the description exactly
 * like one found in the title. SearchIndexBuilder::buildRelevanceOrder() fixes
 * the ORDER: identifiers (120) > title (100) > author (60) > subtitle (40) >
 * publisher (25) > keywords (10) > description (3).
 *
 * This seeds three books that all match the same term but in different fields,
 * rebuilds their search_index, runs the exact WHERE + ORDER the search
 * controllers use, and asserts: title-match first, author-match second,
 * description-only-match last.
 *
 * Run:  php tests/search-relevance-weighting.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\SearchIndexBuilder;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

// A distinctive term that is long enough for the FULLTEXT min token size and
// appears nowhere else in the catalogue.
$run   = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 8);
$term  = 'zephyrq' . $run;                // >= innodb_ft_min_token_size (3)
$prefix = 'ZZSREL_' . $run;

$cleanup = static function () use ($db, $prefix, $term): void {
    $like = $prefix . '%';
    foreach ([
        'DELETE la FROM libri_autori la JOIN libri l ON l.id = la.libro_id WHERE l.titolo LIKE ?',
        'DELETE FROM libri WHERE titolo LIKE ?',
    ] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $db->prepare('DELETE FROM autori WHERE nome = ?');
    $authorName = 'Autore ' . $term;
    $stmt->bind_param('s', $authorName);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('DELETE FROM editori WHERE nome = ?');
    $publisherName = 'Editore ' . $term;
    $stmt->bind_param('s', $publisherName);
    $stmt->execute();
    $stmt->close();
};

$cleanup();
set_exception_handler(static function (\Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (\Throwable) {}
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

// ── Fixtures ────────────────────────────────────────────────────────────────
$makeBook = static function (string $suffix, string $titolo, string $descrizione) use ($db, $prefix): int {
    $full = $prefix . '_' . $suffix . ' ' . $titolo;
    $stmt = $db->prepare("INSERT INTO libri (titolo, descrizione, descrizione_plain, stato, copie_totali, copie_disponibili) VALUES (?, ?, ?, 'disponibile', 1, 1)");
    $plain = $descrizione;
    $stmt->bind_param('sss', $full, $descrizione, $plain);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

// Book T — term in the TITLE.
$bookTitle = $makeBook('T', $term . ' Chronicles', 'An entirely ordinary description with nothing special.');
// Book A — term only in the AUTHOR name.
$bookAuthor = $makeBook('A', 'Ordinary Chronicles', 'An entirely ordinary description with nothing special.');
// Book S — term only in the SUBTITLE.
$bookSubtitle = $makeBook('S', 'Ordinary Chronicles', 'An entirely ordinary description with nothing special.');
// Book P — term only in the PUBLISHER name.
$bookPublisher = $makeBook('P', 'Ordinary Chronicles', 'An entirely ordinary description with nothing special.');
// Book K — term only in KEYWORDS.
$bookKeywords = $makeBook('K', 'Ordinary Chronicles', 'An entirely ordinary description with nothing special.');
// Book D — term only in the DESCRIPTION.
$bookDesc = $makeBook('D', 'Ordinary Chronicles', "A plain title but the {$term} shows up here in the body text.");

$stmt = $db->prepare('UPDATE libri SET sottotitolo = ? WHERE id = ?');
$stmt->bind_param('si', $term, $bookSubtitle);
$stmt->execute();
$stmt->close();

$publisherName = 'Editore ' . $term;
$stmt = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
$stmt->bind_param('s', $publisherName);
$stmt->execute();
$publisherId = (int) $db->insert_id;
$stmt->close();
$stmt = $db->prepare('UPDATE libri SET editore_id = ? WHERE id = ?');
$stmt->bind_param('ii', $publisherId, $bookPublisher);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare('UPDATE libri SET parole_chiave = ? WHERE id = ?');
$stmt->bind_param('si', $term, $bookKeywords);
$stmt->execute();
$stmt->close();

// Link an author named after the term to Book A.
$authorName = 'Autore ' . $term;
$stmt = $db->prepare("INSERT INTO autori (nome) VALUES (?)");
$stmt->bind_param('s', $authorName);
$stmt->execute();
$authorId = (int) $db->insert_id;
$stmt->close();
$stmt = $db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, 'principale')");
$stmt->bind_param('ii', $bookAuthor, $authorId);
$stmt->execute();
$stmt->close();

// Rebuild the denormalized FULLTEXT index so every source field participates in
// the WHERE exactly as it does in production.
SearchIndexBuilder::rebuildMany($db, [
    $bookTitle,
    $bookAuthor,
    $bookSubtitle,
    $bookPublisher,
    $bookKeywords,
    $bookDesc,
]);

// ── Run the exact WHERE + weighted ORDER the search controllers use ─────────
$cond = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $term);
$check($cond !== null, "01 search condition built for the term");

$rel = SearchIndexBuilder::buildRelevanceOrder($db, $term, 'l.');

$sql = "SELECT l.id FROM libri l WHERE l.deleted_at IS NULL AND {$cond['sql']} ORDER BY {$rel['sql']}";
$stmt = $db->prepare($sql);
$stmt->bind_param($cond['types'] . $rel['types'], ...array_merge($cond['params'], $rel['params']));
$stmt->execute();
$res = $stmt->get_result();
$order = [];
while ($row = $res->fetch_row()) {
    $order[] = (int) $row[0];
}
$stmt->close();

// Every field-specific fixture qualifies through the same search_index.
$check(count(array_intersect(
    [$bookTitle, $bookAuthor, $bookSubtitle, $bookPublisher, $bookKeywords, $bookDesc],
    $order
)) === 6, "02 all field-specific books match the FULLTEXT WHERE");

$posTitle  = array_search($bookTitle, $order, true);
$posAuthor = array_search($bookAuthor, $order, true);
$posSubtitle = array_search($bookSubtitle, $order, true);
$posPublisher = array_search($bookPublisher, $order, true);
$posKeywords = array_search($bookKeywords, $order, true);
$posDesc   = array_search($bookDesc, $order, true);

$check($posTitle !== false && $posAuthor !== false && $posTitle < $posAuthor,
    "03 title match ranks ABOVE author match");
$check($posAuthor !== false && $posSubtitle !== false && $posAuthor < $posSubtitle,
    "04 author match ranks ABOVE subtitle match");
$check($posSubtitle !== false && $posPublisher !== false && $posSubtitle < $posPublisher,
    "05 subtitle match ranks ABOVE publisher match");
$check($posPublisher !== false && $posKeywords !== false && $posPublisher < $posKeywords,
    "06 publisher match ranks ABOVE keywords match");
$check($posKeywords !== false && $posDesc !== false && $posKeywords < $posDesc,
    "07 keywords match ranks ABOVE description-only match");

// ── 08 · Trailing-'*' prefix query still ranks by field (F002) ──────────────
$condStar = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $term . '*');
$relStar  = SearchIndexBuilder::buildRelevanceOrder($db, $term . '*', 'l.');
$sqlStar  = "SELECT l.id FROM libri l WHERE l.deleted_at IS NULL AND {$condStar['sql']} ORDER BY {$relStar['sql']}";
$stmt = $db->prepare($sqlStar);
$stmt->bind_param($condStar['types'] . $relStar['types'], ...array_merge($condStar['params'], $relStar['params']));
$stmt->execute();
$resStar = $stmt->get_result();
$orderStar = [];
while ($row = $resStar->fetch_row()) {
    $orderStar[] = (int) $row[0];
}
$stmt->close();
$posTitleStar = array_search($bookTitle, $orderStar, true);
$posDescStar  = array_search($bookDesc, $orderStar, true);
$check($posTitleStar !== false && $posDescStar !== false && $posTitleStar < $posDescStar,
    "08 trailing-'*' query still ranks title ABOVE description (no collapse to titolo ASC)");

// ── 09 · Catalog is uncapped; AJAX cap applies after normalization ─────────
$manyWords = trim(str_repeat($term . ' ', 40));
$relCatalog = SearchIndexBuilder::buildRelevanceOrder($db, $manyWords, 'l.');
$relAjax = SearchIndexBuilder::buildRelevanceOrder($db, $manyWords, 'l.', 24);
$catalogAuthorTerms = substr_count($relCatalog['sql'], 'JOIN autori a_rel');
$ajaxAuthorTerms = substr_count($relAjax['sql'], 'JOIN autori a_rel');
$check($catalogAuthorTerms === 40 && $ajaxAuthorTerms === 24,
    "09 catalog weighs all 40 words while AJAX is capped at 24");

$starPrefix = str_repeat('* ', 30) . $term;
$relAfterStars = SearchIndexBuilder::buildRelevanceOrder($db, $starPrefix, 'l.', 24);
$check($relAfterStars['score_sql'] !== '0'
    && !str_contains($relAfterStars['sql'], '() DESC')
    && substr_count($relAfterStars['sql'], 'JOIN autori a_rel') === 1,
    "10 wildcard-only tokens do not consume the AJAX budget or produce invalid SQL");
$check(count($relAjax['params']) === strlen($relAjax['types'])
    && count($relAjax['params']) === substr_count($relAjax['score_sql'], '?'),
    "11 parameter/type/placeholder counts stay aligned after the cap");

// ── 12 · ISBN/EAN outrank description-only matches ────────────────────────
$identifier = substr('979' . str_pad((string) abs(crc32($run)), 10, '0', STR_PAD_LEFT), 0, 13);
$bookIdentifier = $makeBook('I', 'Ordinary Identifier', 'An ordinary description.');
$bookIdentifierDesc = $makeBook('ID', 'Ordinary Description', "The code {$identifier} occurs only in this description.");
$stmt = $db->prepare('UPDATE libri SET isbn13 = ? WHERE id = ?');
$stmt->bind_param('si', $identifier, $bookIdentifier);
$stmt->execute();
$stmt->close();
SearchIndexBuilder::rebuildMany($db, [$bookIdentifier, $bookIdentifierDesc]);
$condIdentifier = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $identifier);
$relIdentifier = SearchIndexBuilder::buildRelevanceOrder($db, $identifier, 'l.');
$stmt = $db->prepare("SELECT l.id FROM libri l WHERE l.deleted_at IS NULL AND {$condIdentifier['sql']} ORDER BY {$relIdentifier['sql']}");
$stmt->bind_param($condIdentifier['types'] . $relIdentifier['types'], ...array_merge($condIdentifier['params'], $relIdentifier['params']));
$stmt->execute();
$identifierOrder = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
$stmt->close();
$posIdentifier = array_search($bookIdentifier, $identifierOrder, true);
$posIdentifierDesc = array_search($bookIdentifierDesc, $identifierOrder, true);
$check($posIdentifier !== false && $posIdentifierDesc !== false && $posIdentifier < $posIdentifierDesc,
    "12 ISBN match ranks ABOVE description-only match");

// ── 13 · Entity-decoded WHERE and ranking use the same value ───────────────
$entityTerm = 'Q&A' . $run;
$bookEntityTitle = $makeBook('E', 'Q&amp;A' . $run, 'An ordinary description.');
$bookEntityDesc = $makeBook('ED', 'Ordinary Entity', "The token {$entityTerm} occurs only in this description.");
SearchIndexBuilder::rebuildMany($db, [$bookEntityTitle, $bookEntityDesc]);
$condEntity = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $entityTerm);
$relEntity = SearchIndexBuilder::buildRelevanceOrder($db, $entityTerm, 'l.');
$stmt = $db->prepare("SELECT l.id FROM libri l WHERE l.deleted_at IS NULL AND {$condEntity['sql']} ORDER BY {$relEntity['sql']}");
$stmt->bind_param($condEntity['types'] . $relEntity['types'], ...array_merge($condEntity['params'], $relEntity['params']));
$stmt->execute();
$entityOrder = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
$stmt->close();
$posEntityTitle = array_search($bookEntityTitle, $entityOrder, true);
$posEntityDesc = array_search($bookEntityDesc, $entityOrder, true);
$check($posEntityTitle !== false && $posEntityDesc !== false && $posEntityTitle < $posEntityDesc,
    "13 entity-decoded title match keeps the title weight");

// ── 14 · Real AJAX controller uses aligned params and ordering ──────────────
$request = (new Slim\Psr7\Factory\ServerRequestFactory())
    ->createServerRequest('GET', '/api/search/libri')
    ->withQueryParams(['q' => $term]);
$response = (new App\Controllers\SearchController())->books(
    $request,
    (new Slim\Psr7\Factory\ResponseFactory())->createResponse(),
    $db
);
$ajaxRows = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
$check($response->getStatusCode() === 200
    && is_array($ajaxRows)
    && (int) ($ajaxRows[0]['id'] ?? 0) === $bookTitle
    && count($ajaxRows) <= 50,
    "14 AJAX books endpoint returns weighted results within the 50-row limit");

// ── 15 · Pre-migration fallback shares wildcard normalization ──────────────
$legacyMethod = new ReflectionMethod(SearchIndexBuilder::class, 'buildLegacyCondition');
/** @var array{sql:string, params:array<int,string>, types:string}|null $legacy */
$legacy = $legacyMethod->invoke(null, $db, 'l.search_index', $term . '*');
$check($legacy !== null
    && $legacy['params'] !== []
    && count(array_filter($legacy['params'], static fn (string $param): bool => str_contains($param, '*'))) === 0,
    "15 pre-migration WHERE strips trailing wildcards just like FULLTEXT relevance");
$legacyEmpty = $legacyMethod->invoke(null, $db, 'l.search_index', '***');
$check($legacyEmpty === null, "16 wildcard-only legacy query yields no invalid condition");

$cleanup();
$db->close();
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
