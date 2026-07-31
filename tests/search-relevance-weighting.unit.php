<?php
declare(strict_types=1);

/**
 * Regression test for search relevance weighting.
 *
 * The denormalized `libri.search_index` FULLTEXT column folds title, subtitle,
 * authors, publisher, ISBN/EAN, keywords AND the description into one blob, so a
 * plain MATCH(search_index) ranks a term found only in the description exactly
 * like one found in the title. SearchIndexBuilder::buildRelevanceOrder() fixes
 * the ORDER: title (100) > author (60) > subtitle (40) > keywords (10) >
 * description (3).
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
// Book D — term only in the DESCRIPTION.
$bookDesc = $makeBook('D', 'Ordinary Chronicles', "A plain title but the {$term} shows up here in the body text.");

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

// Rebuild the denormalized FULLTEXT index for all three so search_index folds
// title / author / description in — exactly what the live search matches on.
SearchIndexBuilder::rebuild($db, $bookTitle);
SearchIndexBuilder::rebuild($db, $bookAuthor);
SearchIndexBuilder::rebuild($db, $bookDesc);

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

// All three qualify (term is in search_index of each).
$check(in_array($bookTitle, $order, true) && in_array($bookAuthor, $order, true) && in_array($bookDesc, $order, true),
    "02 all three books match the FULLTEXT WHERE");

$posTitle  = array_search($bookTitle, $order, true);
$posAuthor = array_search($bookAuthor, $order, true);
$posDesc   = array_search($bookDesc, $order, true);

$check($posTitle !== false && $posAuthor !== false && $posTitle < $posAuthor,
    "03 title match ranks ABOVE author match");
$check($posAuthor !== false && $posDesc !== false && $posAuthor < $posDesc,
    "04 author match ranks ABOVE description-only match");
$check($posTitle !== false && $posDesc !== false && $posTitle < $posDesc,
    "05 title match ranks ABOVE description-only match (the reported bug)");

// ── 06 · Trailing-'*' prefix query still ranks by field (F002) ──────────────
// The FULLTEXT WHERE strips a trailing '*'; the ORDER BY must normalize the
// same way, else 'term*' builds LIKE '%term*%' (literal asterisk), scores 0 on
// every row and silently collapses relevance to titolo ASC.
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
    "06 trailing-'*' query still ranks title ABOVE description (no collapse to titolo ASC)");

// ── 07 · Per-word relevance subqueries are capped (F007) ────────────────────
// Each query word adds one correlated author EXISTS subquery to the ORDER BY.
// An unbounded word count on the public /api/search endpoints would let a
// caller amplify sort cost; buildRelevanceOrder caps the words it weighs.
$manyWords = trim(str_repeat($term . ' ', 40));           // 40 tokens
$relMany   = SearchIndexBuilder::buildRelevanceOrder($db, $manyWords, 'l.');
$existsCount = substr_count($relMany['sql'], 'EXISTS (');
$check($existsCount > 0 && $existsCount <= 12,
    "07 relevance ORDER BY caps author EXISTS subqueries at 12 (got {$existsCount})");
$check(strlen($relMany['types']) === $existsCount * 6,
    "07b param/type count stays aligned with the capped word count");

$cleanup();
$db->close();
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
