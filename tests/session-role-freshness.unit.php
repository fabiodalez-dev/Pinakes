<?php
declare(strict_types=1);

/**
 * The role a request acts on must be the account's CURRENT role, read from the
 * database, never the snapshot the session took at login (CWE-613).
 *
 * `$_SESSION['user']['tipo_utente']` is read directly in about sixty places.
 * Those reads are only as good as the middleware that runs before them. An
 * audit of all 317 routes found the gap was not in the readers but in the
 * chain: AuthMiddleware compared the snapshot and never touched the database,
 * so on the 33 routes it guards — nine of them admin-only, including the
 * maintenance actions — a demoted, suspended or deleted account kept its old
 * access for the life of its session, and two routes that admit every role
 * then branched on the stale role inline (borrower PII on /admin/books/{id},
 * the activity feed on the dashboard).
 *
 * All three role-aware middlewares now go through SessionRoleRevalidator.
 *
 * A. AuthMiddleware re-reads the account, requires it active, ends the session
 *    of a suspended or deleted account, and fails closed when the database
 *    cannot answer.
 * B. AdminAuthMiddleware keeps its behaviour through the shared class.
 * C. The fresh role is written back into the session, which is what makes the
 *    inline role checks further down a request trustworthy.
 * D. Structural guard: every route whose handler reads the session role must
 *    have a middleware that refreshes it. This is the half that protects the
 *    NEXT route someone adds; behavioural tests only cover routes someone
 *    thought of.
 *
 * Runs against a disposable sandbox database, building the schema itself so
 * the order suites run in cannot change the outcome. FAILS HARD rather than
 * skipping when that database is unreachable.
 *
 * Run:  php tests/session-role-freshness.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

use App\Middleware\AdminAuthMiddleware;
use App\Middleware\AuthMiddleware;
use App\Support\SessionRoleRevalidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = $env['DB_NAME'] ?? '';
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

$sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName) || strcasecmp($sandboxName, (string) $dbName) === 0) {
    fwrite(STDERR, "FAIL: refusing to use '{$sandboxName}' as a sandbox.\n");
    exit(1);
}

$connect = static function (string $database) use ($socket, $dbUser, $dbPass, $dbHost, $dbPort): mysqli {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $database, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $database, $dbPort);
    $db->set_charset('utf8mb4');

    return $db;
};

// Build the schema this suite needs, whatever a sibling left behind.
$prep = $connect($sandboxName);
$prep->query('SET FOREIGN_KEY_CHECKS = 0');
$tables = [];
$res = $prep->query('SHOW TABLES');
while ($t = $res->fetch_row()) {
    $tables[] = $t[0];
}
foreach ($tables as $t) {
    $prep->query('DROP TABLE IF EXISTS `' . $t . '`');
}
$prep->query('SET FOREIGN_KEY_CHECKS = 1');
$prep->close();

$useSocket = is_string($socket) && $socket !== '' && file_exists($socket);
$command = $useSocket
    ? sprintf('mysql --socket=%s -u %s %s', escapeshellarg($socket), escapeshellarg((string) $dbUser), escapeshellarg($sandboxName))
    : sprintf('mysql -h %s -P %d --protocol=TCP -u %s %s', escapeshellarg((string) $dbHost), $dbPort, escapeshellarg((string) $dbUser), escapeshellarg($sandboxName));
$command .= ' < ' . escapeshellarg($root . '/installer/database/schema.sql') . ' 2>&1';
$previousPwd = getenv('MYSQL_PWD');
putenv('MYSQL_PWD=' . (string) $dbPass);
try {
    exec($command, $out, $rc);
} finally {
    $previousPwd === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previousPwd);
}
if ($rc !== 0) {
    throw new RuntimeException('could not load schema.sql: ' . implode("\n", $out));
}

$db = $connect($sandboxName);

const ACTIVE_ADMIN = 910001;
const DEMOTED_TO_STAFF = 910002;
const DEMOTED_TO_STANDARD = 910003;
const SUSPENDED_ADMIN = 910004;
const EXPIRED_STANDARD = 910005;
const ACTIVE_STANDARD = 910006;
const DELETED_ACCOUNT = 910099;

$seed = $db->prepare(
    'INSERT INTO utenti (id, codice_tessera, nome, cognome, email, password, tipo_utente, stato)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ([
    [ACTIVE_ADMIN,        'admin',    'attivo'],
    [DEMOTED_TO_STAFF,    'staff',    'attivo'],
    [DEMOTED_TO_STANDARD, 'standard', 'attivo'],
    [SUSPENDED_ADMIN,     'admin',    'sospeso'],
    [EXPIRED_STANDARD,    'standard', 'scaduto'],
    [ACTIVE_STANDARD,     'standard', 'attivo'],
] as [$id, $role, $state]) {
    $tessera = 'ROLEFRESH' . $id;
    $nome = 'Probe';
    $cognome = (string) $id;
    $email = 'rolefresh' . $id . '@example.invalid';
    $password = 'x';
    $seed->bind_param('isssssss', $id, $tessera, $nome, $cognome, $email, $password, $role, $state);
    $seed->execute();
}
$seed->close();

$handler = new class implements RequestHandlerInterface {
    public bool $reached = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->reached = true;

        return new SlimResponse(200);
    }
};

/**
 * Run one middleware against one session and report what happened.
 *
 * @return array{reached: bool, status: int, session: ?array}
 */
$run = static function (object $middleware, ?array $sessionUser, string $path = '/admin/maintenance/perform') use ($handler): array {
    // Each probe is its own request: forget the previous request's verdicts.
    SessionRoleRevalidator::reset();
    $_SESSION = $sessionUser === null ? [] : ['user' => $sessionUser];
    $handler->reached = false;
    $response = $middleware->process((new ServerRequestFactory())->createServerRequest('GET', $path), $handler);

    return ['reached' => $handler->reached, 'status' => $response->getStatusCode(), 'session' => $_SESSION['user'] ?? null];
};

echo "A. AuthMiddleware re-reads the account instead of trusting the snapshot\n";

$adminOnly = new AuthMiddleware(['admin'], $db);
$everyone = new AuthMiddleware(['admin', 'staff', 'standard', 'premium'], $db);

$r = $run($adminOnly, ['id' => ACTIVE_ADMIN, 'tipo_utente' => 'admin']);
$check($r['reached'], 'an active admin reaches an admin-only route');

$r = $run($adminOnly, ['id' => DEMOTED_TO_STAFF, 'tipo_utente' => 'admin']);
$check(!$r['reached'] && $r['status'] === 302, 'an admin demoted to staff is refused on an admin-only route, although the session still says admin');

$r = $run($adminOnly, ['id' => SUSPENDED_ADMIN, 'tipo_utente' => 'admin']);
$check(!$r['reached'], 'a suspended admin is refused');
$check($r['session'] === null, 'and the session of the suspended account is ended, not merely refused');

$r = $run($everyone, ['id' => EXPIRED_STANDARD, 'tipo_utente' => 'standard']);
$check(!$r['reached'] && $r['session'] === null, "an account whose state is 'scaduto' is logged out, like the login form already refuses it");

$r = $run($adminOnly, ['id' => DELETED_ACCOUNT, 'tipo_utente' => 'admin']);
$check(!$r['reached'] && $r['session'] === null, 'a session for an account that no longer exists is ended');

$r = $run($everyone, ['id' => ACTIVE_STANDARD, 'tipo_utente' => 'standard']);
$check($r['reached'], 'an ordinary active member still reaches a route open to every role');

$closed = $connect($sandboxName);
$closed->close();
$r = $run(new AuthMiddleware(['admin'], $closed), ['id' => ACTIVE_ADMIN, 'tipo_utente' => 'admin']);
$check(!$r['reached'], 'an unreachable database fails CLOSED: a real admin is refused rather than admitted on the snapshot');
$check(($r['session']['tipo_utente'] ?? null) === 'admin', 'and a failed read leaves the session untouched — a blip must not log anyone out');

$r = $run(new AuthMiddleware([], $db), ['id' => ACTIVE_ADMIN, 'tipo_utente' => 'admin']);
$check(!$r['reached'], 'an empty role list still denies everyone');

$r = $run($adminOnly, null);
$check(!$r['reached'] && $r['status'] === 302, 'no session at all is sent to the login page');

echo "\nB. AdminAuthMiddleware keeps its behaviour through the shared revalidator\n";

$admin = new AdminAuthMiddleware($db);

$r = $run($admin, ['id' => ACTIVE_ADMIN, 'tipo_utente' => 'admin'], '/api/admin/probe');
$check($r['reached'], 'an active admin passes');

$r = $run($admin, ['id' => DEMOTED_TO_STANDARD, 'tipo_utente' => 'admin'], '/api/admin/probe');
$check(!$r['reached'] && $r['status'] === 403, 'an admin demoted to standard is refused with 403 on an API path');

$r = $run($admin, ['id' => SUSPENDED_ADMIN, 'tipo_utente' => 'admin'], '/api/admin/probe');
$check(!$r['reached'], 'a suspended admin is refused');

$r = $run(new AdminAuthMiddleware($closed), ['id' => ACTIVE_ADMIN, 'tipo_utente' => 'admin'], '/api/admin/probe');
$check(!$r['reached'], 'an unreachable database fails closed here too');

echo "\nC. The fresh role reaches the inline checks further down the request\n";

// A staff member whose snapshot still says admin: AdminAuthMiddleware admits
// staff, so the request goes through — and the destructive admin-only checks
// inside the controllers must then see 'staff', not the stale 'admin'.
$r = $run($admin, ['id' => DEMOTED_TO_STAFF, 'tipo_utente' => 'admin'], '/api/admin/probe');
$check($r['reached'], 'an admin demoted to staff still passes the admin-or-staff gate');
$check(($r['session']['tipo_utente'] ?? null) === 'staff', "and the session now says 'staff', so an inline tipo_utente === 'admin' check refuses the account");

$r = $run($everyone, ['id' => DEMOTED_TO_STANDARD, 'tipo_utente' => 'admin'], '/admin/books/1');
$check($r['reached'], 'a demoted account still reaches a route open to every role');
$check(($r['session']['tipo_utente'] ?? null) === 'standard', "and the book page's inline admin/staff check now sees 'standard', so borrower PII stays hidden");

echo "\nD. No route reads the session role without a middleware that refreshes it\n";

// Parse web.php with PHP's own tokenizer: a regex loses the closure boundary
// on comments containing quotes, which an earlier audit script did.
$tokens = array_values(array_filter(
    array_map(static fn($t) => is_array($t) ? ['t' => $t[0], 'v' => $t[1]] : ['t' => 0, 'v' => $t], token_get_all((string) file_get_contents($root . '/app/Routes/web.php'))),
    static fn($t) => !in_array($t['t'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
));
$n = count($tokens);
$routes = [];
for ($i = 0; $i < $n - 4; $i++) {
    if ($tokens[$i]['v'] !== '$app' || $tokens[$i + 1]['v'] !== '->'
        || !in_array(strtolower($tokens[$i + 2]['v']), ['get', 'post', 'put', 'delete', 'patch', 'any', 'map'], true)
        || $tokens[$i + 3]['v'] !== '(' || $tokens[$i + 4]['t'] !== T_CONSTANT_ENCAPSED_STRING) {
        continue;
    }
    $depth = 0;
    for ($j = $i + 3; $j < $n; $j++) {
        if ($tokens[$j]['v'] === '(') { $depth++; }
        if ($tokens[$j]['v'] === ')' && --$depth === 0) { break; }
    }
    $middleware = [];
    for ($k = $j + 1; $k < $n && $tokens[$k]['v'] !== ';'; $k++) {
        if ($tokens[$k]['t'] === T_NEW) {
            $middleware[] = ltrim((string) strrchr('\\' . $tokens[$k + 1]['v'], '\\'), '\\');
        }
    }
    $classes = [];
    $calls = [];
    for ($k = $i + 5; $k < $j; $k++) {
        if ($tokens[$k]['t'] === T_NEW && str_contains($tokens[$k + 1]['v'], 'Controller')) {
            $classes[] = ltrim((string) strrchr('\\' . $tokens[$k + 1]['v'], '\\'), '\\');
        }
        if ($tokens[$k]['v'] === '$controller' && ($tokens[$k + 1]['v'] ?? '') === '->') {
            $calls[] = $tokens[$k + 2]['v'];
        }
    }
    $routes[] = ['path' => trim($tokens[$i + 4]['v'], "'\""), 'mw' => $middleware, 'classes' => $classes, 'calls' => $calls];
}

$methodBody = static function (string $file, string $method): ?string {
    $t = token_get_all((string) file_get_contents($file));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_FUNCTION) { continue; }
        $j = $i + 1;
        while (is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) { $j++; }
        if (!is_array($t[$j]) || $t[$j][1] !== $method) { continue; }
        $out = '';
        $depth = 0;
        $open = false;
        for ($k = $j; $k < $n; $k++) {
            $v = is_array($t[$k]) ? $t[$k][1] : $t[$k];
            if ($v === '{') { $depth++; $open = true; }
            if ($open) { $out .= $v; }
            if ($v === '}' && --$depth === 0 && $open) { return $out; }
        }
    }
    return null;
};

$refreshing = ['AdminAuthMiddleware', 'AuthMiddleware', 'SessionRoleRefreshMiddleware'];
$roleRead = '/\$_SESSION\s*\[\s*[\'"]user[\'"]\s*\]\s*\[\s*[\'"]tipo_utente[\'"]\s*\]/';
$offenders = [];
foreach ($routes as $route) {
    if (array_intersect($route['mw'], $refreshing)) {
        continue;
    }
    foreach ($route['classes'] as $class) {
        $files = glob($root . '/app/Controllers/{,*/}' . $class . '.php', GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            foreach ($route['calls'] as $method) {
                $body = $methodBody($file, $method);
                if ($body !== null && preg_match($roleRead, $body)) {
                    $offenders[] = $route['path'] . ' -> ' . $class . '::' . $method;
                }
            }
        }
    }
}
$check(count($routes) > 250, 'the parser found the routes (' . count($routes) . ')');
$check($offenders === [], 'every route whose handler reads the session role refreshes it first' . ($offenders === [] ? '' : ': ' . implode(', ', $offenders)));

$authSource = (string) file_get_contents($root . '/app/Middleware/AuthMiddleware.php');
$check(str_contains($authSource, 'SessionRoleRevalidator'), 'AuthMiddleware goes through the shared revalidator');
$check(!preg_match('/in_array\(\s*\$user\[\s*[\'"]tipo_utente/', $authSource), 'and no longer compares the snapshot role');
$check(str_contains((string) file_get_contents($root . '/app/Middleware/AdminAuthMiddleware.php'), 'SessionRoleRevalidator'), 'AdminAuthMiddleware goes through it too');

$db->query('DELETE FROM utenti WHERE id BETWEEN 910001 AND 910099');
$db->close();
$_SESSION = [];

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
