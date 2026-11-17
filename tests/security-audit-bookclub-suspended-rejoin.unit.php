<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11,
 * storage/plugins/book-club/src/PublicController.php:join:suspended-member-self-reinstates).
 *
 * The self-service membership paths refused only 'banned' rows, so a member
 * suspended by the library could lift the sanction with the ordinary Join
 * button (Repo::upsertMember overwrote 'suspended' with 'active', or 'pending'
 * on a private club), through the app's join endpoint, or by accepting an
 * invitation. Suspension, like a ban, may be lifted only by staff or managers.
 *
 * DB-free: the REAL PublicController::join()/acceptInvite() and
 * MobileApiController::join() run against an in-memory Repo
 * (tests/helpers/bookclub-controller-stubs.php) that records every write.
 *
 * Run:  php tests/security-audit-bookclub-suspended-rejoin.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/helpers/bookclub-controller-stubs.php';

use App\Plugins\BookClub\MobileApiController;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$READER = 10;
$upserts = static fn (BcFakeRepo $r): array => array_values(array_filter($r->writes, static fn (array $w): bool => $w[0] === 'upsertMember'));

$scenario = static function (string $privacy, string $status) use ($READER): BcFakeRepo {
    $repo = bcFakeRepo();
    $club = bcClub(300, 'audit-club', $privacy);
    $repo->clubs['audit-club'] = $club;
    $repo->members['300:' . $READER] = ['id' => 5, 'club_id' => 300, 'user_id' => $READER, 'status' => $status, 'role_slug' => 'member'];
    $repo->invitation = ['id' => 77, 'club_id' => 300, 'email' => 'reader@audit.invalid', 'role_id' => null, 'invited_by' => 1];
    bcLogin($READER);
    return $repo;
};

foreach (['public', 'private'] as $privacy) {
    foreach (['suspended', 'banned'] as $status) {
        echo "Web join — {$status} member of a {$privacy} club\n";
        $repo = $scenario($privacy, $status);
        $res = bcController(BcTestPublicController::class, $repo)->join(bcRequest(), bcResponse(), 'audit-club');
        $check($upserts($repo) === [], 'no membership write');
        $check($repo->members['300:' . $READER]['status'] === $status, "row stays '{$status}'");
        $check(($_SESSION['bookclub_flash']['type'] ?? '') === 'error', 'error flash shown');
        $check($res->getStatusCode() === 302, 'redirected back to the club page');
    }
}

foreach (['suspended', 'banned'] as $status) {
    echo "Mobile join — {$status} member of a public club\n";
    $repo = $scenario('public', $status);
    $res = bcController(MobileApiController::class, $repo)->join(bcRequest(), bcResponse(), 'audit-club');
    $payload = json_decode((string) $res->getBody(), true);
    $check($res->getStatusCode() === 403, 'HTTP 403');
    $check(($payload['error']['code'] ?? '') === 'banned', "stable error code 'banned'");
    $check($upserts($repo) === [] && $repo->members['300:' . $READER]['status'] === $status, "row stays '{$status}'");
}

foreach (['suspended', 'banned'] as $status) {
    echo "Invitation accept — {$status} member of an invite-only club\n";
    $repo = $scenario('invite', $status);
    bcController(BcTestPublicController::class, $repo)->acceptInvite(bcRequest('POST'), bcResponse(), str_repeat('b', 64));
    $check($upserts($repo) === [], 'no membership write');
    $check(!in_array(['markInvitationAccepted', 77], $repo->writes, true), 'invitation not consumed');
    $check($repo->members['300:' . $READER]['status'] === $status, "row stays '{$status}'");
}

// Review of PR #536 (F032): upsertMember() keeps a row that a concurrent ban
// or suspension settled between the check and the write, and still returns
// true. The callers used to report success, announce the join and consume the
// invitation anyway; they now answer from the status the row really has.
foreach (['banned', 'suspended'] as $raced) {
    echo "A {$raced} landing between the check and the write\n";
    $repo = $scenario('public', 'left');
    $repo->concurrentStatus = $raced;
    $_SESSION['bookclub_flash'] = null;
    bcController(BcTestPublicController::class, $repo)->join(bcRequest(), bcResponse(), 'audit-club');
    $check(($_SESSION['bookclub_flash']['type'] ?? '') === 'error' && $repo->members['300:' . $READER]['status'] === $raced,
        "web join: an error, not 'Benvenuto', and the row stays '{$raced}'");

    $repo = $scenario('public', 'left');
    $repo->concurrentStatus = $raced;
    $res = bcController(MobileApiController::class, $repo)->join(bcRequest(), bcResponse(), 'audit-club');
    $payload = json_decode((string) $res->getBody(), true);
    $check($res->getStatusCode() === 403 && ($payload['error']['code'] ?? '') === 'banned',
        "mobile join: 403 'banned', never {status: active}");

    $repo = $scenario('invite', 'left');
    $repo->concurrentStatus = $raced;
    $_SESSION['bookclub_flash'] = null;
    bcController(BcTestPublicController::class, $repo)->acceptInvite(bcRequest('POST'), bcResponse(), str_repeat('b', 64));
    $check(!in_array(['markInvitationAccepted', 77], $repo->writes, true) && ($_SESSION['bookclub_flash']['type'] ?? '') === 'error',
        'invitation accept: the invitation is not consumed and an error is shown');
}

echo "Controls — legitimate (re)joins still work\n";
$repo = $scenario('public', 'left');
bcController(BcTestPublicController::class, $repo)->join(bcRequest(), bcResponse(), 'audit-club');
$check($repo->members['300:' . $READER]['status'] === 'active', 'a member who left re-joins a public club as active');
$repo = $scenario('private', 'left');
bcController(BcTestPublicController::class, $repo)->join(bcRequest(), bcResponse(), 'audit-club');
$check($repo->members['300:' . $READER]['status'] === 'pending', 'a member who left asks again to join a private club (pending)');
$repo = $scenario('public', 'left');
$res = bcController(MobileApiController::class, $repo)->join(bcRequest(), bcResponse(), 'audit-club');
$check($res->getStatusCode() === 200 && $repo->members['300:' . $READER]['status'] === 'active', 'mobile re-join after leaving works');
$repo = $scenario('invite', 'left');
bcController(BcTestPublicController::class, $repo)->acceptInvite(bcRequest('POST'), bcResponse(), str_repeat('b', 64));
$check($repo->members['300:' . $READER]['status'] === 'active', 'an invited former member is (re)admitted');

$_SESSION = [];
echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
