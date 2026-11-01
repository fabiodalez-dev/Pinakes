<?php
declare(strict_types=1);

/**
 * Issue #501, part 1 of 9: App\Support\ExactSearch itself — the tokenizer,
 * the single-value filter parser, the SQL fragments and what they match on a
 * real server (accents distinct, case ignored, LIKE metacharacters literal).
 * The rows live in a TEMPORARY table: per-connection, gone when the run ends.
 */

require __DIR__ . '/helpers/exact-search-501.php';

use App\Support\AuthorNormalizer as N;
use App\Support\ExactSearch as E;

$ok = [Exact501::class, 'check'];
$same = [Exact501::class, 'same'];

// ── Tokenizer ──────────────────────────────────────────────────────────────
$same([['term' => 'haffner', 'exact' => false]], E::tokenize('haffner'), 'a plain word is tolerant');
$same([['term' => 'haffner', 'exact' => true]], E::tokenize('"haffner"'), 'a quoted word is exact');
$same([['term' => 'haffner', 'exact' => true]], E::tokenize('"haffner'), 'an unclosed quote counts as closed');
$same([['term' => 'Häffner', 'exact' => true]], E::tokenize('„Häffner“'), 'German low-high quotes are recognised');
$same([['term' => 'Häffner', 'exact' => true]], E::tokenize('“Häffner”'), 'English curly quotes are recognised');
$same([['term' => 'Häffner', 'exact' => true]], E::tokenize('«Häffner»'), 'guillemets are recognised');
$same(
    [['term' => 'roman', 'exact' => false], ['term' => 'thomas mann', 'exact' => true], ['term' => '1929', 'exact' => false]],
    E::tokenize('roman "thomas   mann" 1929'),
    'a phrase between plain words collapses its spaces and leaves the others tolerant'
);
$ok(E::tokenize('""') === [] && E::tokenize('"   "') === [], 'empty quotes yield no term');
$garbled = E::tokenize("\xff\xfe haffner");
$ok(count($garbled) === 2 && $garbled[1]['term'] === 'haffner', 'invalid UTF-8 is scrubbed instead of discarding the whole query');

// ── Single-value filter parser ─────────────────────────────────────────────
$same(['term' => 'Haffner', 'exact' => true], E::parseFilter('  "Haffner"  '), 'a quoted filter value is exact and trimmed');
$same(['term' => 'van der Berg', 'exact' => false], E::parseFilter('van der Berg'), 'an unquoted filter value stays one tolerant term');
$same(['term' => 'Thomas Mann', 'exact' => true], E::parseFilter('"Thomas" "Mann"'), 'several quoted groups in one box become one phrase without literal quotes');
$same(['term' => 'Haffner', 'exact' => false], E::parseFilter('Haffner"'), 'a stray trailing quote is dropped, never searched as a literal character');
$same(['term' => 'häffner rolf', 'exact' => false], E::parseFilter('"häffner" rolf'), 'a value that only STARTS with a quote is not exact as a whole');
$same(['term' => 'rolf häffner', 'exact' => false], E::parseFilter('rolf "häffner"'), 'a quoted word after a plain one does not make the value exact either');
$same(['term' => '', 'exact' => true], E::parseFilter('"'), 'a lone quote leaves no term');
// quotedValue(): only a name quoted AS A WHOLE loses its quotes when saved.
$same('Rolf Häffner', E::quotedValue('"Rolf Häffner"'), 'a name typed wholly in quotes is saved without them');
$same('Thomas Mann', E::quotedValue('"Thomas" "Mann"'), 'several quoted groups and nothing else still count as wholly quoted');
$same(null, E::quotedValue('"Il Mulino", Bologna'), 'a name with text after the closing quote is kept as typed');
$same(null, E::quotedValue('"Pippo" Inzaghi'), 'a quoted nickname inside a name is kept as typed');
$same(null, E::quotedValue('Edizioni «La Scala»'), 'guillemets inside a publisher name are kept as typed');
$same(null, E::quotedValue('Haffner'), 'an unquoted name is not a quoted value');
$same(null, E::quotedValue('"'), 'a lone quote is not a name');
$ok(E::filterCondition(['a.nome'], '"') === null, 'a lone quote adds no filter');
// AuthorNormalizer: a quoted name is canonicalised INSIDE the quotes, so the
// save and lookup paths still see a whole-value quoted string and the quote
// characters never end up in the stored name (`Rolf" "Häffner`).
$same('"Rolf Häffner"', N::normalize('"Häffner, Rolf"'), 'a quoted inverted name is swapped with the quotes kept around the whole name');
$same('"Rolf Häffner"', N::normalize('"Rolf Häffner"'), 'a quoted direct name is unchanged');
$same('"Rolf Häffner"', N::normalize('„Häffner, Rolf“'), 'typographic quotes come back as straight ones around the canonical name');
$same('Rolf Häffner', N::normalize('Häffner, Rolf'), 'an unquoted inverted name is still swapped');
$same('"Pippo" Inzaghi', N::normalize('"Pippo" Inzaghi'), 'a quoted part inside a name is not a quoted name and is kept as typed');
$same('Rolf Häffner', E::quotedValue(N::normalize('"Häffner, Rolf"')), 'the normalised quoted name still reads as one quoted value when saved');
$same('rolf haffner', N::toSearchForm('"Häffner, Rolf"'), 'the search form of a quoted name is the name inside the quotes');
$ok(N::match('"Häffner, Rolf"', 'Rolf Häffner'), 'match() compares a quoted candidate on the name inside the quotes');
$cond = E::filterCondition(['a.nome', 'a.pseudonimo'], '"x"');
$ok($cond !== null && count($cond['params']) === 2 && $cond['types'] === 'ss', 'one bound pattern per searched column');

// ── SQL fragments ──────────────────────────────────────────────────────────
$same('%50\\%\\_a\\\\b%', E::likePattern('50%_a\\b'), 'likePattern escapes %, _ and the backslash');
$ok(!str_contains(E::likeSql('x', false), 'COLLATE') && str_contains(E::likeSql('x', true), 'LOWER(x) COLLATE utf8mb4_bin'), 'only the exact LIKE uses the binary collation');

// ── Behaviour on the server ────────────────────────────────────────────────
$db = Exact501::db();
$db->query('CREATE TEMPORARY TABLE zz501_core (
    id INT NOT NULL PRIMARY KEY,
    nome VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    pseudonimo VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB');
foreach ([
    1 => ['Rolf Haffner', null], 2 => ['Rolf Häffner', null], 3 => ['Sconto 50% Rossi', null],
    4 => ['a_b Verdi', null], 5 => ['C:\\dir Bianchi', null], 6 => ['Hans Müller', 'H. Mueller'],
] as $id => [$nome, $pseudo]) {
    Exact501::exec($db, 'INSERT INTO zz501_core (id, nome, pseudonimo) VALUES (?, ?, ?)', [$id, $nome, $pseudo]);
}
$f = static fn(string $v): array => Exact501::ids($db, 'zz501_core', E::filterCondition(['nome', 'pseudonimo'], $v));

$same([1, 2], $f('haffner'), 'unquoted haffner still finds both spellings (tolerant default kept)');
$same([1], $f('"haffner"'), '"haffner" excludes Häffner');
$same([2], $f('"HÄFFNER"'), 'case is ignored inside quotes, also on an umlaut');
$same([3], $f('50%'), '% is a literal character, not a wildcard');
$same([4], $f('a_b'), '_ is a literal character, not a one-letter wildcard');
$same([5], $f('C:\\dir'), 'a backslash is a literal character');
$same([6], $f('"mueller"'), 'a quoted value also searches the pseudonym column');
$same([2], $f('rolf "häffner"'), 'a filter box mixing words keeps each word\'s rule (no literal quotes)');
$same([2], $f('"häffner" rolf'), 'a filter box starting with a quote is not glued into one phrase');
$same([1], $f('rolf "haffner"'), 'a mixed filter still keeps the umlaut out');
$same([2], $f('"häffner" "rolf"'), 'several quoted groups are separate exact words, in any order, as in the main search box');
$same([], $f('"haffner" "anna"'), 'each quoted group must match on its own');
$flood = \App\Support\ExactSearch::filterCondition(['nome', 'pseudonimo'], '"x" ' . str_repeat('word ', 5000));
$same(\App\Support\ExactSearch::MAX_FILTER_TERMS * 2, count($flood['params']), 'a pasted wall of words in a filter box is capped, not one condition per word');

$eq = E::equalsCondition('nome', 'rolf haffner');
$same([1], Exact501::ids($db, 'zz501_core', $eq), 'equalsCondition matches the exact name, case ignored, accents distinct');
$in = E::inCondition('nome', ['Rolf Häffner', 'Nobody']);
$same([2], Exact501::ids($db, 'zz501_core', $in), 'inCondition keeps accents distinct across the list');

$stmt = $db->prepare('SELECT id FROM zz501_core WHERE nome = ? ORDER BY ' . E::exactFirstSql('nome') . ', id LIMIT 1');
$probe = 'rolf häffner';
$stmt->bind_param('ss', $probe, $probe);
$stmt->execute();
$same(2, (int) $stmt->get_result()->fetch_column(), 'exactFirstSql ranks the exact spelling above the accent-folded row');
$stmt->close();

$w = static fn(string $v): array => Exact501::ids($db, 'zz501_core', E::wordsCondition(['nome', 'pseudonimo'], $v));
$same([2], $w('rolf "häffner"'), 'wordsCondition ANDs a tolerant word with a quoted one');
$same([], $f("\xff\xfe"), 'a garbled value matches nothing instead of every row');

$limited = \App\Support\ExactSearch::wordsCondition(['nome'], '"thomas mann" other words', 1);
$same(['%thomas mann%'], $limited['params'], 'a search budget counts a quoted phrase as one term');
$same(null, \App\Support\ExactSearch::wordsCondition(['nome'], 'mann', 0), 'a zero search budget produces no invalid empty predicate');
Exact501::finish(55);
