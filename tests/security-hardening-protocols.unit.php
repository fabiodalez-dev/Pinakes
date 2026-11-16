<?php
declare(strict_types=1);

/**
 * Security hardening 2026-11 (group C: protocols, mobile API, throttles) —
 * DB-free regressions.
 *
 *  29  NCIP / OAI-PMH / VIAF admin checks decide on the role read from the DB:
 *      an unreadable role fails CLOSED instead of trusting the session snapshot.
 *  33  /admin/{dashboard,books,authors,publishers} use AdminAuthMiddleware.
 *  47  Mobile login runs the core 'login.validate' hook.
 *  48  mobile_app_tokens.revoked_at is written in UTC everywhere.
 *  49  Staff errors.csv answers catalogue imports only.
 *  50  OAI-PMH archival arm + GetRecord gated on isArchivesSetExposed().
 *  52  NCIP Basic auth requires a verified email and runs 'login.validate'.
 *  63  Per-loan .ics links expire 30 days after a closed loan's due date.
 *  67  RateLimiter buckets IPv6 per /64 and prunes expired state files.
 *  86  An in-app password change keeps the app's own token.
 *  89  TokenQuotaMiddleware also budgets per account.
 *  91  Mobile author / publisher / language filters are length-capped.
 *  108 OAI / ResourceSync / VIAF Basic throttles key on IP + account.
 *
 * Run:  php tests/security-hardening-protocols.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';
require_once $root . '/storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php';
require_once $root . '/storage/plugins/viaf-authority/ViafAuthorityPlugin.php';
foreach (['Support/ResponseEnvelope.php', 'Support/Input.php', 'Support/CursorCodec.php', 'Support/AppAuthMiddleware.php',
          'Support/TokenQuotaMiddleware.php', 'Controllers/CatalogController.php'] as $file) {
    require_once $root . '/storage/plugins/mobile-api/src/' . $file;
}

use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\TokenQuotaMiddleware;
use App\Support\LoanCalendarLinks;
use App\Support\RateLimiter;
use App\Support\SessionRoleRevalidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};
$src = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);

/** A connection that cannot answer: every prepare() fails, and is recorded. */
final class HardeningDeadDb extends mysqli
{
    /** @var list<string> */
    public array $prepared = [];

    public function __construct()
    {
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        $this->prepared[] = $query;
        return false;
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->prepared[] = $query;
        return false;
    }
}

/** @param array<string, mixed> $props */
$bare = static function (string $class, array $props): object {
    $obj = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    foreach ($props as $name => $value) {
        $p = new ReflectionProperty($class, $name);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }
    return $obj;
};
$call = static function (object $obj, string $method, array $args = []): mixed {
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
};

// ── 29 ───────────────────────────────────────────────────────────────────────
echo "29. Plugin admin checks fail closed on an unreadable role\n";
$_SESSION = ['user' => ['id' => 424242, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
SessionRoleRevalidator::reset();
$ncip = $bare(\App\Plugins\NcipServer\NcipServerPlugin::class, ['db' => new HardeningDeadDb()]);
$check($call($ncip, 'requireAdminOrStaff') === false, 'NCIP: an admin snapshot with no readable DB row is refused');
SessionRoleRevalidator::reset();
$oai = $bare(\App\Plugins\OaiPmhServer\OaiPmhServerPlugin::class, ['db' => new HardeningDeadDb()]);
$check($call($oai, 'requireAdminOrStaff') === false, 'OAI-PMH: digital-assets gate refuses it');
$out = null;
$ok = (new ReflectionMethod($oai, 'requireAdminForDownload'))->invokeArgs($oai, [(new ResponseFactory())->createResponse(), &$out, null]);
$check($ok === false && $out !== null && $out->getStatusCode() === 401, 'OAI-PMH: UNIMARC download asks for credentials instead');
SessionRoleRevalidator::reset();
$viaf = $bare(\App\Plugins\ViafAuthority\ViafAuthorityPlugin::class, ['db' => new HardeningDeadDb()]);
$out = null;
$ok = (new ReflectionMethod($viaf, 'requireAdminOrStaff'))->invokeArgs($viaf, [(new ResponseFactory())->createResponse(), &$out, null]);
$check($ok === false, 'VIAF: refused');
$check(str_contains($src('storage/plugins/resource-sync/ResourceSyncPlugin.php'), "SessionRoleRevalidator::holdsActiveRole(['admin', 'staff'], \$this->db)"),
    'ResourceSync: session gate revalidates the role');
$_SESSION = [];
SessionRoleRevalidator::reset();

// ── 33 ───────────────────────────────────────────────────────────────────────
echo "33. Back-office read routes use AdminAuthMiddleware\n";
$web = $src('app/Routes/web.php');
foreach (['/admin/dashboard', '/admin/authors', '/admin/authors/{id:\d+}', '/admin/books', '/admin/books/{id:\d+}', '/admin/publishers', '/admin/publishers/{id:\d+}'] as $path) {
    $at = strpos($web, "\$app->get('{$path}',");
    $next = $at !== false ? strpos($web, '})->add(', $at) : false;
    $tail = $next !== false ? substr($web, $next, 120) : '';
    $check(str_starts_with($tail, '})->add(new AdminAuthMiddleware())'), "{$path} is behind AdminAuthMiddleware");
}

// ── 47 / 52 ──────────────────────────────────────────────────────────────────
echo "47/52. login.validate and email verification on non-web logins\n";
$mobileAuth = $src('storage/plugins/mobile-api/src/Controllers/AuthController.php');
$check(str_contains($mobileAuth, "Hooks::apply('login.validate', true, [\$email, \$request])")
    && str_contains($mobileAuth, '!$customValidation'), 'mobile login runs and honours login.validate');
$ncipSrc = $src('storage/plugins/ncip-server/NcipServerPlugin.php');
$check(str_contains($ncipSrc, "Hooks::apply('login.validate', true, [\$email, \$request])"), 'NCIP Basic auth runs login.validate');
$check(str_contains($ncipSrc, "(int) (\$user['email_verificata'] ?? 0) !== 1"), 'NCIP Basic auth requires a verified email');

// ── 48 ───────────────────────────────────────────────────────────────────────
echo "48. mobile_app_tokens.revoked_at in UTC\n";
foreach (['app/Support/CredentialRevoker.php', 'storage/plugins/mobile-api/MobileApiPlugin.php',
          'storage/plugins/mobile-api/src/Controllers/ActionsController.php', 'storage/plugins/mobile-api/src/Support/TokenService.php'] as $file) {
    $check(preg_match('/mobile_app_tokens\s+SET\s+revoked_at\s*=\s*NOW\(\)/i', $src($file)) === 0, "{$file}: no NOW() for revoked_at");
}

// ── 49 ───────────────────────────────────────────────────────────────────────
echo "49. Staff errors.csv is catalogue-only\n";
$imports = $src('storage/plugins/mobile-api/src/Controllers/StaffImportsController.php');
$check(str_contains($imports, "CATALOG_IMPORT_TYPES = ['csv', 'librarything', 'marc']"), 'catalogue import types are listed');
$errStart = (int) strpos($imports, 'public function errorsCsv');
$check(strpos($imports, 'isCatalogImport($importId)', $errStart) < strpos($imports, 'downloadErrors(', $errStart),
    'the type is checked before delegating to downloadErrors');

// ── 50 ───────────────────────────────────────────────────────────────────────
echo "50. OAI-PMH archival arm gated on Archives exposure\n";
$closed = $bare(\App\Plugins\OaiPmhServer\OaiPmhServerPlugin::class, [
    'db' => $deadDb = new HardeningDeadDb(), 'ricExposedCache' => false, 'periodicalsExposedCache' => false,
]);
$arms = $call($closed, 'harvestArms', ['', 'oai_dc']);
$check($arms['archival_unit'] === false && $arms['book'] === true, 'Archives off: ListRecords oai_dc has no archival arm');
$check($call($closed, 'harvestArms', ['archives', 'oai_dc'])['archival_unit'] === false, 'Archives off: set=archives has no archival arm');
$check($call($closed, 'resolveIdentifier', ['oai:pinakes:archival_unit:7', 'example.org']) === null
    && array_filter($deadDb->prepared, static fn (string $q): bool => stripos($q, 'archival_units') !== false) === [],
    'Archives off: GetRecord never reads archival_units');
$check($call($closed, 'resolveDeletedIdentifier', ['oai:pinakes:archival_unit:7', 'example.org']) === null
    && array_filter($deadDb->prepared, static fn (string $q): bool => stripos($q, 'oai_deleted_records') !== false) === [],
    'Archives off: no archival deletion history either');
$open = $bare(\App\Plugins\OaiPmhServer\OaiPmhServerPlugin::class, [
    'db' => new HardeningDeadDb(), 'ricExposedCache' => true, 'periodicalsExposedCache' => false,
]);
$check($call($open, 'harvestArms', ['', 'oai_dc'])['archival_unit'] === true, 'Archives on: the archival arm is kept');

// ── 63 ───────────────────────────────────────────────────────────────────────
echo "63. Per-loan .ics link lifetime\n";
$today = new DateTimeImmutable('2026-11-16');
$check(LoanCalendarLinks::linkLiveOn('2026-01-01', true, $today), 'an open (overdue) loan keeps its link');
$check(LoanCalendarLinks::linkLiveOn('2026-10-17', false, $today), 'closed, due 30 days ago: still live');
$check(!LoanCalendarLinks::linkLiveOn('2026-10-16', false, $today), 'closed, due 31 days ago: expired');
$check(LoanCalendarLinks::linkLiveOn('2026-12-01', false, $today), 'a future due date is live');
$check(str_contains($web, '!$links->isLinkLive($loanId)'), 'the .ics route checks the link lifetime');

// ── 67 ───────────────────────────────────────────────────────────────────────
echo "67. RateLimiter IPv6 /64 buckets and pruning\n";
$check(RateLimiter::clientKey('2001:db8:1:2::1') === RateLimiter::clientKey('2001:db8:1:2:ffff:ffff:ffff:ffff'), 'two addresses in one /64 share a bucket');
$check(RateLimiter::clientKey('2001:db8:1:2::1') !== RateLimiter::clientKey('2001:db8:1:3::1'), 'different /64s do not');
$check(RateLimiter::clientKey('::ffff:203.0.113.9') === '203.0.113.9', 'an IPv4-mapped address is keyed as its IPv4');
$check(RateLimiter::clientKey('203.0.113.9') === '203.0.113.9' && RateLimiter::clientKey('unknown') === 'unknown', 'IPv4 and non-IPs are unchanged');
$check(str_contains($src('app/Middleware/RateLimitMiddleware.php'), 'RateLimiter::clientKey($ip)'), 'RateLimitMiddleware keys on the client bucket');

$probe = 'hardening-prune-' . bin2hex(random_bytes(6));
RateLimiter::isLimited($probe . ':old', 5, 60);
RateLimiter::isLimited($probe . ':fresh', 5, 60);
$stateFile = static function (string $id) use ($root): string {
    $real = realpath($root) ?: $root;
    return RateLimiter::stateDirectory() . '/' . hash('sha256', $real . "\0" . $id) . '.json';
};
$oldFile = $stateFile($probe . ':old');
$freshFile = $stateFile($probe . ':fresh');
if (!is_file($oldFile) || !is_file($freshFile)) {
    $check(false, 'probe state files were written');
} else {
    touch($oldFile, time() - 2 * 86400);
    RateLimiter::pruneExpired(null, true);
    clearstatcache();
    $check(!is_file($oldFile), 'a state file untouched for two days is pruned');
    $check(is_file($freshFile), 'a live state file is kept');
}
RateLimiter::reset($probe . ':old');
RateLimiter::reset($probe . ':fresh');

// ── 86 ───────────────────────────────────────────────────────────────────────
echo "86. In-app password change keeps the app's own token\n";
$revoker = $src('app/Support/CredentialRevoker.php');
$check(str_contains($revoker, "AND revoked_at IS NULL AND id <> ?") && str_contains($revoker, "\$_SESSION['user']['mobile_token_id']"),
    'CredentialRevoker spares the token the change came from');
$check(str_contains($src('storage/plugins/mobile-api/src/Support/AppAuthMiddleware.php'), "'mobile_token_id' => (int) \$row['id']"),
    'AppAuthMiddleware mirrors the token id into the request session');

// ── 89 ───────────────────────────────────────────────────────────────────────
echo "89. TokenQuotaMiddleware per-account budget\n";
$okHandler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new ResponseFactory())->createResponse(200);
    }
};
$userId = random_int(900_000_000, 999_999_999);
$mw = new TokenQuotaMiddleware(2, 60);
$statuses = [];
$tokenBase = random_int(900_000_000, 999_999_999);
for ($i = 0; $i < 4; $i++) {         // 4 fresh tokens x 2 requests = 8 > 2 x 3
    for ($r = 0; $r < 2; $r++) {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/me')
            ->withAttribute(AppAuthMiddleware::ATTR_TOKEN_ID, $tokenBase + $i)
            ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $userId]);
        $statuses[] = $mw->process($req, $okHandler)->getStatusCode();
    }
}
$check(array_slice($statuses, 0, 6) === [200, 200, 200, 200, 200, 200], 'three devices within their own quota pass');
$check(in_array(429, array_slice($statuses, 6), true), 'minting a fourth token does not buy a fresh budget (429)');
for ($i = 0; $i < 4; $i++) {
    RateLimiter::reset('mobile_api_quota:token:' . ($tokenBase + $i));
}
RateLimiter::reset('mobile_api_quota:user:' . $userId);

// ── 91 ───────────────────────────────────────────────────────────────────────
echo "91. Mobile filter length caps\n";
$filter = new ReflectionMethod(\App\Plugins\MobileApi\Controllers\CatalogController::class, 'filterParam');
$filter->setAccessible(true);
$long = str_repeat('é', 5000);
foreach (['author', 'publisher', 'language'] as $key) {
    $v = $filter->invoke(null, [$key => $long], $key);
    $check(mb_strlen($v, 'UTF-8') === 200, "{$key} is cut to 200 characters");
}
$check($filter->invoke(null, ['author' => '  Levi  '], 'author') === 'Levi', 'a normal value is only trimmed');
$check($filter->invoke(null, [], 'author') === '', 'a missing value is empty');

// ── 108 ──────────────────────────────────────────────────────────────────────
echo "108. Basic-auth throttles key on IP + account\n";
$check(str_contains($src('storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php'),
    "'oai_unimarc_basic:' . \\App\\Support\\RateLimiter::clientKey(\$ip) . ':' . strtolower(\$parts[0] ?? '')"), 'OAI-PMH');
$check(str_contains($src('storage/plugins/resource-sync/ResourceSyncPlugin.php'),
    "'resourcesync_basic:' . RateLimiter::clientKey(\$ip) . ':' . strtolower(\$parts[0] ?? '')"), 'ResourceSync');
$check(str_contains($src('storage/plugins/viaf-authority/ViafAuthorityPlugin.php'),
    "'viaf_basic:' . RateLimiter::clientKey(\$ip) . ':' . strtolower(\$rlUser)"), 'VIAF');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
