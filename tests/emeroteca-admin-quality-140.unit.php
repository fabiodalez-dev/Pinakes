<?php
declare(strict_types=1);

/**
 * Behavioral unit tests for the Emeroteca admin-quality fixes (review #140)
 * against the REAL dev DB.
 *
 * Covers:
 *   1. IssnHelper: format, mod-11 checksum (0378-5955 valid, 0378-5954
 *      invalid, X-final 0002-936X valid), normalize, barcodeBase (977 EAN-13
 *      derivation with a hand-verified check digit);
 *   2. Calendar-aware Kardex counts: quotidiano 2024=366 / 2023=365,
 *      settimanale 2026=53 (long ISO year) / 2025=52, fixed periodicita
 *      untouched, irregolare → null;
 *   3. Destructive deletes rejected for a staff session (inline admin
 *      re-check) and allowed for an admin session;
 *   4. PeriodicalAdminController::holdingsSummary() (the ONE aggregated
 *      index query) returns the same numbers/string as
 *      EmerotecaPlugin::consistenzaTestata() on a fixture with lacune AND
 *      with `consistenza_dichiarata` set — the field the two
 *      implementations used to disagree on. The canonical rule is asserted
 *      as a literal expected string on every surface reachable from here
 *      (holdingsSummary, consistenzaTestata, KbartExporter::consistenzaAnnata):
 *      the declared statement is APPENDED to the computed one after ' · ',
 *      stands alone when nothing is computed, and the empty sentinel is '—'.
 *
 * Conventions follow tests/emeroteca.unit.php (env parsing, DB connection,
 * check()/pass() helpers, zz_* fixtures, FK-ordered cleanup).
 */

require __DIR__ . '/../vendor/autoload.php';

function eaq_env(string $path): array
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

$env    = eaq_env(__DIR__ . '/../.env');
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
    fwrite(STDERR, "FAIL: database not reachable (" . $e->getMessage() . ")\n");
    exit(1);
}
if (!isset($db) || $db->connect_errno !== 0) {
    $error = isset($db) ? $db->connect_error : 'connection failed';
    fwrite(STDERR, "FAIL: database not reachable ({$error})\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    pass($desc);
}

$pluginDir = __DIR__ . '/../storage/plugins/emeroteca';
require_once $pluginDir . '/EmerotecaPlugin.php';
require_once $pluginDir . '/src/Support/IssnHelper.php';
require_once $pluginDir . '/src/Support/KbartExporter.php';
require_once $pluginDir . '/src/Controllers/IssueAdminController.php';
require_once $pluginDir . '/src/Controllers/PeriodicalAdminController.php';

use App\Plugins\Emeroteca\Controllers\IssueAdminController;
use App\Plugins\Emeroteca\Controllers\PeriodicalAdminController;
use App\Plugins\Emeroteca\Support\IssnHelper;
use App\Plugins\Emeroteca\Support\KbartExporter;

// Flash helpers write to $_SESSION; in CLI initialize it explicitly.
$_SESSION = [];

$hm = new \App\Support\HookManager($db);
$plugin = new EmerotecaPlugin($db, $hm);

// Unique fixture marker so cleanup never touches pre-existing data.
$RUN = 'zz-eaq-' . bin2hex(random_bytes(4));
$TITLE_AGG    = "zz EmerotecaAdminQuality Aggregate {$RUN}";
$TITLE_EMPTY  = "zz EmerotecaAdminQuality Empty {$RUN}";
$TITLE_DECL   = "zz EmerotecaAdminQuality Declared {$RUN}";
$TITLE_DELETE = "zz EmerotecaAdminQuality Delete {$RUN}";

/** FK-ordered cleanup (articoli → fascicoli → annate → testate). */
$cleanup = static function () use ($db, $TITLE_AGG, $TITLE_EMPTY, $TITLE_DECL, $TITLE_DELETE): void {
    $titles = implode(',', array_map(
        static fn (string $t): string => "'" . $db->real_escape_string($t) . "'",
        [$TITLE_AGG, $TITLE_EMPTY, $TITLE_DECL, $TITLE_DELETE]
    ));
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
    @$db->query("DELETE FROM emeroteca_testate WHERE titolo IN ({$titles})");
};

try {
    // Schema must exist before fixtures (idempotent).
    $result = $plugin->ensureSchema();
    check(($result['failed'] ?? ['x']) === [], 'ensureSchema() reports no failed tables');

    // ── 1. IssnHelper ─────────────────────────────────────────────────
    check(IssnHelper::isValidFormat('0378-5955'), "isValidFormat accepts '0378-5955'");
    check(IssnHelper::isValidFormat('03785955'), "isValidFormat accepts the compact form '03785955'");
    check(IssnHelper::isValidFormat('0002-936x'), "isValidFormat accepts a lowercase final x");
    check(!IssnHelper::isValidFormat('0378-595'), 'isValidFormat rejects 7 characters');
    check(!IssnHelper::isValidFormat('0378-59555'), 'isValidFormat rejects 9 digits');
    check(!IssnHelper::isValidFormat('abcd-efgh'), 'isValidFormat rejects letters');

    check(IssnHelper::isValidChecksum('0378-5955'), "mod-11 checksum accepts the known-valid '0378-5955'");
    check(!IssnHelper::isValidChecksum('0378-5954'), "mod-11 checksum rejects '0378-5954' (wrong check digit)");
    check(IssnHelper::isValidChecksum('0002-936X'), "mod-11 checksum accepts the X-final '0002-936X' (10 → X)");
    check(IssnHelper::isValidChecksum('0002-936x'), 'checksum is case-insensitive on the final x');
    check(!IssnHelper::isValidChecksum('0002-9360'), "checksum rejects '0002-9360' (0 where X is expected)");

    check(IssnHelper::normalize('03785955') === '0378-5955', "normalize('03785955') → '0378-5955'");
    check(IssnHelper::normalize('0002-936x') === '0002-936X', "normalize uppercases the final x");

    // barcodeBase: 977 + 0378595 + 00 = 977037859500; EAN-13 check digit:
    // odd positions (9+7+3+8+9+0)=36, even (7+0+7+5+5+0)=24 ×3=72,
    // 36+72=108 → (10−8)%10=2 → 9770378595002 (hand-verified).
    check(
        IssnHelper::barcodeBase('0378-5955') === '9770378595002',
        "barcodeBase('0378-5955') === '9770378595002' (hand-verified EAN-13 check digit)"
    );
    check(
        IssnHelper::barcodeBase('0378-5955') === IssnHelper::barcodeBase('03785955'),
        'barcodeBase is deterministic across input formats'
    );
    check(IssnHelper::barcodeBase('0378-5954') === null, 'barcodeBase returns null for a failed checksum');
    check(IssnHelper::barcodeBase('not-an-issn') === null, 'barcodeBase returns null for garbage input');
    $bcX = IssnHelper::barcodeBase('0002-936X');
    check(
        is_string($bcX) && strlen($bcX) === 13 && str_starts_with($bcX, '9770002936'),
        "barcodeBase for an X-final ISSN drops the check digit ('{$bcX}')"
    );

    // ── 2. Calendar-aware Kardex ──────────────────────────────────────
    check(IssueAdminController::kardexIssuesForYear('quotidiano', 2024) === 366, 'quotidiano 2024 (leap) → 366');
    check(IssueAdminController::kardexIssuesForYear('quotidiano', 2023) === 365, 'quotidiano 2023 → 365');
    check(IssueAdminController::kardexIssuesForYear('quotidiano', 2000) === 366, 'quotidiano 2000 (400-rule leap) → 366');
    check(IssueAdminController::kardexIssuesForYear('quotidiano', 1900) === 365, 'quotidiano 1900 (100-rule non-leap) → 365');
    check(IssueAdminController::kardexIssuesForYear('settimanale', 2026) === 53, 'settimanale 2026 (long ISO year) → 53');
    check(IssueAdminController::kardexIssuesForYear('settimanale', 2025) === 52, 'settimanale 2025 → 52');
    check(IssueAdminController::kardexIssuesForYear('mensile', 2024) === 12, 'mensile stays 12 regardless of the year');
    check(IssueAdminController::kardexIssuesForYear('irregolare', 2024) === null, 'irregolare → null (no Kardex)');
    check(IssueAdminController::kardexIssuesForYear('sconosciuta', 2024) === null, 'unknown periodicita → null');

    // ── Fixtures ──────────────────────────────────────────────────────
    $mkTestata = static function (string $titolo) use ($db): int {
        $stmt = $db->prepare("INSERT INTO emeroteca_testate (titolo, tipo) VALUES (?, 'rivista')");
        if ($stmt === false) {
            throw new \RuntimeException('testata fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('s', $titolo);
        if (!$stmt->execute()) {
            throw new \RuntimeException('testata fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    $mkAnnata = static function (int $testataId, int $anno) use ($db): int {
        $stmt = $db->prepare("INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, ?, '')");
        if ($stmt === false) {
            throw new \RuntimeException('annata fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('ii', $testataId, $anno);
        if (!$stmt->execute()) {
            throw new \RuntimeException('annata fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    $mkFascicolo = static function (int $annataId, string $numero, string $stato) use ($db): int {
        $stmt = $db->prepare('INSERT INTO emeroteca_fascicoli (annata_id, numero, stato) VALUES (?, ?, ?)');
        if ($stmt === false) {
            throw new \RuntimeException('fascicolo fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('iss', $annataId, $numero, $stato);
        if (!$stmt->execute()) {
            throw new \RuntimeException('fascicolo fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    $testataExists = static function (int $id) use ($db): bool {
        $res = $db->query('SELECT 1 FROM emeroteca_testate WHERE id = ' . $id);
        return $res instanceof \mysqli_result && $res->num_rows === 1;
    };
    $fascicoloExists = static function (int $id) use ($db): bool {
        $res = $db->query('SELECT 1 FROM emeroteca_fascicoli WHERE id = ' . $id);
        return $res instanceof \mysqli_result && $res->num_rows === 1;
    };

    $reqFactory = new \Slim\Psr7\Factory\ServerRequestFactory();
    $resFactory = new \Slim\Psr7\Factory\ResponseFactory();
    $periodicalController = new PeriodicalAdminController($db, $hm);
    $issueController = new IssueAdminController($db, $hm);

    // ── 3. holdingsSummary == consistenzaTestata on a gap fixture that
    //       ALSO carries consistenza_dichiarata ──────────────────────────
    // 1990 posseduto (+ declared), 1991 mancante, 1992 posseduto ×2
    // (+ declared) → computed "1990–1992 · lacune: 1", 3 posseduti,
    // 1 mancante, and the two declared statements appended in year order.
    //
    // The declared field is the whole point of this comparison: an
    // aggregated index query that simply forgets it agrees with
    // consistenzaTestata() on every OTHER fixture, so a test without it is
    // green while the two surfaces disagree in front of the librarian.
    $mkDeclared = static function (int $annataId, string $value) use ($db): void {
        $stmt = $db->prepare('UPDATE emeroteca_annate SET consistenza_dichiarata = ? WHERE id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('declared fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('si', $value, $annataId);
        if (!$stmt->execute()) {
            throw new \RuntimeException('declared fixture update failed: ' . $stmt->error);
        }
        $stmt->close();
    };

    $aggId = $mkTestata($TITLE_AGG);
    $a1990 = $mkAnnata($aggId, 1990);
    $a1991 = $mkAnnata($aggId, 1991);
    $a1992 = $mkAnnata($aggId, 1992);
    $mkFascicolo($a1990, '1', 'posseduto');
    $mkFascicolo($a1991, '1', 'mancante');
    $mkFascicolo($a1992, '1', 'posseduto');
    $mkFascicolo($a1992, '2', 'posseduto');
    $DECL_1990 = 'lac. fasc. 3-4 (mai pervenuti)';
    $DECL_1992 = 'annata rilegata completa';
    $mkDeclared($a1990, $DECL_1990);
    $mkDeclared($a1992, $DECL_1992);

    $emptyId = $mkTestata($TITLE_EMPTY);
    // Declared-only: no fascicolo at all, one declared statement.
    $declId = $mkTestata($TITLE_DECL);
    $aDecl = $mkAnnata($declId, 1975);
    $DECL_ONLY = 'consistenza da verificare a scaffale';
    $mkDeclared($aDecl, $DECL_ONLY);

    // The canonical rule, written out instead of re-derived from either
    // implementation: computed first, ' · ', then the declared statements
    // of the testata joined by '; ' in year order.
    $EXPECTED_AGG = '1990–1992 · lacune: 1 · ' . $DECL_1990 . '; ' . $DECL_1992;

    $summary = $periodicalController->holdingsSummary([$aggId, $emptyId, $declId]);
    check(
        isset($summary[$aggId]) && $summary[$aggId]['consistenza'] === $EXPECTED_AGG,
        "aggregated consistenza appends the declared statements after ' · ' (expected '{$EXPECTED_AGG}', got '"
            . ($summary[$aggId]['consistenza'] ?? 'MISSING') . "')"
    );
    $fromPlugin = EmerotecaPlugin::consistenzaTestata($db, $aggId);
    check(
        $fromPlugin === $EXPECTED_AGG,
        "consistenzaTestata() renders the same string (got '{$fromPlugin}')"
    );
    check(
        $summary[$aggId]['consistenza'] === $fromPlugin,
        'the admin list and the issues page cannot disagree on the consistenza'
    );
    check($summary[$aggId]['n_posseduti'] === 3, 'aggregated n_posseduti = 3');
    check($summary[$aggId]['n_mancanti'] === 1, 'aggregated n_mancanti = 1 (the lacuna)');

    $expectedEmpty = EmerotecaPlugin::consistenzaTestata($db, $emptyId);
    check(
        isset($summary[$emptyId])
            && $summary[$emptyId]['consistenza'] === $expectedEmpty
            && $summary[$emptyId]['consistenza'] === '—'
            && $summary[$emptyId]['n_posseduti'] === 0
            && $summary[$emptyId]['n_mancanti'] === 0,
        "testata with no annate gets the same '—' default as consistenzaTestata()"
    );
    $declFromPlugin = EmerotecaPlugin::consistenzaTestata($db, $declId);
    check(
        isset($summary[$declId])
            && $summary[$declId]['consistenza'] === $DECL_ONLY
            && $declFromPlugin === $DECL_ONLY,
        "with nothing computed the declared statement stands alone on both surfaces (aggregated '"
            . ($summary[$declId]['consistenza'] ?? 'MISSING') . "', plugin '{$declFromPlugin}')"
    );
    check(
        $summary[$declId]['n_posseduti'] === 0 && $summary[$declId]['n_mancanti'] === 0,
        'a declared-only testata still counts zero owned and zero missing issues'
    );
    check($periodicalController->holdingsSummary([]) === [], 'holdingsSummary([]) is an empty map');

    // ── 3b. the export surface follows the very same rule ──────────────
    // consistenzaAnnata() is per-annata (the union catalogue wants one
    // statement per year), so the declared string is appended to THAT
    // year's computed holdings — never substituted for them, which used to
    // drop every really-owned issue of the annata from the exported file.
    $kbart1990 = KbartExporter::consistenzaAnnata($db, $a1990);
    check(
        $kbart1990 === '1 · ' . $DECL_1990,
        "KbartExporter::consistenzaAnnata appends the declared statement to the computed one (expected '1 · {$DECL_1990}', got '{$kbart1990}')"
    );
    $kbart1991 = KbartExporter::consistenzaAnnata($db, $a1991);
    check(
        $kbart1991 === 'lac. 1',
        "an annata with only a gap and no declared statement renders the computed one alone (got '{$kbart1991}')"
    );
    $kbartDecl = KbartExporter::consistenzaAnnata($db, $aDecl);
    check(
        $kbartDecl === $DECL_ONLY,
        "an annata with no issues renders the declared statement alone (got '{$kbartDecl}')"
    );
    $aEmptyYear = $mkAnnata($aggId, 1993);
    $kbartNothing = KbartExporter::consistenzaAnnata($db, $aEmptyYear);
    check(
        $kbartNothing === '—',
        "an annata with neither issues nor a declared statement uses the '—' sentinel (got '{$kbartNothing}')"
    );

    // ── 4. Destructive deletes: staff rejected, admin allowed ─────────
    $delId = $mkTestata($TITLE_DELETE);
    $delAnnata = $mkAnnata($delId, 2020);
    $delFascicolo = $mkFascicolo($delAnnata, '1', 'posseduto');

    // Staff session → both deletes refused, rows untouched.
    $_SESSION = ['user' => ['id' => 999999, 'tipo_utente' => 'staff']];

    $req = $reqFactory->createServerRequest('POST', '/admin/periodicals/issue/' . $delFascicolo . '/delete');
    $resp = $issueController->delete($req, $resFactory->createResponse(), ['id' => (string) $delFascicolo]);
    check($resp->getStatusCode() === 303, 'issue delete with a staff session redirects (303)');
    check($fascicoloExists($delFascicolo), 'issue delete with a staff session does NOT delete the fascicolo');
    check(($_SESSION['error_message'] ?? '') !== '', 'issue delete with a staff session sets the error flash');
    unset($_SESSION['error_message'], $_SESSION['success_message']);

    $req = $reqFactory->createServerRequest('POST', '/admin/periodicals/delete/' . $delId);
    $resp = $periodicalController->delete($req, $resFactory->createResponse(), ['id' => (string) $delId]);
    check($resp->getStatusCode() === 303, 'periodical delete with a staff session redirects (303)');
    check($testataExists($delId), 'periodical delete with a staff session does NOT delete the testata');
    check(($_SESSION['error_message'] ?? '') !== '', 'periodical delete with a staff session sets the error flash');
    unset($_SESSION['error_message'], $_SESSION['success_message']);

    // Missing session entirely → also refused (defense in depth).
    $_SESSION = [];
    $req = $reqFactory->createServerRequest('POST', '/admin/periodicals/delete/' . $delId);
    $resp = $periodicalController->delete($req, $resFactory->createResponse(), ['id' => (string) $delId]);
    check(
        $resp->getStatusCode() === 303 && $testataExists($delId),
        'periodical delete without any session user is refused too'
    );

    // Admin session → deletes proceed (cascade removes annata+fascicolo).
    $_SESSION = ['user' => ['id' => 999999, 'tipo_utente' => 'admin']];
    $req = $reqFactory->createServerRequest('POST', '/admin/periodicals/issue/' . $delFascicolo . '/delete');
    $resp = $issueController->delete($req, $resFactory->createResponse(), ['id' => (string) $delFascicolo]);
    check(
        $resp->getStatusCode() === 303 && !$fascicoloExists($delFascicolo),
        'issue delete with an admin session deletes the fascicolo'
    );
    $req = $reqFactory->createServerRequest('POST', '/admin/periodicals/delete/' . $delId);
    $resp = $periodicalController->delete($req, $resFactory->createResponse(), ['id' => (string) $delId]);
    check(
        $resp->getStatusCode() === 303 && !$testataExists($delId),
        'periodical delete with an admin session deletes the testata'
    );
    $_SESSION = [];
    // More than 1KB of curator-written holdings must agree on both surfaces.
    $longId = (int) $db->query("SELECT id FROM emeroteca_testate WHERE titolo = '" . $db->real_escape_string($TITLE_DECL) . "'")->fetch_assoc()['id'];
    $parts = [];
    for ($year = 1900; $year < 1910; $year++) {
        $part = $year . ': ' . str_repeat('é', 120);
        $parts[] = $part;
        $stmt = $db->prepare("INSERT INTO emeroteca_annate (testata_id, anno, volume, consistenza_dichiarata) VALUES (?, ?, '', ?)");
        $stmt->bind_param('iis', $longId, $year, $part); $stmt->execute(); $stmt->close();
    }
    $db->query('SET SESSION group_concat_max_len = 1024');
    $declared = $periodicalController->holdingsSummary([$longId])[$longId]['consistenza'];
    check(str_starts_with($declared, implode('; ', $parts)), 'declared holdings are complete beyond group_concat_max_len');
    $summary = $periodicalController->holdingsSummary([$longId]);
    check($summary[$longId]['consistenza'] === EmerotecaPlugin::consistenzaTestata($db, $longId),
        'admin and public holdings agree without SQL truncation');
} finally {
    $cleanup();
    $db->close();
}

printf("\nALL %d PASS\n", $TESTNO);
