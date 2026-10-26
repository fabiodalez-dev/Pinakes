<?php
declare(strict_types=1);

/**
 * Issue #501, part 8 of 9: periodical articles in the unified catalogue
 * (UnifiedCatalogService). They answer the same search box as the books, so a
 * quoted term must be exact there too — including articles found only through
 * a linked author record.
 *
 * The service asks information_schema whether the periodicals plugin is set
 * up, so the suite uses the real tables: it creates minimal ones only where
 * they are missing (dropped afterwards), marks the plugin active for the run
 * (restored afterwards), seeds its own articles and reads back only those.
 * (TEMPORARY tables cannot stand in here: a two-word search references the
 * author-link table twice, which MySQL refuses for a temporary table.)
 */

require __DIR__ . '/helpers/exact-search-501.php';

$ok = [Exact501::class, 'check'];
$same = [Exact501::class, 'same'];
$db = Exact501::db();

$charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$minimal = [
    'emeroteca_contributi' => "id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        reference_key VARCHAR(191) NOT NULL,
        titolo VARCHAR(500) NOT NULL,
        sottotitolo VARCHAR(500) NULL,
        autori VARCHAR(1000) NULL,
        contenitore_titolo VARCHAR(500) NULL,
        keywords VARCHAR(1000) NULL,
        abstract TEXT NULL,
        issn VARCHAR(20) NULL,
        pubblico TINYINT(1) NOT NULL DEFAULT 1,
        anno_pubblicazione INT NULL,
        created_at DATETIME NULL",
    'emeroteca_contributi_autori' => 'contributo_id INT NOT NULL,
        ordine_credito SMALLINT UNSIGNED NOT NULL,
        nome_credito VARCHAR(255) NOT NULL,
        autore_id INT NULL,
        PRIMARY KEY (contributo_id, ordine_credito)',
];
$created = [];
foreach ($minimal as $table => $columns) {
    if ($db->query("SHOW TABLES LIKE '{$table}'")->num_rows === 0) {
        $db->query("CREATE TABLE `{$table}` ({$columns}) {$charset}");
        $created[] = $table;
    }
}
Exact501::onCleanup(static function () use ($db, $created): void {
    foreach (array_reverse($created) as $table) {
        $db->query("DROP TABLE IF EXISTS `{$table}`");
    }
});
$active = $db->query("SELECT is_active FROM plugins WHERE name = 'emeroteca'")->fetch_column();
if ($active === null || $active === false) {
    throw new \RuntimeException('the emeroteca plugin is not registered in this database');
}
$db->query("UPDATE plugins SET is_active = 1 WHERE name = 'emeroteca'");
Exact501::onCleanup(static fn() => Exact501::exec($db, "UPDATE plugins SET is_active = ? WHERE name = 'emeroteca'", [(int) $active]));

$t = ucfirst(Exact501::token());
$authorPlain = Exact501::exec($db, 'INSERT INTO autori (nome) VALUES (?)', ["{$t} Haffner"]);
$authorUmlaut = Exact501::exec($db, 'INSERT INTO autori (nome) VALUES (?)', ["{$t} Häffner"]);

/** @var array<int, int> $real local label (1..11) => real article id */
$real = [];
Exact501::onCleanup(static function () use ($db, &$real, $authorPlain, $authorUmlaut): void {
    if ($real !== []) {
        $db->query('DELETE FROM emeroteca_contributi_autori WHERE contributo_id IN (' . implode(',', $real) . ')');
        $db->query('DELETE FROM emeroteca_contributi WHERE id IN (' . implode(',', $real) . ')');
    }
    $db->query("DELETE FROM autori WHERE id IN ({$authorPlain}, {$authorUmlaut})");
});
foreach ([
    1 => ['Die Brücke', 'Rolf Haffner', null, null, 1],
    2 => ['Die Brucke', 'Anna Häffner', null, null, 1],
    3 => ['Thomas Mann und die Zeit', null, null, null, 1],
    4 => ['Mann ohne Thomas', null, null, null, 1],
    5 => ['Il disegno', null, 'Müller', null, 1],
    6 => ['Die Brücke privat', null, null, null, 0],
    7 => ['Aufsatz', null, null, null, 1],
    8 => ['Essay', null, null, null, 1],
    9 => ['Rivista', null, null, '1234-5678', 1],
    10 => ['Saggio', 'Levi, Primo', null, null, 1],
    11 => ['Sconto 50% oggi', null, null, null, 1],
] as $label => $row) {
    $real[$label] = Exact501::exec(
        $db,
        'INSERT INTO emeroteca_contributi (reference_key, titolo, autori, keywords, issn, pubblico) VALUES (?, ?, ?, ?, ?, ?)',
        ["{$t}-{$label}", ...$row]
    );
}
foreach ([[7, $authorUmlaut, "{$t} Häffner"], [8, $authorPlain, "{$t} Haffner"]] as [$label, $authorId, $credit]) {
    Exact501::exec($db, 'INSERT INTO emeroteca_contributi_autori (contributo_id, ordine_credito, nome_credito, autore_id) VALUES (?, 1, ?, ?)', [$real[$label], $credit, $authorId]);
}

$service = new \App\Services\UnifiedCatalogService($db);
$ok($service->enabled(), 'the unified catalogue sees the periodicals plugin for this run');
$query = new \ReflectionMethod($service, 'articleQuery');
$labels = array_flip($real);
$articles = static function (string $search) use ($db, $service, $query, $real, $labels): array {
    [$from, $types, $params] = $query->invoke($service, ['search' => $search]);
    $stmt = $db->prepare('SELECT c.id' . $from . ' AND c.id IN (' . implode(',', $real) . ') ORDER BY c.id');
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $found = array_map(static fn(array $r): int => $labels[(int) $r['id']], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    sort($found);
    return $found;
};

$same([1, 2], $articles('brücke'), 'unquoted brücke finds both spellings');
$same([1], $articles('"brücke"'), '"brücke" excludes Brucke');
$same([2], $articles('"brucke"'), '"brucke" excludes Brücke');
$same([1], $articles('"BRÜCKE"'), 'case is ignored inside quotes');
$same([1, 2, 7, 8], $articles('haffner'), 'unquoted haffner finds both authors, written or linked');
$same([1, 8], $articles('"haffner"'), '"haffner" excludes Häffner, written or linked');
$same([2, 7], $articles('"häffner"'), '"häffner" excludes Haffner, written or linked');
$same([3], $articles('"thomas mann"'), 'a quoted phrase must appear in that order');
$same([3, 4], $articles('thomas mann'), 'without quotes the words may be anywhere');
$same([5], $articles('muller'), 'unquoted muller finds Müller');
$same([], $articles('"muller"'), '"muller" does not find Müller');
$same([5], $articles('"müller"'), '"müller" finds Müller');
$same([1], $articles('brücke "rolf haffner"'), 'a tolerant word and a quoted phrase are ANDed');
$same([], $articles('brücke "anna haffner"'), 'the quoted phrase filters out the umlaut author');
$same([7], $articles("\"{$t} Häffner\""), 'a linked author record answers the quoted spelling');
$same([8], $articles("\"{$t} haffner\""), 'a linked author record excludes the other spelling');
$same([7, 8], $articles("{$t} haffner"), 'unquoted, both linked authors answer');
$same([], $articles('"brücke privat"'), 'an unpublished article stays hidden');
$same([10], $articles('primo levi'), 'unquoted words still ignore the comma of an inverted name');
$same([10], $articles('"levi, primo"'), 'a quoted inverted name matches with its comma');
$same([9], $articles('"1234-5678"'), 'a quoted ISSN matches literally');
$same([11], $articles('"50%"'), 'a quoted % is literal');
$same([1, 2, 3, 4, 5, 7, 8, 9, 10, 11], $articles('"'), 'a lone quote adds no search condition');
$same([], $articles("\xff\xfe"), 'a garbled query matches no article');
$same([], $articles('!!!'), 'a query of punctuation only matches no article, like the book search');

Exact501::finish(26);
