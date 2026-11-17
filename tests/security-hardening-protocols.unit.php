<?php
declare(strict_types=1);

/**
 * Security hardening 2026-11 (group C: protocols, mobile API, throttles).
 *
 * Sections 29-108 run DB-free. Section DB drives the real Basic-auth gates
 * (OAI-PMH, ResourceSync, VIAF, NCIP), the NCIP / mobile logins and the staff
 * errors.csv endpoint against a disposable database rebuilt from schema.sql
 * (tests/helpers/hardening-sandbox-db.php; E2E_DB_* or .env, SKIP otherwise).
 *
 *  29  NCIP / OAI-PMH / VIAF admin checks decide on the role read from the DB:
 *      an unreadable role fails CLOSED instead of trusting the session snapshot.
 *  33  /admin/{dashboard,books,authors,publishers} use AdminAuthMiddleware.
 *  47  Mobile login runs the core 'login.validate' hook.
 *  48  mobile_app_tokens.revoked_at is written in UTC everywhere.
 *  49  Staff errors.csv answers catalogue imports only.
 *  50  OAI-PMH archival arm + GetRecord gated on isArchivesSetExposed().
 *  52  NCIP Basic auth requires a verified email and runs 'login.validate'.
 *  52b OAI-PMH, ResourceSync and VIAF Basic auth do too (own channel name);
 *      every gate spends a dummy verify on an unknown / inactive email, and
 *      VIAF's CSRF exemption reuses the gate's verdict (no second verify).
 *  63  Per-loan .ics links expire 30 days after a closed loan's due date.
 *  67  RateLimiter buckets IPv6 per /64 and prunes expired state files.
 *  86  An in-app password change keeps the app's own token.
 *  89  TokenQuotaMiddleware also budgets per account.
 *  91  Mobile author / publisher / language filters are length-capped.
 *  108 OAI / ResourceSync / VIAF / NCIP Basic throttles key on IP + canonical
 *      account, keep a failures-only bucket per IP that a success never
 *      clears, and never throttle a client that authenticates every time.
 *
 * Run:  php tests/security-hardening-protocols.unit.php   (exit 0 iff all pass)
 *       (with E2E_DB_* from run-e2e-wtN.sh for section DB)
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
// ResourceSync's session gate runs against a real users table in section DB.
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

// ── 48 ───────────────────────────────────────────────────────────────────────
echo "48. mobile_app_tokens.revoked_at in UTC\n";
foreach (['app/Support/CredentialRevoker.php', 'storage/plugins/mobile-api/MobileApiPlugin.php',
          'storage/plugins/mobile-api/src/Controllers/ActionsController.php', 'storage/plugins/mobile-api/src/Support/TokenService.php'] as $file) {
    $check(preg_match('/mobile_app_tokens\s+SET\s+revoked_at\s*=\s*NOW\(\)/i', $src($file)) === 0, "{$file}: no NOW() for revoked_at");
}

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
echo "108. Basic-auth throttles: IP + canonical account, plus an IP failure cap\n";
$rl = \App\Support\RateLimiter::class;
// Spellings the users-table collation treats as one account share one bucket.
$k = $rl::accountKey('admin@example.org');
$check($rl::accountKey(' Admin@Example.org  ') === $k, 'case and surrounding spaces map to one account bucket');
$check(!class_exists(\Normalizer::class) || $rl::accountKey('ädmin@example.org') === $k, 'accented spelling maps to the same bucket');
$check($rl::accountKey('other@example.org') !== $k, 'a different account gets a different bucket');
// Read-only check never spends an attempt; counting calls do.
$probe = 'hardening_probe_' . bin2hex(random_bytes(6));
try {
    $check($rl::isLimited($probe, 2, 300, false) === false, 'read-only check on an empty bucket is not limited');
    $check($rl::isLimited($probe, 2, 300, false) === false, 'repeated read-only checks do not grow the bucket');
    $rl::isLimited($probe, 2, 300);
    $rl::isLimited($probe, 2, 300);
    $check($rl::isLimited($probe, 2, 300, false) === true, 'after two counted failures a budget of 2 is exhausted');
} finally {
    $rl::reset($probe);
}
// The four gates themselves run on a real database: section DB below.

// ── DB. 47 / 49 / 52 / 108 on a real database ──────────────────────────────
// The gates run end to end: the real plugin / controller methods, real
// PSR-7 requests, a real `utenti` table in a disposable database, and the
// real RateLimiter state files (every bucket is keyed on a random IPv6 /64
// of the documentation prefix, so no two runs share one, and every bucket
// this section can create is reset in the finally block).
echo "DB. Basic-auth gates, login.validate, verified email, errors.csv (real database)\n";
require_once $root . '/tests/helpers/hardening-sandbox-db.php';
// The mobile-api plugin has no Composer autoload entry (it require_once's
// its own files); map its namespace for the controllers driven below.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\Plugins\\MobileApi\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/storage/plugins/mobile-api/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
require_once $root . '/storage/plugins/resource-sync/ResourceSyncPlugin.php';
try {
    $sandbox = hardeningSandboxDb('proto');
    $sandboxError = null;
} catch (\Throwable $e) {
    $sandbox = null;
    $sandboxError = $e->getMessage();
}
if ($sandboxError !== null) {
    $check(false, 'the disposable database could be built: ' . $sandboxError);
} elseif ($sandbox === null) {
    echo "SKIP: no database configured (E2E_DB_* or .env)\n";
} else {
    $db = $sandbox['db'];
    $bucketIps = [];
    $bucketAccounts = [];
    $previousHooks = \App\Support\Hooks::getInstance();
    try {
        $RIGHT = 'right-pass-' . bin2hex(random_bytes(4));
        $ADMIN = 'hb-admin@example.org';
        hardeningSeedUser($db, $ADMIN, $RIGHT, 'admin');
        $spray = [];
        for ($i = 1; $i <= 5; $i++) {
            $spray[] = "hb-s{$i}@example.org";
            hardeningSeedUser($db, "hb-s{$i}@example.org", $RIGHT, 'staff');
        }
        $UNVERIFIED = 'hb-unverified@example.org';
        hardeningSeedUser($db, $UNVERIFIED, $RIGHT, 'staff', ['email_verificata' => 0]);
        $bucketAccounts = array_merge([$ADMIN, $UNVERIFIED], $spray);

        // ResourceSync's gate is opt-in: switch it on the way an admin does.
        $db->query("INSERT INTO plugins (name, display_name, version, is_active, path, main_file) VALUES ('resource-sync', 'ResourceSync', '1.0.0', 1, 'resource-sync', 'wrapper.php')");
        $rsPluginId = (int) $db->insert_id;
        $db->query("INSERT INTO plugin_settings (plugin_id, setting_key, setting_value) VALUES ({$rsPluginId}, 'require_basic_auth', '1')");

        \App\Support\Hooks::init(new \App\Support\HookManager($db));
        $_SESSION = [];
        SessionRoleRevalidator::reset();

        $freshIp = static function () use (&$bucketIps): string {
            $ip = sprintf('2001:db8:%x:%x::%x', random_int(1, 0xffff), random_int(1, 0xffff), random_int(1, 0xffff));
            $bucketIps[] = $ip;
            return $ip;
        };
        $basicRequest = static fn (string $ip, string $user, string $password): ServerRequestInterface => (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => $ip])
            ->withHeader('Authorization', 'Basic ' . base64_encode($user . ':' . $password));

        $hm = new \App\Support\HookManager($db);
        $ncipPlugin = new \App\Plugins\NcipServer\NcipServerPlugin($db, $hm);
        $oaiPlugin = new \App\Plugins\OaiPmhServer\OaiPmhServerPlugin($db, $hm);
        $viafPlugin = new \App\Plugins\ViafAuthority\ViafAuthorityPlugin($db, $hm);
        $rsPlugin = new \App\Plugins\ResourceSync\ResourceSyncPlugin($db, $hm);
        $template = (new ResponseFactory())->createResponse();

        // Each gate answers an HTTP-like status for one Basic attempt: 200 =
        // let through, 401 = challenge, 429 = throttled. NCIP's authenticate()
        // only says "caller or no caller", so it answers 200 or 401 and its
        // throttling shows as a correct password being refused.
        /** @var array<string, callable(ServerRequestInterface): int> $gates */
        $gates = [
            'OAI-PMH' => static function (ServerRequestInterface $rq) use ($oaiPlugin, $template): int {
                $out = null;
                $ok = (new ReflectionMethod($oaiPlugin, 'requireAdminForDownload'))->invokeArgs($oaiPlugin, [$template, &$out, $rq]);
                return $ok ? 200 : ($out instanceof ResponseInterface ? $out->getStatusCode() : 0);
            },
            'ResourceSync' => static function (ServerRequestInterface $rq) use ($rsPlugin, $template): int {
                $out = null;
                $ok = $rsPlugin->gateRequest($rq, $template, $out);
                return $ok ? 200 : ($out instanceof ResponseInterface ? $out->getStatusCode() : 0);
            },
            'VIAF' => static function (ServerRequestInterface $rq) use ($viafPlugin, $template): int {
                $out = null;
                $ok = (new ReflectionMethod($viafPlugin, 'requireAdminOrStaff'))->invokeArgs($viafPlugin, [$template, &$out, $rq]);
                return $ok ? 200 : ($out instanceof ResponseInterface ? $out->getStatusCode() : 0);
            },
            'NCIP' => static function (ServerRequestInterface $rq) use ($ncipPlugin): int {
                $user = (new ReflectionMethod($ncipPlugin, 'authenticate'))->invoke($ncipPlugin, $rq);
                return is_array($user) ? 200 : 401;
            },
        ];
        $throttled = static fn (string $gate, int $status): bool => $gate === 'NCIP' ? $status === 401 : $status === 429;

        // Spellings the users-table collation resolves to the admin account.
        $spellings = ['HB-Admin@Example.org', ' hb-admin@example.org', 'hb-admin@example.org ', 'HB-ADMIN@EXAMPLE.ORG'];
        if (class_exists(\Normalizer::class)) {
            $spellings[] = 'hb-ädmin@example.org';
        }

        foreach ($gates as $label => $gate) {
            // (a) The right password is let through; a wrong one is challenged.
            $ip = $freshIp();
            $check($gate($basicRequest($ip, $ADMIN, $RIGHT)) === 200, "{$label}: the right password is accepted");
            $check($gate($basicRequest($ip, $ADMIN, 'wrong')) === 401, "{$label}: a wrong password is answered 401");

            // (b) Ten wrong passwords under ten spellings of ONE account spend
            //     one per-account budget: the eleventh try is throttled even
            //     with the right password (RateLimiter::accountKey).
            $ip = $freshIp();
            $statuses = [];
            for ($i = 0; $i < 10; $i++) {
                $statuses[] = $gate($basicRequest($ip, $spellings[$i % count($spellings)], 'wrong-' . $i));
            }
            $check($statuses === array_fill(0, 10, 401), "{$label}: ten wrong passwords are each answered 401");
            $last = $gate($basicRequest($ip, $ADMIN, $RIGHT));
            $check($throttled($label, $last), "{$label}: spellings of one account share its counter (right password now throttled, got {$last})");
            $check($gate($basicRequest($freshIp(), $ADMIN, $RIGHT)) === 200, "{$label}: the same account from another client is unaffected");

            // (c) The failures-only bucket per IP: 29 failures spread over five
            //     accounts (never 10 on one), a success, one more failure — the
            //     success must not have cleared the 29, so the client is now
            //     over 30 and even the right password is throttled.
            $ip = $freshIp();
            $statuses = [];
            for ($i = 0; $i < 29; $i++) {
                $statuses[] = $gate($basicRequest($ip, $spray[$i % 5], 'wrong-' . $i));
            }
            $check(!in_array(200, $statuses, true) && array_unique($statuses) === [401], "{$label}: a spray over five accounts is answered 401 while under the IP cap");
            $check($gate($basicRequest($ip, $ADMIN, $RIGHT)) === 200, "{$label}: a success under the cap still passes");
            $gate($basicRequest($ip, $spray[0], 'wrong-30'));
            $after = $gate($basicRequest($ip, $ADMIN, $RIGHT));
            $check($throttled($label, $after), "{$label}: the success did not reset the IP failure bucket (30 failures: throttled, got {$after})");

            // (d) A harvester that authenticates on every request is never
            //     throttled, far past both the per-account and the IP budget.
            $ip = $freshIp();
            $ok = 0;
            for ($i = 0; $i < 40; $i++) {
                $ok += $gate($basicRequest($ip, $ADMIN, $RIGHT)) === 200 ? 1 : 0;
            }
            $check($ok === 40, "{$label}: 40 authenticated requests in a row all pass ({$ok}/40)");
        }

        $rsOff = new \App\Plugins\ResourceSync\ResourceSyncPlugin($db, $hm);
        $db->query("UPDATE plugin_settings SET setting_value = '0' WHERE plugin_id = {$rsPluginId}");
        $out = null;
        $check($rsOff->gateRequest((new ServerRequestFactory())->createServerRequest('GET', '/'), $template, $out) === true,
            'ResourceSync: with the opt-in gate off, an anonymous harvester passes (spec default)');
        $db->query("UPDATE plugin_settings SET setting_value = '1' WHERE plugin_id = {$rsPluginId}");
        // 29 (ResourceSync): the session gate decides on the role read from
        // the database now, not on the login-time snapshot.
        $demoted = hardeningSeedUser($db, 'hb-demoted@example.org', $RIGHT, 'standard');
        $staffNow = hardeningSeedUser($db, 'hb-staffnow@example.org', $RIGHT, 'staff');
        $anon = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $_SESSION = ['user' => ['id' => $demoted, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
        SessionRoleRevalidator::reset();
        $out = null;
        $check($rsPlugin->gateRequest($anon, $template, $out) === false && $out instanceof ResponseInterface && $out->getStatusCode() === 401,
            'ResourceSync: an admin session snapshot whose DB row is now a reader is challenged');
        $_SESSION = ['user' => ['id' => $staffNow, 'tipo_utente' => 'staff', 'stato' => 'attivo']];
        SessionRoleRevalidator::reset();
        $check($rsPlugin->gateRequest($anon, $template, $out) === true, 'ResourceSync: a session whose DB row is staff passes');
        $_SESSION = [];
        SessionRoleRevalidator::reset();

        // ── 52: NCIP Basic auth = verified email + login.validate ─────────
        $ncipAuth = static fn (string $user, string $password): mixed => (new ReflectionMethod($ncipPlugin, 'authenticate'))
            ->invoke($ncipPlugin, $basicRequest($freshIp(), $user, $password));
        $check(is_array($ncipAuth($ADMIN, $RIGHT)), 'NCIP: verified account with the right password is authenticated');
        $check($ncipAuth($UNVERIFIED, $RIGHT) === null, 'NCIP: an unverified email is refused even with the right password');
        $seen = [];
        $refuse = new \App\Support\HookManager($db);
        $refuse->addHook('login.validate', static function ($ok, $email) use (&$seen) {
            $seen[] = $email;
            return false;
        });
        \App\Support\Hooks::init($refuse);
        $check($ncipAuth($ADMIN, $RIGHT) === null, "NCIP: a plugin's login.validate refusal is honoured");
        $check($seen === [$ADMIN], 'NCIP: login.validate is asked about the authenticating email');
        $allow = new \App\Support\HookManager($db);
        $allow->addHook('login.validate', static fn ($ok) => $ok);
        \App\Support\Hooks::init($allow);
        $check(is_array($ncipAuth($ADMIN, $RIGHT)), 'NCIP: a permissive login.validate leaves the login working');

        // ── 52b: OAI-PMH / ResourceSync / VIAF Basic = NCIP's gates ──────
        // A verified email and the 'login.validate' hook with the gate's own
        // channel, so a 2FA / allow-list plugin cannot be bypassed through
        // the harvesting or authority endpoints.
        $channelOf = ['OAI-PMH' => 'oai-pmh', 'ResourceSync' => 'resourcesync', 'VIAF' => 'viaf', 'NCIP' => 'ncip'];
        $channels = [];
        $refuseAll = new \App\Support\HookManager($db);
        $refuseAll->addHook('login.validate', static function ($ok, $email, $rq, string $channel = '?') use (&$channels) {
            $channels[] = [$email, $channel, $rq instanceof ServerRequestInterface];
            return false;
        });
        $allowAll = new \App\Support\HookManager($db);
        $allowAll->addHook('login.validate', static function ($ok, $email, $rq, string $channel = '?') use (&$channels) {
            $channels[] = [$email, $channel, $rq instanceof ServerRequestInterface];
            return $ok;
        });
        foreach ($gates as $label => $gate) {
            \App\Support\Hooks::init($allowAll);
            $check($gate($basicRequest($freshIp(), $UNVERIFIED, $RIGHT)) === 401, "{$label}: an unverified email is refused even with the right password");
            $channels = [];
            $check($gate($basicRequest($freshIp(), $ADMIN, $RIGHT)) === 200, "{$label}: a permissive login.validate leaves the Basic login working");
            $check($channels === [[$ADMIN, $channelOf[$label], true]], "{$label}: login.validate runs once with channel '{$channelOf[$label]}' and the request");
            \App\Support\Hooks::init($refuseAll);
            $channels = [];
            $check($gate($basicRequest($freshIp(), $ADMIN, $RIGHT)) === 401, "{$label}: a login.validate refusal turns the right password into 401");
            $channels = [];
            $gate($basicRequest($freshIp(), $ADMIN, 'wrong'));
            $check($channels === [], "{$label}: login.validate is not asked about a wrong password");
            // An unknown (or inactive) email spends one dummy verify, like a
            // wrong password: no timing oracle on which emails are accounts.
            $before = \App\Support\PasswordTiming::equalizedCount();
            $check($gate($basicRequest($freshIp(), 'hb-nobody@example.org', $RIGHT)) === 401, "{$label}: an unknown email is answered 401");
            $check(\App\Support\PasswordTiming::equalizedCount() === $before + 1, "{$label}: an unknown email spends one dummy password verify");
        }
        $inactive = 'hb-inactive@example.org';
        hardeningSeedUser($db, $inactive, $RIGHT, 'staff', ['stato' => 'sospeso']);
        $bucketAccounts[] = $inactive;
        $bucketAccounts[] = 'hb-nobody@example.org';
        $before = \App\Support\PasswordTiming::equalizedCount();
        $check($gates['NCIP']($basicRequest($freshIp(), $inactive, $RIGHT)) === 401, 'NCIP: an inactive account is refused');
        $check(\App\Support\PasswordTiming::equalizedCount() === $before + 1, 'NCIP: an inactive account spends one dummy password verify');
        // Precomputed, never hashed on the request path, and still the
        // algorithm + cost of PASSWORD_DEFAULT: a change of the default fails here.
        $dummy = \App\Support\PasswordTiming::dummyHash();
        $check(password_needs_rehash($dummy, PASSWORD_DEFAULT) === false, 'the dummy hash uses the algorithm and cost of PASSWORD_DEFAULT');
        $check(password_verify('', $dummy) === false && $dummy === \App\Support\PasswordTiming::dummyHash(), 'the dummy hash is a fixed constant that matches no password');
        \App\Support\Hooks::init($allow);

        // ── VIAF CSRF exemption: only the header the throttled gate verified
        $viafCsrf = new ReflectionMethod($viafPlugin, 'validateCsrf');
        $viafGate = new ReflectionMethod($viafPlugin, 'requireAdminOrStaff');
        $xhr = static fn (string $ip, string $user, string $password): ServerRequestInterface => $basicRequest($ip, $user, $password)
            ->withMethod('POST')->withHeader('X-Requested-With', 'XMLHttpRequest');
        $_SESSION = [];
        $check($viafCsrf->invoke($viafPlugin, $xhr($freshIp(), $ADMIN, $RIGHT)) === false,
            'VIAF: the CSRF exemption never verifies a password on its own (no gate pass on this request)');
        $rq = $xhr($freshIp(), $ADMIN, $RIGHT);
        $out = null;
        $check($viafGate->invokeArgs($viafPlugin, [$template, &$out, $rq]) === true && $viafCsrf->invoke($viafPlugin, $rq) === true,
            'VIAF: after the gate authenticated this Basic header, the API client is exempt from CSRF');
        $other = $xhr($freshIp(), $spray[0], $RIGHT);
        $check($viafCsrf->invoke($viafPlugin, $other) === false, 'VIAF: a different Basic header is not exempt');
        $out = null;
        $viafGate->invokeArgs($viafPlugin, [$template, &$out, $basicRequest($freshIp(), $ADMIN, 'wrong')]);
        $check($viafCsrf->invoke($viafPlugin, $rq) === false, 'VIAF: a later failed gate pass clears the remembered header');

        // ── 47: mobile login runs and honours login.validate ──────────────
        $db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('mobile_api', 'enabled', '1')");
        $mobileLogin = static function (string $email, string $password) use ($db): array {
            $rq = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/auth/login')
                ->withHeader('Content-Type', 'application/json');
            $rq->getBody()->write((string) json_encode(['email' => $email, 'password' => $password]));
            $rq->getBody()->rewind();
            $res = (new \App\Plugins\MobileApi\Controllers\AuthController($db))->login($rq, (new ResponseFactory())->createResponse());
            $body = json_decode((string) $res->getBody(), true);
            return [$res->getStatusCode(), is_array($body) ? (string) ($body['error']['code'] ?? '') : ''];
        };
        // The unverified account stops right AFTER the password and the hook
        // (403 email_not_verified), so it shows which of the two refused.
        \App\Support\Hooks::init($allow);
        $check($mobileLogin($UNVERIFIED, $RIGHT) === [403, 'email_not_verified'], 'mobile: right password + permissive hook reaches the verification step');
        $seen = [];
        \App\Support\Hooks::init($refuse);
        $check($mobileLogin($UNVERIFIED, $RIGHT) === [401, 'invalid_credentials'], 'mobile: a login.validate refusal turns the right password into 401');
        $check($seen === [$UNVERIFIED], 'mobile: login.validate is asked about the login email');
        \App\Support\Hooks::init(new \App\Support\HookManager($db));

        // ── 49: staff errors.csv answers catalogue imports only ───────────
        $importer = hardeningSeedUser($db, 'hb-importer@example.org', $RIGHT, 'staff');
        $errors = (string) json_encode([['line' => 2, 'title' => 'Mario Rossi', 'type' => 'invalid', 'message' => 'patron row']]);
        $imports = [];
        foreach (['csv', 'librarything', 'marc', 'users'] as $type) {
            $id = $type . '-' . bin2hex(random_bytes(6));
            $stmt = $db->prepare("INSERT INTO import_logs (import_id, import_type, file_name, user_id, errors_json, status) VALUES (?, ?, 'f.csv', ?, ?, 'completed')");
            $stmt->bind_param('ssis', $id, $type, $importer, $errors);
            $stmt->execute();
            $stmt->close();
            $imports[$type] = $id;
        }
        $_SESSION = ['user' => ['id' => $importer, 'tipo_utente' => 'staff', 'email' => 'hb-importer@example.org']];
        $errorsCsv = static function (string $importId) use ($db): array {
            $res = (new \App\Plugins\MobileApi\Controllers\StaffImportsController($db))
                ->errorsCsv((new ServerRequestFactory())->createServerRequest('GET', '/'), (new ResponseFactory())->createResponse(), $importId);
            return [$res->getStatusCode(), (string) $res->getBody()];
        };
        foreach (['csv', 'librarything', 'marc'] as $type) {
            [$status, $body] = $errorsCsv($imports[$type]);
            $check($status === 200 && str_contains($body, 'patron row'), "errors.csv: the {$type} catalogue import report is served (HTTP {$status})");
        }
        [$status, $body] = $errorsCsv($imports['users']);
        $check($status === 404 && !str_contains($body, 'Mario Rossi'), "errors.csv: a patron (users) import report is not served here (HTTP {$status})");
        $_SESSION = [];
    } catch (\Throwable $e) {
        $check(false, 'DB section raised ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        $_SESSION = [];
        $previousHooks !== null ? \App\Support\Hooks::init($previousHooks) : \App\Support\Hooks::init(new \App\Support\HookManager($db));
        foreach ($bucketIps as $ip) {
            $ipKey = RateLimiter::clientKey($ip);
            foreach (['oai_unimarc_basic', 'resourcesync_basic', 'viaf_basic', 'ncip_basic'] as $prefix) {
                RateLimiter::reset("{$prefix}_fail:{$ipKey}");
                RateLimiter::reset("{$prefix}:{$ipKey}:" . RateLimiter::accountKey(''));
                foreach ($bucketAccounts as $account) {
                    RateLimiter::reset("{$prefix}:{$ipKey}:" . RateLimiter::accountKey($account));
                }
            }
        }
        ($sandbox['drop'])();
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
