<?php
declare(strict_types=1);

/**
 * Real-controller regression for #282: catalogue language discovery, tolerant
 * filtering, soft-delete exclusion and conditional ETag responses.
 *
 * Run: php tests/mobile-language-filter-282.unit.php
 */

use App\Plugins\MobileApi\Controllers\CatalogController;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/CursorCodec.php';
require_once $root . '/storage/plugins/mobile-api/src/Controllers/CatalogController.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZ_LANG282_' . $run;
$language = 'ZzLang' . $run;
$languageVariant = strtolower($language);

$cleanup = static function () use ($db, $titlePrefix): void {
    $like = $titlePrefix . '%';
    // libri first — libri_autori cascades on the FK — then the facet rows we
    // seeded for the combination tests (autori/editori/generi), which are only
    // deletable once no libro references them.
    foreach (['DELETE FROM libri WHERE titolo LIKE ?',
              'DELETE FROM autori WHERE nome LIKE ?',
              'DELETE FROM editori WHERE nome LIKE ?',
              'DELETE FROM generi WHERE nome LIKE ?'] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt->close();
    }
};
$cleanup();
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try {
        $cleanup();
    } catch (Throwable) {
    }
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $pass++;
    echo "  OK  {$label}\n";
};

$insertBook = static function (string $suffix, ?string $bookLanguage, bool $deleted = false) use ($db, $titlePrefix): int {
    $title = $titlePrefix . '_' . $suffix;
    $deletedAt = $deleted ? date('Y-m-d H:i:s') : null;
    $stmt = $db->prepare(
        "INSERT INTO libri (titolo, lingua, stato, copie_totali, copie_disponibili, deleted_at)
         VALUES (?, ?, 'non_disponibile', 0, 0, ?)"
    );
    $stmt->bind_param('sss', $title, $bookLanguage, $deletedAt);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$firstId = $insertBook('one', '  ' . $language . '  ');
$secondId = $insertBook('two', $languageVariant);
$deletedId = $insertBook('deleted', strtoupper($language), true);
$insertBook('blank', '   ');
$insertBook('null', null);

$controller = new CatalogController($db);
$requestFactory = new ServerRequestFactory();
$responseFactory = new ResponseFactory();

echo "A. Catalogue language discovery\n";
$request = $requestFactory->createServerRequest('GET', '/api/v1/catalog/languages');
$response = $controller->languages($request, $responseFactory->createResponse());
$check($response->getStatusCode() === 200, 'languages endpoint returns 200');
$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
$rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$match = null;
foreach ($rows as $row) {
    if (strtolower(trim((string) ($row['language'] ?? ''))) === strtolower($language)) {
        $match = $row;
        break;
    }
}
$check($match !== null && (int) ($match['count'] ?? 0) === 2, 'case/space variants collapse to one value with the correct active-book count');
$check((int) ($payload['meta']['count'] ?? -1) === count($rows), 'response metadata reports the number of distinct values');
$etag = $response->getHeaderLine('ETag');
$check($etag !== '', 'languages response includes an ETag');

$conditional = $requestFactory
    ->createServerRequest('GET', '/api/v1/catalog/languages')
    ->withHeader('If-None-Match', $etag);
$notModified = $controller->languages($conditional, $responseFactory->createResponse());
$check($notModified->getStatusCode() === 304, 'matching If-None-Match returns 304');

echo "B. Tolerant search filter\n";
$searchRequest = $requestFactory
    ->createServerRequest('GET', '/api/v1/catalog/search')
    ->withQueryParams(['language' => '  ' . strtoupper($language) . '  ', 'limit' => 50]);
$searchResponse = $controller->search($searchRequest, $responseFactory->createResponse());
$check($searchResponse->getStatusCode() === 200, 'case-insensitive language search returns 200');
$searchPayload = json_decode((string) $searchResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
$ids = array_map('intval', array_column(is_array($searchPayload['data'] ?? null) ? $searchPayload['data'] : [], 'id'));
$check(in_array($firstId, $ids, true) && in_array($secondId, $ids, true), 'filter matches stored case and surrounding-space variants');
$check(!in_array($deletedId, $ids, true), 'soft-deleted books are excluded from the filter');

echo "C. Localized error contract\n";
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR'] as $locale) {
    $catalogue = json_decode(
        (string) file_get_contents($root . '/locale/' . $locale . '.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $check(isset($catalogue['Lingue non disponibili.']) && trim((string) $catalogue['Lingue non disponibili.']) !== '', "{$locale} contains the endpoint error translation");
}

// Helper: run /catalog/search with the given query params, return the id list.
$searchIds = static function (array $query) use ($controller, $requestFactory, $responseFactory): array {
    $req = $requestFactory
        ->createServerRequest('GET', '/api/v1/catalog/search')
        ->withQueryParams($query + ['limit' => 50]);
    $resp = $controller->search($req, $responseFactory->createResponse());
    if ($resp->getStatusCode() !== 200) {
        throw new RuntimeException('search returned ' . $resp->getStatusCode());
    }
    $body = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    return array_map('intval', array_column(is_array($body['data'] ?? null) ? $body['data'] : [], 'id'));
};

echo "D. Combined filters + language (the #282 regression)\n";
// Seed a genre, publisher and author, then a book carrying ALL of them plus the
// test language, and a decoy that shares the facets but has a DIFFERENT language
// (must be excluded once the language clause is ANDed in).
$db->query("INSERT INTO generi (nome) VALUES ('" . $db->real_escape_string($titlePrefix . '_G') . "')");
$genreId = (int) $db->insert_id;
$db->query("INSERT INTO editori (nome) VALUES ('" . $db->real_escape_string($titlePrefix . '_P') . "')");
$publisherId = (int) $db->insert_id;
$db->query("INSERT INTO autori (nome) VALUES ('" . $db->real_escape_string($titlePrefix . '_Auth') . "')");
$authorId = (int) $db->insert_id;

$insertRichBook = static function (string $suffix, string $bookLanguage, int $genreId, int $publisherId, ?int $authorId) use ($db, $titlePrefix): int {
    $title = $titlePrefix . '_' . $suffix;
    $stmt = $db->prepare(
        "INSERT INTO libri (titolo, lingua, genere_id, editore_id, stato, copie_totali, copie_disponibili)
         VALUES (?, ?, ?, ?, 'non_disponibile', 0, 0)"
    );
    $stmt->bind_param('ssii', $title, $bookLanguage, $genreId, $publisherId);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    if ($authorId !== null) {
        $link = $db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, 'principale', 1)");
        $link->bind_param('ii', $id, $authorId);
        $link->execute();
        $link->close();
    }
    return $id;
};

$comboMatch = $insertRichBook('combo_match', $language, $genreId, $publisherId, $authorId);
$comboOtherLang = $insertRichBook('combo_otherlang', 'ZzOther' . $run, $genreId, $publisherId, $authorId);

$byGenreLang = $searchIds(['genre' => $genreId, 'language' => $language]);
$check(in_array($comboMatch, $byGenreLang, true), 'genre + language returns the matching book');
$check(!in_array($comboOtherLang, $byGenreLang, true), 'genre + language excludes the same-genre book in another language');

$byAuthorLang = $searchIds(['author' => (string) $authorId, 'language' => $language]);
$check(in_array($comboMatch, $byAuthorLang, true), 'author + language returns the matching book');
$check(!in_array($comboOtherLang, $byAuthorLang, true), 'author + language excludes the same-author book in another language');

$byPublisherLang = $searchIds(['publisher' => (string) $publisherId, 'language' => $language]);
$check(in_array($comboMatch, $byPublisherLang, true), 'publisher + language returns the matching book');
$check(!in_array($comboOtherLang, $byPublisherLang, true), 'publisher + language excludes the same-publisher book in another language');

echo "E. Author sort (issue #282 requirement 1), NULL-safe pagination\n";
$sortLang = 'ZzSort' . $run;
$db->query("INSERT INTO autori (nome) VALUES ('" . $db->real_escape_string($titlePrefix . '_A_first') . "')");
$authorA = (int) $db->insert_id;
$db->query("INSERT INTO autori (nome) VALUES ('" . $db->real_escape_string($titlePrefix . '_Z_last') . "')");
$authorZ = (int) $db->insert_id;
$bookA = $insertRichBook('sortA', $sortLang, $genreId, $publisherId, $authorA);
$bookZ = $insertRichBook('sortZ', $sortLang, $genreId, $publisherId, $authorZ);
$bookNoAuthor = $insertRichBook('sortNone', $sortLang, $genreId, $publisherId, null);

$asc = $searchIds(['language' => $sortLang, 'sort' => 'author_asc']);
$posA = array_search($bookA, $asc, true);
$posZ = array_search($bookZ, $asc, true);
$check($posA !== false && $posZ !== false && $posA < $posZ, 'author_asc orders A-author before Z-author');
$check(in_array($bookNoAuthor, $asc, true), 'author_asc keeps the authorless book (COALESCE NULL-safety)');

$desc = $searchIds(['language' => $sortLang, 'sort' => 'author_desc']);
$posAd = array_search($bookA, $desc, true);
$posZd = array_search($bookZ, $desc, true);
$check($posAd !== false && $posZd !== false && $posZd < $posAd, 'author_desc orders Z-author before A-author');
$check(in_array($bookNoAuthor, $desc, true), 'author_desc keeps the authorless book (COALESCE NULL-safety)');

$cleanup();
$db->close();
echo "\n{$pass} PASS, 0 FAIL\n";
exit(0);
