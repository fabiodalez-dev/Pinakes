<?php
declare(strict_types=1);

/**
 * Behavioral regressions for issue #237 beyond the schema migration itself.
 * Runs transactionally against an otherwise empty CI database.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\SearchController;
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

    // The one-time marker must not disable conversion for imports performed
    // after the migration has completed.
    (new SettingsRepository($db))->set('migrations', 'contributors_backfilled', '1');
    $translator = "ZZ Translator {$token}";
    $created = ContributorSync::linkLegacyValues($db, $bookId, ['traduttore' => $translator]);
    $translatorId = $authors->findByCanonicalName($translator);
    $translatorLinks = $translatorId === null ? 0 : (int)$db->query("SELECT COUNT(*) FROM libri_autori WHERE libro_id={$bookId} AND autore_id={$translatorId} AND ruolo='traduttore'")->fetch_column();
    $check($created === 1 && $translatorId !== null, 'post-migration ingestion creates a contributor entity');
    $check($translatorLinks === 1, 'post-migration ingestion creates the role link');

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
    $check(str_contains($csv, 'ContributorSync::linkLegacyValues'), 'CSV import synchronizes contributor entities');
    $check(str_contains($libraryThing, 'ContributorSync::linkLegacyValues'), 'LibraryThing import synchronizes contributor entities');
    $check(str_contains($publicDetail, 'AuthorName::display($authors[0])'), 'public SEO metadata uses the preferred pseudonym display');
    $check(str_contains($publicDetail, "case 'colorista':") && str_contains($publicDetail, '$bookSchema["contributor"]'), 'colorists are structured-data contributors, not primary authors');
} finally {
    $db->rollback();
    $db->close();
}

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} passed, {$failed} FAILED\n");
exit($failed === 0 ? 0 : 1);
