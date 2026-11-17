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
    // setMemberStatus() purges the affinity lists of a member who leaves, is
    // suspended or banned: shadow those tables so the purge stays session-local.
    $db->query("CREATE TEMPORARY TABLE bookclub_affinity (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NOT NULL, user_a INT NOT NULL, user_b INT NOT NULL,
        score TINYINT UNSIGNED NULL, computed_at DATETIME NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB");
    $db->query("CREATE TEMPORARY TABLE bookclub_affinity_optin (
        id INT NOT NULL AUTO_INCREMENT, club_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB");
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
    // The feed asks the library account too (Repo::feedViewerAllowed()).
    $db->query("CREATE TEMPORARY TABLE utenti (
        id INT NOT NULL, tipo_utente VARCHAR(20) NOT NULL, stato VARCHAR(20) NOT NULL,
        PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

    // The person-bound URL each viewer is shown (MeetingController::feedToken()),
    // keyed with the server secret, never the disclosed club token.
    $savedKey = getenv('PLUGIN_ENCRYPTION_KEY');
    putenv('PLUGIN_ENCRYPTION_KEY=audit-server-secret-' . bin2hex(random_bytes(8)));
    unset($_ENV['PLUGIN_ENCRYPTION_KEY']);
    $urlOf = static fn (int $userId): string => (string) MeetingController::feedToken($repo->clubById($clubId), $userId);
    $keeper = $add(506, 'active');
    $staffUser = 507;     // a Pinakes Staff account, not a member: sees the club page as a manager
    $db->query("INSERT INTO utenti (id, tipo_utente, stato) VALUES (501, 'standard', 'attivo'), (502, 'standard', 'attivo'),
        (503, 'standard', 'attivo'), (504, 'standard', 'attivo'), (505, 'standard', 'attivo'), (506, 'premium', 'attivo'), (507, 'staff', 'attivo')");

    echo "A. Before any change the member URL works\n";
    $t0 = $tokenOf();
    [$code, $body] = $feed($urlOf(501));
    $check($code === 200 && str_contains($body, 'BEGIN:VCALENDAR'), "feed with a member's URL: 200 text/calendar");
    if (str_contains($body, 'BEGIN:VEVENT')) {
        $check(str_contains($body, 'video.audit.invalid'), 'members-only video link present for a valid token');
    } else {
        echo "  NOTE meeting rows not readable here (support tables missing); VEVENT checks skipped\n";
    }
    [$code] = $feed('');
    $check($code === 404, 'no token on a private club: 404');
    [$code] = $feed($t0);
    $check($code === 404, 'the bare club-wide token proves nobody and is no longer accepted (review of PR #536, F071)');
    [$code] = $feed('u501.' . str_repeat('0', 32));
    $check($code === 404, 'a forged person token: 404');
    [$code] = $feed($urlOf(502));
    $check($code === 404, 'a pending requester (never shown the URL) gets nothing with a computed token');
    [$code] = $feed($urlOf($staffUser));
    $check($code === 200, 'a Pinakes Staff account (club manager) reads the feed');
    // Security review of the per-person token: the club ics_token was shown in
    // the old club-wide URLs, so it must not be enough to forge a member's token.
    $clubRow = $repo->clubById($clubId);
    $forged = 'u501.' . substr(hash_hmac('sha256', 'bookclub-ics:' . $clubId . ':501', (string) $clubRow['ics_token']), 0, 32);
    $forgedWithNonce = 'u501.' . substr(hash_hmac('sha256', 'bookclub-ics:' . $clubId . ':501:' . $clubRow['ics_token'], (string) $clubRow['ics_token']), 0, 32);
    [$code] = $feed($forged);
    [$code2] = $feed($forgedWithNonce);
    $check($code === 404 && $code2 === 404, 'a token forged from the disclosed ics_token alone is rejected');
    $realKey = getenv('PLUGIN_ENCRYPTION_KEY');
    putenv('PLUGIN_ENCRYPTION_KEY');
    $savedAppKey = getenv('APP_KEY');
    putenv('APP_KEY');
    unset($_ENV['APP_KEY']);
    [$code] = $feed($urlOf(501));
    $check($code === 404 && MeetingController::feedToken($clubRow, 501) === null, 'without a server key no personal token is issued or accepted');
    putenv('PLUGIN_ENCRYPTION_KEY=' . $realKey);
    if ($savedAppKey !== false) { putenv('APP_KEY=' . $savedAppKey); }

    echo "B. Declining a pending request keeps the token (no needless re-subscribe)\n";
    $repo->setMemberStatus($requester, 'left');
    $check($tokenOf() === $t0, 'pending -> left: token unchanged');

    echo "C. An active member leaving revokes the URL they had\n";
    $leaverUrl = $urlOf(501);
    $repo->setMemberStatus($leaver, 'left');
    $t1 = $tokenOf();
    $check($t1 !== $t0 && preg_match('/^[0-9a-f]{32}$/', $t1) === 1, 'active -> left: new 32-hex token');
    [$code, $body] = $feed($leaverUrl);
    $check($code === 404 && !str_contains($body, 'video.audit.invalid'), 'old URL now 404, no video link');
    [$code] = $feed($urlOf(506));
    $check($code === 200, 'current members keep working with the new token');

    echo "D. Suspension, ban and demotion to pending revoke it too\n";
    foreach ([[$suspendee, 503, 'suspended'], [$bannee, 504, 'banned'], [$demotee, 505, 'pending']] as [$memberId, $userId, $status]) {
        $before = $tokenOf();
        $theirs = $urlOf($userId);
        $repo->setMemberStatus($memberId, $status);
        $after = $tokenOf();
        $check($after !== $before, "active -> {$status}: token rotated");
        [$code] = $feed($theirs);
        $check($code === 404, "active -> {$status}: previous URL 404");
    }

    echo "E. Re-activating a member does not rotate\n";
    $before = $tokenOf();
    $repo->setMemberStatus($suspendee, 'active');
    $check($tokenOf() === $before, 'suspended -> active: token unchanged');
    $repo->setMemberStatus($bannee, 'banned');
    $check($tokenOf() === $before, 'banned -> banned (no longer active): token unchanged');

    echo "F. Losing access outside the club ends the URL at once (review of PR #536, F071)\n";
    $keeperUrl = $urlOf(506);
    $suspendeeUrl = $urlOf(503);
    [$code] = $feed($keeperUrl);
    $check($code === 200, 'setup: an active member reads the feed');
    $db->query("UPDATE utenti SET stato = 'sospeso' WHERE id = 503");
    [$code] = $feed($suspendeeUrl);
    $check($code === 404, 'a member whose library account is suspended: 404, membership untouched');
    $db->query("DELETE FROM utenti WHERE id = 506");
    [$code] = $feed($keeperUrl);
    $check($code === 404, 'a member whose library account was deleted: 404');
    $staffUrl = $urlOf($staffUser);
    $db->query("UPDATE utenti SET tipo_utente = 'standard' WHERE id = {$staffUser}");
    [$code] = $feed($staffUrl);
    $check($code === 404, 'a Staff account demoted to reader (not a member): 404');
    $db->query("UPDATE utenti SET stato = 'attivo' WHERE id = 503");
    [$code] = $feed($suspendeeUrl);
    $check($code === 200, 'reactivating the account restores the same URL (no rotation needed)');
    $check($tokenOf() === $before, 'none of this rotated the club token, so nobody else had to re-subscribe');
    putenv($savedKey === false ? 'PLUGIN_ENCRYPTION_KEY' : 'PLUGIN_ENCRYPTION_KEY=' . $savedKey);
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    foreach (['bookclub_meetings', 'bookclub_members', 'bookclub_roles', 'bookclub_clubs', 'bookclub_workflows', 'bookclub_affinity', 'bookclub_affinity_optin', 'utenti'] as $t) {
        try { $db->query("DROP TEMPORARY TABLE IF EXISTS {$t}"); } catch (\Throwable) {}
    }
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
