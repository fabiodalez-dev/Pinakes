<?php
declare(strict_types=1);

/**
 * Behavioral regressions for issue #237 beyond the schema migration itself.
 * Runs transactionally against an otherwise empty CI database.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\SearchController;
use App\Controllers\FrontendController;
use App\Controllers\PublicApiController;
use App\Models\AuthorRepository;
use App\Models\BookRepository;
use App\Models\SettingsRepository;
use App\Support\AuthorName;
use App\Support\ContributorSync;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}\n";
    }
};

$env = [];
foreach (preg_split('/\r?\n/', (string)@file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $value = trim($value);
    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
        $value = substr($value, 1, -1);
    }
    $env[trim($key)] = $value;
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int)($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect to DB: ' . $e->getMessage() . "\n");
    exit(1);
}

$token = bin2hex(random_bytes(5));
$realName = "ZZ Real Name {$token}";
$pseudonym = "ZZPseudo{$token}";

echo "A. Author display and search\n";
$check(AuthorName::display(['nome' => '  Real Name  ', 'pseudonimo' => '  Pen Name  ']) === 'Pen Name (Real Name)', 'PHP display trims and prefers pseudonym');
$check(AuthorName::display(['nome' => 'Real Name', 'pseudonimo' => '   ']) === 'Real Name', 'whitespace-only pseudonym falls back to real name');

$db->begin_transaction();
try {
    $authors = new AuthorRepository($db);
    $authorId = $authors->create(['nome' => $realName, 'pseudonimo' => $pseudonym]);

    $check($authors->findByCanonicalName($realName) === $authorId, 'canonical lookup finds the real author name');
    $check($authors->findByCanonicalName($pseudonym) === null, 'canonical lookup never treats a pseudonym as an imported author name');
    $check($authors->findByName($pseudonym) === null, 'legacy importer lookup keeps the canonical-name contract');
    $check(ContributorSync::splitNames('Levi, Primo') === ['Levi, Primo'], 'SBN inverted canonical name is not split into two people');
    $check(ContributorSync::splitNames('Mario Rossi, Luigi Bianchi') === ['Mario Rossi', 'Luigi Bianchi'], 'comma-separated complete names remain supported');
    $check(ContributorSync::splitNames('Levi, Primo; Eco, Umberto') === ['Levi, Primo', 'Eco, Umberto'], 'explicit lists preserve each inverted SBN name');

    $sqlDisplay = AuthorName::displaySql('a');
    $row = $db->query("SELECT {$sqlDisplay} AS label FROM autori a WHERE id=" . (int)$authorId)->fetch_assoc();
    $check(($row['label'] ?? '') === "{$pseudonym} ({$realName})", 'SQL display matches PHP display');

    $search = new SearchController();
    $requestFactory = new ServerRequestFactory();
    $call = static function (string $path, callable $handler) use ($requestFactory, $pseudonym): array {
        $request = $requestFactory->createServerRequest('GET', $path)->withQueryParams(['q' => $pseudonym]);
        $response = $handler($request, new Response());
        return json_decode((string)$response->getBody(), true) ?: [];
    };

    $pickerRows = $call('/api/search/autori', fn($request, $response) => $search->authors($request, $response, $db));
    $check(count(array_filter($pickerRows, static fn(array $r): bool => (int)($r['id'] ?? 0) === $authorId)) === 1, 'entity picker finds an author by pseudonym');
    $check(($pickerRows[0]['label'] ?? '') === "{$pseudonym} ({$realName})", 'entity picker displays pseudonym and real name');

    $globalRows = $call('/api/search', fn($request, $response) => $search->unifiedSearch($request, $response, $db));
    $globalAuthor = array_values(array_filter($globalRows, static fn(array $r): bool => ($r['type'] ?? '') === 'author' && (int)($r['id'] ?? 0) === $authorId));
    $check(count($globalAuthor) === 1, 'global search finds an author by pseudonym');
    $check(($globalAuthor[0]['label'] ?? '') === "{$pseudonym} ({$realName})", 'global search uses the preferred display name');

    $previewRows = $call('/search/preview', fn($request, $response) => $search->searchPreview($request, $response, $db));
    $previewAuthor = array_values(array_filter($previewRows, static fn(array $r): bool => ($r['type'] ?? '') === 'author' && (int)($r['id'] ?? 0) === $authorId));
    $check(count($previewAuthor) === 1, 'search preview finds an author by pseudonym');
    $check(($previewAuthor[0]['name'] ?? '') === "{$pseudonym} ({$realName})", 'search preview uses the preferred display name');

    echo "B. Persistence and ingestion\n";
    $repo = new BookRepository($db);
    $bookId = $repo->createBasic([
        'titolo' => "ZZ contributor book {$token}",
        'autori_ids' => [$authorId],
        'illustratori_ids' => [],
        'traduttori_ids' => [],
        'curatori_ids' => [],
        'coloristi_ids' => [],
    ]);

    $legacyIllustrator = "ZZ Legacy Illustrator {$token}";
    $stmt = $db->prepare('UPDATE libri SET illustratore=? WHERE id=?');
    $stmt->bind_param('si', $legacyIllustrator, $bookId);
    $stmt->execute();
    $stmt->close();

    // Full entity-form submission with no illustrator must not blank the
    // retained legacy safety-net column before a backfill has consumed it.
    $repo->updateBasic($bookId, [
        'titolo' => "ZZ contributor book edited {$token}",
        'autori_ids' => [$authorId],
        'illustratori_ids' => [],
        'traduttori_ids' => [],
        'curatori_ids' => [],
        'coloristi_ids' => [],
    ]);
    $legacyAfterSave = $db->query('SELECT illustratore FROM libri WHERE id=' . (int)$bookId)->fetch_column();
    $check($legacyAfterSave === $legacyIllustrator, 'entity-form save preserves untouched legacy contributor text');

    // A partial repository update has no contributor keys and must not mutate
    // any existing links.
    $repo->updateBasic($bookId, ['titolo' => "ZZ partial update {$token}"]);
    $principalCount = (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$authorId} AND ruolo='principale'")->fetch_column();
    $check($principalCount === 1, 'partial repository update preserves contributor links');

    // Invalid desired rows must roll back the entire contributor sync before
    // the existing principal link is pruned.
    $invalidRejected = false;
    try {
        $repo->updateBasic($bookId, [
            'titolo' => "ZZ invalid contributor {$token}",
            'autori_ids' => [PHP_INT_MAX],
        ]);
    } catch (Throwable) {
        $invalidRejected = true;
    }
    $principalAfterInvalid = (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$authorId} AND ruolo='principale'")->fetch_column();
    $check($invalidRejected, 'invalid contributor id aborts the synchronization');
    $check($principalAfterInvalid === 1, 'invalid contributor id cannot prune the existing principal');

    $zeroRejected = false;
    try {
        $repo->updateBasic($bookId, [
            'titolo' => "ZZ zero contributor {$token}",
            'autori_ids' => [0],
        ]);
    } catch (Throwable) {
        $zeroRejected = true;
    }
    $principalAfterZero = (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$authorId} AND ruolo='principale'")->fetch_column();
    $check($zeroRejected, 'zero contributor id aborts the synchronization');
    $check($principalAfterZero === 1, 'zero contributor id cannot clear the existing principal');

    // The creator picker contains both principals and co-authors. Resubmitting
    // it must preserve an existing co-author role rather than adding a duplicate
    // principal row for the same person.
    $coauthorId = $authors->create(['nome' => "ZZ Coauthor {$token}"]);
    $db->query("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES ({$bookId}, {$coauthorId}, 'co-autore')");
    $repo->updateBasic($bookId, [
        'titolo' => "ZZ coauthor resubmit {$token}",
        'autori_ids' => [$authorId, $coauthorId],
    ]);
    $coauthorRoles = $db->query("SELECT ruolo FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$coauthorId} ORDER BY ruolo")->fetch_all(MYSQLI_ASSOC);
    $check(array_column($coauthorRoles, 'ruolo') === ['co-autore'], 'creator resubmit preserves co-author role without a principal duplicate');

    // The one-time marker must not disable conversion for imports performed
    // after the migration has completed.
    (new SettingsRepository($db))->set('migrations', 'contributors_backfilled', '1');
    $translator = "ZZ Translator {$token}";
    $created = ContributorSync::linkLegacyValues($db, $bookId, ['traduttore' => $translator]);
    $translatorId = $authors->findByCanonicalName($translator);
    $translatorLinks = $translatorId === null ? 0 : (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$translatorId} AND ruolo='traduttore'")->fetch_column();
    $check($created === 1 && $translatorId !== null, 'post-migration ingestion creates a contributor entity');
    $check($translatorLinks === 1, 'post-migration ingestion creates the role link');

    // Import provenance: a CSV reimport replaces its own translator, while an
    // unrelated manual translator remains linked.
    $manualTranslator = "ZZ Manual Translator {$token}";
    $manualTranslatorId = $authors->create(['nome' => $manualTranslator]);
    $db->query("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES ({$bookId}, {$manualTranslatorId}, 'traduttore')");
    $oldImported = "ZZ Old Imported Translator {$token}";
    ContributorSync::syncImportedLegacyValues($db, $bookId, ['traduttore' => $oldImported], 'csv');
    $oldImportedId = $authors->findByCanonicalName($oldImported);
    $newImported = "ZZ New Imported Translator {$token}";
    ContributorSync::syncImportedLegacyValues($db, $bookId, ['traduttore' => $newImported], 'csv');
    $newImportedId = $authors->findByCanonicalName($newImported);
    $oldCount = $oldImportedId === null ? 0 : (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$oldImportedId} AND ruolo='traduttore'")->fetch_column();
    $newCount = $newImportedId === null ? 0 : (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$newImportedId} AND ruolo='traduttore'")->fetch_column();
    $manualCount = (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$manualTranslatorId} AND ruolo='traduttore'")->fetch_column();
    $check($oldCount === 0 && $newCount === 1, 'CSV reimport replaces its own stale contributor link');
    $check($manualCount === 1, 'CSV reimport preserves an untracked manual contributor link');

    // Two importers may legitimately own the same link. Removing it from one
    // source must keep it alive until the last owning source also replaces it.
    ContributorSync::syncImportedLegacyValues($db, $bookId, ['traduttore' => $newImported], 'librarything');
    $csvReplacement = "ZZ CSV Replacement {$token}";
    ContributorSync::syncImportedLegacyValues($db, $bookId, ['traduttore' => $csvReplacement], 'csv');
    $sharedStillLinked = $newImportedId === null ? 0 : (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$newImportedId} AND ruolo='traduttore'")->fetch_column();
    $check($sharedStillLinked === 1, 'a contributor shared by two importers survives the first source replacement');
    $libraryThingReplacement = "ZZ LibraryThing Replacement {$token}";
    ContributorSync::syncImportedLegacyValues($db, $bookId, ['traduttore' => $libraryThingReplacement], 'librarything');
    $sharedAfterBothReplace = $newImportedId === null ? 0 : (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$newImportedId} AND ruolo='traduttore'")->fetch_column();
    $check($sharedAfterBothReplace === 0, 'shared imported contributor is removed after its final owning source replaces it');

    // Saving an imported association in the admin form is an explicit manual
    // decision. It must release importer ownership so a later re-import cannot
    // silently remove the contributor the administrator retained.
    $importedIllustrator = "ZZ Imported Illustrator {$token}";
    ContributorSync::syncImportedLegacyValues($db, $bookId, ['illustratore' => $importedIllustrator], 'csv');
    $importedIllustratorId = $authors->findByCanonicalName($importedIllustrator);
    $check($importedIllustratorId !== null, 'imported contributor is resolved before manual adoption');
    $repo->updateBasic($bookId, [
        'titolo' => "ZZ manual ownership {$token}",
        'illustratori_ids' => [$importedIllustratorId],
    ]);
    $retainedAfterAdmin = $importedIllustratorId === null ? 0 : (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$importedIllustratorId} AND ruolo='illustratore'")->fetch_column();
    $provenanceAfterAdmin = $importedIllustratorId === null ? 1 : (int)$db->query("SELECT COUNT(*) FROM libri_autori_import_sources WHERE libro_id={$bookId} AND autore_id={$importedIllustratorId} AND ruolo='illustratore'")->fetch_column();
    $check($retainedAfterAdmin === 1, 'admin save retains the explicitly selected imported contributor');
    $check($provenanceAfterAdmin === 0, 'admin save converts the selected contributor to manual ownership');
    $replacementIllustrator = "ZZ Replacement Illustrator {$token}";
    ContributorSync::syncImportedLegacyValues($db, $bookId, ['illustratore' => $replacementIllustrator], 'csv');
    $retainedAfterImport = $importedIllustratorId === null ? 0 : (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$importedIllustratorId} AND ruolo='illustratore'")->fetch_column();
    $releasedProvenance = $importedIllustratorId === null ? 1 : (int)$db->query("SELECT COUNT(*) FROM libri_autori_import_sources WHERE libro_id={$bookId} AND autore_id={$importedIllustratorId} AND ruolo='illustratore'")->fetch_column();
    $check($retainedAfterImport === 1, 'admin-confirmed contributor survives a later importer replacement');
    $check($releasedProvenance === 0, 'admin save releases stale importer ownership');

    // Related-book matching uses EXISTS, while the independent display subquery
    // must still aggregate every creator on the matched title.
    $relatedCoauthorName = "ZZ Related Coauthor {$token}";
    $relatedCoauthorId = $authors->create(['nome' => $relatedCoauthorName]);
    $relatedBookId = $repo->createBasic([
        'titolo' => "ZZ related complete creators {$token}",
        'autori_ids' => [$authorId, $relatedCoauthorId],
    ]);
    $relatedMethod = new ReflectionMethod(FrontendController::class, 'getRelatedBooks');
    $relatedMethod->setAccessible(true);
    $relatedRows = $relatedMethod->invoke(
        new FrontendController(),
        $db,
        $bookId,
        ['genere_id' => null],
        [['id' => $authorId, 'ruolo' => 'principale']],
        []
    );
    $relatedRow = array_values(array_filter(
        is_array($relatedRows) ? $relatedRows : [],
        static fn(array $row): bool => (int)($row['id'] ?? 0) === $relatedBookId
    ))[0] ?? [];
    $check(str_contains((string)($relatedRow['autori'] ?? ''), $realName)
        && str_contains((string)($relatedRow['autori'] ?? ''), $relatedCoauthorName),
        'related books display every creator, not only the matched author');

    // Public API author filters must treat SQL wildcard characters literally.
    $literalAuthor = "ZZ Wild_Author {$token}";
    $wildcardTwin = "ZZ WildXAuthor {$token}";
    $literalId = $authors->create(['nome' => $literalAuthor]);
    $wildcardTwinId = $authors->create(['nome' => $wildcardTwin]);
    $literalBookId = $repo->createBasic(['titolo' => "ZZ literal wildcard {$token}", 'autori_ids' => [$literalId]]);
    $repo->createBasic(['titolo' => "ZZ wildcard twin {$token}", 'autori_ids' => [$wildcardTwinId]]);
    $findBooks = new ReflectionMethod(PublicApiController::class, 'findBooks');
    $findBooks->setAccessible(true);
    $apiRows = $findBooks->invoke(new PublicApiController(), $db, null, null, null, $literalAuthor);
    $apiIds = array_map('intval', array_column(is_array($apiRows) ? $apiRows : [], 'id'));
    $check(in_array($literalBookId, $apiIds, true) && count($apiIds) === 1,
        'public API escapes underscore wildcards in author searches');

    // SBN/UNIMARC values are canonical names even when they happen to equal
    // another author's pseudonym. They must create/find by `nome`, never bind
    // to that pseudonymous identity.
    $sbnCanonical = "ZZ SBN Imported {$token}";
    $pseudonymousId = $authors->create(['nome' => "ZZ Other Real {$token}", 'pseudonimo' => $sbnCanonical]);
    $sbnResult = ContributorSync::resolveNameIds($db, $sbnCanonical);
    $sbnId = $sbnResult['ids'][0] ?? 0;
    $sbnRow = $sbnId > 0 ? $authors->getById($sbnId) : null;
    $check($sbnId > 0 && $sbnId !== $pseudonymousId, 'SBN canonical name never resolves through an existing pseudonym');
    $check(($sbnRow['nome'] ?? '') === $sbnCanonical, 'SBN canonical name is retained as the new author real name');

    echo "C. Wiring guards\n";
    $form = (string)file_get_contents($root . '/app/Views/libri/partials/book_form.php');
    $csv = (string)file_get_contents($root . '/app/Controllers/CsvImportController.php');
    $libraryThing = (string)file_get_contents($root . '/app/Controllers/LibraryThingImportController.php');
    $publicDetail = (string)file_get_contents($root . '/app/Views/frontend/book-detail.php');
    $check(str_contains($form, 'contributors_entity_picker') && str_contains($form, 'createContributorFromInput'), 'form marks authoritative picker payload and supports create-on-Enter');
    $check(str_contains($form, 'authorChoiceLabelMatchesInput') && str_contains($form, 'match[1].trim() === normalizedInput'), 'Enter recognizes pseudonym and real-name labels as existing authors');
    $check(str_contains($form, '__contributorPickers.traduttori.addName') && str_contains($form, '__contributorPickers.illustratori.addName'), 'scraping writes visible contributor chips');
    $check(str_contains($csv, 'ContributorSync::syncImportedLegacyValues') && str_contains($csv, "'csv'"), 'CSV import synchronizes contributor entities with provenance');
    $check(str_contains($libraryThing, 'ContributorSync::syncImportedLegacyValues') && str_contains($libraryThing, "'librarything'"), 'LibraryThing import synchronizes contributor entities with provenance');
    $check(str_contains($publicDetail, 'AuthorName::display($authors[0])'), 'public SEO metadata uses the preferred pseudonym display');
    $check(str_contains($publicDetail, "case 'colorista':") && str_contains($publicDetail, '$bookSchema["contributor"]'), 'colorists are structured-data contributors, not primary authors');
} finally {
    $db->rollback();
    $db->close();
}

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} passed, {$failed} FAILED\n");
exit($failed === 0 ? 0 : 1);
