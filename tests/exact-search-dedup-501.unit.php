<?php
declare(strict_types=1);

/**
 * Issue #501, part 4 of 9: find-by-name lookups that decide which author or
 * publisher a saved/imported book is linked to. utf8mb4_unicode_ci makes
 * `nome = 'Häffner'` also match `Haffner`, and with LIMIT 1 the older row won:
 * a book by Häffner was attached to Haffner even when Häffner was on file.
 * Every lookup now ranks the exact spelling (accents distinct, case ignored)
 * first. The deliberate accent-tolerant fallback — only Haffner on file,
 * "Häffner" incoming — is kept and pinned here too.
 */

require __DIR__ . '/helpers/exact-search-501.php';
require_once dirname(__DIR__) . '/storage/plugins/book-club/src/Repo.php';
require_once dirname(__DIR__) . '/storage/plugins/emeroteca/src/Services/PeriodicalPublisherService.php';

use App\Models\AuthorRepository;
use App\Models\PublisherRepository;

$ok = [Exact501::class, 'check'];
$same = [Exact501::class, 'same'];
$db = Exact501::db();

$given = ucfirst(Exact501::token());
$authorPlain = Exact501::exec($db, 'INSERT INTO autori (nome) VALUES (?)', ["{$given} Haffner"]);
$authorUmlaut = Exact501::exec($db, 'INSERT INTO autori (nome) VALUES (?)', ["{$given} Häffner"]);
$pubPlain = Exact501::exec($db, 'INSERT INTO editori (nome) VALUES (?)', ["Verlag {$given} Haffner"]);
$pubUmlaut = Exact501::exec($db, 'INSERT INTO editori (nome) VALUES (?)', ["Verlag {$given} Häffner"]);
Exact501::onCleanup(static function () use ($db, $given): void {
    $like = '%' . $given . '%';
    Exact501::exec($db, 'DELETE FROM autori WHERE nome LIKE ?', [$like]);
    Exact501::exec($db, 'DELETE FROM editori WHERE nome LIKE ?', [$like]);
});

// ── AuthorRepository (book form, CSV, LibraryThing, scrapers, mobile API) ──
$authors = new AuthorRepository($db);
$same($authorUmlaut, $authors->findByCanonicalName("{$given} Häffner"), 'author: the exact umlaut spelling wins over the older Haffner');
$same($authorPlain, $authors->findByCanonicalName("{$given} Haffner"), 'author: Haffner still resolves to Haffner');
$same($authorUmlaut, $authors->findByCanonicalName(mb_strtolower("{$given} Häffner")), 'author: a lower-case Häffner resolves to Häffner');
$same($authorUmlaut, $authors->findByCanonicalName(mb_strtoupper("{$given} Häffner")), 'author: an upper-case HÄFFNER resolves to Häffner');
$same($authorUmlaut, $authors->findByCanonicalName("Häffner, {$given}"), 'author: the inverted "Häffner, Given" form resolves to Häffner');
$same($authorPlain, $authors->findByCanonicalName("Haffner, {$given}"), 'author: the inverted "Haffner, Given" form resolves to Haffner');
$same($authorUmlaut, $authors->findByName("{$given} Häffner"), 'author: findByName() follows the same rule');

// ── PublisherRepository (book form, bulk edit, mobile API) ─────────────────
$publishers = new PublisherRepository($db);
$same($pubUmlaut, $publishers->findByName("Verlag {$given} Häffner"), 'publisher: the exact umlaut spelling wins');
$same($pubPlain, $publishers->findByName("Verlag {$given} Haffner"), 'publisher: Haffner still resolves to Haffner');
$same($pubUmlaut, $publishers->findByName(mb_strtolower("Verlag {$given} Häffner")), 'publisher: case is ignored while the umlaut is kept');

// ── BulkEnrichmentService (cover/metadata enrichment) ──────────────────────
$bulk = new \App\Services\BulkEnrichmentService($db);
$bulkPublisher = new \ReflectionMethod($bulk, 'findOrCreatePublisher');
$bulkAuthor = new \ReflectionMethod($bulk, 'findOrCreateAuthor');
$same($pubUmlaut, $bulkPublisher->invoke($bulk, "Verlag {$given} Häffner"), 'bulk enrichment: publisher Häffner is not merged into Haffner');
$same($pubPlain, $bulkPublisher->invoke($bulk, "Verlag {$given} Haffner"), 'bulk enrichment: publisher Haffner keeps its own row');
$same($authorUmlaut, $bulkAuthor->invoke($bulk, "{$given} Häffner"), 'bulk enrichment: author Häffner is not merged into Haffner');
$same($authorPlain, $bulkAuthor->invoke($bulk, "{$given} Haffner"), 'bulk enrichment: author Haffner keeps its own row');

// ── CSV and LibraryThing importers ─────────────────────────────────────────
foreach ([\App\Controllers\CsvImportController::class => 'CSV import', \App\Controllers\LibraryThingImportController::class => 'LibraryThing import'] as $class => $label) {
    $importer = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    $getOrCreate = new \ReflectionMethod($importer, 'getOrCreatePublisher');
    $same(['id' => $pubUmlaut, 'created' => false], $getOrCreate->invoke($importer, $db, "Verlag {$given} Häffner"), "{$label}: publisher Häffner links to Häffner");
    $same(['id' => $pubPlain, 'created' => false], $getOrCreate->invoke($importer, $db, "Verlag {$given} Haffner"), "{$label}: publisher Haffner links to Haffner");
}

// ── Plugins: book club and periodicals ─────────────────────────────────────
$club = new \App\Plugins\BookClub\Repo($db);
$clubPublisher = new \ReflectionMethod($club, 'findOrCreatePublisher');
$same($pubUmlaut, $clubPublisher->invoke($club, "Verlag {$given} Häffner"), 'book club: publisher Häffner links to Häffner');
$same($pubPlain, $clubPublisher->invoke($club, "Verlag {$given} Haffner"), 'book club: publisher Haffner links to Haffner');

$periodicals = new \App\Plugins\Emeroteca\Services\PeriodicalPublisherService($db);
$same([$pubUmlaut], $periodicals->resolve(["new:Verlag {$given} Häffner"]), 'periodicals: a typed publisher Häffner links to Häffner');
$same([$pubPlain], $periodicals->resolve(["new:Verlag {$given} Haffner"]), 'periodicals: a typed publisher Haffner links to Haffner');

// ── The tolerant fallback is deliberate and stays ─────────────────────────
Exact501::exec($db, 'DELETE FROM autori WHERE id = ?', [$authorUmlaut]);
Exact501::exec($db, 'DELETE FROM editori WHERE id = ?', [$pubUmlaut]);
$same($authorPlain, $authors->findByCanonicalName("{$given} Häffner"), 'author: with only Haffner on file, an incoming Häffner still reuses it (tolerant dedup kept)');
$same($pubPlain, $publishers->findByName("Verlag {$given} Häffner"), 'publisher: with only Haffner on file, an incoming Häffner still reuses it (tolerant dedup kept)');
$ok($authors->findByCanonicalName("{$given} Hoffner") === null, 'author: a different letter is never merged');

Exact501::finish(25);
