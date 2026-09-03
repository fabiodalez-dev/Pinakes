<?php
declare(strict_types=1);

/**
 * Behavioral unit tests for the Emeroteca plugin against the REAL dev DB.
 *
 * Covers (with rigorous cleanup — DDL breaks transactions, so teardown is
 * explicit and FK-ordered):
 *   1. 'emeroteca' is picked up by the dynamic schema guards
 *      (BundledPlugins::LIST + plugin_schema_declared_tables_in_directory);
 *   2. ensureSchema() creates the 4 tables, reports no failures and is
 *      idempotent (second call = clean no-op);
 *   3. expectedTables() is EXACTLY the set ensureSchema() creates;
 *   4. consistenzaTestata() on a known holding set (1990-1992 with one
 *      'mancante') renders the expected string;
 *   5. Kardex "genera attesi" on a monthly testata creates 12 fascicoli
 *      stato='atteso' through the REAL controller action and does NOT
 *      duplicate on a second run;
 *   6. "marca attesi come mancanti" converts ONLY the 'atteso' issues.
 *
 * Conventions follow tests/plugin-schema-guard.unit.php (env parsing, DB
 * connection, check()/pass() helpers).
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/helpers/plugin-schema-source.php';

function emu_env(string $path): array
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

$env    = emu_env(__DIR__ . '/../.env');
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
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
if (!isset($db) || $db->connect_errno !== 0) {
    $error = isset($db) ? $db->connect_error : 'connection failed';
    echo "SKIP: database not reachable ({$error})\n";
    exit(0);
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

$tableExists = function (string $t) use ($db): bool {
    return (bool) $db->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($t) . "'"
    )->num_rows;
};

$pluginDir = __DIR__ . '/../storage/plugins/emeroteca';
require_once $pluginDir . '/EmerotecaPlugin.php';
require_once $pluginDir . '/src/Controllers/IssueAdminController.php';
require_once $pluginDir . '/src/Controllers/PeriodicalAdminController.php';

// Flash helpers in AbstractAdminController write to $_SESSION; in CLI the
// superglobal is not started — initialize it so the writes are harmless.
$_SESSION = [];

$hm = new \App\Support\HookManager($db);
$plugin = new EmerotecaPlugin($db, $hm);

// Unique fixture marker so cleanup never touches pre-existing data.
$RUN = 'emu-' . bin2hex(random_bytes(4));
$TITLE_CONSISTENZA = "EmerotecaUnit Consistenza {$RUN}";
$TITLE_KARDEX      = "EmerotecaUnit Kardex {$RUN}";
$PLUGIN_FIXTURE    = "emeroteca-unit-{$RUN}";

/**
 * FK-ordered cleanup of every fixture testata (articoli → fascicoli →
 * annate → testate). Safe when the tables do not exist yet.
 */
$cleanup = static function () use ($db, $TITLE_CONSISTENZA, $TITLE_KARDEX, $PLUGIN_FIXTURE): void {
    $titles = "'" . $db->real_escape_string($TITLE_CONSISTENZA) . "','" . $db->real_escape_string($TITLE_KARDEX) . "'";
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
    @$db->query("DELETE FROM plugins WHERE name = '" . $db->real_escape_string($PLUGIN_FIXTURE) . "'");
};

try {
    // ── 1. Dynamic guard pickup ───────────────────────────────────────
    check(
        in_array('emeroteca', \App\Support\BundledPlugins::LIST, true),
        "'emeroteca' is in BundledPlugins::LIST → collected automatically by the dynamic schema guards"
    );

    $installerSource = (string) file_get_contents(__DIR__ . '/../installer/classes/Installer.php');
    $installerSummary = (string) file_get_contents(__DIR__ . '/../installer/steps/step7.php');
    check(
        str_contains($installerSource, "\$installPlugin('emeroteca', [], false)")
            && str_contains($installerSource, "'installed_inactive'"),
        'fresh installer registers emeroteca exactly once as an inactive optional plugin'
    );
    check(
        str_contains($installerSummary, "\$p['status'] === 'installed_inactive'")
            && str_contains($installerSummary, 'Plugin opzionali installati (disattivati):'),
        'installer completion summary counts inactive optional plugins separately'
    );

    $ddlTables = plugin_schema_declared_tables_in_directory($pluginDir);
    check($ddlTables !== [], 'plugin-schema-source helper detects the CREATE TABLE declarations of the plugin');

    // ── 2. ensureSchema creates the 4 tables and is idempotent ────────
    $result = $plugin->ensureSchema();
    check(($result['failed'] ?? ['x']) === [], 'ensureSchema() reports no failed tables ('
        . implode(',', $result['failed'] ?? []) . ')');

    $expected = $plugin->expectedTables();
    check(is_array($expected) && count($expected) === 4, 'expectedTables() declares exactly 4 tables');

    $sortedExpected = array_values(array_unique(array_map('strval', $expected)));
    sort($sortedExpected);
    check(
        $sortedExpected === $ddlTables,
        'expectedTables() is EXACTLY the set of unconditional CREATE TABLE in the plugin sources ('
        . implode(',', $sortedExpected) . ')'
    );

    foreach ($expected as $t) {
        check($tableExists((string) $t), "table {$t} exists after ensureSchema()");
    }

    $result2 = $plugin->ensureSchema();
    check(($result2['failed'] ?? ['x']) === [], 'second ensureSchema() is a clean no-op (idempotent)');

    foreach ($plugin->expectedColumns() as $column) {
        $table = $db->real_escape_string((string) $column['table']);
        $name = $db->real_escape_string((string) $column['column']);
        $probe = $db->query(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$name}'"
        );
        check(
            $probe instanceof \mysqli_result && $probe->num_rows === 1,
            "migration sentinel {$table}.{$name} exists"
        );
    }

    foreach ($plugin->expectedForeignKeys() as $foreignKey) {
        $table = $db->real_escape_string((string) $foreignKey['table']);
        $column = $db->real_escape_string((string) $foreignKey['column']);
        $referenced = $db->real_escape_string((string) $foreignKey['ref_table']);
        $probe = $db->query(
            "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
                AND COLUMN_NAME = '{$column}' AND REFERENCED_TABLE_NAME = '{$referenced}'"
        );
        check(
            $probe instanceof \mysqli_result && $probe->num_rows >= 1,
            "declared FK {$table}.{$column} → {$referenced} exists"
        );
    }

    $indexProbe = $db->query(
        "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emeroteca_fascicoli'
            AND INDEX_NAME = 'uq_emeroteca_fascicolo_numero' AND NON_UNIQUE = 0"
    );
    $indexColumns = $indexProbe instanceof \mysqli_result
        ? (string) ($indexProbe->fetch_assoc()['columns_list'] ?? '')
        : '';
    check(
        $indexColumns === 'annata_id,numero',
        'schema migration installs the unique issue-number index'
    );

    // Exercise the lifecycle contract without changing the real plugin row.
    $fixtureName = $PLUGIN_FIXTURE;
    $fixtureDisplay = 'Emeroteca unit lifecycle';
    $fixtureVersion = '1.2.0';
    $fixturePath = 'emeroteca';
    $fixtureMain = 'wrapper.php';
    $insPlugin = $db->prepare(
        'INSERT INTO plugins (name, display_name, version, path, main_file, is_active)
         VALUES (?, ?, ?, ?, ?, 0)'
    );
    check($insPlugin !== false, 'lifecycle fixture plugin insert prepared');
    $insPlugin->bind_param('sssss', $fixtureName, $fixtureDisplay, $fixtureVersion, $fixturePath, $fixtureMain);
    check($insPlugin->execute(), 'lifecycle fixture plugin inserted');
    $fixturePluginId = (int) $db->insert_id;
    $insPlugin->close();
    $plugin->setPluginId($fixturePluginId);
    $plugin->onActivate();
    $plugin->onActivate();
    $hookRows = $db->query(
        "SELECT hook_name, callback_method FROM plugin_hooks
          WHERE plugin_id = {$fixturePluginId} ORDER BY hook_name"
    );
    check(
        $hookRows instanceof \mysqli_result && $hookRows->num_rows === 2,
        'activation registers exactly two hooks and remains idempotent'
    );
    $plugin->onDeactivate();
    $hookRowsAfter = $db->query("SELECT 1 FROM plugin_hooks WHERE plugin_id = {$fixturePluginId}");
    check(
        $hookRowsAfter instanceof \mysqli_result && $hookRowsAfter->num_rows === 0,
        'deactivation removes every plugin hook'
    );
    $db->query("DELETE FROM plugins WHERE id = {$fixturePluginId}");

    // ── 3. consistenzaTestata on a known holding set ──────────────────
    // Anni 1990-1992, fascicoli: 1990 posseduto, 1991 mancante, 1992
    // posseduto → "1990–1992 · lacune: 1".
    $stmt = $db->prepare('INSERT INTO emeroteca_testate (titolo, tipo, periodicita) VALUES (?, ?, ?)');
    check($stmt !== false, 'consistenza fixture: testata insert prepared');
    $tipo = 'rivista';
    $per  = 'annuale';
    $stmt->bind_param('sss', $TITLE_CONSISTENZA, $tipo, $per);
    check($stmt->execute(), 'consistenza fixture: testata inserted');
    $consTestataId = (int) $db->insert_id;
    $stmt->close();

    $holdings = [
        [1990, 'posseduto'],
        [1991, 'mancante'],
        [1992, 'posseduto'],
    ];
    foreach ($holdings as [$anno, $stato]) {
        $ins = $db->prepare("INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, ?, '')");
        check($ins !== false, "consistenza fixture: annata {$anno} prepared");
        $ins->bind_param('ii', $consTestataId, $anno);
        check($ins->execute(), "consistenza fixture: annata {$anno} inserted");
        $annataId = (int) $db->insert_id;
        $ins->close();

        $insF = $db->prepare("INSERT INTO emeroteca_fascicoli (annata_id, numero, stato) VALUES (?, '1', ?)");
        check($insF !== false, "consistenza fixture: fascicolo {$anno} prepared");
        $insF->bind_param('is', $annataId, $stato);
        check($insF->execute(), "consistenza fixture: fascicolo {$anno} ({$stato}) inserted");
        $insF->close();
    }

    $lacuneLabel = function_exists('__') ? __('lacune') : 'lacune';
    $expectedStr = '1990–1992 · ' . $lacuneLabel . ': 1';
    $got = EmerotecaPlugin::consistenzaTestata($db, $consTestataId);
    check(
        $got === $expectedStr,
        "consistenzaTestata renders '{$expectedStr}' for 1990-1992 with one gap (got '{$got}')"
    );

    // Empty testata → em dash.
    $stmt = $db->prepare('INSERT INTO emeroteca_testate (titolo, tipo) VALUES (?, ?)');
    check($stmt !== false, 'kardex fixture: testata insert prepared');
    $stmt->bind_param('ss', $TITLE_KARDEX, $tipo);
    check($stmt->execute(), 'kardex fixture: testata inserted');
    $kardexTestataId = (int) $db->insert_id;
    $stmt->close();
    $gotEmpty = EmerotecaPlugin::consistenzaTestata($db, $kardexTestataId);
    check($gotEmpty === '—', "consistenzaTestata renders '—' with no holdings (got '{$gotEmpty}')");

    $linkTitles = $db->prepare(
        'UPDATE emeroteca_testate SET testata_precedente_id = ? WHERE id = ?'
    );
    check($linkTitles !== false, 'title-cycle fixture update prepared');
    $linkTitles->bind_param('ii', $kardexTestataId, $consTestataId);
    check($linkTitles->execute(), 'title-cycle fixture linked A → B');
    $linkTitles->close();
    $periodicalController = new \App\Plugins\Emeroteca\Controllers\PeriodicalAdminController($db, $hm);
    $cycleProbe = new \ReflectionMethod($periodicalController, 'wouldCreateTitleCycle');
    check(
        $cycleProbe->invoke($periodicalController, $kardexTestataId, $consTestataId) === true,
        'title predecessor validation rejects a two-title cycle'
    );
    check(
        $cycleProbe->invoke($periodicalController, $consTestataId, $consTestataId) === true,
        'title predecessor validation rejects a direct self-cycle'
    );

    // ── 4. Kardex "genera attesi" (mensile → 12 attesi, no dup) ───────
    // Set the periodicita to 'mensile' and drive the REAL controller
    // action (kardexGenerate) with a PSR-7 request.
    $upd = $db->prepare("UPDATE emeroteca_testate SET periodicita = 'mensile' WHERE id = ?");
    check($upd !== false, 'kardex fixture: periodicita update prepared');
    $upd->bind_param('i', $kardexTestataId);
    check($upd->execute(), 'kardex fixture: periodicita set to mensile');
    $upd->close();

    $controller = new \App\Plugins\Emeroteca\Controllers\IssueAdminController($db, $hm);
    $reqFactory = new \Slim\Psr7\Factory\ServerRequestFactory();
    $resFactory = new \Slim\Psr7\Factory\ResponseFactory();

    $kardexRun = static function () use ($controller, $reqFactory, $resFactory, $kardexTestataId) {
        $request = $reqFactory
            ->createServerRequest('POST', '/admin/periodicals/' . $kardexTestataId . '/kardex/generate')
            ->withParsedBody(['anno' => '2024']);
        return $controller->kardexGenerate($request, $resFactory->createResponse(), ['id' => (string) $kardexTestataId]);
    };

    $resp1 = $kardexRun();
    check($resp1->getStatusCode() === 303, 'kardexGenerate redirects (303) after the first run');

    $countByStato = static function (int $testataId, string $stato) use ($db): int {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS c FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON f.annata_id = a.id
              WHERE a.testata_id = ? AND f.stato = ?'
        );
        if ($stmt === false) {
            return -1;
        }
        $stmt->bind_param('is', $testataId, $stato);
        if (!$stmt->execute()) {
            $stmt->close();
            return -1;
        }
        $res = $stmt->get_result();
        $c = $res instanceof \mysqli_result ? (int) ($res->fetch_assoc()['c'] ?? -1) : -1;
        $stmt->close();
        return $c;
    };

    check(
        $countByStato($kardexTestataId, 'atteso') === 12,
        "kardex 'genera attesi' on a monthly testata creates 12 fascicoli stato='atteso'"
    );

    $resp2 = $kardexRun();
    check($resp2->getStatusCode() === 303, 'kardexGenerate redirects (303) after the second run');
    check(
        $countByStato($kardexTestataId, 'atteso') === 12,
        'second kardex run does NOT duplicate: still exactly 12 attesi'
    );

    $totalRow = $db->query(
        'SELECT COUNT(*) AS c FROM emeroteca_fascicoli f
           JOIN emeroteca_annate a ON f.annata_id = a.id
          WHERE a.testata_id = ' . $kardexTestataId
    );
    $total = $totalRow instanceof \mysqli_result ? (int) ($totalRow->fetch_assoc()['c'] ?? -1) : -1;
    check($total === 12, "kardex testata holds exactly 12 fascicoli overall after two runs (got {$total})");

    // ── 5. "marca attesi come mancanti" converts ONLY the attesi ──────
    // Receive one issue first (atteso → posseduto), then mark the annata
    // missing: 11 mancanti, the posseduto untouched, zero attesi left.
    $annataRow = $db->query(
        "SELECT a.id FROM emeroteca_annate a WHERE a.testata_id = {$kardexTestataId} AND a.anno = 2024 LIMIT 1"
    );
    $kardexAnnataId = $annataRow instanceof \mysqli_result ? (int) ($annataRow->fetch_assoc()['id'] ?? 0) : 0;
    check($kardexAnnataId > 0, 'kardex annata 2024 exists');

    $invalidDateReq = $reqFactory
        ->createServerRequest('POST', '/admin/periodicals/' . $kardexTestataId . '/issues')
        ->withParsedBody([
            'action' => 'add_fascicolo',
            'annata_id' => (string) $kardexAnnataId,
            'numero' => '99',
            'data_pubblicazione' => '2024-02-31',
            'stato' => 'posseduto',
        ]);
    $invalidDateResp = $controller->manageSubmit(
        $invalidDateReq,
        $resFactory->createResponse(),
        ['id' => (string) $kardexTestataId]
    );
    check($invalidDateResp->getStatusCode() === 303, 'invalid calendar date redirects safely');
    $invalidDateRow = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli
          WHERE annata_id = {$kardexAnnataId} AND numero = '99'"
    );
    $invalidDateCount = $invalidDateRow instanceof \mysqli_result
        ? (int) ($invalidDateRow->fetch_assoc()['c'] ?? -1)
        : -1;
    check($invalidDateCount === 0, 'invalid calendar date is rejected without creating an issue');

    $duplicateReq = $reqFactory
        ->createServerRequest('POST', '/admin/periodicals/' . $kardexTestataId . '/issues')
        ->withParsedBody([
            'action' => 'add_fascicolo',
            'annata_id' => (string) $kardexAnnataId,
            'numero' => '1',
            'stato' => 'posseduto',
        ]);
    $controller->manageSubmit($duplicateReq, $resFactory->createResponse(), ['id' => (string) $kardexTestataId]);
    $afterDuplicate = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli WHERE annata_id = {$kardexAnnataId}"
    );
    $afterDuplicateCount = $afterDuplicate instanceof \mysqli_result
        ? (int) ($afterDuplicate->fetch_assoc()['c'] ?? -1)
        : -1;
    check($afterDuplicateCount === 12, 'unique index rejects a duplicate issue number');

    $oneRow = $db->query(
        "SELECT id FROM emeroteca_fascicoli WHERE annata_id = {$kardexAnnataId} AND stato = 'atteso' ORDER BY id LIMIT 1"
    );
    $receivedId = $oneRow instanceof \mysqli_result ? (int) ($oneRow->fetch_assoc()['id'] ?? 0) : 0;
    check($receivedId > 0, 'one atteso fascicolo picked for reception');

    // Reception through the REAL action switch (receive_issue).
    $recReq = $reqFactory
        ->createServerRequest('POST', '/admin/periodicals/' . $kardexTestataId . '/issues')
        ->withParsedBody(['action' => 'receive_issue', 'fascicolo_id' => (string) $receivedId]);
    $recResp = $controller->manageSubmit($recReq, $resFactory->createResponse(), ['id' => (string) $kardexTestataId]);
    check($recResp->getStatusCode() === 303, 'receive_issue redirects (303)');
    check($countByStato($kardexTestataId, 'posseduto') === 1, 'received fascicolo is now posseduto');
    check($countByStato($kardexTestataId, 'atteso') === 11, '11 attesi remain after reception');

    // Mark missing through the REAL action switch (mark_missing).
    $mmReq = $reqFactory
        ->createServerRequest('POST', '/admin/periodicals/' . $kardexTestataId . '/issues')
        ->withParsedBody(['action' => 'mark_missing', 'annata_id' => (string) $kardexAnnataId]);
    $mmResp = $controller->manageSubmit($mmReq, $resFactory->createResponse(), ['id' => (string) $kardexTestataId]);
    check($mmResp->getStatusCode() === 303, 'mark_missing redirects (303)');

    check($countByStato($kardexTestataId, 'atteso') === 0, 'mark_missing leaves zero attesi');
    check($countByStato($kardexTestataId, 'mancante') === 11, 'mark_missing converted exactly the 11 attesi to mancante');
    check($countByStato($kardexTestataId, 'posseduto') === 1, 'mark_missing did NOT touch the posseduto fascicolo');
} finally {
    $cleanup();
    $db->close();
}

printf("\nALL %d PASS\n", $TESTNO);
