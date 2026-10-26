<?php
declare(strict_types=1);

/**
 * Issue #501, part 7 of 9: the archives plugin. Its searches (admin list,
 * public listing, unified search, the catalogue's archive box, the author
 * picker) honour quotes like the book search: unquoted keeps the stemming
 * ("disegno" finds "disegni") and the accent folding, quoted is the exact
 * spelling with nothing trimmed.
 *
 * The archive tables are created as TEMPORARY tables with their real names:
 * for this connection they shadow the real ones, so the suite sees only its
 * own rows, needs no activated plugin and leaves no trace.
 */

require __DIR__ . '/helpers/exact-search-501.php';
require_once dirname(__DIR__) . '/storage/plugins/archives/ArchivesPlugin.php';

$ok = [Exact501::class, 'check'];
$same = [Exact501::class, 'same'];
$db = Exact501::db();

$db->query("CREATE TEMPORARY TABLE archival_units (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    reference_code VARCHAR(64) NOT NULL DEFAULT '',
    level ENUM('fonds','series','file','item') NOT NULL DEFAULT 'fonds',
    constructed_title VARCHAR(500) NOT NULL DEFAULT '',
    formal_title VARCHAR(500) NULL,
    scope_content TEXT NULL,
    archival_history TEXT NULL,
    date_start INT NULL,
    date_end INT NULL,
    extent TEXT NULL,
    published TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$db->query('CREATE TEMPORARY TABLE authority_records (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    authorised_form VARCHAR(500) NOT NULL,
    type VARCHAR(30) NULL,
    dates_of_existence TEXT NULL,
    parallel_forms TEXT NULL,
    history TEXT NULL,
    functions TEXT NULL,
    deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$db->query('CREATE TEMPORARY TABLE archival_unit_authority (
    archival_unit_id BIGINT UNSIGNED NOT NULL,
    authority_id BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB');
foreach ([
    [1, 'U-1', 'Die Brücke', null, null, 1, null],
    [2, 'U-2', 'Die Brucke', null, null, 1, null],
    [3, 'U-3', 'Disegni architettonici', null, null, 1, null],
    [4, 'IT-MI-1943', 'Fotografie', null, null, 1, null],
    [5, 'U-5', 'Corrispondenza', null, 'Briefe an Häffner', 1, null],
    [6, 'U-6', 'Carte', 'Haffner Nachlass', null, 1, null],
    [7, 'U-7', 'Raccolta Rossi', null, null, 0, null],
    [8, 'U-8', 'Fondo eliminato Brücke', null, null, 1, '2026-01-01 00:00:00'],
] as $row) {
    Exact501::exec($db, 'INSERT INTO archival_units (id, reference_code, constructed_title, formal_title, scope_content, published, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?)', $row);
}
Exact501::exec($db, "INSERT INTO authority_records (id, authorised_form) VALUES (1, 'Häffner, Anna'), (2, 'Haffner, Rolf')");
Exact501::exec($db, 'INSERT INTO archival_unit_authority (archival_unit_id, authority_id) VALUES (3, 1), (4, 2)');

$plugin = new \App\Plugins\Archives\ArchivesPlugin($db, new \App\Support\HookManager($db));
$condition = new \ReflectionMethod($plugin, 'archiveSearchCondition');
$units = static fn(string $q, ?string $unitId = null): array => Exact501::ids($db, 'archival_units u', $condition->invoke($plugin, $q, $unitId));
$resultIds = static function (array $rows): array {
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    sort($ids);
    return $ids;
};

// ── The shared condition ───────────────────────────────────────────────────
$same([1, 2, 8], $units('brücke'), 'unquoted brücke finds both spellings');
$same([1, 8], $units('"brücke"'), '"brücke" excludes Brucke');
$same([2], $units('"brucke"'), '"brucke" excludes Brücke');
$same([1, 8], $units('"BRÜCKE"'), 'case is ignored inside quotes');
$same([3], $units('disegno'), 'unquoted search keeps the stemming (disegno → disegni)');
$same([], $units('"disegno"'), 'quoted search trims no stem: "disegno" is not "disegni"');
$same([3], $units('"disegni"'), 'the exact word is found');
$same([4], $units('"IT-MI-1943"'), 'a quoted reference code matches literally');
$same([5, 6], $units('häffner'), 'unquoted häffner finds the scope note and the formal title');
$same([5], $units('"häffner"'), '"häffner" keeps the umlaut');
$same([6], $units('"haffner"'), '"haffner" excludes the umlaut');
$same([3, 5], $units('"häffner"', 'u.id'), 'with authorities: a linked authority record counts, exactly');
$same([3, 4, 5, 6], $units('haffner', 'u.id'), 'with authorities: unquoted matches every linked spelling');
$same([], $units('"'), 'a lone quote does not match every unit');

// ── Catalogue archive box (frontend.catalog.archive_results) ──────────────
$same([3, 5], $resultIds($plugin->getPublicArchiveResults([], '"häffner"')), 'catalogue box: quoted Häffner, authority included');
$same([1, 2], $resultIds($plugin->getPublicArchiveResults([], 'brücke')), 'catalogue box: unquoted finds both, deleted unit left out');
$same([1], $resultIds($plugin->getPublicArchiveResults([], '"brücke"')), 'catalogue box: quoted Brücke only');

// ── Unified search (search.unified.sources) ────────────────────────────────
$same([2], $resultIds($plugin->addArchivalSources([], '"brucke"')), 'unified search: quoted Brucke only');
$same([], $resultIds($plugin->addArchivalSources([], '"raccolta rossi"')), 'unified search: an unpublished unit stays hidden from the public');
$same([7], $resultIds($plugin->addArchivalSources([], '"raccolta rossi"', 'admin')), 'unified search: an operator finds the unpublished unit');

// ── Author picker (searchAutori) on real authors ───────────────────────────
$t = ucfirst(Exact501::token());
$a1 = Exact501::exec($db, 'INSERT INTO autori (nome) VALUES (?)', ["{$t} Haffner"]);
$a2 = Exact501::exec($db, 'INSERT INTO autori (nome) VALUES (?)', ["{$t} Häffner"]);
Exact501::onCleanup(static fn() => $db->query("DELETE FROM autori WHERE id IN ({$a1}, {$a2})"));
$authors = static fn(string $q): array => $resultIds($plugin->searchAutori($q));
$same([$a1, $a2], $authors("{$t} haffner"), 'author picker: unquoted finds both spellings');
$same([$a1], $authors("\"{$t} haffner\""), 'author picker: quoted Haffner excludes Häffner');
$same([$a2], $authors("\"{$t} Häffner\""), 'author picker: quoted Häffner excludes Haffner');
$same([$a2], $authors('"' . mb_strtoupper("{$t} häffner") . '"'), 'author picker: case ignored inside quotes');
$same([$a2], $authors("\"{$t}\" \"häffner\""), 'author picker: two quoted groups form one phrase');

// The archive's dedicated cross-entity bar and authority typeahead use
// separate FULLTEXT paths: they must not bypass the quoted spelling either.
foreach (['searchArchivalUnits'=>['"brücke"'=>[1],'"brucke"'=>[2]], 'searchAuthorityRecords'=>['"häffner"'=>[1],'"haffner"'=>[2]], 'searchAuthorityRecordsForTypeahead'=>['"häffner"'=>[1],'"haffner"'=>[2]]] as $method=>$cases) {
    foreach ($cases as $q=>$expected) {
        $same($expected,$resultIds((new ReflectionMethod($plugin,$method))->invoke($plugin,$q,20)),"archive $method: $q");
    }
}
$db->query('CREATE TEMPORARY TABLE autori_authority_link(autori_id INT,authority_id INT)');
Exact501::exec($db,'INSERT INTO autori_authority_link VALUES (?,2),(?,1)',[$a1,$a2]);
$reconciled=new ReflectionMethod($plugin,'searchReconciledAutori');
$same([$a1],$resultIds($reconciled->invoke($plugin,"{$t} \"haffner\"",20)),'reconciled-author search keeps the plain spelling');
$same([$a2],$resultIds($reconciled->invoke($plugin,"{$t} \"häffner\"",20)),'reconciled-author search keeps the umlaut spelling');
Exact501::finish(33);
