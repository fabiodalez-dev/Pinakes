<?php
declare(strict_types=1);

/**
 * Security regression (PR #536 review of
 * storage/plugins/book-club/src/PublicController.php:dashboard).
 *
 * The web dashboard (/my/book-clubs) lists every club from
 * Repo::listClubsForUser(), which includes PENDING memberships, and took
 * Repo::clubSnapshot() (current books, next meeting, open polls) for each of
 * them. A pending requester of a non-public club therefore saw members-only
 * activity there, bypassing canSeeContent() (active membership required for
 * every non-public club). The mobile dashboard already applied the rule; the
 * web one must too: the card stays, the snapshot is empty.
 *
 * DB-free: the REAL PublicController::dashboard() runs against an in-memory
 * Repo (tests/helpers/bookclub-controller-stubs.php); only the final render is
 * captured instead of executed.
 *
 * Run:  php tests/security-audit-bookclub-dashboard-content.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/helpers/bookclub-controller-stubs.php';

use App\Plugins\BookClub\PublicController;
use Psr\Http\Message\ResponseInterface;

/** PublicController whose page render is captured, not executed. */
final class BcDashboardProbe extends PublicController
{
    /** @var array<string, mixed> */
    public array $rendered = [];

    protected function renderPublic(ResponseInterface $response, string $view, array $data, string $title = ''): ResponseInterface
    {
        $this->rendered = $data;
        return $response;
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$READER = 10;
$repo = bcFakeRepo();
$ctrl = bcController(BcDashboardProbe::class, $repo);

$scenario = [
    // slug => [privacy, membership status, members-only activity expected]
    'pending-private' => ['private', 'pending', false],
    'pending-invite'  => ['invite',  'pending', false],
    'pending-hidden'  => ['hidden',  'pending', false],
    'pending-public'  => ['public',  'pending', true],
    'active-private'  => ['private', 'active',  true],
    'active-hidden'   => ['hidden',  'active',  true],
];
$id = 300;
foreach ($scenario as $slug => [$privacy, $status, $visible]) {
    $club = bcClub(++$id, $slug, $privacy);
    $repo->clubs[$slug] = $club;
    $repo->members[$id . ':' . $READER] = ['id' => $id * 10, 'club_id' => $id, 'user_id' => $READER, 'status' => $status, 'role_slug' => 'member'];
    // listClubsForUser() returns the raw c.* row plus the membership columns.
    $repo->userClubs[] = array_merge($club, ['settings' => '{}', 'member_status' => $status, 'role_slug' => 'member', 'role_name' => 'Member']);
}

bcLogin($READER);
$ctrl->dashboard(bcRequest('GET'), bcResponse());
$cards = [];
foreach ($ctrl->rendered['cards'] ?? [] as $card) {
    $cards[(string) $card['club']['slug']] = $card;
}

echo "Web dashboard of a reader with pending and active memberships\n";
$check(count($cards) === count($scenario), 'every club the reader belongs to or asked to join keeps its card');
foreach ($scenario as $slug => [$privacy, $status, $visible]) {
    $snap = $cards[$slug]['snapshot'] ?? null;
    $hasActivity = is_array($snap) && ($snap['current_books'] !== [] || $snap['next_meeting'] !== null || $snap['open_polls'] !== []);
    $taken = in_array((int) $repo->clubs[$slug]['id'], $repo->snapshotsTaken, true);
    if ($visible) {
        $check($hasActivity && $taken, "{$status} member of a {$privacy} club sees its activity");
    } else {
        $check(
            is_array($snap) && $snap['current_books'] === [] && $snap['next_meeting'] === null && $snap['open_polls'] === [] && !$taken,
            "{$status} requester of a {$privacy} club: card only, no books/meeting/polls (snapshot not even read)"
        );
    }
}

echo "Pinakes staff keep the activity of a club they asked to join\n";
$repo->snapshotsTaken = [];
bcLogin(2, 'staff');
foreach ($repo->members as $k => $m) {
    unset($repo->members[$k]);
    $repo->members[$m['club_id'] . ':2'] = array_merge($m, ['user_id' => 2]);
}
$ctrl->dashboard(bcRequest('GET'), bcResponse());
$check(count($repo->snapshotsTaken) === count($scenario), 'staff: every card has its snapshot (unchanged)');

$_SESSION = [];
echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
