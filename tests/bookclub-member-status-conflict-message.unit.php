<?php
declare(strict_types=1);

/**
 * Review of PR #536 (F034): Repo::setMemberStatus() returns false both for a
 * database failure and for a membership another request changed first (the
 * request already approved or rejected by another moderator, the member
 * already gone). approveMember() and leave() answered both with "Si è
 * verificato un errore... Riprova più tardi", which a retry can never fix.
 * They now re-read the row and say that it was already changed.
 *
 * DB-free: the REAL PublicController against the in-memory Repo of
 * tests/helpers/bookclub-controller-stubs.php.
 *
 * Run:  php tests/bookclub-member-status-conflict-message.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/helpers/bookclub-controller-stubs.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$generic = "Si è verificato un errore durante l'operazione. Riprova più tardi.";
$setup = static function (): array {
    $repo = bcFakeRepo();
    $repo->clubs['audit-club'] = bcClub(300, 'audit-club', 'private');
    $repo->members['300:10'] = ['id' => 5, 'club_id' => 300, 'user_id' => 10, 'status' => 'pending', 'role_slug' => 'member'];
    $ctrl = bcController(BcTestPublicController::class, $repo);
    $ctrl->grants = ['members.approve'];
    return [$repo, $ctrl];
};

foreach (['approve' => 'active', 'reject' => 'left'] as $action => $meanwhile) {
    [$repo, $ctrl] = $setup();
    $repo->statusChangedMeanwhile = $meanwhile;
    $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'standard']];
    $ctrl->approveMember(bcRequest('POST', ['action' => $action]), bcResponse(), 'audit-club', 5);
    $flash = $_SESSION['bookclub_flash'] ?? [];
    $check(($flash['type'] ?? '') === 'warning' && ($flash['message'] ?? '') !== $generic,
        "{$action}: a request already handled by someone else is reported as such, not as a retryable error");

    [$repo, $ctrl] = $setup();
    $repo->statusWriteFails = true;
    $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'standard']];
    $ctrl->approveMember(bcRequest('POST', ['action' => $action]), bcResponse(), 'audit-club', 5);
    $flash = $_SESSION['bookclub_flash'] ?? [];
    $check(($flash['type'] ?? '') === 'error' && ($flash['message'] ?? '') === $generic, "{$action}: a real failure keeps the retry message (control)");
}

[$repo, $ctrl] = $setup();
$repo->members['300:10']['status'] = 'active';
$repo->statusChangedMeanwhile = 'suspended';
$_SESSION = ['user' => ['id' => 10, 'tipo_utente' => 'standard']];
$ctrl->leave(bcRequest('POST'), bcResponse(), 'audit-club');
$flash = $_SESSION['bookclub_flash'] ?? [];
$check(($flash['type'] ?? '') === 'warning' && $repo->members['300:10']['status'] === 'suspended',
    'leave: a suspension that landed first is reported, and not overwritten');

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
