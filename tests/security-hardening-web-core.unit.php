<?php
declare(strict_types=1);

/**
 * Security-audit hardening follow-up, group A (web core / auth / users /
 * public API). DB-free: behaviour is exercised directly where the code path
 * returns before touching the database (an unconnected mysqli_init() handle
 * throws if it is used), the rest is pinned on the source.
 *
 * Run: php tests/security-hardening-web-core.unit.php   (exit 0 iff all pass)
 */

use App\Controllers\Admin\LanguagesController;
use App\Controllers\RecensioniController;
use App\Controllers\SearchController;
use App\Controllers\SecurityLogsController;
use App\Middleware\CsrfMiddleware;
use App\Support\RememberMeService;
use App\Support\RouteTranslator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

// Buffer output so setcookie() calls in the code under test do not warn.
ob_start();
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$src = static fn(string $rel): string => (string) file_get_contents($root . '/' . $rel);
/** Strip // and /* comments so a source assertion cannot be satisfied by prose. */
$code = static function (string $php): string {
    $out = '';
    foreach (token_get_all($php) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};

// ---------------------------------------------------------------------------
echo "-- [1] login double-submit cookie: __Host- on HTTPS --\n";
$check(CsrfMiddleware::loginCookieName([]) === 'csrf_login', 'plain HTTP keeps the legacy csrf_login name');
$check(CsrfMiddleware::loginCookieName(['HTTPS' => 'off']) === 'csrf_login', 'HTTPS=off is plain HTTP');
$check(CsrfMiddleware::loginCookieName(['HTTPS' => 'on']) === '__Host-csrf_login', 'native HTTPS uses __Host-csrf_login');
$check(CsrfMiddleware::loginCookieName(['HTTP_X_FORWARDED_PROTO' => 'https']) === '__Host-csrf_login', 'TLS proxy (X-Forwarded-Proto) uses __Host-csrf_login');
$check(CsrfMiddleware::loginCookieName(['HTTP_X_FORWARDED_SSL' => 'on']) === '__Host-csrf_login', 'TLS proxy (X-Forwarded-Ssl) uses __Host-csrf_login');

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $r = new SlimResponse(200);
        $r->getBody()->write('handled');
        return $r;
    }
};
$loginRoute = RouteTranslator::route('login');
$token = bin2hex(random_bytes(32));
$loginPost = static function (array $server, array $cookies) use ($loginRoute, $token): ServerRequestInterface {
    return (new ServerRequestFactory())->createServerRequest('POST', $loginRoute, $server)
        ->withHeader('Accept', 'application/json')
        ->withParsedBody(['csrf_token' => $token])
        ->withCookieParams($cookies);
};
$mw = new CsrfMiddleware();
$_SESSION = [];
$res = $mw->process($loginPost(['HTTPS' => 'on'], ['__Host-csrf_login' => $token]), $handler);
$check($res->getStatusCode() === 200, 'HTTPS login with matching __Host-csrf_login passes');
$_SESSION = [];
$res = $mw->process($loginPost(['HTTPS' => 'on'], ['csrf_login' => $token]), $handler);
$check($res->getStatusCode() === 403, 'HTTPS login with a plantable plain csrf_login is rejected');
$_SESSION = [];
$res = $mw->process($loginPost(['REQUEST_METHOD' => 'POST'], ['csrf_login' => $token]), $handler);
$check($res->getStatusCode() === 200, 'HTTP login with matching csrf_login still passes');
$_SESSION = [];
$res = $mw->process($loginPost(['REQUEST_METHOD' => 'POST'], ['__Host-csrf_login' => $token]), $handler);
$check($res->getStatusCode() === 403, 'HTTP login does not read the __Host- name');
$auth = $code($src('app/Controllers/AuthController.php'));
$check(str_contains($auth, 'CsrfMiddleware::loginCookieName($_SERVER)') && !str_contains($auth, "'csrf_login='"), 'AuthController writes the scheme-matching cookie name');

// ---------------------------------------------------------------------------
echo "-- [2] password reset mails the stored address --\n";
$pw = $code($src('app/Controllers/PasswordController.php'));
$check(str_contains($pw, 'SELECT id, email, nome'), 'forgot() selects the stored email');
$check(str_contains($pw, 'sendEmail($recipient,') && str_contains($pw, 'Mailer::send($recipient,') && !str_contains($pw, 'sendEmail($email,'), 'forgot() sends to the stored address, not the typed one');

// ---------------------------------------------------------------------------
echo "-- [4] remember_token[] array cookie --\n";
$svcRef = new ReflectionClass(RememberMeService::class);
$svcRef->setStaticPropertyValue('tableExistsCache', true);
$svc = $svcRef->newInstanceWithoutConstructor();
$svcRef->getProperty('db')->setValue($svc, mysqli_init());
$_COOKIE['remember_token'] = ['x'];
try {
    $check($svc->validateToken() === null, 'array cookie is treated as no cookie by validateToken()');
} catch (\Throwable $e) {
    $check(false, 'validateToken() threw ' . get_class($e) . ': ' . $e->getMessage());
}
try {
    $svc->revokeCurrentToken();
    $check(true, 'revokeCurrentToken() ignores an array cookie');
} catch (\Throwable $e) {
    $check(false, 'revokeCurrentToken() threw ' . get_class($e));
}
unset($_COOKIE['remember_token']);
RememberMeService::resetTableExistsCache();

// ---------------------------------------------------------------------------
echo "-- [12] sitemap / llms.txt not shared-cacheable --\n";
$seo = $code($src('app/Controllers/SeoController.php'));
$check(!str_contains($seo, 's-maxage') && substr_count($seo, "'private, max-age=3600'") === 2, 'sitemap.xml and llms.txt are Cache-Control: private');

// ---------------------------------------------------------------------------
echo "-- [15] public API reviews carry no account id --\n";
$api = $code($src('app/Controllers/PublicApiController.php'));
$check(!str_contains($api, 'u.id AS utente_id') && !str_contains($api, "\$row['utente_id']"), 'review payload drops the reviewer user id');

// ---------------------------------------------------------------------------
echo "-- [16] reader browse by author/publisher excludes wanted titles --\n";
$libri = $code($src('app/Controllers/LibriApiController.php'));
$check(substr_count($libri, '$this->readerBrowsePredicate($db)') === 2, 'getByAuthor and getByPublisher apply the reader predicate');
$ctrl = new \App\Controllers\LibriApiController();
$pred = new ReflectionMethod($ctrl, 'readerBrowsePredicate');
$_SESSION['user'] = ['id' => 1, 'tipo_utente' => 'staff'];
$check($pred->invoke($ctrl, mysqli_init()) === '1=1', 'staff keep the full list');
$_SESSION['user'] = ['id' => 2, 'tipo_utente' => 'standard'];
$probe = mysqli_init();
$check($pred->invoke($ctrl, $probe) === \App\Support\BookVisibility::catalogue($probe, 'l'), 'readers get BookVisibility::catalogue()');
unset($_SESSION['user']);

// ---------------------------------------------------------------------------
echo "-- [17/66] /api/search/autori without q --\n";
$search = new SearchController();
$anon = (new ServerRequestFactory())->createServerRequest('GET', '/api/search/autori');
try {
    $r = $search->authors($anon, new SlimResponse(), mysqli_init());
    $check((string) $r->getBody() === '[]', 'anonymous empty q returns [] without querying');
} catch (\Throwable $e) {
    $check(false, 'anonymous empty q queried the authority file (' . get_class($e) . ')');
}
$op = $anon->withAttribute(\App\Middleware\SessionRoleRefreshMiddleware::ATTRIBUTE, true);
try {
    $search->authors($op, new SlimResponse(), mysqli_init());
    $check(false, 'operator empty q should still load the full list');
} catch (\Throwable $e) {
    $check(true, 'operator empty q still loads the full list (book form Choices.js)');
}
$sc = $code($src('app/Controllers/SearchController.php'));
$check(str_contains($sc, "\$limitSql = \$isOperator ? '' : ' LIMIT '"), 'non-operator q search is capped');

// ---------------------------------------------------------------------------
echo "-- [27/28/98] public/index.php --\n";
$index = $code($src('public/index.php'));
$check(str_contains($index, "storage/cache/update.lock") && str_contains($index, '!$maintenanceUpdateRunning && time() - $maintenanceTime > 1800'), 'stale maintenance flag is kept while the updater holds update.lock');
$check(str_contains($index, "if (!\$isCli && !file_exists(\$maintenanceFile)) {\n    try {\n        \\App\\Support\\SchemaRepair::runOncePerVersion(")
    && str_contains($src('app/Support/SchemaRepair.php'), 'if (self::installInProgress($root))'),
    'SchemaRepair is gated on the installer claim, not on .installed (.env alone counts as installed)');
$check(str_contains($index, '\\App\\Support\\HtmlHelper::isRemoteAddrTrustedProxy()')
    && str_contains($index, "getenv('APP_ENV') === 'production' && \$httpsForSharedHeaders"), 'CSP upgrade-insecure-requests needs a trusted proxy for X-Forwarded-*');

// ---------------------------------------------------------------------------
echo "-- [31] security logs admin-only --\n";
$_SESSION['user'] = ['id' => 3, 'tipo_utente' => 'staff'];
$r = (new SecurityLogsController())->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/security-logs'), new SlimResponse());
$check($r->getStatusCode() === 302 && !str_contains((string) $r->getBody(), 'SECURITY'), 'staff is redirected away from /admin/security-logs');
unset($_SESSION['user'], $_SESSION['error_message']);

// ---------------------------------------------------------------------------
echo "-- [41] notification link allow-list --\n";
$layout = $src('app/Views/layout.php');
$check(str_contains($layout, 'isSafeNotificationLink(notif.link)') && !str_contains($layout, '!/^javascript:/i.test(String(notif.link))'), 'notification renderer uses an allow-list');
$node = trim((string) @shell_exec('command -v node 2>/dev/null'));
if ($node !== '' && preg_match('/const isSafeNotificationLink = (\(value\) => \{.*?\n            \});/s', $layout, $m)) {
    $js = 'global.window={location:{origin:"https://lib.example"}};const f=' . $m[1] . ';'
        . 'const c={"/admin/loans":true,"https://lib.example/admin/x":true,"//evil.com":false,"/\\\\evil.com":false,'
        . '" javascript:alert(1)":false,"\\u0001javascript:alert(1)":false,"javascript:alert(1)":false,'
        . '"https://evil.com/":false,"/\\t/evil.com":false,"data:text/html,x":false};'
        . 'let bad=Object.entries(c).filter(([k,v])=>f(k)!==v).map(([k])=>JSON.stringify(k));console.log(bad.join(","));';
    $tmp = tempnam(sys_get_temp_dir(), 'notif') . '.js';
    file_put_contents($tmp, $js);
    $out = trim((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1'));
    @unlink($tmp);
    $check($out === '', 'allow-list accepts app paths/same origin and refuses the rest' . ($out !== '' ? " (wrong: {$out})" : ''));
} else {
    echo "  SKIP node not available for the behavioural JS check\n";
}

// ---------------------------------------------------------------------------
echo "-- [42] route slug charset --\n";
$allShipped = true;
foreach (glob($root . '/locale/routes_*.json') ?: [] as $file) {
    foreach ((array) json_decode((string) file_get_contents($file), true) as $key => $value) {
        if (!LanguagesController::isSafeRouteSlug((string) $value)) {
            $allShipped = false;
            echo "    rejected shipped route {$key} = {$value}\n";
        }
    }
}
$check($allShipped, 'every shipped route slug is accepted');
$check(LanguagesController::isSafeRouteSlug('/bøger'), 'non-ASCII letters are accepted');
$rejected = true;
foreach (['/a"b', "/a'b", '/<x', '/a>b', '/a?b', '/a#b', '/a%22', '/a\\b', "/a\x01", 'catalogo', '/a`b', '/a&b'] as $bad) {
    if (LanguagesController::isSafeRouteSlug($bad)) {
        $rejected = false;
        echo "    accepted unsafe slug " . json_encode($bad) . "\n";
    }
}
$check($rejected, 'quotes, angle brackets, ?, #, %, backslash, control chars are refused');
$lang = $code($src('app/Controllers/Admin/LanguagesController.php'));
$check(str_contains($lang, 'if (!self::isSafeRouteSlug($trimmedValue))'), 'updateRoutes() enforces the slug charset');

// ---------------------------------------------------------------------------
echo "-- [58] review create with array fields --\n";
$_SESSION['user'] = ['id' => 7, 'tipo_utente' => 'standard'];
$req = (new ServerRequestFactory())->createServerRequest('POST', '/api/user/recensioni')
    ->withParsedBody(['libro_id' => '1', 'stelle' => '5', 'titolo' => ['x'], 'descrizione' => 'y']);
try {
    $r = (new RecensioniController())->create($req, new SlimResponse(), mysqli_init());
    $check($r->getStatusCode() === 400, 'array titolo is a 400, not a TypeError');
} catch (\Throwable $e) {
    $check(false, 'array titolo threw ' . get_class($e));
}
unset($_SESSION['user']);

// ---------------------------------------------------------------------------
echo "-- [84/85/99] UsersController --\n";
$users = $code($src('app/Controllers/UsersController.php'));
$check(str_contains($users, "SET stato = 'sospeso', token_verifica_email = NULL, data_token_verifica = NULL"), 'delete() suspend fallback clears the email-verification token');
$check((bool) preg_match('/strtolower\(trim\(\(string\) \(\$original\[\'email\'\] \?\? \'\'\)\)\) !== strtolower\(trim\(\(string\) \$email\)\)\) \{\s*\$tokenReset = null;\s*\$dataTokenReset = null;/', $users), 'update() drops the reset token when the email changes');
$check(str_contains($users, '\\App\\Support\\ContentCache::reviewsChanged();'), 'delete() invalidates the cached public reviews');

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
