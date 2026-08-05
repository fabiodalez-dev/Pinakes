<?php
declare(strict_types=1);

/**
 * Regression: the header search links a publisher result to /editore/{id}
 * (SearchController builds route_path('publisher').'/'.id), but only the
 * by-name public route existed. So /editore/5 matched the {name} route with
 * name="5", found no publisher named "5" and 404'd — unlike authors, which have
 * both a by-id and a by-name public route.
 *
 * Fix: FrontendController::publisherArchiveById + a /editore/{id:\d+} route
 * registered BEFORE the /editore/{name} route (so a numeric id matches the id
 * variant, not the name one). Guards the method, the route, and the critical
 * registration ORDER.
 */

$root = dirname(__DIR__);

$controller = (string) file_get_contents($root . '/app/Controllers/FrontendController.php');
$web = (string) file_get_contents($root . '/app/Routes/web.php');
$search = (string) file_get_contents($root . '/app/Controllers/SearchController.php');

$checks = [];

$checks['FrontendController::publisherArchiveById exists'] =
    str_contains($controller, 'public function publisherArchiveById(');

$checks['publisher by-id route registered ({id:\\d+} → publisherArchiveById)'] =
    str_contains($web, "RouteTranslator::getRouteForLocale('publisher', \$locale) . '/{id:\\d+}'")
    && str_contains($web, 'publisherArchiveById($request, $response, $db, (int) $args[\'id\'])');

$checks['publisher by-name route still registered'] =
    str_contains($web, "RouteTranslator::getRouteForLocale('publisher', \$locale) . '/{name}'");

// ORDER is the correctness crux: the numeric {id:\d+} route must be registered
// before the catch-all {name} route, else /editore/5 hits {name} with name="5".
$posId = strpos($web, "getRouteForLocale('publisher', \$locale) . '/{id:\\d+}'");
$posName = strpos($web, "getRouteForLocale('publisher', \$locale) . '/{name}'");
$checks['by-id route registered BEFORE by-name route'] =
    $posId !== false && $posName !== false && $posId < $posName;

// The search links to route_path('publisher').'/'.id — the URL the id route now serves.
$checks['search builds the id-based publisher URL it now resolves'] =
    str_contains($search, "route_path('publisher') . '/' . (int)\$row['id']");

// Symmetry with authors (the pattern we mirrored).
$checks['mirrors the author by-id route'] =
    str_contains($web, "getRouteForLocale('author', \$locale) . '/{id:\\d+}'");

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
}
echo $failed === 0 ? "\nOK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
