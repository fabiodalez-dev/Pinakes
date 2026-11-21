<?php
declare(strict_types=1);

/**
 * /api/search/unified discloses wanted titles to operators. The claim it acts
 * on must be re-validated against the database, not read from the login-time
 * session snapshot (CWE-613).
 *
 * The shape of the original defect is worth stating, because it is not "a
 * missing permission check". `$_SESSION['user']['tipo_utente']` is written at
 * login and read directly in roughly sixty places. Those reads are sound
 * because they sit behind AdminAuthMiddleware, whose revalidateRole() does not
 * merely decide yes/no — it writes the CURRENT database role back into the
 * session. The invariant "the session role is fresh" is maintained by the
 * middleware chain and merely consumed by the controllers.
 *
 * /api/search/unified takes no middleware — deliberately, since anonymous
 * callers and plugin sources must reach it — so nothing maintained that
 * invariant for it, and its role read was a stale claim that outlived a
 * demotion or a suspension until the session expired. The fix is not an inline
 * check in the controller (that would close this one hole and leave the
 * structure that produced it) but a middleware that re-validates WITHOUT
 * denying, publishing its verdict as a request attribute.
 *
 * Part C is the one that matters most: a middleware that is correct and not
 * attached to the route protects nothing, and nothing else in the suite would
 * notice.
 *
 * Runs against a DISPOSABLE database, building the schema itself if it is not
 * already there, so the order suites run in cannot change the outcome. FAILS
 * HARD rather than skipping when that database is unreachable — a skip here is
 * indistinguishable from a pass.
 *
 * Run:  php tests/desiderata-operator-session.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

use App\Middleware\SessionRoleRefreshMiddleware;
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
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

$sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName)) {
    throw new RuntimeException("invalid sandbox database name '{$sandboxName}'");
}
// The sandbox is refused if it names ANY real database this run could mean:
// the installation's (.env DB_NAME) and the E2E one (E2E_DB_NAME). Comparing
// against only one let DESIDERATA_SANDBOX_DB equal to the other slip through,
// and the setup below would then empty every table in it.
if (in_array(strtolower($sandboxName), array_map('strtolower', array_filter([(string) $dbName, (string) getenv('E2E_DB_NAME'), (string) ($env['DB_NAME'] ?? '')], static fn (string $name): bool => $name !== '')), true)) {
    fwrite(STDERR, "FAIL: the sandbox name is the installation's own database. Name a disposable one.\n");
    exit(1);
}

$connect = static function (string $database) use ($socket, $dbUser, $dbPass, $dbHost, $dbPort): mysqli {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $database, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $database, $dbPort);
    $db->set_charset('utf8mb4');

    return $db;
};

// The shared sandbox, used through its REAL `utenti` table rather than a
// hand-rolled one. Two reasons: the app user has no CREATE DATABASE grant, so
// a private database is not available; and the middleware queries `utenti` by
// name, so a stand-in table would only prove that a fixture I wrote agrees
// with a query I wrote. Fixtures live on reserved high ids and are removed at
// the end, leaving the sandbox as it was found.
$admin = $connect('');
$admin->query("CREATE DATABASE IF NOT EXISTS `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->close();

// Builds its own schema, unconditionally. The sandbox is shared, and the
// siblings leave it in mutually incompatible states — desiderata-visibility
// deliberately reduces `libri` to a three-column stub of the plugin's own
// making, which is correct for what that suite tests and useless here. The
// rule every desiderata suite now follows is the same: build what you need
// rather than hope to find it, so the order they run in cannot decide the
// outcome. Verified by running the whole set in both directions.
$prep = $connect($sandboxName);
$prep->query('SET FOREIGN_KEY_CHECKS = 0');
$leftovers = [];
$shown = $prep->query('SHOW TABLES');
while ($t = $shown->fetch_row()) {
    $leftovers[] = $t[0];
}
foreach ($leftovers as $t) {
    $prep->query('DROP TABLE IF EXISTS `' . $t . '`');
}
$prep->query('SET FOREIGN_KEY_CHECKS = 1');
$prep->close();

$useSocket = is_string($socket) && $socket !== '' && file_exists($socket);
$command = $useSocket
    ? sprintf('mysql --socket=%s -u %s %s', escapeshellarg($socket), escapeshellarg((string) $dbUser), escapeshellarg($sandboxName))
    : sprintf(
        'mysql -h %s -P %d --protocol=TCP -u %s %s',
        escapeshellarg((string) $dbHost),
        $dbPort,
        escapeshellarg((string) $dbUser),
        escapeshellarg($sandboxName)
    );
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

const ADMIN_ID = 900001;
const STAFF_ID = 900002;
const MEMBER_ID = 900003;
const SUSPENDED_ID = 900004;
const FRESH_ID = 900005;
const ABSENT_ID = 900099;
const CATALOGER_ID = 900006;
const LENDER_ID = 900007;
const SUSPENDED_CATALOGER_ID = 900008;
const CATALOG_ROLE_ID = 900101;
const LOANS_ROLE_ID = 900102;

$ids = [ADMIN_ID, STAFF_ID, MEMBER_ID, SUSPENDED_ID, FRESH_ID, ABSENT_ID, CATALOGER_ID, LENDER_ID, SUSPENDED_CATALOGER_ID];
$purge = static function () use ($db, $ids): void {
    $db->query('DELETE FROM utenti WHERE id IN (' . implode(',', $ids) . ')');
    $db->query('DELETE FROM ruoli WHERE id IN (' . CATALOG_ROLE_ID . ',' . LOANS_ROLE_ID . ')');
};
$purge();
$db->query("INSERT INTO ruoli (id, nome, permessi) VALUES
    (" . CATALOG_ROLE_ID . ", 'Probe cataloguer', '[\"backend.access\",\"catalog.manage\"]'),
    (" . LOANS_ROLE_ID . ", 'Probe lender', '[\"backend.access\",\"loans.manage\"]')");

$seed = $db->prepare(
    'INSERT INTO utenti (id, codice_tessera, nome, cognome, email, password, tipo_utente, stato)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ([
    [ADMIN_ID,     'admin',  'attivo'],
    [STAFF_ID,     'staff',  'attivo'],
    [MEMBER_ID,    'standard', 'attivo'],
    [SUSPENDED_ID, 'admin',  'sospeso'],
    // A genuine active admin kept UNPROBED until part C — see the note there.
    [FRESH_ID,     'admin',  'attivo'],
    [CATALOGER_ID, 'standard', 'attivo'],
    [LENDER_ID, 'standard', 'attivo'],
    [SUSPENDED_CATALOGER_ID, 'standard', 'sospeso'],
] as [$id, $role, $state]) {
    $tessera = 'ROLEPROBE' . $id;
    $nome = 'Probe';
    $cognome = (string) $id;
    $email = 'roleprobe' . $id . '@example.invalid';
    $password = 'x';
    $seed->bind_param('isssssss', $id, $tessera, $nome, $cognome, $email, $password, $role, $state);
    $seed->execute();
}
$seed->close();
$db->query('UPDATE utenti SET ruolo_id = ' . CATALOG_ROLE_ID . ' WHERE id IN (' . CATALOGER_ID . ',' . SUSPENDED_CATALOGER_ID . ')');
$db->query('UPDATE utenti SET ruolo_id = ' . LOANS_ROLE_ID . ' WHERE id = ' . LENDER_ID);

$_SESSION = [];

/** Runs the middleware with a given session and returns the published verdict. */
$verdict = static function (?array $sessionUser) use ($db): bool {
    if ($sessionUser === null) {
        unset($_SESSION['user']);
    } else {
        $_SESSION['user'] = $sessionUser;
    }

    // A fresh instance per call, though note this does NOT clear the memo: it
    // is a STATIC property, so it outlives every instance for the life of the
    // process. That is correct in production — PHP is shared-nothing, one
    // request per process, and AdminAuthMiddleware caches the same way — but it
    // means a suite walking several identities in one process must give each
    // probe an id of its own. Part C depends on this.
    $middleware = new SessionRoleRefreshMiddleware($db);

    $seen = null;
    $handler = new class ($seen) implements RequestHandlerInterface {
        public function __construct(private mixed &$seen) {}

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->seen = $request->getAttribute(SessionRoleRefreshMiddleware::ATTRIBUTE);

            return new SlimResponse(200);
        }
    };

    $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/search/unified');
    $response = $middleware->process($request, $handler);

    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('the middleware must never deny: got ' . $response->getStatusCode());
    }

    return $seen === true;
};

echo "A. The verdict follows the DATABASE, not the session snapshot\n";

$check($verdict(null) === false, 'an anonymous caller is not an operator');
$check($verdict(['id' => ADMIN_ID, 'tipo_utente' => 'admin']) === true, 'a real admin is');
$check($verdict(['id' => STAFF_ID, 'tipo_utente' => 'staff']) === true, 'so is a real staff member');
$check($verdict(['id' => MEMBER_ID, 'tipo_utente' => 'standard']) === false, 'an ordinary member is not');

echo "\nB. And a stale claim does not survive it — this is the whole point\n";

// Each of these sessions SAYS admin. Before the fix the controller read exactly
// this and believed it.
$check($verdict(['id' => MEMBER_ID, 'tipo_utente' => 'admin']) === false,
    'a session claiming admin for an account that is an ordinary member is refused');
$check($verdict(['id' => SUSPENDED_ID, 'tipo_utente' => 'admin']) === false,
    'a suspended admin is refused — the session outlived the suspension');
$check($verdict(['id' => ABSENT_ID, 'tipo_utente' => 'admin']) === false,
    'a session for an account that no longer exists is refused');
$check($verdict(['tipo_utente' => 'admin']) === false,
    'a session with no usable id is refused rather than trusted');

echo "\nC. The privilege fails closed; the request does not\n";

// A public endpoint that started erroring because a role lookup hiccuped would
// be a worse bug than the one being fixed. Simulate the unreachable database by
// giving the middleware neither a handle nor a container.
//
// FRESH_ID, not ADMIN_ID, and the distinction is the whole value of this part.
// The memo is static and already holds `true` for every id probed above, so
// reusing one would return that cached verdict without ever reaching the
// fail-closed branch — the check would pass while observing nothing. FRESH_ID
// is a real, active admin that no earlier probe has touched, so the only thing
// that can make this false is the unreachable database.
$_SESSION['user'] = ['id' => FRESH_ID, 'tipo_utente' => 'admin'];
$orphan = new SessionRoleRefreshMiddleware();
$reached = false;
$handler = new class ($reached) implements RequestHandlerInterface {
    public function __construct(private mixed &$reached) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->reached = $request->getAttribute(SessionRoleRefreshMiddleware::ATTRIBUTE);

        return new SlimResponse(200);
    }
};
$response = $orphan->process((new ServerRequestFactory())->createServerRequest('GET', '/api/search/unified'), $handler);
$check($response->getStatusCode() === 200, 'the handler still runs when the role cannot be checked');
$check($reached === false, 'but the operator claim is dropped, though the account is a real active admin');

echo "\nC2. Custom-role cataloguers get the author picker's full list\n";

$attributes = static function (array $sessionUser) use ($db): array {
    $_SESSION['user'] = $sessionUser;
    $seen = [];
    $handler = new class ($seen) implements RequestHandlerInterface {
        public function __construct(private mixed &$seen) {}

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->seen = $request->getAttributes();

            return new SlimResponse(200);
        }
    };
    (new SessionRoleRefreshMiddleware($db))->process(
        (new ServerRequestFactory())->createServerRequest('GET', '/api/search/autori'),
        $handler
    );
    return $seen;
};
$cataloguer = $attributes(['id' => CATALOGER_ID, 'tipo_utente' => 'standard']);
$check(($cataloguer[SessionRoleRefreshMiddleware::ATTRIBUTE] ?? null) === false
    && ($cataloguer[SessionRoleRefreshMiddleware::CATALOG_ATTRIBUTE] ?? null) === true,
    'a reader whose role grants catalog.manage is a cataloguer, not an operator');
$check(($attributes(['id' => LENDER_ID, 'tipo_utente' => 'standard'])[SessionRoleRefreshMiddleware::CATALOG_ATTRIBUTE] ?? null) === false,
    'a role without catalog.manage is not');
$check(($attributes(['id' => SUSPENDED_CATALOGER_ID, 'tipo_utente' => 'standard'])[SessionRoleRefreshMiddleware::CATALOG_ATTRIBUTE] ?? null) === false,
    'a suspended cataloguer is not');
$check(($attributes(['id' => STAFF_ID, 'tipo_utente' => 'staff'])[SessionRoleRefreshMiddleware::CATALOG_ATTRIBUTE] ?? null) === true,
    'operators keep it');

// The controller, fed by the real middleware: 60 authors, empty query.
$db->query("DELETE FROM autori WHERE nome LIKE 'ProbeAuthor %'");
for ($i = 1; $i <= 60; $i++) {
    $db->query("INSERT INTO autori (nome) VALUES ('ProbeAuthor " . sprintf('%02d', $i) . "')");
}
$listFor = static function (array $sessionUser, string $q) use ($db): array {
    $_SESSION['user'] = $sessionUser;
    $out = [];
    $handler = new class ($db, $q, $out) implements RequestHandlerInterface {
        public function __construct(private mysqli $db, private string $q, private mixed &$out) {}

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $response = (new \App\Controllers\SearchController())->authors(
                $request->withQueryParams(['q' => $this->q]),
                new SlimResponse(),
                $this->db
            );
            $this->out = json_decode((string) $response->getBody(), true);

            return $response;
        }
    };
    (new SessionRoleRefreshMiddleware($db))->process(
        (new ServerRequestFactory())->createServerRequest('GET', '/api/search/autori'),
        $handler
    );
    return is_array($out) ? $out : [];
};
$check(count($listFor(['id' => CATALOGER_ID, 'tipo_utente' => 'standard'], '')) >= 60,
    'a cataloguer gets the full author list on an empty query (picker preload)');
$check(count($listFor(['id' => CATALOGER_ID, 'tipo_utente' => 'standard'], 'ProbeAuthor')) === 60,
    'and uncapped search results');
$check($listFor(['id' => LENDER_ID, 'tipo_utente' => 'standard'], '') === []
    && count($listFor(['id' => LENDER_ID, 'tipo_utente' => 'standard'], 'ProbeAuthor')) === 50,
    'a non-cataloguing reader still gets nothing for an empty query and a capped search');
$db->query("DELETE FROM autori WHERE nome LIKE 'ProbeAuthor %'");

echo "\nD. The middleware is actually attached to the route it protects\n";

// A middleware that is correct and unreferenced protects nothing, and every
// behavioural check above would still pass.
$routes = (string) file_get_contents($root . '/app/Routes/web.php');
$check(
    (bool) preg_match(
        "#'/api/search/unified'.{0,400}SessionRoleRefreshMiddleware#s",
        $routes
    ),
    '/api/search/unified chains SessionRoleRefreshMiddleware'
);

// And the controller must read the ATTRIBUTE, not the session: going back to
// $_SESSION at the point of use throws away everything the middleware did.
$controller = (string) file_get_contents($root . '/app/Controllers/SearchController.php');
$check(
    str_contains($controller, 'SessionRoleRefreshMiddleware::ATTRIBUTE'),
    'SearchController reads the re-validated attribute'
);
$check(
    !str_contains($controller, "\$_SESSION['user']['tipo_utente']"),
    'and no longer reads the stale session role anywhere'
);

$purge();
$db->close();

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
