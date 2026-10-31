<?php
declare(strict_types=1);

/**
 * Closures calendar (/admin/calendar): the page follows the settings
 * permission, exactly as LibraryCalendarController::guard() admits
 * Permissions::mayConfigure(). Without the prefix in the map a custom role
 * with settings.manage was refused by AdminAuthMiddleware before the
 * controller ran. /admin/calendar/rotate-link stays admin-only inside its
 * route, whatever the map says: checked statically and with a real POST
 * through the routes and middleware (needs the database from .env).
 *
 * Run: php tests/calendar-permission.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Permissions as P;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        // Thrown, not exit(): the finally below still removes the fixtures.
        throw new RuntimeException("FAIL {$label}");
    }
    $checks++;
    echo "OK {$label}\n";
};

foreach (['/admin/calendar', '/admin/calendar/weekdays', '/admin/calendar/closures', '/admin/calendar/closures/12/update', '/admin/calendar/closures/12/delete', '/admin/calendar/preset', '/admin/calendar/import-ics'] as $path) {
    $check(P::permissionForPath($path) === 'settings.manage', "{$path} needs settings.manage");
}
$check(P::permissionForPath('/admin/calendarx') === null, 'a similarly named path does not inherit it');
$check(P::permissionForPath('/admin/settings') === 'settings.manage', 'the settings pages keep their permission');

$configurator = ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'settings.manage']];
$check(P::allowsPath('/admin/calendar', $configurator), 'a custom role with settings.manage opens the calendar page');
$check(P::allowsPath('/admin/calendar/import-ics', $configurator, 'POST'), 'and reaches the ICS import');
foreach (['/admin/books', '/admin/loans', '/admin/users', '/admin/inventory'] as $path) {
    $check(!P::allowsPath($path, $configurator), "settings.manage does not open {$path}");
}

$desk = ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage']];
$check(!P::allowsPath('/admin/calendar', $desk), 'the loan desk permission alone does not open the calendar');
$check(!P::allowsPath('/admin/calendar/closures', $desk, 'POST'), 'nor its writes');
$check(P::allowsPath('/admin/calendar', ['tipo_utente' => 'staff', 'stato' => 'attivo']), 'Staff passes the middleware (the controller guard decides)');
$check(P::allowsPath('/admin/calendar', ['tipo_utente' => 'admin', 'stato' => 'attivo']), 'administrators reach the calendar');

// The route closure of /admin/calendar/rotate-link refuses every non-admin
// itself: the map admits the configurator to the middleware only.
$routes = (string) file_get_contents(dirname(__DIR__) . '/app/Routes/web.php');
$pos = strpos($routes, "'/admin/calendar/rotate-link'");
$check($pos !== false && str_contains(substr($routes, $pos, 400), "!== 'admin'"), 'rotate-link checks tipo_utente admin inside its route');

// The same at request level: a POST to /admin/calendar/rotate-link through
// the real routes and middleware, as a custom role holding settings.manage
// (admitted by the map) and as staff, with a valid CSRF token. Both are
// refused by the route and the library feed secret is not rotated.
$root = dirname(__DIR__);
// config/settings.php (read by the session role re-validation) needs the environment.
Dotenv\Dotenv::createImmutable($root)->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');

$containerBuilder = new DI\ContainerBuilder();
require $root . '/config/settings.php';
require $root . '/config/container.php';
$containerBuilder->addDefinitions($containerDefinitions ?? []);
$containerBuilder->addDefinitions(['db' => $db]);
$container = $containerBuilder->build();
Slim\Factory\AppFactory::setContainer($container);
\App\Support\Hooks::init($container->get('hookManager'));
$GLOBALS['hookManager'] = $container->get('hookManager');
$app = Slim\Factory\AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
(require $root . '/app/Routes/web.php')($app);

$tag = 'calperm-' . bin2hex(random_bytes(4));
$userIds = [];
$roleId = 0;
// The route writes the flash messages into $_SESSION while it runs.
$flash = static fn (string $key): mixed => $_SESSION[$key] ?? null;
$secretOf = static function () use ($db): ?string {
    $row = $db->query("SELECT setting_value FROM system_settings WHERE category = 'calendar' AND setting_key = 'library_ics_secret'")->fetch_row();
    return $row === null ? null : (string) $row[0];
};
try {
    (new \App\Models\CustomRoleRepository($db))->save([], ['nome' => $tag, 'permissions' => ['backend.access', 'settings.manage']]);
    $roleId = (int) $db->query('SELECT id FROM ruoli WHERE nome = ' . "'" . $db->real_escape_string($tag) . "'")->fetch_row()[0];
    $addUser = static function (string $type, ?int $role) use ($db, $tag, &$userIds): int {
        $code = $tag . count($userIds);
        $email = $code . '@example.test';
        $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata, ruolo_id) VALUES ('Cal', 'Perm', ?, 'unused', ?, ?, 'attivo', 1, ?)");
        $stmt->bind_param('sssi', $email, $code, $type, $role);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        $userIds[] = $id;
        return $id;
    };
    // The secret exists before the attempts, so "unchanged" is a real comparison.
    (new \App\Support\LoanCalendarLinks($db))->libraryFeedToken(1);
    $secretBefore = $secretOf();
    $check($secretBefore !== null && $secretBefore !== '', 'rotate-link: the library feed secret exists before the attempts');

    foreach (['custom role with settings.manage' => $addUser('standard', $roleId), 'staff' => $addUser('staff', null)] as $who => $userId) {
        $_SESSION = ['user' => ['id' => $userId, 'tipo_utente' => 'standard', 'stato' => 'attivo']];
        $token = \App\Support\Csrf::ensureToken();
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/admin/calendar/rotate-link')
            ->withParsedBody(['csrf_token' => $token]);
        $res = $app->handle($request);
        $check($res->getStatusCode() === 302 && str_ends_with($res->getHeaderLine('Location'), '/admin/dashboard'), "rotate-link: the {$who} passes the middleware and is sent back to the dashboard");
        $check($flash('error_message') === __('Operazione riservata agli amministratori.') && $flash('success_message') === null, "rotate-link: the route refuses the {$who}");
        $check($secretOf() === $secretBefore, "rotate-link: the secret is unchanged after the {$who} POST");
    }
} finally {
    foreach ($userIds as $id) {
        $db->query('DELETE FROM utenti WHERE id = ' . (int) $id);
    }
    if ($roleId > 0) {
        $db->query('DELETE FROM ruoli WHERE id = ' . $roleId);
    }
}

echo "SUCCESS {$checks} calendar permission checks\n";
