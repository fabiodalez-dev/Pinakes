<?php
declare(strict_types=1);

/**
 * Closures calendar (/admin/calendar): the page follows the settings
 * permission, exactly as LibraryCalendarController::guard() admits
 * Permissions::mayConfigure(). Without the prefix in the map a custom role
 * with settings.manage was refused by AdminAuthMiddleware before the
 * controller ran. /admin/calendar/rotate-link stays admin-only inside its
 * route, whatever the map says.
 *
 * Run: php tests/calendar-permission.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Permissions as P;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
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

echo "SUCCESS {$checks} calendar permission checks\n";
