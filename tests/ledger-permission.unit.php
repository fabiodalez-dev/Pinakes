<?php
declare(strict_types=1);

/**
 * Patron ledger (plan §7): the desk actions on a reader's ledger (payment,
 * manual charge, waiver, payment receipt) live under /admin/users/{id}/ledger/
 * but are circulation-desk work, so they need loans.manage, not users.manage.
 * The rest of the reader record keeps users.manage; Staff keeps everything.
 *
 * Run: php tests/ledger-permission.unit.php
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

foreach (['/admin/users/5/ledger/payment', '/admin/users/5/ledger/charge', '/admin/users/5/ledger/waive', '/admin/users/5/ledger/receipt/12'] as $path) {
    $check(P::permissionForPath($path) === 'loans.manage', "{$path} needs loans.manage");
}
foreach (['/admin/users/5', '/admin/users/details/5', '/admin/users/update/5', '/admin/users/5/renew-card', '/admin/users'] as $path) {
    $check(P::permissionForPath($path) === 'users.manage', "{$path} still needs users.manage");
}
$check(P::permissionForPath('/admin/users/ledger/payment') === 'users.manage', 'without a reader id the path is not a ledger action');
$check(P::permissionForPath('/admin/usersx/5/ledger/payment') === null, 'a similarly named prefix does not inherit the rule');

$desk = ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage']];
$check(P::allowsPath('/admin/users/5/ledger/payment', $desk, 'POST'), 'a custom role with loans.manage only records a payment');
$check(P::allowsPath('/admin/users/5/ledger/waive', $desk, 'POST') && P::allowsPath('/admin/users/5/ledger/charge', $desk, 'POST'), '...and a waiver or a manual charge');
$check(P::allowsPath('/admin/users/5/ledger/receipt/12', $desk), '...and downloads the receipt');
$check(!P::allowsPath('/admin/users/details/5', $desk), 'loans.manage alone does not open the reader record');

$users = ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'users.manage']];
$check(P::allowsPath('/admin/users/details/5', $users), 'a users-only role opens the reader record (ledger read-only)');
foreach (['/admin/users/5/ledger/payment', '/admin/users/5/ledger/charge', '/admin/users/5/ledger/waive'] as $path) {
    $check(!P::allowsPath($path, $users, 'POST'), "a users-only role cannot POST {$path}");
}
$check(!P::allowsPath('/admin/users/5/ledger/receipt/12', $users), 'a users-only role does not download receipts');

$both = ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage', 'users.manage']];
$check(P::allowsPath('/admin/users/5/ledger/payment', $both, 'POST') && P::allowsPath('/admin/users/details/5', $both), 'with both capabilities the record and its desk actions are reachable');
$check(P::allowsPath('/admin/users/5/ledger/payment', ['tipo_utente' => 'staff', 'stato' => 'attivo'], 'POST'), 'Staff records payments');
$check(!P::allowsPath('/admin/users/5/ledger/payment', ['tipo_utente' => 'staff', 'stato' => 'sospeso'], 'POST'), 'a suspended operator cannot');
$check(!P::allowsPath('/admin/users/5/ledger/payment', ['tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => []], 'POST'), 'a reader without a role cannot');

$view = (string) file_get_contents(dirname(__DIR__) . '/app/Views/utenti/_movimenti.php');
$check(str_contains($view, "Permissions::can('loans.manage')") && substr_count($view, '$ledgerCanManage') >= 4, 'the movements partial renders forms and receipt links only with loans.manage');
$controller = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/PatronLedgerController.php');
$check(substr_count($controller, "Permissions::can('loans.manage')") === 2, 'the controller demands the same capability on writes and receipts');

echo "SUCCESS {$checks} ledger permission checks\n";
