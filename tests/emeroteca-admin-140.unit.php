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

    // An empty barcode falls back to the testata's base.
    $inheritTestata = $mkTestata($TITLE_KARDEX, '9770028083602');
    $inheritAnnata = $mkAnnata($inheritTestata, $currentYear - 1);
    $inheritIssue = $mkFascicolo($inheritAnnata, '1', 'posseduto');
    $issues->update(
        $post('/admin/periodicals/issue/' . $inheritIssue, ['numero' => '1', 'stato' => 'posseduto', 'barcode' => '']),
        $resFactory->createResponse(),
        ['id' => (string) $inheritIssue]
    );
    check(
        ($rowById('emeroteca_fascicoli', $inheritIssue)['barcode'] ?? '') === '9770028083602',
        "an empty issue barcode inherits the testata's base barcode"
    );
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
