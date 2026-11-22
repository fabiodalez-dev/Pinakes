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

// approveAndSendActivation() refuses up front when no trusted host is
// configured (its email carries a token link); the role checks below run with
// one, and the refusal itself is checked at the end.
putenv('APP_CANONICAL_URL=https://library.example');
$_ENV['APP_CANONICAL_URL'] = 'https://library.example';

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

    // Review of PR #536 (F023): a reader account an administrator gave a
    // custom role or digital access is an operator; Staff may not lift its
    // suspension either.
    $op = $target(30, 'standard');
    $op['ruolo_id'] = 4;
    $r = $run($handler, $callers['staff'], $op);
    $check($r['status'] === 403 && !$r['past'], 'staff on a suspended custom-role operator: 403, no write');
    $op = $target(31, 'premium');
    $op['accesso_digitale'] = 1;
    $r = $run($handler, $callers['staff'], $op);
    $check($r['status'] === 403 && !$r['past'], 'staff on a suspended digital-access operator: 403, no write');
    $op['ruolo_id'] = 4;
    $check($run($handler, $callers['admin'], $op)['past'], 'administrator may reactivate a custom-role operator');

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

echo "approveAndSendActivation without a trusted host (review of PR #536)\n";
putenv('APP_CANONICAL_URL');
putenv('APP_TRUSTED_HOSTS');
unset($_ENV['APP_CANONICAL_URL'], $_ENV['APP_TRUSTED_HOSTS'], $_SERVER['APP_CANONICAL_URL'], $_SERVER['APP_TRUSTED_HOSTS']);
$_SESSION = ['user' => $callers['admin']];
$db = new SecurityAuditFakeDb([$target(40, 'standard')], 1);
$out = null;
try {
    $out = (new UsersController())->approveAndSendActivation(
        (new ServerRequestFactory())->createServerRequest('POST', '/admin/users/40/approve-and-send-activation'),
        (new ResponseFactory())->createResponse(), $db, ['id' => '40']);
} catch (SecurityAuditPastGuard) {
}
$_SESSION = [];
$check($out !== null && $out->getStatusCode() === 302 && str_contains($out->getHeaderLine('Location'), 'error=no_trusted_host') && count($db->calls) === 1,
    'refused before any write: the account stays sospeso and the verification link is never built from the Host');
$check($run('activateDirectly', $callers['admin'], $target(41, 'standard'))['past'], '"Attiva utente" still works without a trusted host');
$users = (string) file_get_contents($root . '/app/Controllers/UsersController.php');
$check(preg_match('#^\s*"/admin/users/details/\{\$userId\}\?success=#m', $users) === 0, 'success redirects go through url() (base path, F088)');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
