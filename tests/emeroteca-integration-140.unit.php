<?php
declare(strict_types=1);

/**
 * Behavioral unit tests for the Emeroteca integration layer added in the
 * #140 review follow-up: the testata merge, the Kardex barcode lookup, the
 * union-catalogue export routes and the issue-label route.
 *
 * Everything runs against the REAL dev DB through the REAL controllers —
 * the merge is a transaction over two unique keys and a pure-function test
 * would prove nothing about the SQL that actually moves the holdings.
 *
 * Covers:
 *   1. merge with a DOUBLE collision (same annata AND same issue numbers on
 *      both sides) — the invariant that matters is that NO issue disappears:
 *      the issue count under the two titles before the merge must equal the
 *      count under the survivor after it;
 *   2. the documented conflict rule: the `posseduto` copy keeps the plain
 *      number (destination first, source when the destination is not owned)
 *      and the loser is renumbered "-dup", never deleted;
 *   3. an annata with no counterpart changes owner whole, with its issues;
 *   4. subscriptions migrate to the survivor and the title history of a
 *      third periodical is repointed instead of being nulled by the FK;
 *   5. both audit events are written (periodical.deleted on the source,
 *      periodical.merged on the destination);
 *   6. the merge is REFUSED for a staff session (inline admin re-check) and
 *      leaves the database untouched;
 *   7. scan lookup resolves an exact issue barcode, resolves the testata
 *      through the 13-digit barcode_base prefix of a code with an EAN
 *      add-on, and suggests the "receive" action for an expected issue;
 *   8. the export routes answer with the right Content-Type / attachment
 *      disposition and a body starting with the canonical KBART / ACNP
 *      header;
 *   9. the labels route rejects an empty selection with a flash instead of a
 *      500, and returns real PDF bytes for a valid one.
 *
 * Conventions follow tests/emeroteca-admin-140.unit.php: env parsing, socket
 * connection, check()/pass() helpers, zz fixtures, FK-ordered cleanup in
 * finally, hard failure (exit 1) when the DB is unreachable.
 *
 * Run:  php tests/emeroteca-integration-140.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\HookManager;
use App\Support\Hooks;

function emint_env(string $path): array
{
    $env = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
    return $env;
}

$env    = emint_env(__DIR__ . '/../.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass   = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? @new mysqli(null, $user, $pass, $name, 0, $socket)
        : @new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database not reachable (" . $e->getMessage() . ") — this suite requires the real DB\n");
    exit(1);
}
if (!isset($db) || $db->connect_errno !== 0) {
    $error = isset($db) ? $db->connect_error : 'connection failed';
    fwrite(STDERR, "FAIL: database not reachable ({$error}) — this suite requires the real DB\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$TESTNO = 0;
$FAILED = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    global $TESTNO, $FAILED;
    if (!$cond) {
        $TESTNO++;
        $FAILED++;
        printf("[%02d] FAIL: %s\n", $TESTNO, $desc);
        return;
    }
    pass($desc);
}

$pluginDir = __DIR__ . '/../storage/plugins/emeroteca';
require_once $pluginDir . '/EmerotecaPlugin.php';
require_once $pluginDir . '/src/Support/IssnHelper.php';
require_once $pluginDir . '/src/Controllers/PeriodicalAdminController.php';
require_once $pluginDir . '/src/Controllers/ExportAdminController.php';

use App\Plugins\Emeroteca\Controllers\ExportAdminController;
use App\Plugins\Emeroteca\Controllers\PeriodicalAdminController;

// Flash helpers write to $_SESSION; in CLI initialize it explicitly.
// NOTE: no 'id' in the session user — log_modifiche.utente_id is a FK
// towards utenti, so a made-up operator id would fail every audit INSERT.
$_SESSION = [];

// Real HookManager marked "runtime-loaded", so the DB-registered plugin
// hooks are NOT pulled in: this test wires only what it exercises.
$hookManager = new HookManager($db);
$hookManager->setPluginsLoadedRuntime();
Hooks::init($hookManager);

$RUN = 'zz-eint140-' . bin2hex(random_bytes(4));
$T_SOURCE   = "zz Emeroteca Merge Source {$RUN}";
$T_TARGET   = "zz Emeroteca Merge Target {$RUN}";
$T_FOLLOWER = "zz Emeroteca Merge Follower {$RUN}";
$T_STAFF_A  = "zz Emeroteca Staff A {$RUN}";
$T_STAFF_B  = "zz Emeroteca Staff B {$RUN}";
$T_SCAN     = "zz Emeroteca Scan {$RUN}";
$T_BASE     = "zz Emeroteca Scan Base {$RUN}";
$TITLES = [$T_SOURCE, $T_TARGET, $T_FOLLOWER, $T_STAFF_A, $T_STAFF_B, $T_SCAN, $T_BASE];

/** Ids that received an audit row and must be cleaned out of log_modifiche. */
$auditedTestataIds = [];

$cleanup = static function () use ($db, $TITLES, &$auditedTestataIds): void {
    $titles = implode(',', array_map(
        static fn(string $t): string => "'" . $db->real_escape_string($t) . "'",
        $TITLES
    ));

    $ids = array_values(array_unique(array_filter(array_map('intval', $auditedTestataIds))));
    if ($ids !== []) {
        @$db->query(
            "DELETE FROM log_modifiche WHERE tabella = 'emeroteca_testate'"
            . ' AND record_id IN (' . implode(',', $ids) . ')'
        );
    }

    @$db->query(
        "DELETE ar FROM emeroteca_articoli ar
           JOIN emeroteca_fascicoli f ON ar.fascicolo_id = f.id
           JOIN emeroteca_annate a ON f.annata_id = a.id
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo IN ({$titles})"
    );
    @$db->query(
        "DELETE f FROM emeroteca_fascicoli f
           JOIN emeroteca_annate a ON f.annata_id = a.id
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo IN ({$titles})"
    );
    @$db->query(
        "DELETE a FROM emeroteca_annate a
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo IN ({$titles})"
    );
    @$db->query(
        "DELETE ab FROM emeroteca_abbonamenti ab
           JOIN emeroteca_testate t ON ab.testata_id = t.id
          WHERE t.titolo IN ({$titles})"
    );
    // Break the self-referencing FK before deleting the titles themselves.
    @$db->query("UPDATE emeroteca_testate SET testata_precedente_id = NULL WHERE titolo IN ({$titles})");
    @$db->query("DELETE FROM emeroteca_testate WHERE titolo IN ({$titles})");
};

$cleanup();

try {
    // ── helpers ───────────────────────────────────────────────────────
    $exec = static function (string $sql, string $types, array $params) use ($db): int {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('fixture prepare failed: ' . $db->error . ' — ' . $sql);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('fixture failed: ' . $error . ' — ' . $sql);
        }
        $id = (int) $db->insert_id;
        $affected = (int) $stmt->affected_rows;
        $stmt->close();
        return $id > 0 ? $id : $affected;
    };

    $scalar = static function (string $sql, string $types = '', array $params = []) use ($db): ?string {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('probe prepare failed: ' . $db->error . ' — ' . $sql);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_row() : null;
        $stmt->close();
        return is_array($row) ? (string) $row[0] : null;
    };

    $newTestata = static function (string $titolo, ?string $barcodeBase = null) use ($exec): int {
        return $exec(
            'INSERT INTO emeroteca_testate (titolo, tipo, stato_raccolta, barcode_base) VALUES (?, ?, ?, ?)',
            'ssss',
            [$titolo, 'rivista', 'attiva', $barcodeBase]
        );
    };
    $newAnnata = static function (int $testataId, int $anno, string $volume = '') use ($exec): int {
        return $exec(
            'INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, ?, ?)',
            'iis',
            [$testataId, $anno, $volume]
        );
    };
    $newFascicolo = static function (
        int $annataId,
        string $numero,
        string $stato = 'posseduto',
        ?string $barcode = null
    ) use ($exec): int {
        return $exec(
            'INSERT INTO emeroteca_fascicoli (annata_id, numero, stato, barcode) VALUES (?, ?, ?, ?)',
            'isss',
            [$annataId, $numero, $stato, $barcode]
        );
    };

    /** Issues hanging off a whole title, whatever the annata. */
    $countIssues = static function (int $testataId) use ($scalar): int {
        return (int) $scalar(
            'SELECT COUNT(*) FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
              WHERE a.testata_id = ?',
            'i',
            [$testataId]
        );
    };

    /** numero => stato of every issue in one annata of a title. */
    $issuesOfYear = static function (int $testataId, int $anno) use ($db): array {
        $stmt = $db->prepare(
            'SELECT f.numero, f.stato FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
              WHERE a.testata_id = ? AND a.anno = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('issues probe prepare failed: ' . $db->error);
        }
        $stmt->bind_param('ii', $testataId, $anno);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[(string) $row['numero']] = (string) $row['stato'];
            }
        }
        $stmt->close();
        return $out;
    };

    $auditCount = static function (int $recordId, string $event) use ($db): int {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM log_modifiche
              WHERE tabella = 'emeroteca_testate' AND record_id = ?
                AND dati_nuovi LIKE CONCAT('%\"event\":\"', ?, '\"%')"
        );
        if ($stmt === false) {
            throw new \RuntimeException('audit probe prepare failed: ' . $db->error);
        }
        $stmt->bind_param('is', $recordId, $event);
        $stmt->execute();
        $res = $stmt->get_result();
        $c = $res instanceof \mysqli_result ? (int) ($res->fetch_assoc()['c'] ?? 0) : 0;
        $stmt->close();
        return $c;
    };

    $reqFactory = new \Slim\Psr7\Factory\ServerRequestFactory();
    $resFactory = new \Slim\Psr7\Factory\ResponseFactory();
    /** POST request carrying a parsed body, as CsrfMiddleware would hand it over. */
    $post = static function (string $path, array $body) use ($reqFactory): \Psr\Http\Message\ServerRequestInterface {
        return $reqFactory->createServerRequest('POST', $path)->withParsedBody($body);
    };
    /** GET request carrying query params, as Slim would parse them. */
    $get = static function (string $path, array $query) use ($reqFactory): \Psr\Http\Message\ServerRequestInterface {
        return $reqFactory->createServerRequest('GET', $path)->withQueryParams($query);
    };

    $periodicals = new PeriodicalAdminController($db, $hookManager);
    $exports = new ExportAdminController($db, $hookManager);

    // ── Fixtures: the double-collision merge ──────────────────────────
    //
    //  TARGET 2001: "1" posseduto, "2" mancante
    //  SOURCE 2001: "1" mancante   → destination is owned, source renamed
    //               "2" posseduto  → source is owned, DESTINATION renamed
    //               "3" posseduto  → no collision, moves as it is
    //  SOURCE 2002: "5" posseduto  → no counterpart, the whole annata moves
    $targetId = $newTestata($T_TARGET);
    $sourceId = $newTestata($T_SOURCE);
    $followerId = $newTestata($T_FOLLOWER);
    $auditedTestataIds[] = $targetId;
    $auditedTestataIds[] = $sourceId;

    $targetYear = $newAnnata($targetId, 2001);
    $newFascicolo($targetYear, '1', 'posseduto');
    $newFascicolo($targetYear, '2', 'mancante');

    $sourceYear = $newAnnata($sourceId, 2001);
    $newFascicolo($sourceYear, '1', 'mancante');
    $newFascicolo($sourceYear, '2', 'posseduto');
    $newFascicolo($sourceYear, '3', 'posseduto');

    $sourceYear2 = $newAnnata($sourceId, 2002);
    $newFascicolo($sourceYear2, '5', 'posseduto');

    $exec(
        'INSERT INTO emeroteca_abbonamenti (testata_id, fornitore, attivo) VALUES (?, ?, 1)',
        'is',
        [$sourceId, "zz Fornitore {$RUN}"]
    );
    // A third title that "continues from" the source: the FK would null the
    // link when the source row goes, so the merge must repoint it.
    $exec(
        'UPDATE emeroteca_testate SET testata_precedente_id = ? WHERE id = ?',
        'ii',
        [$sourceId, $followerId]
    );

    $issuesBefore = $countIssues($sourceId) + $countIssues($targetId);
    check($issuesBefore === 6, 'fixture: six issues across the two titles before the merge');

    // ── 1. Staff sessions cannot merge ────────────────────────────────
    $_SESSION = ['user' => ['tipo_utente' => 'staff']];
    $periodicals->mergeSubmit(
        $post('/admin/periodicals/merge', [
            'ids'       => [(string) $sourceId, (string) $targetId],
            'target_id' => (string) $targetId,
        ]),
        $resFactory->createResponse()
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'a staff session is refused the merge');
    check(
        $countIssues($sourceId) === 4 && $countIssues($targetId) === 2,
        'the refused merge left both titles untouched'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 2. The merge itself ───────────────────────────────────────────
    $_SESSION = ['user' => ['tipo_utente' => 'admin']];
    $mergeResponse = $periodicals->mergeSubmit(
        $post('/admin/periodicals/merge', [
            'ids'       => [(string) $sourceId, (string) $targetId],
            'target_id' => (string) $targetId,
        ]),
        $resFactory->createResponse()
    );
    // The success flash is CONSUMED by the layout while this very response is
    // rendered (app/Views/layout.php unsets it), so the proof of success is
    // the rendered page, not a leftover session key.
    $summaryHtml = (string) $mergeResponse->getBody();
    check(
        $mergeResponse->getStatusCode() === 200 && str_contains($summaryHtml, 'emeroteca-admin-merge'),
        'the merge renders the summary view instead of redirecting away from it'
    );
    check(
        str_contains($summaryHtml, '1-dup') && str_contains($summaryHtml, '2-dup'),
        'the summary names every renumbered duplicate — nothing is resolved silently'
    );
    check(($_SESSION['error_message'] ?? '') === '', 'the merge reported no error');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // THE invariant: nothing may be lost on the way.
    $issuesAfter = $countIssues($targetId);
    check(
        $issuesAfter === $issuesBefore,
        sprintf('no issue disappeared in the merge (%d before, %d after)', $issuesBefore, $issuesAfter)
    );
    check(
        (int) $scalar('SELECT COUNT(*) FROM emeroteca_testate WHERE id = ?', 'i', [$sourceId]) === 0,
        'the source title is gone after the merge'
    );

    // ── 3. Conflict resolution, number by number ──────────────────────
    $year2001 = $issuesOfYear($targetId, 2001);
    check(count($year2001) === 5, '2001 of the survivor holds all five issues of the fused annata');
    check(
        ($year2001['1'] ?? '') === 'posseduto',
        'the owned DESTINATION copy keeps the plain number "1"'
    );
    check(
        ($year2001['1-dup'] ?? '') === 'mancante',
        'the losing source copy of "1" survives, renumbered "1-dup"'
    );
    check(
        ($year2001['2'] ?? '') === 'posseduto',
        'the owned SOURCE copy takes the plain number "2" from a non-owned destination'
    );
    check(
        ($year2001['2-dup'] ?? '') === 'mancante',
        'the non-owned destination copy of "2" survives, renumbered "2-dup"'
    );
    check(isset($year2001['3']), 'the non-colliding issue "3" moved across untouched');

    $year2002 = $issuesOfYear($targetId, 2002);
    check(count($year2002) === 1 && isset($year2002['5']), 'the annata without a counterpart moved whole');
    check(
        (int) $scalar('SELECT COUNT(*) FROM emeroteca_annate WHERE testata_id = ?', 'i', [$targetId]) === 2,
        'the survivor owns exactly the two annate (2001 fused, 2002 moved)'
    );

    // ── 4. Subscriptions and title history ────────────────────────────
    check(
        (int) $scalar('SELECT COUNT(*) FROM emeroteca_abbonamenti WHERE testata_id = ?', 'i', [$targetId]) === 1,
        'the subscription migrated to the survivor'
    );
    check(
        (int) $scalar('SELECT testata_precedente_id FROM emeroteca_testate WHERE id = ?', 'i', [$followerId])
            === $targetId,
        'the title that continued from the source now continues from the survivor'
    );

    // ── 5. Audit ──────────────────────────────────────────────────────
    check($auditCount($sourceId, 'periodical.deleted') === 1, 'the disappearing source is audited as deleted');
    check($auditCount($targetId, 'periodical.merged') === 1, 'the surviving destination is audited as merged');

    // ── 6. Merge guards ───────────────────────────────────────────────
    $periodicals->mergeSubmit(
        $post('/admin/periodicals/merge', [
            'ids'       => [(string) $targetId],
            'target_id' => (string) $targetId,
        ]),
        $resFactory->createResponse()
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'a single-title selection is refused');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    $staffA = $newTestata($T_STAFF_A);
    $staffB = $newTestata($T_STAFF_B);
    $periodicals->mergeSubmit(
        $post('/admin/periodicals/merge', [
            'ids'       => [(string) $staffA, (string) $staffB],
            'target_id' => (string) ($staffB + 100000),
        ]),
        $resFactory->createResponse()
    );
    check(
        ($_SESSION['error_message'] ?? '') !== ''
        && (int) $scalar('SELECT COUNT(*) FROM emeroteca_testate WHERE id = ?', 'i', [$staffA]) === 1,
        'a destination outside the selected pair is refused'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 7. Scan lookup ────────────────────────────────────────────────
    // 977-prefixed EAN-13s: one printed on the issue itself, one that only
    // exists as the title's barcode_base (the usual case — most periodicals
    // vary only the add-on from issue to issue).
    $ISSUE_BARCODE = '9771234567003';
    $TITLE_BASE    = '9779876543008';
    $scanTitle = $newTestata($T_SCAN);
    $scanYear = $newAnnata($scanTitle, 2010);
    $scanIssue = $newFascicolo($scanYear, '7', 'atteso', $ISSUE_BARCODE);
    $baseTitle = $newTestata($T_BASE, $TITLE_BASE);

    $decode = static function (\Psr\Http\Message\ResponseInterface $response): array {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    };

    $res = $exports->scanLookup(
        $get('/admin/periodicals/scan-lookup', ['code' => $ISSUE_BARCODE, 'testata' => (string) $scanTitle]),
        $resFactory->createResponse()
    );
    $payload = $decode($res);
    check(
        str_contains($res->getHeaderLine('Content-Type'), 'application/json'),
        'the scan lookup answers as JSON'
    );
    check(
        ($payload['found'] ?? false) === true
        && ($payload['match'] ?? '') === 'issue'
        && (int) ($payload['issue']['id'] ?? 0) === $scanIssue,
        'an exact issue barcode resolves to that issue'
    );
    check(
        ($payload['action'] ?? null) === 'receive',
        'an expected issue comes back with the "receive" suggestion'
    );

    // Same code plus a 2-digit EAN add-on: no issue carries it, so the
    // lookup falls back to the 13-digit base of the title.
    $res = $exports->scanLookup(
        $get('/admin/periodicals/scan-lookup', ['code' => $TITLE_BASE . '12']),
        $resFactory->createResponse()
    );
    $payload = $decode($res);
    check(
        ($payload['found'] ?? false) === true
        && ($payload['match'] ?? '') === 'title'
        && (int) ($payload['title']['id'] ?? 0) === $baseTitle,
        'a code with an EAN add-on resolves to the title through its barcode_base'
    );

    $res = $exports->scanLookup(
        $get('/admin/periodicals/scan-lookup', ['code' => '9770000000000']),
        $resFactory->createResponse()
    );
    $payload = $decode($res);
    check(
        ($payload['found'] ?? true) === false && ($payload['match'] ?? '') === 'none',
        'an unknown code reports no match instead of guessing'
    );

    $res = $exports->scanLookup(
        $get('/admin/periodicals/scan-lookup', ['code' => 'not-a-barcode']),
        $resFactory->createResponse()
    );
    $payload = $decode($res);
    check(($payload['found'] ?? true) === false, 'a non-numeric code is rejected without a query');

    // ── 8. Export routes ──────────────────────────────────────────────
    $res = $exports->kbart(
        $get('/admin/periodicals/export/kbart', ['testata' => (string) $targetId]),
        $resFactory->createResponse()
    );
    $body = (string) $res->getBody();
    check(
        $res->getStatusCode() === 200
        && str_contains($res->getHeaderLine('Content-Type'), 'text/tab-separated-values'),
        'the KBART route answers as TSV'
    );
    check(
        str_starts_with($res->getHeaderLine('Content-Disposition'), 'attachment; filename="emeroteca-kbart-')
        && str_contains($res->getHeaderLine('Content-Disposition'), date('Y-m-d')),
        'the KBART download carries a dated attachment filename'
    );
    check(
        str_starts_with($body, implode("\t", \App\Plugins\Emeroteca\Support\KbartExporter::KBART_COLUMNS)),
        'the KBART body starts with the canonical 25-column header'
    );

    $res = $exports->acnp(
        $get('/admin/periodicals/export/acnp', ['testata' => (string) $targetId]),
        $resFactory->createResponse()
    );
    $body = (string) $res->getBody();
    check(
        str_contains($res->getHeaderLine('Content-Type'), 'text/csv')
        && str_starts_with($res->getHeaderLine('Content-Disposition'), 'attachment; filename="emeroteca-acnp-'),
        'the ACNP route answers as a CSV download'
    );
    check(
        str_starts_with($body, 'titolo,issn,e_issn,'),
        'the ACNP body starts with the documented column header'
    );

    $res = $exports->kbart(
        $get('/admin/periodicals/export/kbart', ['testata' => (string) ($targetId + 9000000)]),
        $resFactory->createResponse()
    );
    check(
        $res->getStatusCode() === 303 && ($_SESSION['error_message'] ?? '') !== '',
        'an unknown ?testata is a flash + redirect, never an empty file passed off as an export'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 9. Label route ────────────────────────────────────────────────
    $res = $exports->labels(
        $post('/admin/periodicals/' . $targetId . '/issues/labels', ['ids' => []]),
        $resFactory->createResponse(),
        ['id' => (string) $targetId]
    );
    check(
        $res->getStatusCode() === 303 && ($_SESSION['error_message'] ?? '') !== '',
        'an empty label selection is a handled flash, not a 500'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // An id belonging to ANOTHER title must not leak into the batch: with
    // only that id the selection is empty after filtering.
    $res = $exports->labels(
        $post('/admin/periodicals/' . $targetId . '/issues/labels', ['ids' => [(string) $scanIssue]]),
        $resFactory->createResponse(),
        ['id' => (string) $targetId]
    );
    check(
        $res->getStatusCode() === 303 && ($_SESSION['error_message'] ?? '') !== '',
        'an issue of another title is dropped from the label selection'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    $labelIds = [];
    $stmt = $db->prepare(
        'SELECT f.id FROM emeroteca_fascicoli f
           JOIN emeroteca_annate a ON a.id = f.annata_id
          WHERE a.testata_id = ? ORDER BY f.id LIMIT 3'
    );
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $resSet = $stmt->get_result();
    if ($resSet instanceof \mysqli_result) {
        while ($row = $resSet->fetch_assoc()) {
            $labelIds[] = (string) $row['id'];
        }
    }
    $stmt->close();

    if (!class_exists('TCPDF')) {
        // Loud skip: the label route is only meaningful with the PDF library.
        fwrite(STDERR, "SKIP: TCPDF is not installed — the label PDF assertions were NOT executed\n");
        check(
            count($labelIds) === 3,
            'label fixture ready (PDF assertions skipped: TCPDF missing)'
        );
    } else {
        $res = $exports->labels(
            $post('/admin/periodicals/' . $targetId . '/issues/labels', ['ids' => $labelIds]),
            $resFactory->createResponse(),
            ['id' => (string) $targetId]
        );
        $body = (string) $res->getBody();
        check(
            $res->getStatusCode() === 200 && str_starts_with($body, '%PDF-'),
            'a valid label selection returns real PDF bytes'
        );
        check(
            str_contains($res->getHeaderLine('Content-Type'), 'application/pdf')
            && str_contains($res->getHeaderLine('Content-Disposition'), 'etichette-fascicoli-' . $targetId),
            'the label response is served as a named PDF'
        );
    }
} finally {
    $cleanup();
    $db->close();
}

if ($FAILED > 0) {
    printf("\n%d assertion(s) FAILED\n", $FAILED);
    exit(1);
}
printf("\nAll %d assertions passed.\n", $TESTNO);
