<?php
declare(strict_types=1);

/**
 * Behavioural contract for issue #387 step 4 (hot public dataset caching):
 *
 *  A. Book-detail STATIC/LIVE split — the static DTO (book row, authors,
 *     publishers, series, related) is cached under 'book_detail_' while
 *     availability (copie_disponibili/copie_totali/stato) is re-read from the
 *     database on EVERY request:
 *       - mutate copie_disponibili+stato+titolo directly in the DB (no
 *         invalidation) → the served page shows the NEW availability but the
 *         OLD title (metadata came from cache, availability did not);
 *       - ContentCache::booksChanged() → the page shows the new title.
 *  B. Reviews block — cached under 'book_reviews_':
 *       - a review force-approved via raw SQL (bypassing moderation) does NOT
 *         appear while the cache is warm (proves the block is cached);
 *       - the real moderation path (RecensioniRepository::approveReview /
 *         deleteReview) invalidates immediately.
 *  C. Bounded catalog page rows — cached under 'catalog_' ('catalog_page_*'):
 *       - the default catalog listing serves cached rows (old title) while the
 *         per-card availability badge is merged live (status flips without any
 *         invalidation);
 *       - booksChanged() invalidates the rows.
 *  D. Namespace mechanics — 'book_detail_' and 'book_reviews_' are
 *     generation-tracked; booksChanged() bumps book_detail_ but not
 *     book_reviews_; availabilityChanged() leaves book_detail_ intact;
 *     reviewsChanged() bumps only book_reviews_.
 *  E. Indirect write paths — authority updates invalidate book DTOs and
 *     admin edits of reviewer names invalidate rendered review DTOs.
 *
 * FAILS BY DESIGN on pre-change code: without the DTO cache the page would
 * show the NEW title in step A (assertion "old title still served" fails), and
 * the force-approved review in step B would appear immediately.
 *
 * Seeds run inside a transaction that is always rolled back; every cache
 * namespace touched is generation-bumped in the finally block so no cached
 * uncommitted row can leak into the shared dev cache.
 *
 * Run: php tests/hot-dataset-cache-387.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\FrontendController;
use App\Repositories\RecensioniRepository;
use App\Support\ContentCache;
use App\Support\QueryCache;
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

// ── DB connection: E2E_* env first, .env fallback ───────────────────────────
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
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
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}

$_SESSION = $_SESSION ?? [];

$controller = new FrontendController();
$requestFactory = new ServerRequestFactory();

/**
 * Invoke bookDetail for the given id starting from an arbitrary path,
 * following canonical 301 redirects (max 3). Returns [status, body, path].
 */
$renderBookPage = static function (int $bookId, string $path = '/__cache387__') use ($controller, $requestFactory, $db): array {
    for ($hop = 0; $hop < 4; $hop++) {
        $request = $requestFactory->createServerRequest('GET', $path)->withQueryParams(['id' => $bookId]);
        $response = $controller->bookDetail($request, new Response(), $db);
        $status = $response->getStatusCode();
        if ($status !== 301) {
            return [$status, (string) $response->getBody(), $path];
        }
        $location = $response->getHeaderLine('Location');
        $path = (string) (parse_url($location, PHP_URL_PATH) ?: $location);
    }
    return [301, '', $path];
};

$callCatalog = static function (array $params) use ($controller, $requestFactory, $db): array {
    $request = $requestFactory->createServerRequest('GET', '/api/catalogo')->withQueryParams($params);
    $response = $controller->catalogAPI($request, new Response(), $db);
    $decoded = json_decode((string) $response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
};

/** The status-badge marker inside the card whose <img alt> carries $title. */
$badgeNearTitle = static function (string $html, string $title): string {
    $needle = 'alt="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
    $pos = strpos($html, $needle);
    if ($pos === false) {
        return '';
    }
    $window = substr($html, $pos, 600);
    if (preg_match('/book-status-badge (status-[a-z]+)/', $window, $m)) {
        return $m[1];
    }
    return '';
};

$token = bin2hex(random_bytes(5));
$titleV1 = "ZZ Cache387 Original {$token}";
$titleV2 = "ZZ Cache387 Renamed {$token}";
$reviewTitle = "ZZ Review387 {$token}";

$db->begin_transaction();

try {
    // ── Seed: one book (2/2 available) + one user ───────────────────────────
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 2, 2, 'disponibile')");
    $stmt->bind_param('s', $titleV1);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();

    $card = 'C387' . substr($token, 0, 8);
    $email = "cache387.{$token}@example.test";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, privacy_accettata) VALUES (?, 'Cache', 'Tester', ?, 'x', 1)");
    $stmt->bind_param('ss', $card, $email);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();

    // Start from clean generations so stale dev-cache entries cannot interfere.
    ContentCache::booksChanged();
    ContentCache::reviewsChanged();

    echo "A. Book-detail static/live split:\n";

    [$status1, $body1] = $renderBookPage($bookId);
    $check($status1 === 200, "first render returns 200 (got {$status1})");
    $check(str_contains($body1, $titleV1), 'first render shows the seeded title');
    $check(str_contains($body1, 'availability-badge available'), 'first render shows the book as available (2/2)');

    // Mutate availability AND metadata directly — deliberately WITHOUT any
    // cache invalidation. Availability must change on the next render (live
    // read), metadata must NOT (cached DTO reused, no re-query).
    $stmt = $db->prepare("UPDATE libri SET titolo = ?, copie_disponibili = 0, stato = 'prestato' WHERE id = ?");
    $stmt->bind_param('si', $titleV2, $bookId);
    $stmt->execute();
    $stmt->close();

    [$status2, $body2] = $renderBookPage($bookId);
    $check($status2 === 200, "second render returns 200 (got {$status2})");
    $check(str_contains($body2, 'availability-badge unavailable'), 'availability flips to unavailable WITHOUT invalidation (read live every request)');
    $check(str_contains($body2, $titleV1), 'cached static metadata still served (old title — DTO not re-queried)');
    $check(!str_contains($body2, $titleV2), 'new title NOT visible while the DTO cache is warm');

    // booksChanged() (the bump every book write path fires) must rebuild the DTO.
    ContentCache::booksChanged();
    [$status3, $body3] = $renderBookPage($bookId);
    $check($status3 === 200, "post-invalidation render returns 200 (got {$status3})");
    $check(str_contains($body3, $titleV2), 'booksChanged() invalidates the DTO (new title now served)');
    $check(str_contains($body3, 'availability-badge unavailable'), 'availability still correct after invalidation');

    echo "\nB. Reviews block cache + moderation invalidation:\n";

    $stmt = $db->prepare("INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione, stato) VALUES (?, ?, 5, ?, 'body', 'pendente')");
    $stmt->bind_param('iis', $bookId, $userId, $reviewTitle);
    $stmt->execute();
    $reviewId = (int) $db->insert_id;
    $stmt->close();

    [, $bodyR1] = $renderBookPage($bookId);
    $check(!str_contains($bodyR1, $reviewTitle), 'pending review is not publicly visible (reviews block cached empty)');

    // Force-approve via raw SQL (bypassing moderation): the cached block must
    // keep serving the old state — this is the assertion that fails when the
    // reviews block is not cached at all (pre-change behaviour).
    $db->query("UPDATE recensioni SET stato = 'approvata' WHERE id = {$reviewId}");
    [, $bodyR2] = $renderBookPage($bookId);
    $check(!str_contains($bodyR2, $reviewTitle), 'raw-SQL approval does not appear while the reviews cache is warm (block IS cached)');

    // The real moderation path DEFERS invalidation to request shutdown, so a
    // concurrent public read during the caller's open transaction cannot
    // populate the new generation with pre-commit rows. Within this single
    // process the shutdown hook has not fired yet, so the cached block is still
    // the old one; a manual reviewsChanged() stands in for the shutdown.
    $db->query("UPDATE recensioni SET stato = 'pendente' WHERE id = {$reviewId}");
    $repo = new RecensioniRepository($db);
    $check($repo->approveReview($reviewId, $userId), 'approveReview() succeeds');
    $approvedRows = $repo->getApprovedReviewsForBook($bookId);
    $check(
        isset($approvedRows[0]) && !array_key_exists('utente_email', $approvedRows[0]),
        'public review DTO does not cache reviewer email'
    );
    [, $bodyR3a] = $renderBookPage($bookId);
    $check(!str_contains($bodyR3a, $reviewTitle), 'approval is NOT applied mid-transaction (invalidation deferred to shutdown)');
    ContentCache::reviewsChanged(); // simulate the request-shutdown deferred flush
    [, $bodyR3] = $renderBookPage($bookId);
    $check(str_contains($bodyR3, $reviewTitle), 'approved review visible after the deferred invalidation fires');

    $check($repo->deleteReview($reviewId), 'deleteReview() succeeds');
    ContentCache::reviewsChanged(); // deferred → simulate shutdown flush
    [, $bodyR4] = $renderBookPage($bookId);
    $check(!str_contains($bodyR4, $reviewTitle), 'deleted review disappears after the deferred invalidation fires');

    echo "\nC. Bounded catalog page rows (static rows cached, availability live):\n";

    // Reset the book to a stable, available state and clear the catalog cache.
    $stmt = $db->prepare("UPDATE libri SET titolo = ?, copie_disponibili = 2, stato = 'disponibile' WHERE id = ?");
    $stmt->bind_param('si', $titleV1, $bookId);
    $stmt->execute();
    $stmt->close();
    ContentCache::booksChanged();

    // Default catalog state (no filters) is bounded → page 1 rows get cached.
    // The seeded book is the newest row, so it is on page 1 of sort=newest.
    $cat1 = $callCatalog(['page' => '1']);
    $html1 = (string) ($cat1['html'] ?? '');
    $check(str_contains($html1, $titleV1), 'seeded book appears on the default catalog first page');
    $check($badgeNearTitle($html1, $titleV1) === 'status-available', 'catalog card badge shows available (2 copies)');

    // Mutate availability + title with NO invalidation: cached rows must keep
    // the old title, the badge must flip (merged live).
    $stmt = $db->prepare("UPDATE libri SET titolo = ?, copie_disponibili = 0, stato = 'prestato' WHERE id = ?");
    $stmt->bind_param('si', $titleV2, $bookId);
    $stmt->execute();
    $stmt->close();

    $cat2 = $callCatalog(['page' => '1']);
    $html2 = (string) ($cat2['html'] ?? '');
    $check(str_contains($html2, $titleV1), 'catalog rows still served from cache (old title)');
    $check(!str_contains($html2, $titleV2), 'new title NOT visible in the cached catalog rows');
    $check($badgeNearTitle($html2, $titleV1) === 'status-borrowed', 'catalog card badge flips to borrowed WITHOUT invalidation (availability merged live)');

    ContentCache::booksChanged();
    $cat3 = $callCatalog(['page' => '1']);
    $html3 = (string) ($cat3['html'] ?? '');
    $check(str_contains($html3, $titleV2), 'booksChanged() invalidates the catalog page rows (new title served)');

    echo "\nD. Namespace mechanics:\n";

    $nsRun = 'ns387_' . $token;
    QueryCache::set('book_detail_' . $nsRun, 'dto', 120);
    QueryCache::set('book_reviews_' . $nsRun, 'reviews', 120);
    QueryCache::set('catalog_page_' . $nsRun, 'rows', 120);
    ContentCache::availabilityChanged();
    $check(QueryCache::get('book_detail_' . $nsRun) === 'dto', 'availabilityChanged leaves static book_detail_ DTOs warm');
    $check(QueryCache::get('catalog_page_' . $nsRun) === null, 'availabilityChanged bumps catalog_page_* membership');
    QueryCache::set('catalog_page_' . $nsRun, 'rows', 120);
    ContentCache::booksChanged();
    $check(QueryCache::get('book_detail_' . $nsRun) === null, 'booksChanged bumps book_detail_');
    $check(QueryCache::get('catalog_page_' . $nsRun) === null, 'booksChanged bumps catalog_page_* (catalog_ namespace)');
    $check(QueryCache::get('book_reviews_' . $nsRun) === 'reviews', 'booksChanged leaves book_reviews_ alone');
    ContentCache::reviewsChanged();
    $check(QueryCache::get('book_reviews_' . $nsRun) === null, 'reviewsChanged bumps book_reviews_');

    $mobileReviews = (string) file_get_contents(
        $root . '/storage/plugins/mobile-api/src/Controllers/ReviewsController.php'
    );
    $check(
        substr_count($mobileReviews, 'ContentCache::reviewsChanged()') >= 2,
        'mobile review edit/delete paths invalidate the public reviews block'
    );

    echo "\nE. Indirect write-path invalidation:\n";

    $authorityPlugin = (string) file_get_contents(
        $root . '/storage/plugins/viaf-authority/ViafAuthorityPlugin.php'
    );
    $check(
        substr_count($authorityPlugin, 'ContentCache::booksChanged()') >= 2,
        'VIAF and ISNI author updates invalidate cached book-detail DTOs'
    );

    $usersController = (string) file_get_contents($root . '/app/Controllers/UsersController.php');
    $check(
        str_contains($usersController, "original['nome']")
            && str_contains($usersController, "original['cognome']")
            && str_contains($usersController, 'ContentCache::deferReviewsChanged()'),
        'admin reviewer-name edits invalidate cached public review DTOs (deferred to shutdown)'
    );
} catch (\Throwable $e) {
    $failed++;
    echo "FAIL  unexpected exception: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
} finally {
    // Roll back every seeded row and make any cache entry built from the
    // uncommitted rows unreachable (the dev site shares storage/cache).
    try {
        $db->rollback();
    } catch (\Throwable $e) {
        // connection already gone — nothing to roll back
    }
    ContentCache::booksChanged();
    ContentCache::reviewsChanged();
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
