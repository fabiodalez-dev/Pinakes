<?php
declare(strict_types=1);

/**
 * Behavioral unit tests for the Emeroteca 1.4.0 admin surface (review #140),
 * driven through the REAL controllers against the REAL dev database.
 *
 * Covers:
 *   1. Kardex claims ("sollecito al fornitore"): a single claim moves the
 *      issue to 'reclamato', stamps reclamato_il with the application day and
 *      increments n_reclami; a second claim increments again; an issue that
 *      finally arrives is receivable FROM 'reclamato' and keeps its claim
 *      history;
 *   2. the per-annata bulk claim only touches overdue awaited issues (past
 *      publication date, or undated issues of a closed year) and leaves the
 *      current year alone;
 *   3. barcode_base derivation: saving a testata with a valid ISSN and an
 *      empty barcode derives the 977 EAN-13 server-side;
 *   4. subscription CRUD end to end (create → edit → delete), including the
 *      expiry window used by the testate list;
 *   5. the delete of a subscription is refused for a staff session (inline
 *      admin re-check) and allowed for an admin one;
 *   6. the core entity hook listeners: a REAL PublisherRepository merge makes
 *      the testata follow the surviving publisher instead of going NULL, the
 *      genre listener repoints the same way, and shelf.can_delete vetoes the
 *      deletion of a mensola still used by emeroteca holdings — verified
 *      through the real CollocazioneController::deleteMensola().
 *
 * Conventions follow tests/emeroteca-admin-quality-140.unit.php (env parsing,
 * DB connection, check()/pass() helpers, zz_* fixtures, FK-ordered cleanup in
 * finally, hard failure without a database, exit 1 on any failure).
 *
 * Run:  php tests/emeroteca-admin-140.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\CollocazioneController;
use App\Models\PublisherRepository;
use App\Support\DateHelper;
use App\Support\HookManager;
use App\Support\Hooks;

function ea140_env(string $path): array
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

$env    = ea140_env(__DIR__ . '/../.env');
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
require_once $pluginDir . '/src/Controllers/IssueAdminController.php';
require_once $pluginDir . '/src/Controllers/SubscriptionAdminController.php';

use App\Plugins\Emeroteca\Controllers\IssueAdminController;
use App\Plugins\Emeroteca\Controllers\PeriodicalAdminController;
use App\Plugins\Emeroteca\Controllers\SubscriptionAdminController;
use App\Plugins\Emeroteca\Support\IssnHelper;

// Flash helpers write to $_SESSION; in CLI initialize it explicitly.
// NOTE: no 'id' in the session user — log_modifiche.utente_id is a FK towards
// utenti, so a made-up operator id would make every audit INSERT fail.
$_SESSION = [];

// Real HookManager, marked "runtime-loaded" so the DB-registered plugin hooks
// are NOT pulled in: the test wires exactly the listeners it is testing.
$hookManager = new HookManager($db);
$hookManager->setPluginsLoadedRuntime();
Hooks::init($hookManager);

$plugin = new EmerotecaPlugin($db, $hookManager);

$RUN = 'zz-ea140-' . bin2hex(random_bytes(4));
$TITLE_KARDEX = "zz EmerotecaAdmin Kardex {$RUN}";
$TITLE_SUBS   = "zz EmerotecaAdmin Subs {$RUN}";
$TITLE_HOOKS  = "zz EmerotecaAdmin Hooks {$RUN}";
$TITLE_ISSN   = "zz EmerotecaAdmin Issn {$RUN}";
$TITLE_SHELF  = "zz EmerotecaAdmin Shelf {$RUN}";
$TITLES = [$TITLE_KARDEX, $TITLE_SUBS, $TITLE_HOOKS, $TITLE_ISSN, $TITLE_SHELF];

$createdPublisherIds = [];
$createdGenreIds = [];
$createdScaffaleIds = [];
$auditedIssueIds = [];
$auditedTestataIds = [];
$auditedSubscriptionIds = [];

/** FK-ordered cleanup: audit rows → articoli → fascicoli → annate → testate → core fixtures. */
$cleanup = static function () use (
    $db,
    $TITLES,
    &$createdPublisherIds,
    &$createdGenreIds,
    &$createdScaffaleIds,
    &$auditedIssueIds,
    &$auditedTestataIds,
    &$auditedSubscriptionIds
): void {
    $titles = implode(',', array_map(
        static fn(string $t): string => "'" . $db->real_escape_string($t) . "'",
        $TITLES
    ));

    // Audit rows first: they carry no FK to the emeroteca tables, so they
    // would otherwise survive the fixtures they describe.
    $auditGroups = [
        'emeroteca_fascicoli'   => $auditedIssueIds,
        'emeroteca_testate'     => $auditedTestataIds,
        'emeroteca_abbonamenti' => $auditedSubscriptionIds,
    ];
    foreach ($auditGroups as $tabella => $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            continue;
        }
        @$db->query(
            "DELETE FROM log_modifiche WHERE tabella = '" . $db->real_escape_string($tabella) . "'"
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
    @$db->query("DELETE FROM emeroteca_testate WHERE titolo IN ({$titles})");

    // Core fixtures. Scaffali cascade onto their mensole.
    foreach (['scaffali' => $createdScaffaleIds, 'editori' => $createdPublisherIds, 'generi' => $createdGenreIds] as $table => $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids !== []) {
            @$db->query("DELETE FROM {$table} WHERE id IN (" . implode(',', $ids) . ')');
        }
    }
};

try {
    $result = $plugin->ensureSchema();
    check(($result['failed'] ?? ['x']) === [], 'ensureSchema() reports no failed tables');

    // ── Fixture helpers ───────────────────────────────────────────────
    $mkTestata = static function (string $titolo, ?string $barcode = null) use ($db, &$auditedTestataIds): int {
        $stmt = $db->prepare("INSERT INTO emeroteca_testate (titolo, tipo, barcode_base) VALUES (?, 'rivista', ?)");
        if ($stmt === false) {
            throw new \RuntimeException('testata fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('ss', $titolo, $barcode);
        if (!$stmt->execute()) {
            throw new \RuntimeException('testata fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        $auditedTestataIds[] = $id;
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
    $mkFascicolo = static function (
        int $annataId,
        string $numero,
        string $stato,
        ?string $dataPub = null
    ) use ($db, &$auditedIssueIds): int {
        $stmt = $db->prepare(
            'INSERT INTO emeroteca_fascicoli (annata_id, numero, stato, data_pubblicazione) VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('fascicolo fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('isss', $annataId, $numero, $stato, $dataPub);
        if (!$stmt->execute()) {
            throw new \RuntimeException('fascicolo fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        $auditedIssueIds[] = $id;
        return $id;
    };
    /** @return array<string, mixed> */
    $rowById = static function (string $table, int $id) use ($db): array {
        $res = $db->query("SELECT * FROM {$table} WHERE id = " . $id);
        if (!$res instanceof \mysqli_result) {
            throw new \RuntimeException("row fetch failed on {$table}: " . $db->error);
        }
        $row = $res->fetch_assoc();
        $res->free();
        return is_array($row) ? $row : [];
    };

    $reqFactory = new \Slim\Psr7\Factory\ServerRequestFactory();
    $resFactory = new \Slim\Psr7\Factory\ResponseFactory();
    /** POST request carrying a parsed body, as CsrfMiddleware would hand it over. */
    $post = static function (string $path, array $body) use ($reqFactory) {
        return $reqFactory->createServerRequest('POST', $path)->withParsedBody($body);
    };

    $issues = new IssueAdminController($db, $hookManager);
    $periodicals = new PeriodicalAdminController($db, $hookManager);
    $subscriptions = new SubscriptionAdminController($db, $hookManager);

    $today = DateHelper::today();
    $currentYear = (int) substr($today, 0, 4);

    // ── 1. Single claim ───────────────────────────────────────────────
    $kardexId = $mkTestata($TITLE_KARDEX);
    $pastAnnata = $mkAnnata($kardexId, $currentYear - 3);
    $claimTarget = $mkFascicolo($pastAnnata, '1', 'atteso');

    $_SESSION = ['user' => ['tipo_utente' => 'admin']];
    $resp = $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'claim_issue',
            'fascicolo_id' => (string) $claimTarget,
            'annata_id' => (string) $pastAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    check($resp->getStatusCode() === 303, 'claim_issue redirects (303)');
    $row = $rowById('emeroteca_fascicoli', $claimTarget);
    check(($row['stato'] ?? '') === 'reclamato', "claim sets stato = 'reclamato'");
    check((int) ($row['n_reclami'] ?? 0) === 1, 'claim increments n_reclami to 1');
    check(
        (string) ($row['reclamato_il'] ?? '') === $today,
        "claim stamps reclamato_il with the application day ({$today})"
    );
    check(($_SESSION['success_message'] ?? '') !== '', 'claim reports success to the operator');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 2. Second claim on the SAME issue ─────────────────────────────
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'claim_issue',
            'fascicolo_id' => (string) $claimTarget,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    $row = $rowById('emeroteca_fascicoli', $claimTarget);
    check((int) ($row['n_reclami'] ?? 0) === 2, 'a second claim increments n_reclami to 2');
    check(($row['stato'] ?? '') === 'reclamato', "a re-claimed issue stays 'reclamato'");
    check(($_SESSION['success_message'] ?? '') !== '', 're-claiming an already claimed issue is allowed');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // A 'posseduto' issue is not claimable.
    $ownedIssue = $mkFascicolo($pastAnnata, '90', 'posseduto');
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'claim_issue',
            'fascicolo_id' => (string) $ownedIssue,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    $row = $rowById('emeroteca_fascicoli', $ownedIssue);
    check(
        ($row['stato'] ?? '') === 'posseduto' && (int) ($row['n_reclami'] ?? 0) === 0,
        'an issue already owned cannot be claimed'
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'claiming a non-claimable issue reports an error');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 3. Receive FROM 'reclamato' ───────────────────────────────────
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'receive_issue',
            'fascicolo_id' => (string) $claimTarget,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    $row = $rowById('emeroteca_fascicoli', $claimTarget);
    check(($row['stato'] ?? '') === 'posseduto', "a claimed issue can be received ('reclamato' → 'posseduto')");
    check(
        (int) ($row['n_reclami'] ?? 0) === 2 && (string) ($row['reclamato_il'] ?? '') === $today,
        'receiving keeps the claim history (n_reclami, reclamato_il)'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 4. Bulk claim of the overdue awaited issues ───────────────────
    // In the closed annata: two undated 'atteso' (overdue) + one dated in the
    // future (NOT overdue) + one already 'mancante' (out of scope).
    $bulkA = $mkFascicolo($pastAnnata, '11', 'atteso');
    $bulkB = $mkFascicolo($pastAnnata, '12', 'atteso');
    $futureDated = $mkFascicolo($pastAnnata, '13', 'atteso', ($currentYear + 2) . '-01-01');
    $missing = $mkFascicolo($pastAnnata, '14', 'mancante');

    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'claim_overdue',
            'annata_id' => (string) $pastAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    check(
        ($rowById('emeroteca_fascicoli', $bulkA)['stato'] ?? '') === 'reclamato'
            && ($rowById('emeroteca_fascicoli', $bulkB)['stato'] ?? '') === 'reclamato',
        'bulk claim reclaims the undated awaited issues of a closed year'
    );
    check(
        (int) ($rowById('emeroteca_fascicoli', $bulkA)['n_reclami'] ?? 0) === 1,
        'bulk claim increments n_reclami on each claimed issue'
    );
    check(
        ($rowById('emeroteca_fascicoli', $futureDated)['stato'] ?? '') === 'atteso',
        'bulk claim leaves an awaited issue dated in the future alone'
    );
    check(
        ($rowById('emeroteca_fascicoli', $missing)['stato'] ?? '') === 'mancante',
        "bulk claim never touches issues that are not 'atteso'"
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // The current year is not overdue: nothing to claim there.
    $currentAnnata = $mkAnnata($kardexId, $currentYear);
    $currentIssue = $mkFascicolo($currentAnnata, '1', 'atteso');
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'claim_overdue',
            'annata_id' => (string) $currentAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    check(
        ($rowById('emeroteca_fascicoli', $currentIssue)['stato'] ?? '') === 'atteso',
        'bulk claim does not claim the undated awaited issues of the CURRENT year'
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'a bulk claim with nothing to do says so');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 5. barcode_base derived from the ISSN at save time ────────────
    $resp = $periodicals->createSubmit(
        $post('/admin/periodicals/create', [
            'titolo' => $TITLE_ISSN,
            'issn' => '0378-5955',
            'barcode_base' => '',
            'tipo' => 'rivista',
            'stato_raccolta' => 'attiva',
            'prestabile' => 'consultazione',
        ]),
        $resFactory->createResponse()
    );
    check($resp->getStatusCode() === 303, 'creating a testata redirects (303)');
    $res = $db->query(
        "SELECT * FROM emeroteca_testate WHERE titolo = '" . $db->real_escape_string($TITLE_ISSN) . "' LIMIT 1"
    );
    $issnRow = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
    check(is_array($issnRow), 'the testata was created');
    if (is_array($issnRow)) {
        $auditedTestataIds[] = (int) $issnRow['id'];
        check(
            (string) ($issnRow['barcode_base'] ?? '') === IssnHelper::barcodeBase('0378-5955'),
            "an empty barcode_base is derived from the ISSN ('" . (string) ($issnRow['barcode_base'] ?? '') . "')"
        );
        check(
            (string) ($issnRow['barcode_base'] ?? '') === '9770378595002',
            'the derived barcode is the hand-verified 977 EAN-13 of 0378-5955'
        );
        check((string) ($issnRow['issn'] ?? '') === '0378-5955', 'the ISSN is stored normalized');
        check(
            (string) ($issnRow['prestabile'] ?? '') === 'consultazione',
            'the lending policy is persisted'
        );
    }
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 6. Subscription CRUD ──────────────────────────────────────────
    $subsTestata = $mkTestata($TITLE_SUBS);
    $expiring = (new \DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
    $faraway  = (new \DateTimeImmutable($today))->modify('+300 days')->format('Y-m-d');

    $resp = $subscriptions->createSubmit(
        $post('/admin/periodicals/' . $subsTestata . '/subscriptions', [
            'fornitore' => 'zz Fornitore ' . $RUN,
            'costo' => '120,50',
            'valuta' => 'eur',
            'data_inizio' => $today,
            'data_scadenza' => $expiring,
            'rinnovo_automatico' => '1',
            'attivo' => '1',
            'note' => 'zz nota',
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $subsTestata]
    );
    check($resp->getStatusCode() === 303, 'creating a subscription redirects (303)');
    $res = $db->query('SELECT * FROM emeroteca_abbonamenti WHERE testata_id = ' . $subsTestata . ' ORDER BY id DESC LIMIT 1');
    $subRow = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
    check(is_array($subRow), 'the subscription row exists');
    $subId = is_array($subRow) ? (int) $subRow['id'] : 0;
    $auditedSubscriptionIds[] = $subId;
    check(is_array($subRow) && (float) $subRow['costo'] === 120.50, 'the comma decimal separator is accepted (120,50 → 120.50)');
    check(is_array($subRow) && (string) $subRow['valuta'] === 'EUR', 'the currency code is upper-cased');
    check(is_array($subRow) && (int) $subRow['rinnovo_automatico'] === 1, 'auto-renewal is persisted');

    // Invalid dates are refused without writing anything.
    $before = (int) ($db->query('SELECT COUNT(*) AS c FROM emeroteca_abbonamenti WHERE testata_id = ' . $subsTestata)
        ->fetch_assoc()['c'] ?? 0);
    $subscriptions->createSubmit(
        $post('/admin/periodicals/' . $subsTestata . '/subscriptions', [
            'fornitore' => 'zz Fornitore invalido ' . $RUN,
            'data_inizio' => $faraway,
            'data_scadenza' => $today, // scadenza before inizio
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $subsTestata]
    );
    $after = (int) ($db->query('SELECT COUNT(*) AS c FROM emeroteca_abbonamenti WHERE testata_id = ' . $subsTestata)
        ->fetch_assoc()['c'] ?? 0);
    check($after === $before, 'a subscription whose expiry precedes its start is not written');

    // Expiry window used by the testate list.
    $window = $periodicals->expiringSubscriptions([$subsTestata]);
    check(($window[$subsTestata] ?? 0) === 1, 'a subscription expiring in 10 days is counted as expiring');

    $resp = $subscriptions->editSubmit(
        $post('/admin/periodicals/' . $subsTestata . '/subscriptions/' . $subId . '/edit', [
            'fornitore' => 'zz Fornitore rinnovato ' . $RUN,
            'costo' => '99.00',
            'valuta' => 'CHF',
            'data_inizio' => $today,
            'data_scadenza' => $faraway,
            'attivo' => '1',
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $subsTestata, 'sid' => (string) $subId]
    );
    check($resp->getStatusCode() === 303, 'editing a subscription redirects (303)');
    $subRow = $rowById('emeroteca_abbonamenti', $subId);
    check((string) ($subRow['valuta'] ?? '') === 'CHF', 'the subscription edit is persisted');
    check((int) ($subRow['rinnovo_automatico'] ?? 1) === 0, 'unticking auto-renewal is honoured');
    $window = $periodicals->expiringSubscriptions([$subsTestata]);
    check(($window[$subsTestata] ?? 0) === 0, 'once pushed 300 days out the subscription is no longer expiring');

    // A subscription of ANOTHER testata cannot be edited through this path.
    $otherTestata = $mkTestata($TITLE_HOOKS);
    $resp = $subscriptions->editSubmit(
        $post('/admin/periodicals/' . $otherTestata . '/subscriptions/' . $subId . '/edit', [
            'fornitore' => 'zz Dirottato ' . $RUN,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $otherTestata, 'sid' => (string) $subId]
    );
    check(
        $resp->getStatusCode() === 303
            && (string) ($rowById('emeroteca_abbonamenti', $subId)['fornitore'] ?? '') !== 'zz Dirottato ' . $RUN,
        'a subscription cannot be edited through another testata id'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 7. Subscription delete: staff refused, admin allowed ──────────
    $_SESSION = ['user' => ['tipo_utente' => 'staff']];
    $resp = $subscriptions->delete(
        $post('/admin/periodicals/' . $subsTestata . '/subscriptions/' . $subId . '/delete', []),
        $resFactory->createResponse(),
        ['id' => (string) $subsTestata, 'sid' => (string) $subId]
    );
    check($resp->getStatusCode() === 303, 'subscription delete with a staff session redirects (303)');
    check($rowById('emeroteca_abbonamenti', $subId) !== [], 'subscription delete with a staff session does NOT delete');
    check(($_SESSION['error_message'] ?? '') !== '', 'subscription delete with a staff session sets the error flash');

    $_SESSION = [];
    $subscriptions->delete(
        $post('/admin/periodicals/' . $subsTestata . '/subscriptions/' . $subId . '/delete', []),
        $resFactory->createResponse(),
        ['id' => (string) $subsTestata, 'sid' => (string) $subId]
    );
    check($rowById('emeroteca_abbonamenti', $subId) !== [], 'subscription delete without any session user is refused too');

    $_SESSION = ['user' => ['tipo_utente' => 'admin']];
    $resp = $subscriptions->delete(
        $post('/admin/periodicals/' . $subsTestata . '/subscriptions/' . $subId . '/delete', []),
        $resFactory->createResponse(),
        ['id' => (string) $subsTestata, 'sid' => (string) $subId]
    );
    check(
        $resp->getStatusCode() === 303 && $rowById('emeroteca_abbonamenti', $subId) === [],
        'subscription delete with an admin session deletes the row'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 8. Listener: a real publisher merge repoints the testata ──────
    $mkPublisher = static function (string $suffix) use ($db, $RUN, &$createdPublisherIds): int {
        $nome = "zz_ea140_{$RUN}_{$suffix}";
        $stmt = $db->prepare('INSERT INTO editori (nome) VALUES (?)');
        if ($stmt === false) {
            throw new \RuntimeException('publisher fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('s', $nome);
        if (!$stmt->execute()) {
            throw new \RuntimeException('publisher fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        $createdPublisherIds[] = $id;
        return $id;
    };

    $primary = $mkPublisher('primary');
    $duplicate = $mkPublisher('duplicate');
    $db->query('UPDATE emeroteca_testate SET editore_id = ' . $duplicate . ' WHERE id = ' . $otherTestata);

    $hookManager->clearHooks();
    $hookManager->setPluginsLoadedRuntime();
    Hooks::add('publisher.merging', [$plugin, 'onPublisherMerging']);

    $repo = new PublisherRepository($db);
    $merged = $repo->mergePublishers([$primary, $duplicate], $primary);
    check($merged === $primary, 'the real mergePublishers() kept the requested primary');
    check(
        (int) ($rowById('emeroteca_testate', $otherTestata)['editore_id'] ?? 0) === $primary,
        'publisher.merging listener: the testata FOLLOWS the surviving publisher instead of going NULL'
    );
    $res = $db->query('SELECT id FROM editori WHERE id = ' . $duplicate);
    check($res instanceof \mysqli_result && $res->num_rows === 0, 'the duplicate publisher row is gone after the merge');

    // publisher.deleting: no survivor to follow, the link legitimately clears.
    $hookManager->clearHooks();
    $hookManager->setPluginsLoadedRuntime();
    Hooks::add('publisher.deleting', [$plugin, 'onPublisherDeleting']);
    Hooks::do('publisher.deleting', [$primary]);
    check(
        $rowById('emeroteca_testate', $otherTestata)['editore_id'] === null,
        'publisher.deleting listener: the link is explicitly cleared (and logged) when the publisher goes away'
    );

    // ── 9. Listener: genre merge repoints the same way ────────────────
    $mkGenre = static function (string $suffix) use ($db, $RUN, &$createdGenreIds): int {
        $nome = "zz_ea140_{$RUN}_{$suffix}";
        $stmt = $db->prepare('INSERT INTO generi (nome) VALUES (?)');
        if ($stmt === false) {
            throw new \RuntimeException('genre fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('s', $nome);
        if (!$stmt->execute()) {
            throw new \RuntimeException('genre fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        $createdGenreIds[] = $id;
        return $id;
    };
    $genreTarget = $mkGenre('genre_target');
    $genreSource = $mkGenre('genre_source');
    $db->query('UPDATE emeroteca_testate SET genere_id = ' . $genreSource . ' WHERE id = ' . $otherTestata);

    $hookManager->clearHooks();
    $hookManager->setPluginsLoadedRuntime();
    Hooks::add('genre.merging', [$plugin, 'onGenreMerging']);
    Hooks::do('genre.merging', [$genreTarget, [$genreSource]]);
    check(
        (int) ($rowById('emeroteca_testate', $otherTestata)['genere_id'] ?? 0) === $genreTarget,
        'genre.merging listener: the testata follows the surviving genre'
    );

    // ── 10. shelf.can_delete vetoes a shelf still in use ──────────────
    $stmt = $db->prepare("INSERT INTO scaffali (codice, nome, lettera) VALUES (?, ?, 'Z')");
    $scaffaleCode = mb_substr('zzE' . bin2hex(random_bytes(4)), 0, 20);
    $scaffaleName = 'zz_ea140_scaffale_' . $RUN;
    $stmt->bind_param('ss', $scaffaleCode, $scaffaleName);
    $stmt->execute();
    $scaffaleId = (int) $db->insert_id;
    $stmt->close();
    $createdScaffaleIds[] = $scaffaleId;

    $mkMensola = static function (int $scaffaleId, int $livello) use ($db): int {
        $stmt = $db->prepare('INSERT INTO mensole (scaffale_id, numero_livello) VALUES (?, ?)');
        if ($stmt === false) {
            throw new \RuntimeException('mensola fixture prepare failed: ' . $db->error);
        }
        $stmt->bind_param('ii', $scaffaleId, $livello);
        if (!$stmt->execute()) {
            throw new \RuntimeException('mensola fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    $usedByIssue = $mkMensola($scaffaleId, 1);
    $usedByAnnata = $mkMensola($scaffaleId, 2);
    $freeShelf = $mkMensola($scaffaleId, 3);

    $shelfTestata = $mkTestata($TITLE_SHELF);
    $shelfAnnata = $mkAnnata($shelfTestata, $currentYear - 1);
    $shelfIssue = $mkFascicolo($shelfAnnata, '1', 'posseduto');
    $db->query('UPDATE emeroteca_fascicoli SET collocazione_id = ' . $usedByIssue . ' WHERE id = ' . $shelfIssue);
    $db->query('UPDATE emeroteca_annate SET collocazione_id = ' . $usedByAnnata . ' WHERE id = ' . $shelfAnnata);

    check(
        $plugin->onShelfCanDelete(true, $usedByIssue) === false,
        'shelf.can_delete listener vetoes a mensola holding a fascicolo'
    );
    check(
        $plugin->onShelfCanDelete(true, $usedByAnnata) === false,
        'shelf.can_delete listener vetoes a mensola holding a bound annata'
    );
    check(
        $plugin->onShelfCanDelete(true, $freeShelf) === true,
        'shelf.can_delete listener allows an unused mensola'
    );

    // Same veto through the REAL core controller.
    $hookManager->clearHooks();
    $hookManager->setPluginsLoadedRuntime();
    Hooks::add('shelf.can_delete', [$plugin, 'onShelfCanDelete']);
    Hooks::add('shelf.deleted', [$plugin, 'onShelfDeleted']);

    $_SESSION = ['user' => ['tipo_utente' => 'admin']];
    $collocazione = new CollocazioneController();
    $resp = $collocazione->deleteMensola(
        $post('/admin/placement/mensola/' . $usedByIssue . '/delete', []),
        $resFactory->createResponse(),
        $db,
        $usedByIssue
    );
    check($resp->getStatusCode() === 302, 'deleteMensola redirects (302)');
    check(
        $rowById('mensole', $usedByIssue) !== [],
        'CollocazioneController::deleteMensola is BLOCKED by the plugin veto: the mensola survives'
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'the blocked shelf delete reports an error to the operator');
    unset($_SESSION['error_message'], $_SESSION['success_message']);

    // The unused one really is deletable — the veto is targeted, not blanket.
    $collocazione->deleteMensola(
        $post('/admin/placement/mensola/' . $freeShelf . '/delete', []),
        $resFactory->createResponse(),
        $db,
        $freeShelf
    );
    check(
        $rowById('mensole', $freeShelf) === [],
        'an unused mensola is still deletable with the listener registered'
    );
    $_SESSION = [];

    // ── 11. Audit trail (ActivityLog::recordEntityEvent) ──────────────
    // The event name lives in dati_nuovi._activity.event; matching on the
    // JSON fragment is enough here and keeps the assertion independent of the
    // exact snapshot fields.
    $auditCount = static function (string $tabella, int $recordId, string $event) use ($db): int {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM log_modifiche
              WHERE tabella = ? AND record_id = ? AND dati_nuovi LIKE CONCAT('%\"event\":\"', ?, '\"%')"
        );
        if ($stmt === false) {
            throw new \RuntimeException('audit probe prepare failed: ' . $db->error);
        }
        $stmt->bind_param('sis', $tabella, $recordId, $event);
        $stmt->execute();
        $res = $stmt->get_result();
        $c = ($res instanceof \mysqli_result) ? (int) ($res->fetch_assoc()['c'] ?? 0) : 0;
        $stmt->close();
        return $c;
    };

    check(
        $auditCount('emeroteca_fascicoli', $claimTarget, 'issue.claimed') === 2,
        'both claims of the same issue are recorded as issue.claimed'
    );
    check(
        $auditCount('emeroteca_fascicoli', $bulkA, 'issue.claimed') === 1,
        'the bulk claim records one issue.claimed per affected fascicolo'
    );
    if (is_array($issnRow)) {
        check(
            $auditCount('emeroteca_testate', (int) $issnRow['id'], 'periodical.created') === 1,
            'creating a testata records periodical.created'
        );
    }
    check(
        $auditCount('emeroteca_abbonamenti', $subId, 'subscription.created') === 1,
        'creating a subscription records subscription.created'
    );
    check(
        $auditCount('emeroteca_abbonamenti', $subId, 'subscription.updated') === 1,
        'editing a subscription records subscription.updated'
    );
    check(
        $auditCount('emeroteca_abbonamenti', $subId, 'subscription.deleted') === 1,
        'deleting a subscription records subscription.deleted'
    );
    check(
        $auditCount('emeroteca_abbonamenti', $subId, 'subscription.deleted') === 1
            && (int) ($db->query(
                "SELECT COUNT(*) AS c FROM log_modifiche
                  WHERE tabella = 'emeroteca_abbonamenti' AND record_id = {$subId}
                    AND azione = 'cancellazione'"
            )->fetch_assoc()['c'] ?? 0) === 1,
        "the subscription delete is logged with azione = 'cancellazione'"
    );

    // PDF visibility: the privacy-relevant toggle gets its OWN event, in both
    // directions, and is not emitted when the flag does not change.
    $pdfIssue = $mkFascicolo($pastAnnata, '50', 'posseduto');
    $db->query("UPDATE emeroteca_fascicoli SET pdf_path = 'zz_ea140_fake.pdf' WHERE id = " . $pdfIssue);
    $_SESSION = ['user' => ['tipo_utente' => 'admin']];

    $issues->update(
        $post('/admin/periodicals/issue/' . $pdfIssue, ['numero' => '50', 'stato' => 'posseduto', 'pdf_pubblico' => '1']),
        $resFactory->createResponse(),
        ['id' => (string) $pdfIssue]
    );
    check(
        (int) ($rowById('emeroteca_fascicoli', $pdfIssue)['pdf_pubblico'] ?? 0) === 1,
        'the PDF can be published to the public catalogue'
    );
    check(
        $auditCount('emeroteca_fascicoli', $pdfIssue, 'issue.pdf_visibility') === 1,
        'publishing the PDF records issue.pdf_visibility'
    );

    // Saving again with the same visibility must NOT log a second toggle.
    $issues->update(
        $post('/admin/periodicals/issue/' . $pdfIssue, ['numero' => '50', 'stato' => 'posseduto', 'pdf_pubblico' => '1']),
        $resFactory->createResponse(),
        ['id' => (string) $pdfIssue]
    );
    check(
        $auditCount('emeroteca_fascicoli', $pdfIssue, 'issue.pdf_visibility') === 1,
        'an unchanged PDF visibility does not log a spurious toggle'
    );

    // Withdrawing it logs the reverse toggle.
    $issues->update(
        $post('/admin/periodicals/issue/' . $pdfIssue, ['numero' => '50', 'stato' => 'posseduto']),
        $resFactory->createResponse(),
        ['id' => (string) $pdfIssue]
    );
    check(
        (int) ($rowById('emeroteca_fascicoli', $pdfIssue)['pdf_pubblico'] ?? 1) === 0,
        'the PDF can be withdrawn from the public catalogue'
    );
    check(
        $auditCount('emeroteca_fascicoli', $pdfIssue, 'issue.pdf_visibility') === 2,
        'withdrawing the PDF records the reverse issue.pdf_visibility'
    );
    check(
        $auditCount('emeroteca_fascicoli', $pdfIssue, 'issue.updated') === 3,
        'each issue save also records the ordinary issue.updated diff'
    );

    // The issue delete is audited too.
    $issues->delete(
        $post('/admin/periodicals/issue/' . $pdfIssue . '/delete', []),
        $resFactory->createResponse(),
        ['id' => (string) $pdfIssue]
    );
    check(
        $rowById('emeroteca_fascicoli', $pdfIssue) === [],
        'the issue is deleted by an admin session'
    );
    check(
        $auditCount('emeroteca_fascicoli', $pdfIssue, 'issue.deleted') === 1,
        'deleting an issue records issue.deleted'
    );

    // 1.4.0 fields round-trip through the issue form.
    $fieldsIssue = $mkFascicolo($pastAnnata, '60', 'posseduto');
    $issues->update(
        $post('/admin/periodicals/issue/' . $fieldsIssue, [
            'numero' => '60',
            'stato' => 'scartato',
            'condizione' => 'danneggiato',
            'acquisizione' => 'dono',
            'prezzo' => '4,90',
            'barcode' => '9770378595002',
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $fieldsIssue]
    );
    $row = $rowById('emeroteca_fascicoli', $fieldsIssue);
    check(($row['stato'] ?? '') === 'scartato', "the new 'scartato' state is accepted by the issue form");
    check(($row['condizione'] ?? '') === 'danneggiato', 'condition is stored separately from possession');
    check(($row['acquisizione'] ?? '') === 'dono', 'the acquisition channel is stored');
    check((float) ($row['prezzo'] ?? 0) === 4.90, 'the issue price accepts a comma decimal separator');
    check(($row['barcode'] ?? '') === '9770378595002', 'the issue barcode is stored');

    // An invalid condition is refused and changes nothing.
    $issues->update(
        $post('/admin/periodicals/issue/' . $fieldsIssue, [
            'numero' => '60',
            'stato' => 'posseduto',
            'condizione' => 'inventata',
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $fieldsIssue]
    );
    check(
        ($rowById('emeroteca_fascicoli', $fieldsIssue)['stato'] ?? '') === 'scartato',
        'an invalid condition aborts the whole issue save (nothing is written)'
    );

    // ── 12. An empty barcode is NOT back-filled with the testata's base ──
    // The base is shared by every issue of a title and the column has a
    // non-UNIQUE key: copying it here would give every fascicolo the same
    // code, and a scan at the desk would resolve to whichever issue comes
    // first — the arrival gets recorded on the wrong issue while the real one
    // stays 'atteso' and goes into the supplier reminder. The base is already
    // the scan lookup's third step and the label renderer's fallback, so an
    // empty barcode still resolves to the title.
    $TESTATA_BASE = '9770028083602';
    $inheritTestata = $mkTestata($TITLE_KARDEX, $TESTATA_BASE);
    $inheritAnnata = $mkAnnata($inheritTestata, $currentYear - 1);
    $inheritIssue = $mkFascicolo($inheritAnnata, '1', 'posseduto');
    $issues->update(
        $post('/admin/periodicals/issue/' . $inheritIssue, ['numero' => '1', 'stato' => 'posseduto', 'barcode' => '']),
        $resFactory->createResponse(),
        ['id' => (string) $inheritIssue]
    );
    $inheritRow = $rowById('emeroteca_fascicoli', $inheritIssue);
    check(
        array_key_exists('barcode', $inheritRow) && $inheritRow['barcode'] === null,
        "an empty issue barcode stays empty: it does NOT inherit the testata's base"
    );

    // …and the quick-add form does not copy it either.
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $inheritTestata . '/issues', [
            'action' => 'add_fascicolo',
            'annata_id' => (string) $inheritAnnata,
            'numero' => '2',
            'stato' => 'atteso',
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $inheritTestata]
    );
    $res = $db->query(
        "SELECT id, barcode FROM emeroteca_fascicoli WHERE annata_id = {$inheritAnnata} AND numero = '2' LIMIT 1"
    );
    $quickAdded = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
    check(is_array($quickAdded), 'the quick-add form created the fascicolo');
    if (is_array($quickAdded)) {
        $auditedIssueIds[] = (int) $quickAdded['id'];
        check(
            $quickAdded['barcode'] === null,
            "a fascicolo created from the quick form does NOT inherit the testata's base barcode"
        );
    }

    // Two issues of the same title must therefore not collide on one code.
    $res = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli
          WHERE annata_id = {$inheritAnnata} AND barcode = '{$TESTATA_BASE}'"
    );
    check(
        ($res instanceof \mysqli_result ? (int) ($res->fetch_assoc()['c'] ?? -1) : -1) === 0,
        'no fascicolo carries the shared testata base as its own barcode'
    );

    // A barcode typed by hand is still stored verbatim (EAN + add-on).
    $issues->update(
        $post('/admin/periodicals/issue/' . $inheritIssue, [
            'numero' => '1',
            'stato' => 'posseduto',
            'barcode' => $TESTATA_BASE . '01234',
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $inheritIssue]
    );
    check(
        ($rowById('emeroteca_fascicoli', $inheritIssue)['barcode'] ?? '') === $TESTATA_BASE . '01234',
        'a hand-typed full EAN with add-on is still stored on the fascicolo'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 13. stato is validated, never coerced ─────────────────────────
    // It is the column that drives holdings, consistency, public catalogue
    // and claims: an unknown value must abort the save like condizione does,
    // and an absent one must leave the issue where it is. Sliding to
    // 'posseduto' would put a missing issue back on the shelf on paper.
    $statoIssue = $mkFascicolo($inheritAnnata, '70', 'mancante');
    $issues->update(
        $post('/admin/periodicals/issue/' . $statoIssue, ['numero' => '70', 'stato' => 'inventato']),
        $resFactory->createResponse(),
        ['id' => (string) $statoIssue]
    );
    check(
        ($rowById('emeroteca_fascicoli', $statoIssue)['stato'] ?? '') === 'mancante',
        "an invalid stato aborts the issue save and does NOT write 'posseduto'"
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'an invalid stato reports an explicit error');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // The same value posted with no stato at all: the state must survive an
    // edit of an unrelated field.
    $issues->update(
        $post('/admin/periodicals/issue/' . $statoIssue, ['numero' => '70', 'note' => 'zz nota scorrelata']),
        $resFactory->createResponse(),
        ['id' => (string) $statoIssue]
    );
    $row = $rowById('emeroteca_fascicoli', $statoIssue);
    check(
        ($row['stato'] ?? '') === 'mancante',
        "a save with no stato in the POST keeps the current state (no slide to 'posseduto')"
    );
    check(($row['note'] ?? '') === 'zz nota scorrelata', 'that save did go through for the field it did change');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // Same rule on the create paths: nothing is written.
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $inheritTestata . '/issues', [
            'action' => 'add_fascicolo',
            'annata_id' => (string) $inheritAnnata,
            'numero' => '71',
            'stato' => 'inventato',
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $inheritTestata]
    );
    $res = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli WHERE annata_id = {$inheritAnnata} AND numero = '71'"
    );
    check(
        ($res instanceof \mysqli_result ? (int) ($res->fetch_assoc()['c'] ?? -1) : -1) === 0,
        'an invalid stato in the quick-add form creates nothing'
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'the quick-add form reports the invalid stato');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    $bulkResp = $issues->bulkCreate(
        $post('/admin/periodicals/' . $inheritTestata . '/issues/bulk', [
            'anno' => (string) ($currentYear - 1),
            'numero_da' => '200',
            'numero_a' => '202',
            'stato' => 'inventato',
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $inheritTestata]
    );
    $res = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli
          WHERE annata_id = {$inheritAnnata} AND numero IN ('200','201','202')"
    );
    check(
        $bulkResp->getStatusCode() === 303
            && ($res instanceof \mysqli_result ? (int) ($res->fetch_assoc()['c'] ?? -1) : -1) === 0,
        'an invalid stato in the bulk-create form creates no series at all'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 14. The bulk claim does not overwrite a concurrent reception ──
    // The real race, reproduced deterministically on ONE thread: the test
    // connection opens a REPEATABLE READ transaction and pins its read view,
    // a SECOND connection then receives one of the issues at the desk and
    // commits. Inside the transaction the controller's SELECT still sees the
    // issue as 'atteso' (consistent read) while its UPDATE sees the committed
    // row (current read) — exactly the window between the two statements.
    // Without 'AND stato = ...' in the UPDATE, the received issue is dragged
    // back to 'reclamato': out of the holdings, into the supplier reminder.
    $raceAnnata = $mkAnnata($kardexId, $currentYear - 5);
    $raceStays = $mkFascicolo($raceAnnata, '1', 'atteso');   // claimed for real
    $raceReceived = $mkFascicolo($raceAnnata, '2', 'atteso'); // received mid-flight

    $conn2 = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? @new mysqli(null, $user, $pass, $name, 0, $socket)
        : @new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
    check($conn2 instanceof mysqli && $conn2->connect_errno === 0, 'a second connection is available to stage the race');

    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->begin_transaction();
    // Pin the read view on this table BEFORE the other session commits.
    $db->query('SELECT id FROM emeroteca_fascicoli WHERE annata_id = ' . $raceAnnata);
    $conn2->query("UPDATE emeroteca_fascicoli SET stato = 'posseduto' WHERE id = " . $raceReceived);
    $conn2->close();

    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'claim_overdue',
            'annata_id' => (string) $raceAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    $raceMessage = (string) ($_SESSION['success_message'] ?? '');
    $db->commit();

    check(
        ($rowById('emeroteca_fascicoli', $raceReceived)['stato'] ?? '') === 'posseduto',
        'the bulk claim does NOT reclaim an issue received between its SELECT and its UPDATE'
    );
    check(
        (int) ($rowById('emeroteca_fascicoli', $raceReceived)['n_reclami'] ?? -1) === 0,
        'that issue keeps n_reclami = 0: no reminder was counted for a copy already on the shelf'
    );
    check(
        ($rowById('emeroteca_fascicoli', $raceStays)['stato'] ?? '') === 'reclamato',
        'the issue that really was still awaited is claimed'
    );
    check(
        preg_match('/(?<!\d)1(?!\d)/', $raceMessage) === 1 && preg_match('/(?<!\d)2(?!\d)/', $raceMessage) !== 1,
        "the operator is told how many rows CHANGED (1), not how many the SELECT had picked (2): '{$raceMessage}'"
    );
    check(
        $auditCount('emeroteca_fascicoli', $raceReceived, 'issue.claimed') === 0,
        'no issue.claimed is logged for the issue the bulk claim skipped'
    );
    check(
        $auditCount('emeroteca_fascicoli', $raceStays, 'issue.claimed') === 1,
        'exactly one issue.claimed is logged for the issue the bulk claim did move'
    );

    // The bulk audit is now as rich as the per-issue one: the claim counter
    // on both sides, not just "stato: atteso → reclamato".
    $res = $db->query(
        "SELECT dati_precedenti, dati_nuovi FROM log_modifiche
          WHERE tabella = 'emeroteca_fascicoli' AND record_id = {$raceStays}
            AND dati_nuovi LIKE '%\"event\":\"issue.claimed\"%' ORDER BY id DESC LIMIT 1"
    );
    $bulkAudit = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
    check(
        is_array($bulkAudit)
            && str_contains((string) $bulkAudit['dati_precedenti'], 'n_reclami')
            && str_contains((string) $bulkAudit['dati_nuovi'], 'n_reclami')
            && str_contains((string) $bulkAudit['dati_nuovi'], 'numero'),
        'the bulk claim audits numero and the claim counter on both sides, like the single claim'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // The UPDATE statements themselves must carry the state guard: a future
    // refactor that drops it puts the race straight back.
    $controllerSrc = (string) @file_get_contents(
        __DIR__ . '/../storage/plugins/emeroteca/src/Controllers/IssueAdminController.php'
    );
    check(
        preg_match(
            "/UPDATE emeroteca_fascicoli\s+SET stato = 'reclamato'.*?WHERE id IN \(\{\\\$placeholders\}\) AND stato = 'atteso'/s",
            $controllerSrc
        ) === 1,
        "the bulk claim UPDATE repeats the 'atteso' condition in its WHERE"
    );
    check(
        preg_match(
            "/UPDATE emeroteca_fascicoli\s+SET stato = 'reclamato'.*?WHERE id = \? AND stato IN \(\{\\\$claimable\}\)/s",
            $controllerSrc
        ) === 1,
        'the single claim UPDATE repeats the claimable-state condition in its WHERE'
    );

    // ── 15. mark_missing / claim_overdue are admin-only ───────────────
    // Both rewrite a whole annata in one statement with no undo: 'mancante'
    // has no inverse action anywhere, and n_reclami is never decremented.
    // AdminAuthMiddleware also admits staff, hence the inline re-check.
    $staffAnnata = $mkAnnata($kardexId, $currentYear - 6);
    $staffAwaited = $mkFascicolo($staffAnnata, '1', 'atteso');

    $_SESSION = ['user' => ['tipo_utente' => 'staff']];
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'mark_missing',
            'annata_id' => (string) $staffAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    check(
        ($rowById('emeroteca_fascicoli', $staffAwaited)['stato'] ?? '') === 'atteso',
        'mark_missing with a staff session changes nothing'
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'mark_missing with a staff session sets the error flash');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'claim_overdue',
            'annata_id' => (string) $staffAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    $row = $rowById('emeroteca_fascicoli', $staffAwaited);
    check(
        ($row['stato'] ?? '') === 'atteso' && (int) ($row['n_reclami'] ?? -1) === 0,
        'claim_overdue with a staff session claims nothing'
    );
    check(($_SESSION['error_message'] ?? '') !== '', 'claim_overdue with a staff session sets the error flash');
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    $_SESSION = [];
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'mark_missing',
            'annata_id' => (string) $staffAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    check(
        ($rowById('emeroteca_fascicoli', $staffAwaited)['stato'] ?? '') === 'atteso',
        'mark_missing without any session user is refused too'
    );

    // The same two actions DO work for an admin — the guard is targeted.
    $_SESSION = ['user' => ['tipo_utente' => 'admin']];
    $issues->manageSubmit(
        $post('/admin/periodicals/' . $kardexId . '/issues', [
            'action' => 'mark_missing',
            'annata_id' => (string) $staffAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $kardexId]
    );
    check(
        ($rowById('emeroteca_fascicoli', $staffAwaited)['stato'] ?? '') === 'mancante',
        'mark_missing with an admin session does mark the annata missing'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // ── 16. Current-year bulk claim, driven by the publication schedule ──
    // Kardex issues carry no date, so before #140 the bulk claim could never
    // reach the running subscription year — the very year reminders exist
    // for. An undated issue of the current year is now overdue once its slot
    // in the schedule closed more than 30 days ago; the deadline below is
    // computed independently of the controller.
    $schedTestata = $mkTestata($TITLE_KARDEX);
    $db->query("UPDATE emeroteca_testate SET periodicita = 'mensile' WHERE id = " . $schedTestata);
    $schedAnnata = $mkAnnata($schedTestata, $currentYear);
    $schedFirst = $mkFascicolo($schedAnnata, '1', 'atteso');   // slot closes 31 Jan
    $schedLast  = $mkFascicolo($schedAnnata, '12', 'atteso');  // slot closes 31 Dec
    $schedOdd   = $mkFascicolo($schedAnnata, '4-5', 'atteso'); // double issue: no schedule

    $firstDeadline = (new \DateTimeImmutable($currentYear . '-01-31'))->modify('+30 days')->format('Y-m-d');
    $firstIsLate = $firstDeadline < $today;

    $issues->manageSubmit(
        $post('/admin/periodicals/' . $schedTestata . '/issues', [
            'action' => 'claim_overdue',
            'annata_id' => (string) $schedAnnata,
        ]),
        $resFactory->createResponse(),
        ['id' => (string) $schedTestata]
    );
    check(
        ($rowById('emeroteca_fascicoli', $schedFirst)['stato'] ?? '') === ($firstIsLate ? 'reclamato' : 'atteso'),
        "n. 1 of a monthly is claimable in the current year once 31 Jan + 30 days has passed "
        . "(deadline {$firstDeadline}, today {$today})"
    );
    check(
        ($rowById('emeroteca_fascicoli', $schedLast)['stato'] ?? '') === 'atteso',
        "n. 12 of a monthly is never claimed within its own year (its slot closes on 31 Dec)"
    );
    check(
        ($rowById('emeroteca_fascicoli', $schedOdd)['stato'] ?? '') === 'atteso',
        'a double issue ("4-5") has no schedule slot and is left to the per-issue claim'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    // Without a periodicita there is no schedule at all: the current year is
    // untouched even for issues numbered 1 (already covered above for the
    // 'irregolare'-equivalent NULL case, asserted here on the claim counter).
    check(
        (int) ($rowById('emeroteca_fascicoli', $currentIssue)['n_reclami'] ?? -1) === 0,
        'a current-year issue of a testata with no periodicita is still never bulk-claimed'
    );

    // ── 17. The ordinary save no longer looks like it wipes the claims ──
    // The "before" snapshot carries reclamato_il/n_reclami; when the "after"
    // omitted them, every save read in the audit as if the claim history had
    // been cleared.
    $historyIssue = $mkFascicolo($inheritAnnata, '80', 'reclamato');
    $db->query(
        "UPDATE emeroteca_fascicoli SET n_reclami = 3, reclamato_il = '{$today}' WHERE id = " . $historyIssue
    );
    $issues->update(
        $post('/admin/periodicals/issue/' . $historyIssue, ['numero' => '80', 'stato' => 'reclamato']),
        $resFactory->createResponse(),
        ['id' => (string) $historyIssue]
    );
    $res = $db->query(
        "SELECT dati_precedenti, dati_nuovi FROM log_modifiche
          WHERE tabella = 'emeroteca_fascicoli' AND record_id = {$historyIssue}
            AND dati_nuovi LIKE '%\"event\":\"issue.updated\"%' ORDER BY id DESC LIMIT 1"
    );
    $saveAudit = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
    check(
        is_array($saveAudit) && str_contains((string) $saveAudit['dati_precedenti'], 'n_reclami'),
        'fixture: the audit "before" of an ordinary save carries the claim history'
    );
    check(
        is_array($saveAudit)
            && str_contains((string) $saveAudit['dati_nuovi'], '"n_reclami":3')
            && str_contains((string) $saveAudit['dati_nuovi'], '"reclamato_il":"' . $today . '"'),
        'the audit "after" carries it unchanged, so the save does not read as a wipe'
    );
    check(
        (int) ($rowById('emeroteca_fascicoli', $historyIssue)['n_reclami'] ?? -1) === 3,
        'and the claim history really is untouched on the row'
    );
    unset($_SESSION['success_message'], $_SESSION['error_message']);

    $_SESSION = [];
} catch (\Throwable $e) {
    $FAILED++;
    fwrite(STDERR, "FAIL: unexpected exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
} finally {
    $cleanup();
    $db->close();
}

if ($FAILED > 0) {
    printf("\n%d of %d checks FAILED\n", $FAILED, $TESTNO);
    exit(1);
}
printf("\nALL %d PASS\n", $TESTNO);
