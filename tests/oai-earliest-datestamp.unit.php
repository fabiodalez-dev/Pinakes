<?php
declare(strict_types=1);

/**
 * OAI-PMH Identify: earliestDatestamp is the lower limit of EVERY datestamp
 * the repository can answer with, deleted headers included (OAI-PMH 2.0 §4.2).
 *
 * It used to be MIN(created_at) over the live books (plus archival units and
 * mastheads). Two things were wrong with that. The repository declares
 * deletedRecord=persistent and answers tombstones dated by their own
 * datestamp, which can be older than any live record: a harvester starting at
 * from=earliestDatestamp was never told about those deletions. And a live
 * record's datestamp is updated_at, not created_at, so the two could disagree
 * in either direction. The tombstone arm is read whether or not the deletion
 * triggers are installed, exactly as the list verbs read it.
 *
 * The checks drive the real dispatcher (oaiPmhAction) against the real
 * database. The rows they add are written inside a transaction that is rolled
 * back, and the last check proves nothing was left behind.
 *
 * Run: php tests/oai-earliest-datestamp.unit.php   (exit 0 iff all pass)
 */

use App\Plugins\OaiPmhServer\OaiPmhServerPlugin;
use App\Support\BookVisibility;
use App\Support\HookManager;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$env = [];
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $dbPass, $name, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $dbPass, $name, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL: database not reachable (' . $e->getMessage() . ") — this suite requires the real DB\n");
    exit(1);
}

$plugin = new OaiPmhServerPlugin($db, new HookManager($db));
// The tombstone table arrives with the plugin's schema, which an installation
// that never activated the plugin does not have. Idempotent, and run before
// the transaction because DDL commits implicitly.
$schema = $plugin->ensureSchema();
$check(($schema['failed'] ?? ['x']) === [], '01 the plugin schema is in place');

/** One OAI request through the real dispatcher; returns the response body. */
$oai = static function (array $query) use ($plugin): string {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/oai')->withQueryParams($query);
    $stream = $plugin->oaiPmhAction($request, new SlimResponse())->getBody();
    $stream->rewind();
    return $stream->getContents();
};
$earliestOf = static function (string $body): string {
    return preg_match('/<earliestDatestamp>([^<]+)<\/earliestDatestamp>/', $body, $m) ? $m[1] : '';
};
/** The UTC datestamp the plugin emits for a stored (server-local) DATETIME. */
$utc = static fn(string $local): string => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($local));

/**
 * Every header of a ListIdentifiers harvest, resumption tokens followed.
 *
 * @return list<array{id:string, datestamp:string, deleted:bool}>
 */
$harvest = static function (array $query) use ($oai): array {
    $headers = [];
    for ($page = 0; $page < 2000; $page++) {
        $body = $oai($query);
        if (preg_match('/<error code="([^"]*)"/', $body, $error)) {
            // "Nothing matches" is an answer, an empty list. Any other error
            // in the middle of a harvest would leave a partial list that the
            // checks below would compare as if it were the whole repository.
            if ($error[1] === 'noRecordsMatch' && $page === 0) {
                return [];
            }
            throw new RuntimeException("harvest page {$page} answered the OAI error {$error[1]}");
        }
        preg_match_all('/<header( status="deleted")?>\s*<identifier>([^<]+)<\/identifier>\s*<datestamp>([^<]+)<\/datestamp>/', $body, $m, PREG_SET_ORDER);
        foreach ($m as $h) {
            $headers[] = ['id' => $h[2], 'datestamp' => $h[3], 'deleted' => $h[1] !== ''];
        }
        if (!preg_match('/<resumptionToken[^>]*>([^<]+)<\/resumptionToken>/', $body, $t)) {
            // No token, or the empty one that closes the list: complete.
            return $headers;
        }
        $query = ['verb' => 'ListIdentifiers', 'resumptionToken' => html_entity_decode($t[1], ENT_QUOTES | ENT_XML1)];
    }
    throw new RuntimeException('harvest not finished after 2000 pages: a resumption token is still open');
};

$tombstoneId = 2000000001;
$tombstoneOai = 'oai:earliest-datestamp-test:book:' . $tombstoneId;
$tombstoneAt = '2001-02-03 04:05:06';
$staleTitle = 'ZZ earliest datestamp ' . bin2hex(random_bytes(4));
$staleAt = '2000-06-07 08:09:10';

// The fixtures are dated 2000, 2001 and 2002 and must be the oldest records
// of the repository: the checks below name those datestamps. A database that
// already holds something that old cannot run them, and says so instead of
// failing on a datestamp nobody recognises.
//
// Two questions, because neither verb sees everything the other does. The
// list verb does not answer a book withdrawn from the catalogue more than 90
// days ago, which Identify counts at any age. Identify answers 1970-01-01 for
// an empty repository, its fallback and not a record, so that exact value is
// not taken as one.
$emptyRepository = '1970-01-01T00:00:00Z';
$listed = $harvest(['verb' => 'ListIdentifiers', 'metadataPrefix' => 'oai_dc', 'from' => '1970-01-01T00:00:00Z', 'until' => '2002-12-31T23:59:59Z']);
$identified = $earliestOf($oai(['verb' => 'Identify']));
$alreadyThere = $listed !== [] ? min(array_column($listed, 'datestamp')) : null;
if ($identified !== '' && $identified !== $emptyRepository && $identified < '2003-01-01T00:00:00Z') {
    $alreadyThere = $alreadyThere === null ? $identified : min($alreadyThere, $identified);
}
if ($alreadyThere !== null) {
    fwrite(STDERR, "FAIL: this database already holds a record dated {$alreadyThere}, as old as the fixtures of this suite (2000-2002). Run it on a test database whose records are newer.\n");
    exit(1);
}

$db->begin_transaction();
try {
    // ── 1. A tombstone older than every live record ────────────────────────
    $stmt = $db->prepare("INSERT INTO oai_deleted_records (entity_type, entity_id, oai_id, datestamp) VALUES ('book', ?, ?, ?)");
    $stmt->bind_param('iss', $tombstoneId, $tombstoneOai, $tombstoneAt);
    $stmt->execute();
    $stmt->close();

    // A book withdrawn from the catalogue 60 days ago. Its deleted header is
    // only listed inside a window that depends on the request (90 days with a
    // from=, 30 without), so this one is in a from= harvest and not in a
    // bare one: the two are not the same list, by design.
    $withdrawn = BookVisibility::hasDesiderata($db) && BookVisibility::hasCataloguedAt($db);
    if ($withdrawn) {
        $stmt = $db->prepare('INSERT INTO libri (titolo, is_desiderata, catalogued_at, created_at, updated_at)'
            . ' VALUES (?, 1, DATE_SUB(NOW(), INTERVAL 200 DAY), DATE_SUB(NOW(), INTERVAL 200 DAY), DATE_SUB(NOW(), INTERVAL 60 DAY))');
        $withdrawnTitle = $staleTitle . ' withdrawn';
        $stmt->bind_param('s', $withdrawnTitle);
        $stmt->execute();
        $stmt->close();
    }

    $earliest = $earliestOf($oai(['verb' => 'Identify']));
    $check($earliest === $utc($tombstoneAt), "02 earliestDatestamp is the oldest tombstone's datestamp ({$earliest}, expected " . $utc($tombstoneAt) . ')');

    // A harvester that starts where Identify tells it to is given the deletion.
    $fromEarliest = $harvest(['verb' => 'ListIdentifiers', 'metadataPrefix' => 'oai_dc', 'from' => $earliest]);
    $found = array_values(array_filter($fromEarliest, static fn(array $h): bool => str_ends_with($h['id'], ':book:' . $tombstoneId)));
    $check(count($found) === 1 && $found[0]['deleted'] && $found[0]['datestamp'] === $utc($tombstoneAt),
        '03 from=earliestDatestamp harvests that tombstone as a deleted header');

    // The contract itself: nothing the list verbs answer is dated before it,
    // neither in a bare harvest nor in one that asks from the beginning of
    // time.
    $everything = $harvest(['verb' => 'ListIdentifiers', 'metadataPrefix' => 'oai_dc']);
    $sinceEpoch = $harvest(['verb' => 'ListIdentifiers', 'metadataPrefix' => 'oai_dc', 'from' => '1970-01-01T00:00:00Z']);
    $datestamps = array_merge(array_column($everything, 'datestamp'), array_column($sinceEpoch, 'datestamp'));
    $check($everything !== [] && $sinceEpoch !== [] && min($datestamps) >= $earliest,
        '04 no header of a full harvest, bare or from=1970, is dated before earliestDatestamp (' . count($everything) . ' and ' . count($sinceEpoch)
        . ' headers, oldest ' . ($datestamps !== [] ? min($datestamps) : 'none') . ')');
    // from=earliestDatestamp leaves nothing out. The reference is the from=1970
    // harvest, not the bare one: a from= widens the window of the withdrawn
    // books' deleted headers, so only two harvests that both carry a from=
    // list the same repository.
    $ids = static function (array $headers): array {
        $out = array_map(static fn(array $h): string => ($h['deleted'] ? 'deleted ' : '') . $h['id'], $headers);
        sort($out);
        return $out;
    };
    $check($ids($fromEarliest) === $ids($sinceEpoch),
        '05 from=earliestDatestamp returns the whole repository (' . count($fromEarliest) . ' of ' . count($sinceEpoch) . ' headers of a from=1970 harvest)');
    if ($withdrawn) {
        // Every header of the bare harvest is in the from= one as well; the
        // from= one may only add deleted headers (the wider window).
        $extra = array_values(array_diff($ids($fromEarliest), $ids($everything)));
        $check(array_diff($ids($everything), $ids($fromEarliest)) === []
            && array_filter($extra, static fn(string $id): bool => !str_starts_with($id, 'deleted ')) === [],
            '05b a bare harvest is contained in it, and what it adds are deleted headers only (' . count($extra) . ' more)');
    }

    // ── 2. A live record is dated by updated_at, not created_at ────────────
    $stmt = $db->prepare('INSERT INTO libri (titolo, created_at, updated_at) VALUES (?, NOW(), ?)');
    $stmt->bind_param('ss', $staleTitle, $staleAt);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    // The row must really carry the old datestamp (no ON UPDATE rewrite).
    $stored = (string) $db->query('SELECT updated_at FROM libri WHERE id = ' . $bookId)->fetch_row()[0];
    $check($stored === $staleAt, "06 the fixture book is stored with updated_at {$staleAt}");

    $earliest = $earliestOf($oai(['verb' => 'Identify']));
    $check($earliest === $utc($staleAt), "07 earliestDatestamp follows the live record's datestamp ({$earliest}, expected " . $utc($staleAt) . ')');
    $first = $harvest(['verb' => 'ListIdentifiers', 'metadataPrefix' => 'oai_dc', 'from' => $earliest, 'until' => $earliest]);
    $check(count($first) === 1 && !$first[0]['deleted'] && str_ends_with($first[0]['id'], ':book:' . $bookId),
        '08 from=until=earliestDatestamp returns exactly that live record');

    // ── 3. A soft-deleted book does not count by its row, only its tombstone
    $db->query("UPDATE oai_deleted_records SET datestamp = '2002-03-04 05:06:07' WHERE entity_id = {$tombstoneId} AND entity_type = 'book'");
    // CI-SOFT-DELETE-EXEMPT: the fixture is soft-deleted on purpose.
    $db->query("UPDATE libri SET deleted_at = NOW(), updated_at = '{$staleAt}' WHERE id = {$bookId}");
    $db->query("DELETE FROM oai_deleted_records WHERE entity_type = 'book' AND entity_id = {$bookId}");
    $earliest = $earliestOf($oai(['verb' => 'Identify']));
    $check($earliest === $utc('2002-03-04 05:06:07'),
        "09 a soft-deleted row is not a datestamp source, the remaining tombstone is ({$earliest})");
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    $db->rollback();
}

$left = (int) $db->query("SELECT (SELECT COUNT(*) FROM oai_deleted_records WHERE entity_id = {$tombstoneId})"
    . " + (SELECT COUNT(*) FROM libri WHERE titolo LIKE '" . $db->real_escape_string($staleTitle) . "%')")->fetch_row()[0];
$check($left === 0, '10 the fixtures were rolled back');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
