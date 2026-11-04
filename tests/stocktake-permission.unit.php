<?php
declare(strict_types=1);

/**
 * Inventory stocktake (plan §8): the inventory.manage capability opens the
 * stocktake pages and nothing else; Staff keeps it through its built-in
 * privileges; the seeded preset grants exactly backend access + stocktakes.
 *
 * Run: php tests/stocktake-permission.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Permissions as P;
use App\Support\RolePresets;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    $checks++;
    echo "OK {$label}\n";
};

$check(in_array('inventory.manage', P::ALL, true) && isset(P::labels()['inventory.manage']), 'inventory.manage is a known, labelled capability');
$check(P::normalize(['backend.access', 'inventory.manage']) === ['backend.access', 'inventory.manage'], 'a role can store it');
foreach (['/admin/inventory', '/admin/inventory/12', '/admin/inventory/12/scan', '/admin/inventory/12/close', '/admin/inventory/12/report.pdf'] as $path) {
    $check(P::permissionForPath($path) === 'inventory.manage', "{$path} needs inventory.manage");
}
$check(P::permissionForPath('/admin/inventoryx') === null, 'a similarly named path does not inherit it');

$reviewer = ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'inventory.manage']];
$check(P::allowsPath('/admin/inventory/3/scan', $reviewer, 'POST'), 'the stocktake role reaches the scan endpoint');
foreach (['/admin/books', '/admin/copies/by-code', '/admin/loans', '/admin/users', '/admin/settings'] as $path) {
    $check(!P::allowsPath($path, $reviewer), "the stocktake role does not reach {$path}");
}
$cataloguer = ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'catalog.manage']];
$check(!P::allowsPath('/admin/inventory', $cataloguer), 'cataloguing alone does not open stocktakes');
$check(P::can('inventory.manage', ['tipo_utente' => 'staff', 'stato' => 'attivo']), 'Staff can run stocktakes');
$check(P::can('inventory.manage', ['tipo_utente' => 'admin', 'stato' => 'attivo']), 'administrators can run stocktakes');
$check(!P::can('inventory.manage', ['tipo_utente' => 'staff', 'stato' => 'sospeso']), 'a suspended operator cannot');

$check(RolePresets::displayName('Revisore inventario') !== '' && RolePresets::description('Revisore inventario') !== '', 'the preset has a display name and a description');
$schema = (string) file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql');
$check(str_contains($schema, "('Revisore inventario', '[\"backend.access\",\"inventory.manage\"]')"), 'the preset grants backend access and stocktakes only');

echo "SUCCESS {$checks} stocktake permission checks\n";
