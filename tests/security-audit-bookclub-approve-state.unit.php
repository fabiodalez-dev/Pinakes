<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11,
 * storage/plugins/book-club/src/PublicController.php:changeBookState:proposals-approve-sets-any-workflow-state).
 *
 * changeBookState() treated ANY request on a 'pending' book as moderation and
 * checked only proposals.approve, then accepted any workflow state as target.
 * A custom club role holding proposals.approve but not workflow.transition
 * could push a pending proposal straight to 'reading' or 'finished', skipping
 * the vote. Moderation is: pending -> workflow entry state, or reject. Every
 * other target needs workflow.transition (and, from pending, proposals.approve).
 *
 * DB-free: the REAL PublicController::changeBookState() runs against an
 * in-memory Repo; the governance matrix is replaced by a fixed grant list.
 *
 * Run:  php tests/security-audit-bookclub-approve-state.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/helpers/bookclub-controller-stubs.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/**
 * @param list<string> $grants
 * @return array{0: int, 1: string|null, 2: BcFakeRepo}
 */
$run = static function (array $grants, string $from, string $to): array {
    $repo = bcFakeRepo();
    $repo->clubs['audit-club'] = bcClub(400, 'audit-club', 'public');
    $repo->books[1] = ['id' => 1, 'club_id' => 400, 'state' => $from];
    bcLogin(10);
    $ctrl = bcController(BcTestPublicController::class, $repo);
    $ctrl->grants = $grants;
    $res = $ctrl->changeBookState(bcRequest('POST', ['state' => $to]), bcResponse(), 'audit-club', 1);
    return [$res->getStatusCode(), $repo->books[1]['state'] ?? null, $repo];
};

echo "A. Role with proposals.approve only\n";
$approver = ['proposals.approve'];
foreach (['finished', 'reading', 'selected', 'archived', 'voting'] as $target) {
    [$code, $state] = $run($approver, 'pending', $target);
    $check($code === 404 && $state === 'pending', "pending -> {$target} refused, book stays pending");
}
[$code, $state] = $run($approver, 'pending', 'proposed');
$check($code === 302 && $state === 'proposed', 'pending -> entry state (approve) allowed');
[$code, $state, $repo] = $run($approver, 'pending', 'reject-proposal');
$check($code === 302 && $state === null && in_array(['deleteClubBook', 1], $repo->writes, true), 'reject-proposal allowed (proposal deleted)');
[$code, $state] = $run($approver, 'proposed', 'finished');
$check($code === 404 && $state === 'proposed', 'non-pending transition still refused');

echo "B. Role with workflow.transition only\n";
$mover = ['workflow.transition'];
[$code, $state] = $run($mover, 'proposed', 'reading');
$check($code === 302 && $state === 'reading', 'ordinary workflow move allowed (unchanged)');
[$code, $state] = $run($mover, 'pending', 'reading');
$check($code === 404 && $state === 'pending', 'cannot pull a pending proposal out of moderation');
[$code, $state] = $run($mover, 'pending', 'proposed');
$check($code === 404 && $state === 'pending', 'cannot approve without proposals.approve');
[$code, $state] = $run($mover, 'pending', 'reject-proposal');
$check($code === 404 && $state === 'pending', 'cannot reject without proposals.approve');

echo "C. Role with both permissions (owner/moderator equivalent)\n";
$both = ['proposals.approve', 'workflow.transition'];
[$code, $state] = $run($both, 'pending', 'finished');
$check($code === 302 && $state === 'finished', 'pending -> any state allowed with both grants');
[$code, $state] = $run($both, 'pending', 'proposed');
$check($code === 302 && $state === 'proposed', 'approve allowed');
[$code, $state] = $run($both, 'pending', 'no-such-state');
$check($code === 302 && $state === 'pending', 'unknown target state rejected (no write)');

echo "D. No grants\n";
[$code, $state] = $run([], 'pending', 'proposed');
$check($code === 404 && $state === 'pending', 'plain member cannot approve');

$_SESSION = [];
echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
