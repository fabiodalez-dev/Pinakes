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

// ── Quotes opt out of the tolerant fallback (Fabio, 2026-10-27) ─────────────
// Only Haffner is on file now. Typed WITHOUT quotes, Häffner is still merged
// into it (above). Typed IN quotes, it is that exact spelling: a different
// person or publisher, created without the quotes.
$nameOf = static fn(string $table, int $id): string => (string) $db->query("SELECT nome FROM {$table} WHERE id = {$id}")->fetch_column();
$ok($authors->findByCanonicalName("\"{$given} Häffner\"") === null, 'author: quoted Häffner is not merged into Haffner');
$same($authorPlain, $authors->findByCanonicalName("\"{$given} Haffner\""), 'author: quoted Haffner still finds Haffner');
$same($authorPlain, $authors->findByCanonicalName('"' . mb_strtolower("{$given} haffner") . '"'), 'author: case is ignored inside quotes');
$newAuthor = $authors->create(['nome' => "\"{$given} Häffner\""]);
$same("{$given} Häffner", $nameOf('autori', $newAuthor), 'author: a quoted new name is stored without the quotes');
$same($newAuthor, $authors->findByCanonicalName("\"{$given} Häffner\""), 'author: the quoted spelling now finds the new author');
$same($newAuthor, $authors->findByCanonicalName("{$given} Häffner"), 'author: unquoted, the exact spelling wins once both exist');
$same($newAuthor, $authors->findByCanonicalName("\"Häffner, {$given}\""), 'author: a quoted inverted name is canonicalised before the exact match');
$ok($newAuthor !== $authorPlain, 'author: quoted and unquoted spellings are two people');

$ok($publishers->findByName("\"Verlag {$given} Häffner\"") === null, 'publisher: quoted Häffner is not merged into Haffner');
$newPublisher = $publishers->create(['nome' => "\"Verlag {$given} Häffner\""]);
$same("Verlag {$given} Häffner", $nameOf('editori', $newPublisher), 'publisher: a quoted new name is stored without the quotes');
$same($newPublisher, $publishers->findByName("\"Verlag {$given} Häffner\""), 'publisher: the quoted spelling now finds the new publisher');

foreach ([\App\Controllers\CsvImportController::class => 'CSV import', \App\Controllers\LibraryThingImportController::class => 'LibraryThing import'] as $class => $label) {
    $importer = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    $getOrCreate = new \ReflectionMethod($importer, 'getOrCreatePublisher');
    $first = $getOrCreate->invoke($importer, $db, "\"Verlag {$given} Höffner {$label}\"");
    $ok($first['created'] === true && $nameOf('editori', (int) $first['id']) === "Verlag {$given} Höffner {$label}", "{$label}: a quoted publisher is created without quotes");
    $same(['id' => $first['id'], 'created' => false], $getOrCreate->invoke($importer, $db, "\"Verlag {$given} Höffner {$label}\""), "{$label}: the same quoted publisher is reused, not duplicated");
}
// A LibraryThing row normalises its names before the lookup. A quoted
// inverted name must come out canonical WITH the quotes around the whole name
// (`"Given Höffner"`, never `Given" "Höffner`), so the exact lookup still
// applies and the stored author has no quote characters.
$lt = (new \ReflectionClass(\App\Controllers\LibraryThingImportController::class))->newInstanceWithoutConstructor();
$ltRow = (new \ReflectionMethod($lt, 'parseLibraryThingRow'))->invoke($lt, [
    'Title' => 'Quoted names',
    'Primary Author' => "\"Höffner, {$given}\"",
    'Secondary Author' => "\"Hüffner, {$given}\"",
    'Secondary Author Roles' => 'Translator',
]);
$same("\"{$given} Höffner\"", $ltRow['autori'], 'LibraryThing import: a quoted inverted primary author is canonicalised inside the quotes');
$same("\"{$given} Hüffner\"", $ltRow['traduttore'] ?? null, 'LibraryThing import: a quoted inverted translator is canonicalised inside the quotes too');
$ltAuthor = new \ReflectionMethod($lt, 'getOrCreateAuthor');
$ltFirst = $ltAuthor->invoke($lt, $db, $ltRow['autori']);
$ltName = $nameOf('autori', (int) $ltFirst['id']);
$ok($ltFirst['created'] === true && $ltName === "{$given} Höffner" && !str_contains($ltName, '"'), 'LibraryThing import: the quoted author is stored without quote characters');
$same(['id' => $ltFirst['id'], 'created' => false], $ltAuthor->invoke($lt, $db, $ltRow['autori']), 'LibraryThing import: the same quoted author is reused, not duplicated');
$ltTranslator = \App\Support\ContributorSync::resolveNameIds($db, (string) $ltRow['traduttore']);
$ok($ltTranslator['created'] === 1 && $nameOf('autori', $ltTranslator['ids'][0]) === "{$given} Hüffner", 'LibraryThing import: a quoted translator reaches ContributorSync and is stored without quotes');

// CSV duplicate check, strategy 4 (title + first author, no ISBN): a quoted
// author matches only the author written that way; an unquoted one keeps the
// accent-tolerant comparison.
$dedupTitle = "Quoted dedup {$given}";
$dedupBook = Exact501::exec($db, "INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', 1, 1)", [$dedupTitle]);
Exact501::exec($db, "INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, 'principale', 0)", [$dedupBook, $authorPlain]);
Exact501::onCleanup(static function () use ($db, $dedupBook): void {
    Exact501::exec($db, 'DELETE FROM libri_autori WHERE libro_id = ?', [$dedupBook]);
    Exact501::exec($db, 'DELETE FROM libri WHERE id = ?', [$dedupBook]);
});
$csv = (new \ReflectionClass(\App\Controllers\CsvImportController::class))->newInstanceWithoutConstructor();
$findExisting = new \ReflectionMethod($csv, 'findExistingBook');
$same($dedupBook, $findExisting->invoke($csv, $db, ['titolo' => $dedupTitle, 'autori' => "{$given} Haffner"]), 'CSV dedup: title + unquoted first author finds the book');
$same($dedupBook, $findExisting->invoke($csv, $db, ['titolo' => $dedupTitle, 'autori' => "{$given} Häffner"]), 'CSV dedup: an unquoted Häffner still matches Haffner (accent-tolerant)');
$same($dedupBook, $findExisting->invoke($csv, $db, ['titolo' => $dedupTitle, 'autori' => "\"{$given} Haffner\""]), 'CSV dedup: a quoted Haffner matches the book by Haffner');
$same($dedupBook, $findExisting->invoke($csv, $db, ['titolo' => $dedupTitle, 'autori' => "\"Haffner, {$given}\""]), 'CSV dedup: a quoted inverted Haffner matches too');
$same(null, $findExisting->invoke($csv, $db, ['titolo' => $dedupTitle, 'autori' => "\"{$given} Häffner\""]), 'CSV dedup: a quoted Häffner is a different person and does not match the book by Haffner');

// The typed contributor branch of updateOptionals() runs after the entity
// sync: it must write the cache column without the whole-name quotes.
(new \App\Models\BookRepository($db))->updateOptionals($dedupBook, ['traduttore' => "\"Hüffner, {$given}\"", 'curatore' => "\"{$given} Höffner\"; Mario Rossi"]);
$cached = $db->query("SELECT traduttore, curatore FROM libri WHERE id = {$dedupBook}")->fetch_assoc();
$same("{$given} Hüffner", $cached['traduttore'] ?? null, 'updateOptionals: a quoted inverted translator is cached canonical and without quotes');
$same("{$given} Höffner; Mario Rossi", $cached['curatore'] ?? null, 'updateOptionals: only the whole-name quotes go; a list keeps its names');

$typed = $periodicals->resolve(["new:\"Verlag {$given} Hüffner\""]);
$ok(count($typed) === 1 && $nameOf('editori', $typed[0]) === "Verlag {$given} Hüffner" && $typed[0] !== $pubPlain, 'periodicals: a quoted typed publisher is a new one, stored without quotes');

Exact501::finish(53);
