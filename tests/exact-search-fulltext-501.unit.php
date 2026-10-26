<?php
declare(strict_types=1);

/**
 * Issue #501, part 2 of 9: the book search (SearchIndexBuilder) — the
 * FULLTEXT condition every catalogue/admin/autocomplete search goes through,
 * the relevance ranking and the pre-migration LIKE fallback.
 *
 * FULLTEXT does not work on TEMPORARY tables, so the condition is exercised on
 * a dedicated zz_ table (dropped at the end); ranking and the fallback run on
 * two real books seeded with a unique token and removed afterwards.
 */

require __DIR__ . '/helpers/exact-search-501.php';

use App\Support\SearchIndexBuilder as S;

$ok = [Exact501::class, 'check'];
$same = [Exact501::class, 'same'];
$db = Exact501::db();

const ZZ501_FT = 'zz_exact_501_fulltext';
$db->query('DROP TABLE IF EXISTS `' . ZZ501_FT . '`');
Exact501::onCleanup(static fn() => $db->query('DROP TABLE IF EXISTS `' . ZZ501_FT . '`'));
$db->query('CREATE TABLE `' . ZZ501_FT . '` (
    id INT NOT NULL PRIMARY KEY,
    search_index MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    FULLTEXT KEY ft (search_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
foreach ([
    1 => 'Die Brücke Rolf Haffner',
    2 => 'Gedichte Anna Häffner',
    3 => 'Der Zauberberg Thomas Mann',
    4 => 'Mann ohne Eigenschaften Thomas Bernhard',
    5 => 'Müller Lexikon',
    6 => 'Schaffner Werke',
    7 => "L'orologio di Häffner",
    8 => 'C++ für Haffner',
] as $id => $text) {
    Exact501::exec($db, 'INSERT INTO `' . ZZ501_FT . '` (id, search_index) VALUES (?, ?)', [$id, $text]);
}
$b = static fn(string $q): array => Exact501::ids($db, '`' . ZZ501_FT . '` s', S::buildSearchCondition($db, 's.search_index', $q));

$same([1, 2, 7, 8], $b('haffner'), 'unquoted haffner finds every spelling');
$same([1, 8], $b('"haffner"'), '"haffner" finds only Haffner');
$same([2, 7], $b('"Häffner"'), '"Häffner" finds only Häffner');
$same([1, 8], $b('"HAFFNER"'), 'case is ignored inside quotes');
$same([1, 8], $b('"haff'), 'an unclosed quote counts as closed, and a quoted word start is enough');
$same([1, 2, 7, 8], $b('haff'), 'unquoted word start keeps finding both spellings');
$same([2, 7], $b('"häff"'), 'a quoted word start keeps the umlaut distinct');
$ok(!in_array(6, $b('"haff"'), true), 'a quoted word start does not match inside another word (Schaffner), like the unquoted search');
$same([2, 7], $b('„Häffner“'), 'typographic quotes behave like straight ones');
$same([3], $b('"thomas mann"'), 'a quoted phrase must appear in that order');
$same([3, 4], $b('thomas mann'), 'without quotes the words may be anywhere');
$same([3], $b('"thomas man"'), 'a phrase whose last word is still being typed matches');
$same([5], $b('muller'), 'unquoted muller still finds Müller');
$same([], $b('"muller"'), '"muller" does not find Müller');
$same([5], $b('"müller"'), '"müller" finds Müller');
$same([2], $b('gedichte "häffner"'), 'quoted and tolerant terms are ANDed');
$same([], $b('brücke "häffner"'), 'the quoted term filters out books where only the tolerant word matches');
$same([7], $b("\"l'orologio\""), 'an apostrophe inside quotes is matched literally');
$same([8], $b('"c++ für"'), 'punctuation and short words inside quotes are matched literally');
$ok(S::buildSearchCondition($db, 's.search_index', '"') === null, 'a lone quote adds no condition');
$phrase = S::buildSearchCondition($db, 's.search_index', '"thomas mann"');
$ok($phrase !== null && str_contains($phrase['sql'], 'MATCH(') && $phrase['params'][0] === '+thomas* +mann*', 'a quoted phrase is pre-filtered by FULLTEXT on each of its words');
$same([], $b("\xff\xfe"), 'a garbled query matches nothing instead of the whole catalogue');
$explain = $db->prepare('EXPLAIN SELECT s.id FROM `' . ZZ501_FT . '` s WHERE ' . $phrase['sql']);
$explain->bind_param($phrase['types'], ...$phrase['params']);
$explain->execute();
$plan = strtolower((string) json_encode($explain->get_result()->fetch_all(MYSQLI_ASSOC)));
$explain->close();
$ok(str_contains($plan, 'fulltext') || str_contains($plan, 'full-text'), 'EXPLAIN confirms a quoted phrase uses the FULLTEXT index');


// ── Ranking and the pre-migration fallback, on real books ─────────────────
$token = Exact501::token();
$bookIds = [];
foreach (["{$token} Häffner Studien", "{$token} Haffner Studien"] as $title) {
    $bookIds[] = Exact501::exec($db, 'INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 1, 1)', [$title]);
}
Exact501::onCleanup(static fn() => $db->query('DELETE FROM libri WHERE id IN (' . implode(',', $bookIds) . ')'));
S::rebuildMany($db, $bookIds);
[$umlautId, $plainId] = $bookIds;

$rel = S::buildRelevanceOrder($db, "{$token} \"häffner\"", 'l.');
$stmt = $db->prepare("SELECT l.id, {$rel['score_sql']} AS score FROM libri l WHERE l.id IN (?, ?) ORDER BY l.id");
$stmt->bind_param($rel['types'] . 'ii', ...[...$rel['params'], $umlautId, $plainId]);
$stmt->execute();
$scores = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'score', 'id');
$stmt->close();
$ok((int) $scores[$umlautId] > (int) $scores[$plainId], 'ranking scores the quoted term only on the exact spelling');

$capped = S::buildRelevanceOrder($db, '"thomas mann" roman', 'l.', 1);
$ok(substr_count($capped['sql'], 'THEN 100') === 1 && str_contains($capped['sql'], 'COLLATE utf8mb4_bin'), 'the word cap counts a quoted phrase as one term');

$prop = new \ReflectionProperty(S::class, 'columnExists');
$saved = $prop->getValue();
$prop->setValue(null, false);
try {
    $legacyExact = S::buildSearchCondition($db, 'l.search_index', "{$token} \"häffner\"");
    $legacyTolerant = S::buildSearchCondition($db, 'l.search_index', "{$token} haffner");
} finally {
    $prop->setValue(null, $saved);
}
$same([$umlautId], Exact501::ids($db, 'libri l', $legacyExact, 'AND l.deleted_at IS NULL'), 'pre-migration fallback: the quoted spelling finds only its book');
$same([$umlautId, $plainId], Exact501::ids($db, 'libri l', $legacyTolerant, 'AND l.deleted_at IS NULL'), 'pre-migration fallback: unquoted still finds both');

Exact501::finish(27);
