<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11,
 * storage/plugins/book-club/src/MeetingController.php:icsFeed:club-ics-token-never-rotated).
 *
 * A non-public club's iCal feed (and its members-only video links) is served on
 * the club-wide ics_token alone, and that token was written once at club
 * creation and never changed: a member who left or was suspended/banned kept a
 * working, session-less feed URL. Repo::setMemberStatus() must rotate the token
 * whenever an ACTIVE member stops being one, so the old URL returns 404, while
 * declining a pending join request (the requester never saw the URL) must not
 * break every member's calendar subscription.
 *
 * Drives the REAL Repo and MeetingController::icsFeed() against MySQL. The five
 * book-club tables the flow writes (clubs, members, roles, meetings, and the
 * workflows that Repo::createClub() copies the default template into) are
 * session-local TEMPORARY tables that shadow any real ones, so nothing is
 * written to the database and nothing needs cleaning up. The meeting query's
 * read-only joins (RSVPs, club books, libri) use the real tables when present.
 *
 * Run:  php tests/security-audit-bookclub-ics-rotation.unit.php
 * DB:   E2E_DB_NAME / E2E_DB_USER / E2E_DB_PASS / E2E_DB_SOCKET (or .env).
 *       Without a reachable DB it prints SKIP and exits 0 (a failure under
 *       CI_STRICT_TESTS=1).
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';

use App\Plugins\BookClub\BaseController;
use App\Plugins\BookClub\MeetingController;
use App\Plugins\BookClub\Repo;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$skip = static function (string $why): never {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: {$why} (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "SKIP: {$why}\n";
    exit(0);
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
        $v = substr($v, 1, -1);
    }
    $env[$k] = $v;
}
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
if ($name === '' || $user === '') {
    $skip('no database configured (E2E_DB_NAME/E2E_DB_USER or .env)');
}
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $dbPass, $name, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $dbPass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
} catch (\Throwable $e) {
    $skip('database not reachable (' . $e->getMessage() . ')');
}
$db->set_charset('utf8mb4');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

try {
    // ── Session-local shadows of the tables the flow writes ────────────────
    $db->query("CREATE TEMPORARY TABLE bookclub_clubs (
        id INT NOT NULL AUTO_INCREMENT, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL,
        description TEXT NULL, rules TEXT NULL, color CHAR(7) NOT NULL DEFAULT '#4f46e5',
        privacy ENUM('public','private','invite','hidden') NOT NULL DEFAULT 'public',
        max_members INT NULL, settings TEXT NULL, workflow_id INT NULL, ics_token CHAR(32) NOT NULL,
        created_by INT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, deleted_at DATETIME NULL,
        PRIMARY KEY (id), UNIQUE KEY uq_slug (slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TEMPORARY TABLE bookclub_roles (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NULL, slug VARCHAR(60) NOT NULL, name VARCHAR(120) NOT NULL,
        PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TEMPORARY TABLE bookclub_members (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NOT NULL, user_id INT NOT NULL, role_id INT NOT NULL,
        status ENUM('pending','active','suspended','left','banned') NOT NULL DEFAULT 'active',
        invited_by INT NULL, PRIMARY KEY (id), UNIQUE KEY uq_m (club_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TEMPORARY TABLE bookclub_meetings (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NOT NULL, club_book_id INT NULL, title VARCHAR(190) NOT NULL,
        agenda TEXT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NULL, location VARCHAR(255) NULL,
        video_url VARCHAR(500) NULL, status ENUM('scheduled','done','cancelled') NOT NULL DEFAULT 'scheduled',
        PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // createClub() copies the default (club_id IS NULL) workflow into a new
    // per-club row: without this shadow it would write that row into the
    // real bookclub_workflows table, keyed to a temporary club id.
    $db->query("CREATE TEMPORARY TABLE bookclub_workflows (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NULL, name VARCHAR(190) NOT NULL, states TEXT NOT NULL,
        PRIMARY KEY (id), KEY idx_bcwf_club (club_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $tpl = $db->prepare('INSERT INTO bookclub_workflows (club_id, name, states) VALUES (NULL, ?, ?)');
    $tplName = 'Workflow predefinito';
    $tplStates = (string) json_encode(\App\Plugins\BookClub\BookClubPlugin::defaultWorkflowStates(), JSON_UNESCAPED_UNICODE);
    $tpl->bind_param('ss', $tplName, $tplStates);
    $tpl->execute();
    $tpl->close();
    $db->query("INSERT INTO bookclub_roles (id, club_id, slug, name) VALUES (3, NULL, 'member', 'Member'), (1, NULL, 'owner', 'Owner')");

    $repo = new Repo($db);
    $clubId = (int) $repo->createClub(['name' => 'Audit ICS Club', 'privacy' => 'private', 'max_members' => null], 1);
    $club = $repo->clubById($clubId);
    $wf = $db->query("SELECT COUNT(*) AS n FROM bookclub_workflows WHERE club_id = {$clubId}")->fetch_assoc();
    $check((int) $wf['n'] === 1 && (int) $club['workflow_id'] > 0, 'createClub() copied the workflow into the TEMPORARY shadow table');
    $slug = (string) $club['slug'];
    $tokenOf = static fn (): string => (string) $repo->clubById($clubId)['ics_token'];

    $add = static function (int $userId, string $status) use ($db, $clubId): int {
        $db->query("INSERT INTO bookclub_members (club_id, user_id, role_id, status) VALUES ({$clubId}, {$userId}, 3, '{$status}')");
        return (int) $db->insert_id;
    };
    $leaver = $add(501, 'active');
    $requester = $add(502, 'pending');
    $suspendee = $add(503, 'active');
    $bannee = $add(504, 'active');
    $demotee = $add(505, 'active');
    $db->query("INSERT INTO bookclub_meetings (club_id, title, agenda, starts_at, location, video_url)
                VALUES ({$clubId}, 'Audit meeting', 'Audit agenda', '2030-01-15 18:00:00', 'Audit room', 'https://video.audit.invalid/members-only')");

    $meetings = (new ReflectionClass(MeetingController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(BaseController::class, 'repo'))->setValue($meetings, $repo);
    $_SESSION = [];
    $feed = static function (string $token) use ($meetings, $slug): array {
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/calendar.ics')->withQueryParams(['token' => $token]);
        $res = $meetings->icsFeed($req, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(), $slug);
        return [$res->getStatusCode(), (string) $res->getBody()];
    };

    echo "A. Before any change the member URL works\n";
    $t0 = $tokenOf();
    [$code, $body] = $feed($t0);
    $check($code === 200 && str_contains($body, 'BEGIN:VCALENDAR'), 'feed with the club token: 200 text/calendar');
    if (str_contains($body, 'BEGIN:VEVENT')) {
        $check(str_contains($body, 'video.audit.invalid'), 'members-only video link present for a valid token');
    } else {
        echo "  NOTE meeting rows not readable here (support tables missing); VEVENT checks skipped\n";
    }
    [$code] = $feed('');
    $check($code === 404, 'no token on a private club: 404');

    echo "B. Declining a pending request keeps the token (no needless re-subscribe)\n";
    $repo->setMemberStatus($requester, 'left');
    $check($tokenOf() === $t0, 'pending -> left: token unchanged');

    echo "C. An active member leaving revokes the URL they had\n";
    $repo->setMemberStatus($leaver, 'left');
    $t1 = $tokenOf();
    $check($t1 !== $t0 && preg_match('/^[0-9a-f]{32}$/', $t1) === 1, 'active -> left: new 32-hex token');
    [$code, $body] = $feed($t0);
    $check($code === 404 && !str_contains($body, 'video.audit.invalid'), 'old URL now 404, no video link');
    [$code] = $feed($t1);
    $check($code === 200, 'current members keep working with the new token');

    echo "D. Suspension, ban and demotion to pending revoke it too\n";
    foreach ([[$suspendee, 'suspended'], [$bannee, 'banned'], [$demotee, 'pending']] as [$memberId, $status]) {
        $before = $tokenOf();
        $repo->setMemberStatus($memberId, $status);
        $after = $tokenOf();
        $check($after !== $before, "active -> {$status}: token rotated");
        [$code] = $feed($before);
        $check($code === 404, "active -> {$status}: previous URL 404");
    }

    echo "E. Re-activating a member does not rotate\n";
    $before = $tokenOf();
    $repo->setMemberStatus($suspendee, 'active');
    $check($tokenOf() === $before, 'suspended -> active: token unchanged');
    $repo->setMemberStatus($bannee, 'banned');
    $check($tokenOf() === $before, 'banned -> banned (no longer active): token unchanged');
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    foreach (['bookclub_meetings', 'bookclub_members', 'bookclub_roles', 'bookclub_clubs', 'bookclub_workflows'] as $t) {
        try { $db->query("DROP TEMPORARY TABLE IF EXISTS {$t}"); } catch (\Throwable) {}
    }
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
