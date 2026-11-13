<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11,
 * storage/plugins/book-club/src/BaseController.php:canSeeContent:pending-member-sees-invite-only-hidden-content).
 *
 * canView() lets a PENDING requester of an invite-only or hidden club see the
 * club card; canSeeContent() then let that same requester into the members-only
 * content (meetings with location, polls and voter names, reading list, module
 * pages, mobile bridge) because it required an active membership only for
 * 'private'. Every non-public club must require an ACTIVE membership (or a
 * Pinakes admin/staff) for its content.
 *
 * DB-free: the REAL BaseController::canSeeContent()/canView() run against an
 * in-memory Repo (tests/helpers/bookclub-controller-stubs.php).
 *
 * Run:  php tests/security-audit-bookclub-pending-content.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/helpers/bookclub-controller-stubs.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$repo = bcFakeRepo();
$ctrl = bcController(BcTestPublicController::class, $repo);
$READER = 10;

$member = static function (int $clubId, string $status) use ($repo, $READER): void {
    $repo->members = [$clubId . ':' . $READER => ['id' => 5, 'club_id' => $clubId, 'user_id' => $READER, 'status' => $status, 'role_slug' => 'member']];
};

foreach (['invite', 'hidden', 'private'] as $i => $privacy) {
    $club = bcClub(100 + $i, 'audit-' . $privacy, $privacy);
    echo "{$privacy} club\n";
    bcLogin($READER);

    $member((int) $club['id'], 'pending');
    $check(!$ctrl->contentVisible($club), "pending requester does NOT see the content");

    $member((int) $club['id'], 'active');
    $check($ctrl->contentVisible($club), "active member sees the content");

    foreach (['suspended', 'left', 'banned'] as $status) {
        $member((int) $club['id'], $status);
        $check(!$ctrl->contentVisible($club), "{$status} member does not see the content");
    }

    $repo->members = [];
    $check(!$ctrl->contentVisible($club), 'non-member does not see the content');

    bcLogin(1, 'admin');
    $check($ctrl->contentVisible($club), 'Pinakes admin sees the content');
    bcLogin(2, 'staff');
    $check($ctrl->contentVisible($club), 'Pinakes staff sees the content');
}

echo "public club\n";
$public = bcClub(200, 'audit-public', 'public');
$repo->members = [];
$_SESSION = [];
$check($ctrl->contentVisible($public), 'anonymous visitor sees a public club');
bcLogin($READER);
$member(200, 'pending');
$check($ctrl->contentVisible($public), 'pending requester still sees a public club (unchanged)');

$_SESSION = [];
echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
