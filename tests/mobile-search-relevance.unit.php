<?php
declare(strict_types=1);

/**
 * Mobile API regression for weighted catalog search and relevance cursors.
 *
 * Run: php tests/mobile-search-relevance.unit.php
 */

use App\Plugins\MobileApi\Controllers\CatalogController;
use App\Support\SchemaInfo;
use App\Support\SearchIndexBuilder;
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
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$prefix = 'ZZ_MOBREL_' . $run;
$term = 'mobrel' . $run;
$likePrefix = $prefix . '%';

$cleanup = static function () use ($db, $likePrefix): void {
    foreach ([
        'DELETE la FROM libri_autori la JOIN libri l ON l.id = la.libro_id WHERE l.titolo LIKE ?',
        'DELETE le FROM libri_editori le JOIN libri l ON l.id = le.libro_id WHERE l.titolo LIKE ?',
        'DELETE FROM libri WHERE titolo LIKE ?',
        'DELETE FROM autori WHERE nome LIKE ?',
        'DELETE FROM editori WHERE nome LIKE ?',
    ] as $sql) {
        if (str_contains($sql, 'libri_editori') && !SchemaInfo::hasLibriEditori($db)) {
            continue;
        }
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $likePrefix);
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

$makeBook = static function (string $suffix, string $title, string $description = '') use ($db, $prefix): int {
    $fullTitle = $prefix . '_' . $suffix . ' ' . $title;
    $stmt = $db->prepare(
        "INSERT INTO libri (titolo, descrizione, descrizione_plain, stato, copie_totali, copie_disponibili)
         VALUES (?, ?, ?, 'disponibile', 1, 1)"
    );
    $stmt->bind_param('sss', $fullTitle, $description, $description);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$bookTitle = $makeBook('T', $term . ' title');
$bookAuthor = $makeBook('A', 'ordinary title');
$bookSubtitle = $makeBook('S', 'ordinary title');
$bookPublisher = $makeBook('P', 'ordinary title');
$bookKeywords = $makeBook('K', 'ordinary title');
$bookDescription = $makeBook('D', 'ordinary title', "The {$term} occurs only here.");

$authorName = $prefix . ' Author ' . $term;
$stmt = $db->prepare('INSERT INTO autori (nome) VALUES (?)');
$stmt->bind_param('s', $authorName);
$stmt->execute();
$authorId = (int) $db->insert_id;
$stmt->close();
$stmt = $db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, 'principale')");
$stmt->bind_param('ii', $bookAuthor, $authorId);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare('UPDATE libri SET sottotitolo = ? WHERE id = ?');
$stmt->bind_param('si', $term, $bookSubtitle);
$stmt->execute();
$stmt->close();

$publisherName = $prefix . ' Publisher ' . $term;
$stmt = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
$stmt->bind_param('s', $publisherName);
$stmt->execute();
$publisherId = (int) $db->insert_id;
$stmt->close();
$stmt = $db->prepare('UPDATE libri SET editore_id = ? WHERE id = ?');
$stmt->bind_param('ii', $publisherId, $bookPublisher);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare('UPDATE libri SET parole_chiave = ? WHERE id = ?');
$stmt->bind_param('si', $term, $bookKeywords);
$stmt->execute();
$stmt->close();

SearchIndexBuilder::rebuildMany($db, [
    $bookTitle,
    $bookAuthor,
    $bookSubtitle,
    $bookPublisher,
    $bookKeywords,
    $bookDescription,
]);
$indexRows = $db->query("SELECT id, search_index FROM libri WHERE id IN ({$bookAuthor}, {$bookPublisher})")
    ->fetch_all(MYSQLI_ASSOC);
$indexes = array_column($indexRows, 'search_index', 'id');
$check(str_contains((string) ($indexes[$bookAuthor] ?? ''), $term), 'author is folded into the mobile search index');
$check(str_contains((string) ($indexes[$bookPublisher] ?? ''), $term), 'publisher is folded into the mobile search index');

$controller = new CatalogController($db);
$requestFactory = new ServerRequestFactory();
$responseFactory = new ResponseFactory();
$call = static function (array $query) use ($controller, $requestFactory, $responseFactory): array {
    $request = $requestFactory
        ->createServerRequest('GET', '/api/v1/catalog/search')
        ->withQueryParams($query);
    $response = $controller->search($request, $responseFactory->createResponse());
    $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    return [$response->getStatusCode(), $payload];
};

echo "A. Weighted relevance + stable cursor\n";
$expected = [$bookTitle, $bookAuthor, $bookSubtitle, $bookPublisher, $bookKeywords, $bookDescription];
$actual = [];
$cursor = null;
do {
    $query = ['q' => $term, 'limit' => 2];
    if ($cursor !== null) {
        $query['cursor'] = $cursor;
    }
    [$status, $payload] = $call($query);
    $check($status === 200, 'relevance page returns 200');
    $actual = array_merge($actual, array_map('intval', array_column($payload['data'] ?? [], 'id')));
    $cursor = $payload['meta']['next_cursor'] ?? null;
} while (is_string($cursor) && $cursor !== '' && count($actual) < 20);
$check($actual === $expected, 'mobile default relevance preserves every weighted field across cursor pages'
    . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
$check(count($actual) === count(array_unique($actual)), 'relevance cursor does not duplicate rows');

echo "B. Wildcard normalization + catalog limits\n";
[$statusStars, $payloadStars] = $call(['q' => str_repeat('* ', 30) . $term, 'limit' => 999]);
$idsStars = array_map('intval', array_column($payloadStars['data'] ?? [], 'id'));
$check($statusStars === 200 && in_array($bookTitle, $idsStars, true), 'ignored wildcard tokens cannot make mobile search return 500');
$check((int) ($payloadStars['meta']['limit'] ?? 0) === 50, 'mobile catalog page size is clamped to 50');

$catalogWords = [];
for ($i = 1; $i <= 26; $i++) {
    $catalogWords[] = $term . 'word' . $i;
}
$lastCatalogWord = $catalogWords[count($catalogWords) - 1];
$bookUncappedTitle = $makeBook('Z_UNCAPPED_TITLE', $lastCatalogWord, implode(' ', array_slice($catalogWords, 0, -1)));
$bookUncappedDescription = $makeBook('A_UNCAPPED_DESCRIPTION', 'ordinary title', implode(' ', $catalogWords));
SearchIndexBuilder::rebuildMany($db, [$bookUncappedTitle, $bookUncappedDescription]);
[$uncappedStatus, $uncappedPayload] = $call(['q' => implode(' ', $catalogWords), 'limit' => 2]);
$uncappedIds = array_map('intval', array_column($uncappedPayload['data'] ?? [], 'id'));
$check($uncappedStatus === 200 && ($uncappedIds[0] ?? 0) === $bookUncappedTitle,
    'mobile catalog weighs terms beyond the AJAX 24-word cap');

// A non-empty query with no usable FULLTEXT token (pure wildcards like '*')
// adds no filter and browses the whole catalogue — matching the web catalog
// page, this endpoint's analogue. It must NOT 500 or return an empty set.
[$wildStatus, $wildPayload] = $call(['q' => '**', 'limit' => 50]);
$check($wildStatus === 200 && ($wildPayload['data'] ?? []) !== [],
    'pure-wildcard query browses the catalogue (mirrors the web catalog page), not empty/500');

echo "C. Identifier relevance\n";
$identifier = substr('979' . str_pad((string) abs(crc32($run)), 10, '0', STR_PAD_LEFT), 0, 13);
$bookIdentifier = $makeBook('I', 'ordinary identifier');
$bookIdentifierDescription = $makeBook('ID', 'ordinary identifier description', "The {$identifier} occurs only here.");
$stmt = $db->prepare('UPDATE libri SET isbn13 = ? WHERE id = ?');
$stmt->bind_param('si', $identifier, $bookIdentifier);
$stmt->execute();
$stmt->close();
SearchIndexBuilder::rebuildMany($db, [$bookIdentifier, $bookIdentifierDescription]);
[$identifierStatus, $identifierPayload] = $call(['q' => $identifier, 'sort' => 'relevance']);
$identifierIds = array_map('intval', array_column($identifierPayload['data'] ?? [], 'id'));
$check($identifierStatus === 200 && ($identifierIds[0] ?? 0) === $bookIdentifier,
    'mobile API ranks ISBN/EAN matches above description-only matches');

if (SchemaInfo::hasLibriEditori($db)) {
    echo "D. Secondary publisher filter\n";
    $secondaryName = $prefix . '_SECONDARY';
    $stmt = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
    $stmt->bind_param('s', $secondaryName);
    $stmt->execute();
    $secondaryId = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO libri_editori (libro_id, editore_id, ordine) VALUES (?, ?, 1)');
    $stmt->bind_param('ii', $bookTitle, $secondaryId);
    $stmt->execute();
    $stmt->close();
    SearchIndexBuilder::rebuild($db, $bookTitle);

    [$publisherStatus, $publisherPayload] = $call(['publisher' => (string) $secondaryId]);
    $publisherIds = array_map('intval', array_column($publisherPayload['data'] ?? [], 'id'));
    $check($publisherStatus === 200 && in_array($bookTitle, $publisherIds, true),
        'numeric mobile publisher filter includes secondary publishers');
    [$publisherNameStatus, $publisherNamePayload] = $call(['publisher' => $secondaryName]);
    $publisherNameIds = array_map('intval', array_column($publisherNamePayload['data'] ?? [], 'id'));
    $check($publisherNameStatus === 200 && in_array($bookTitle, $publisherNameIds, true),
        'name mobile publisher filter includes secondary publishers');
}

$cleanup();
$db->close();
echo "\n{$pass} PASS, 0 FAIL\n";
exit(0);
