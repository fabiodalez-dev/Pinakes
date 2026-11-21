<?php
declare(strict_types=1);

/**
 * Mobile API 1.6.0 — staff area, the parts that need no database:
 *
 *   A. StaffRoleMiddleware: admin/staff pass, every other role (and a missing
 *      identity) gets 403 `forbidden` and the handler never runs.
 *   B. BookCirculationCheck keeps the SAME two guard queries as
 *      LibriController::delete() — pinned against the core source text, so a
 *      change on either side fails here instead of silently diverging.
 *   C. StaffBookPayload: validation, the create mapping, and the PUT merge
 *      rule (absent key keeps the stored value, null clears, untouched
 *      relations are re-posted).
 *   D. Identifier parsing for the scanner (ISBN-10/13, non-book EAN-13) and
 *      the scraper → preview mapping.
 *   E. The ISBN / feed throttle answers 429 in the API envelope.
 *
 * Run: php tests/mobile-api-staff.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$plugin = __DIR__ . '/../storage/plugins/mobile-api/src';
require_once $plugin . '/Support/ResponseEnvelope.php';
require_once $plugin . '/Support/AppAuthMiddleware.php';
require_once $plugin . '/Support/StaffRoleMiddleware.php';
require_once $plugin . '/Support/ActionRateLimitMiddleware.php';
require_once $plugin . '/Support/BookCirculationCheck.php';
require_once $plugin . '/Support/StaffBookPayload.php';
require_once $plugin . '/Support/HttpCache.php';
require_once $plugin . '/Support/StaffBookReader.php';
require_once $plugin . '/Support/StaffCoverStore.php';
require_once $plugin . '/Support/JsonBody.php';
require_once $plugin . '/Controllers/StaffBooksController.php';

use App\Plugins\MobileApi\Controllers\StaffBooksController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\BookCirculationCheck;
use App\Plugins\MobileApi\Support\StaffBookPayload;
use App\Plugins\MobileApi\Support\StaffRoleMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $label . "\n";
    $condition ? $passed++ : $failed++;
};

// ── A. Role guard ────────────────────────────────────────────────────────────
echo "A. StaffRoleMiddleware\n";
$handler = new class implements RequestHandlerInterface {
    public int $calls = 0;
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return new Response(200);
    }
};
$guard = new StaffRoleMiddleware();
$cases = ['admin' => 200, 'staff' => 200, 'standard' => 403, 'premium' => 403, '' => 403, 'ADMIN' => 403];
foreach ($cases as $role => $expected) {
    $before = $handler->calls;
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/staff/lookups')
        ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => 7, 'tipo_utente' => $role]);
    $response = $guard->process($request, $handler);
    $check($response->getStatusCode() === $expected, "role '{$role}' → {$expected}");
    if ($expected === 403) {
        $body = json_decode((string) $response->getBody(), true);
        $check(($body['error']['code'] ?? null) === 'forbidden' && $handler->calls === $before, "role '{$role}': forbidden envelope, handler not run");
    }
}
$response = $guard->process((new ServerRequestFactory())->createServerRequest('GET', '/x'), $handler);
$check($response->getStatusCode() === 403, 'missing identity attribute fails closed');
$response = $guard->process(
    (new ServerRequestFactory())->createServerRequest('GET', '/x')->withAttribute(AppAuthMiddleware::ATTR_USER, 'admin'),
    $handler
);
$check($response->getStatusCode() === 403, 'non-array identity fails closed');

// ── B. Circulation guard parity with core ───────────────────────────────────
echo "\nB. BookCirculationCheck mirrors LibriController::delete()\n";
$normalize = static fn (string $sql): string => strtolower(trim((string) preg_replace('/\s+/', ' ', $sql)));
$core = (string) file_get_contents(__DIR__ . '/../app/Controllers/LibriController.php');
$deleteBody = substr($core, (int) strpos($core, 'public function delete('), 4000);
preg_match_all('/\$db->prepare\("\s*(SELECT COUNT\(\*\).*?)"\)/s', $deleteBody, $m);
$coreQueries = array_map($normalize, $m[1] ?? []);
$check(count($coreQueries) === 2, 'core delete() still runs exactly two COUNT guards (found ' . count($coreQueries) . ')');
$check(in_array($normalize(BookCirculationCheck::ACTIVE_LOANS_SQL), $coreQueries, true), 'active-loans query identical to core');
$check(in_array($normalize(BookCirculationCheck::ACTIVE_RESERVATIONS_SQL), $coreQueries, true), 'active-reservations query identical to core');

// ── C. Payload mapping ───────────────────────────────────────────────────────
echo "\nC. StaffBookPayload\n";
$invalid = static fn (array $json, bool $create = true): ?string => StaffBookPayload::validate($json, $create)['field'] ?? null;
$check($invalid([]) === 'title', 'create without title → title');
$check($invalid(['subtitle' => 'x'], false) === null, 'update without title is fine');
$check($invalid(['title' => ''], false) === 'title', 'update with an empty title → title');
$check($invalid(['title' => 'x', 'isbn13' => '978-88-04-66823-7']) === null, 'dashed valid ISBN-13 accepted');
$check($invalid(['title' => 'x', 'isbn13' => '9788804668238']) === 'isbn13', 'bad ISBN-13 checksum rejected');
$check($invalid(['title' => 'x', 'isbn10' => '0-14-032872-6']) === null, 'valid ISBN-10 accepted');
$check($invalid(['title' => 'x', 'isbn10' => '0140328727']) === 'isbn10', 'bad ISBN-10 rejected');
$check($invalid(['title' => 'x', 'ean' => '0720642442524']) === null, 'EAN-13 accepted');
$check($invalid(['title' => 'x', 'copies' => 101]) === 'copies', 'copies > 100 rejected');
$check($invalid(['title' => 'x', 'copies' => '2']) === null, 'numeric-string copies accepted');
$check($invalid(['title' => 'x', 'author_ids' => [1, 'a']]) === 'author_ids', 'non-numeric author id rejected');
$check($invalid(['title' => 'x', 'author_ids' => ['x' => 1]]) === 'author_ids', 'author_ids must be a list');
$check($invalid(['title' => str_repeat('a', 256)]) === 'title', 'title over 255 chars rejected');
$check($invalid(['title' => 'x', 'subtitle' => ['array']]) === 'subtitle', 'array where text is expected rejected');
$check($invalid(['title' => 'x', 'cover_url' => 'javascript:alert(1)']) === 'cover_url', 'non-http cover URL rejected');
$check($invalid(['title' => 'x', 'cover_url' => '/uploads/copertine/../../index.php']) === 'cover_url', 'path traversal cover rejected');
$check($invalid(['title' => 'x', 'contributors' => ['translator_ids' => [0]]]) === 'contributors.translator_ids', 'contributor ids validated');
$check($invalid(['title' => 'x', 'acquisition_date' => '2026-02-30']) === 'acquisition_date', 'impossible date rejected');

$create = StaffBookPayload::toCreatePost([
    'title' => ' Il nome ', 'isbn13' => '978-88-04-66823-7', 'copies' => 3, 'year' => 2020, 'media_type' => 'disco',
    'author_ids' => [5, 5, 6], 'authors_new' => ['Nuovo', ' '], 'publisher_ids' => [9], 'shelf_id' => 2, 'level_id' => 4, 'position' => 0,
    'cover_url' => 'https://covers.example.org/x.jpg', 'scraped' => ['author_bio' => 'Bio'],
]);
$check($create['titolo'] === 'Il nome' && $create['isbn13'] === '9788804668237', 'create: text trimmed, ISBN cleaned');
$check($create['copie_totali'] === '3' && $create['anno_pubblicazione'] === '2020' && $create['tipo_media'] === 'disco', 'create: copies/year/media type mapped');
$check($create['autori_ids'] === ['5', '6'] && $create['autori_new'] === ['Nuovo'], 'create: author ids de-duplicated, blank names dropped');
$check($create['editori_ids'] === ['9'] && $create['editore_id'] === 9, 'create: publishers with primary');
$check($create['scaffale_id'] === '2' && $create['mensola_id'] === '4' && $create['posizione_progressiva'] === '', 'create: position 0 → next free');
$check($create['copertina_url'] === $create['scraped_cover_url'], 'create: remote cover travels in both keys like the web form');
$check($create['scraped_author_bio'] === 'Bio', 'create: scraped author bio');
$check(!isset($create['contributors_entity_picker']), 'create: no contributor picker flag unless contributors were sent');

$current = [
    'row' => [
        'titolo' => 'Vecchio', 'sottotitolo' => 'Sotto', 'note_varie' => 'Nota', 'isbn13' => '9788804668237', 'genere_id' => 3,
        'sottogenere_id' => 4, 'scaffale_id' => 2, 'mensola_id' => 5, 'posizione_progressiva' => 7, 'copertina_url' => '/uploads/copertine/a.jpg',
        'peso' => '1.5', 'review' => null, 'barcode' => 'B1', 'tipo_media' => 'libro',
    ],
    'creator_ids' => [11, 12],
    'contributor_ids' => ['traduttori' => [21], 'illustratori' => [], 'curatori' => [23], 'coloristi' => []],
    'publisher_ids' => [31, 32],
    'other_series' => "Seconda\nTerza",
];
$put = StaffBookPayload::toUpdatePost(['subtitle' => 'Nuovo sotto', 'notes' => null], $current);
$check($put['titolo'] === 'Vecchio' && $put['sottotitolo'] === 'Nuovo sotto' && $put['note_varie'] === '', 'update: absent keeps, present replaces, null clears');
$check($put['autori_ids'] === ['11', '12'] && $put['editori_ids'] === ['31', '32'], 'update: untouched relations re-posted');
$check(!isset($put['contributors_entity_picker']) && !isset($put['traduttori_ids']), 'update: contributors untouched when not sent');
$check($put['altre_collane'] === "Seconda\nTerza", 'update: secondary series re-posted');
$check($put['peso'] === '1.5' && $put['copertina_url'] === '/uploads/copertine/a.jpg' && $put['posizione_progressiva'] === '7', 'update: non-API columns preserved');
$check(!array_key_exists('review', $put) && $put['barcode'] === 'B1', 'update: NULL LibraryThing column stays absent, set one is kept');
$put = StaffBookPayload::toUpdatePost(['contributors' => ['illustrator_ids' => [40]]], $current);
$check($put['contributors_entity_picker'] === '1' && $put['illustratori_ids'] === ['40'], 'update: sent contributor role replaced');
$check($put['traduttori_ids'] === ['21'] && $put['curatori_ids'] === ['23'] && $put['coloristi_ids'] === [], 'update: unsent contributor roles re-posted, not cleared');
$put = StaffBookPayload::toUpdatePost(['genre_id' => 3], $current);
$check($put['sottogenere_id'] === '4', 'update: same genre keeps the subgenre');
$put = StaffBookPayload::toUpdatePost(['genre_id' => 8], $current);
$check($put['genere_id'] === '8' && $put['sottogenere_id'] === '', 'update: a different genre drops the stale subgenre');
$put = StaffBookPayload::toUpdatePost(['level_id' => 6], $current);
$check($put['scaffale_id'] === '2' && $put['mensola_id'] === '6' && $put['posizione_progressiva'] === '', 'update: moving level recomputes the position');
$put = StaffBookPayload::toUpdatePost(['publisher_ids' => []], $current);
$check($put['editori_ids'] === [] && $put['editore_id'] === '', 'update: publishers cleared including the legacy primary');
$put = StaffBookPayload::toUpdatePost(['remove_cover' => true], $current);
$check($put['remove_cover'] === '1' && $put['copertina_url'] === '', 'update: remove_cover');

// ── D. Scanner identifiers and preview mapping ──────────────────────────────
echo "\nD. Identifiers and preview\n";
$id = StaffBooksController::parseIdentifier('978-0-14-032872-1');
$check($id !== null && $id['kind'] === 'isbn' && $id['isbn13'] === '9780140328721' && $id['isbn10'] === '0140328726', 'ISBN-13 with dashes → both forms');
$id = StaffBooksController::parseIdentifier('0140328726');
$check($id !== null && $id['isbn13'] === '9780140328721' && $id['ean'] === '9780140328721', 'ISBN-10 → ISBN-13 + EAN');
$id = StaffBooksController::parseIdentifier('080442957X');
$check($id !== null && $id['isbn10'] === '080442957X', 'ISBN-10 with X check digit');
$id = StaffBooksController::parseIdentifier('9791032305690');
$check($id !== null && $id['kind'] === 'isbn' && $id['isbn10'] === null, '979 ISBN has no ISBN-10');
$id = StaffBooksController::parseIdentifier('0720642442524');
$check($id !== null && $id['kind'] === 'ean' && $id['isbn13'] === null, 'non-book EAN-13 → kind ean');
foreach (['9780140328722', '12345', '', 'ISBN', '97801403287210'] as $bad) {
    $check(StaffBooksController::parseIdentifier($bad) === null, "rejects '{$bad}'");
}
$preview = StaffBooksController::mapPreview([
    'title' => 'T', 'authors' => ['A', ['name' => 'B'], 'A'], 'pubDate' => 'March 2001', 'pages' => '320 p.',
    'keywords' => ['uno', 'due'], 'tipo_media' => 'libro', 'classificazione_dewey' => '853', '_primary_source' => 'open-library',
    'image' => 'https://covers.example.org/c.jpg', 'editor' => ['Curatore'],
], ['input' => 'x', 'isbn10' => '0140328726', 'isbn13' => '9780140328721', 'ean' => '9780140328721', 'kind' => 'isbn']);
$check($preview['authors'] === ['A', 'B'] && $preview['year'] === 2001 && $preview['pages'] === 320, 'preview: authors, year from pubDate, pages');
$check($preview['keywords'] === 'uno, due' && $preview['dewey'] === '853' && $preview['source'] === 'open-library', 'preview: keywords, dewey, source');
$check($preview['isbn13'] === '9780140328721' && $preview['editors'] === ['Curatore'], 'preview: identifiers fall back to the scanned code');
$sameAuthor = StaffBooksController::mapPreview(['title' => 'T', 'authors' => ['Primo Levi', 'Levi, Primo', 'levi, primo', 'Italo Calvino']],
    ['input' => 'x', 'isbn10' => null, 'isbn13' => '9788806219352', 'ean' => '9788806219352', 'kind' => 'isbn']);
$check($sameAuthor['authors'] === ['Primo Levi', 'Italo Calvino'], 'preview: one entry per person, in the canonical "Name Surname" form');

// ── E. Envelope throttle ─────────────────────────────────────────────────────
echo "\nE. ActionRateLimitMiddleware\n";
$bypass = getenv('PINAKES_E2E_BYPASS_RATE_LIMIT');
putenv('PINAKES_E2E_BYPASS_RATE_LIMIT');
unset($_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT']);
$action = 'unit_' . bin2hex(random_bytes(6));
$limiter = new \App\Plugins\MobileApi\Support\ActionRateLimitMiddleware(2, 60, $action);
$tokenRequest = static fn (int $tokenId): ServerRequestInterface => (new ServerRequestFactory())
    ->createServerRequest('GET', '/api/v1/staff/isbn/9780140328721')
    ->withAttribute(AppAuthMiddleware::ATTR_TOKEN_ID, $tokenId);
$statuses = [];
for ($i = 0; $i < 3; $i++) {
    $statuses[] = $limiter->process($tokenRequest(901), $handler)->getStatusCode();
}
$check($statuses === [200, 200, 429], 'third call within the window → 429 (got ' . implode(',', $statuses) . ')');
$limited = $limiter->process($tokenRequest(901), $handler);
$body = json_decode((string) $limited->getBody(), true);
$check(($body['error']['code'] ?? null) === 'rate_limited' && ($body['meta']['retry_after'] ?? null) === 60, 'envelope rate_limited with meta.retry_after');
$check($limited->getHeaderLine('Retry-After') === '60', 'Retry-After header');
$check($limiter->process($tokenRequest(902), $handler)->getStatusCode() === 200, 'another device token has its own budget');
if ($bypass !== false) {
    putenv('PINAKES_E2E_BYPASS_RATE_LIMIT=' . $bypass);
}
// Remove this run's RateLimiter state (file names are namespaced per install).
foreach ([901, 902] as $tokenId) {
    \App\Support\RateLimiter::reset("mobile_api_action:{$action}:token:{$tokenId}");
}

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
