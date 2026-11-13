<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11, UsersController:staff-reactivates-suspended-operator).
 *
 * Staff may edit only their own account and only administrators may suspend or
 * delete one, yet activateDirectly() and approveAndSendActivation() let every
 * staff caller through and never looked at the target's role: one POST lifted an
 * administrator's lockout of an admin account. renewCard() had the same guard.
 *
 * Invariant: an administrator may change the activation state of any account;
 * Staff and custom roles holding users.manage only that of reader accounts
 * (standard/premium). A refused request must not reach any write.
 *
 * DB-free: a connection-less mysqli answers the target lookup and records every
 * later statement (tests/helpers/security-audit-fake-db.php).
 *
 * Run:  php tests/security-audit-users-reactivation.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/helpers/security-audit-fake-db.php';

use App\Controllers\UsersController;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$callers = [
    'admin' => ['id' => 1, 'tipo_utente' => 'admin', 'stato' => 'attivo'],
    'staff' => ['id' => 2, 'tipo_utente' => 'staff', 'stato' => 'attivo'],
    // A reader account holding the users.manage capability through a custom role.
    'manager' => ['id' => 3, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['users.manage']],
];
$target = static fn(int $id, string $role): array => [
    'id' => $id, 'tipo_utente' => $role, 'stato' => 'sospeso',
    'ruolo_id' => null, 'accesso_digitale' => 0, 'email' => "audit-a1-{$id}@example.test",
];

/**
 * @return array{status: ?int, past: bool}
 */
$run = static function (string $handler, array $caller, array $targetRow): array {
    $_SESSION = ['user' => $caller];
    $db = new SecurityAuditFakeDb([$targetRow], 1);
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/users/' . $targetRow['id'] . '/x');
    $response = (new ResponseFactory())->createResponse();
    $controller = new UsersController();
    $status = null;
    try {
        $out = $handler === 'renewCard'
            ? $controller->renewCard($request, $response, $db, (int) $targetRow['id'])
            : $controller->{$handler}($request, $response, $db, ['id' => (string) $targetRow['id']]);
        $status = $out->getStatusCode();
    } catch (SecurityAuditPastGuard) {
        // The handler went on to its write.
    }
    $past = count($db->calls) > 1;
    $_SESSION = [];
    return ['status' => $status, 'past' => $past];
};

foreach (['activateDirectly', 'approveAndSendActivation'] as $handler) {
    echo "{$handler}\n";

    foreach (['admin' => 9, 'staff' => 10] as $role => $id) {
        $r = $run($handler, $callers['staff'], $target($id, $role));
        $check($r['status'] === 403 && !$r['past'], "staff on a suspended {$role} account: 403, no write");
        $r = $run($handler, $callers['manager'], $target($id, $role));
        $check($r['status'] === 403 && !$r['past'], "users.manage role on a suspended {$role} account: 403, no write");
    }

    // Legitimate use is unchanged.
    $check($run($handler, $callers['staff'], $target(20, 'standard'))['past'], 'staff approves a pending standard reader');
    $check($run($handler, $callers['staff'], $target(21, 'premium'))['past'], 'staff approves a pending premium reader');
    $check($run($handler, $callers['manager'], $target(22, 'standard'))['past'], 'users.manage role approves a pending reader');
    $check($run($handler, $callers['admin'], $target(9, 'admin'))['past'], 'administrator may reactivate a suspended admin');
    $check($run($handler, $callers['admin'], $target(10, 'staff'))['past'], 'administrator may reactivate a suspended staff account');
}

echo "renewCard\n";
// Operator accounts hold no card: refused for every caller, nothing written
// (the same "not renewable" answer the Mobile API returns as 409).
foreach (['staff', 'manager', 'admin'] as $caller) {
    foreach (['admin' => 9, 'staff' => 10] as $role => $id) {
        $_SESSION = [];
        $r = $run('renewCard', $callers[$caller], $target($id, $role));
        $check($r['status'] === 302 && !$r['past'], "{$caller} renewing a {$role} account: refused as not renewable, no write");
    }
}
// A reader outside a custom role's reach is still refused by the permission check.
$r = $run('renewCard', ['id' => 3, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => []], $target(20, 'standard'));
$check($r['status'] === 403 && !$r['past'], 'a caller without users.manage renewing a reader: 403, no write');
$check($run('renewCard', $callers['staff'], $target(20, 'standard'))['past'], "staff renews a reader's card");
$check($run('renewCard', $callers['admin'], $target(20, 'standard'))['past'], "administrator renews a reader's card");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
