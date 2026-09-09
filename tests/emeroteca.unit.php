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
 *   6. "marca attesi come mancanti" converts ONLY the 'atteso' issues;
 *   7. the 1.4.0 volume migration survives a COLLISION on its synthetic
 *      label: an annata already holding 'v<id>' in the same
 *      (testata_id, anno) group used to make ensureSchema() fail with
 *      ER_DUP_ENTRY, which bricks activation permanently;
 *   8. the 1.4.0 stato migration repairs rows written OUTSIDE the ENUM
 *      (the empty index-0 slot, reachable with sql_mode='') — invisible
 *      issues that count neither as owned nor as a gap.
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
// The destructive bulk actions exercised below (mark_missing, bulk claim)
// re-check the role inline because AdminAuthMiddleware also admits staff,
// so the fixture session must be an administrator: without it those
// actions return early and the test would be asserting a no-op.
$_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin']];

$hm = new \App\Support\HookManager($db);
$plugin = new EmerotecaPlugin($db, $hm);

// Unique fixture marker so cleanup never touches pre-existing data.
$RUN = 'emu-' . bin2hex(random_bytes(4));
$TITLE_CONSISTENZA = "EmerotecaUnit Consistenza {$RUN}";
$TITLE_KARDEX      = "EmerotecaUnit Kardex {$RUN}";
$TITLE_VOLUME      = "EmerotecaUnit Volume {$RUN}";
$TITLE_STATO       = "EmerotecaUnit Stato {$RUN}";
$PLUGIN_FIXTURE    = "emeroteca-unit-{$RUN}";

/**
 * FK-ordered cleanup of every fixture testata (articoli → fascicoli →
 * annate → testate). Safe when the tables do not exist yet.
 *
 * Sections 7 and 8 temporarily revert two column definitions to their
 * pre-1.4.0 shape; restoring them here (not only on the happy path)
 * keeps a failing run from leaving the dev schema half-migrated.
 */
$cleanup = static function () use (
    $db,
    $TITLE_CONSISTENZA,
    $TITLE_KARDEX,
    $TITLE_VOLUME,
    $TITLE_STATO,
    $PLUGIN_FIXTURE
): void {
    $titles = "'" . $db->real_escape_string($TITLE_CONSISTENZA)
        . "','" . $db->real_escape_string($TITLE_KARDEX)
        . "','" . $db->real_escape_string($TITLE_VOLUME)
        . "','" . $db->real_escape_string($TITLE_STATO) . "'";
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
    @$db->query("ALTER TABLE emeroteca_annate MODIFY volume VARCHAR(50) NOT NULL DEFAULT ''");
    @$db->query(
        "ALTER TABLE emeroteca_fascicoli
         MODIFY stato ENUM('posseduto','mancante','atteso','smarrito','reclamato','scartato')
             NOT NULL DEFAULT 'posseduto'"
    );
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
        substr_count($installerSource, "\$installPlugin('emeroteca'") === 1
            && str_contains($installerSource, "\$installPlugin('emeroteca', [], false)")
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
    // 5 since plugin 1.4.0 (emeroteca_abbonamenti joined the four originals).
    check(is_array($expected) && count($expected) === 5, 'expectedTables() declares exactly 5 tables');

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
    // Si controlla l'INSIEME dei nomi hook dopo la prima attivazione e dopo
    // la seconda: i nomi dicono quale contratto è registrato (un semplice
    // conteggio non distingue un hook mancante da uno nuovo), e ripetere
    // l'attivazione prova l'idempotenza — il solo stato finale non
    // distingue "hook idempotenti" da hook registrati due volte.
    // Gli hook: routes, admin menu, mobile_api.openapi (bridge mobile,
    // v1.3.0), i cinque listener sulle entità core (v1.4.0), che
    // ripuntano editore_id/genere_id sul superstite di un merge e vietano
    // la cancellazione di una mensola ancora usata dall'emeroteca, e i
    // due listener di visibilità pubblica (v1.4.0): senza 'sitemap.entries'
    // le pagine dell'emeroteca non entrano nella sitemap e senza
    // 'search.external_suggestions' cercare una rivista nel catalogo resta
    // un vicolo cieco — i due filtri esistono nel core ma senza queste
    // righe non ha alcun ascoltatore.
    $expectedHooks = [
        'admin.menu.render',
        'app.routes.register',
        'genre.merging',
        'mobile_api.openapi',
        'publisher.deleting',
        'publisher.merging',
        'search.external_suggestions',
        'shelf.can_delete',
        'shelf.deleted',
        'sitemap.entries',
    ];
    /** @return list<string> nomi hook registrati, ordinati e con i duplicati visibili */
    $hookNames = static function () use ($db, $fixturePluginId): array {
        $res = $db->query(
            "SELECT hook_name FROM plugin_hooks WHERE plugin_id = {$fixturePluginId} ORDER BY hook_name"
        );
        $names = [];
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $names[] = (string) $row['hook_name'];
            }
        }
        return $names;
    };
    $plugin->onActivate();
    $afterFirst = $hookNames();
    $plugin->onActivate();
    $afterSecond = $hookNames();
    check(
        $afterFirst === $expectedHooks && $afterSecond === $expectedHooks,
        'activation registers exactly the expected hooks and remains idempotent ('
        . count($expectedHooks) . ' after the first run, the same after the second)'
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

    // ── 6. Volume migration: COLLISION on the synthetic label ─────────
    // The 1.4.0 migration collapses NULL volumes to '' and labels the
    // leftovers (duplicates the UNIQUE key only admitted via NULL). The
    // label used to be a blind CONCAT('v', id) — but 'v14' is a volume a
    // librarian can type by hand, and hitting uq_emeroteca_annata makes
    // ensureSchema() fail, onActivate() throw and PluginManager roll the
    // version back, repeating the same error on every boot.
    //
    // Fixture: one (testata, 2050) group holding '' (so the NULL cannot
    // collapse into it), one NULL row, and — inserted once its id is
    // known — a decoy already occupying exactly 'v<id>'.
    $insTestata = $db->prepare('INSERT INTO emeroteca_testate (titolo, tipo) VALUES (?, ?)');
    check($insTestata !== false, 'volume fixture: testata insert prepared');
    $insTestata->bind_param('ss', $TITLE_VOLUME, $tipo);
    check($insTestata->execute(), 'volume fixture: testata inserted');
    $volTestataId = (int) $db->insert_id;
    $insTestata->close();

    check(
        $db->query('ALTER TABLE emeroteca_annate MODIFY volume VARCHAR(50) NULL') !== false,
        'volume fixture: annate.volume temporarily reverted to NULLable'
    );

    /** @param string|null $volume */
    $insertAnnata = static function (int $testataId, int $anno, $volume) use ($db): int {
        $stmt = $db->prepare('INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, ?, ?)');
        if ($stmt === false) {
            throw new \RuntimeException('annata insert prepare failed: ' . $db->error);
        }
        $stmt->bind_param('iis', $testataId, $anno, $volume);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException("annata insert failed: {$error}");
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };

    $volEmptyId = $insertAnnata($volTestataId, 2050, '');
    $volNullId  = $insertAnnata($volTestataId, 2050, null);
    $volDecoyId = $insertAnnata($volTestataId, 2050, 'v' . $volNullId);
    pass("volume fixture: group 2050 seeded with '', NULL and the decoy 'v{$volNullId}'");

    $resultVolume = $plugin->ensureSchema();
    check(
        ($resultVolume['failed'] ?? ['x']) === [],
        'ensureSchema() survives the synthetic-label collision without failing ('
        . implode(',', $resultVolume['failed'] ?? []) . ')'
    );

    $volumes = [];
    $volRes = $db->query("SELECT id, volume FROM emeroteca_annate WHERE testata_id = {$volTestataId}");
    while ($volRes instanceof \mysqli_result && ($row = $volRes->fetch_assoc())) {
        $volumes[(int) $row['id']] = $row['volume'] === null ? null : (string) $row['volume'];
    }
    check(($volumes[$volEmptyId] ?? null) === '', "pre-existing '' volume untouched by the migration");
    check(
        ($volumes[$volDecoyId] ?? null) === 'v' . $volNullId,
        "the hand-typed 'v{$volNullId}' volume is NOT stolen from its annata"
    );
    check(
        ($volumes[$volNullId] ?? null) !== null
            && ($volumes[$volNullId] ?? '') !== ''
            && ($volumes[$volNullId] ?? '') !== 'v' . $volNullId,
        'the duplicate NULL volume got a DIFFERENT synthetic label (got "'
        . (string) ($volumes[$volNullId] ?? 'NULL') . '")'
    );
    $volDupProbe = $db->query(
        "SELECT COUNT(*) AS c FROM (
            SELECT testata_id, anno, volume FROM emeroteca_annate
             WHERE testata_id = {$volTestataId}
             GROUP BY testata_id, anno, volume HAVING COUNT(*) > 1
         ) d"
    );
    check(
        $volDupProbe instanceof \mysqli_result && (int) ($volDupProbe->fetch_assoc()['c'] ?? -1) === 0,
        'UNIQUE(testata_id, anno, volume) holds after the collision-aware migration'
    );
    $volNullLeft = $db->query('SELECT COUNT(*) AS c FROM emeroteca_annate WHERE volume IS NULL');
    check(
        $volNullLeft instanceof \mysqli_result && (int) ($volNullLeft->fetch_assoc()['c'] ?? -1) === 0,
        'no NULL volume remains after the migration'
    );

    // ── 7. stato written OUTSIDE the ENUM is normalized ───────────────
    // MySQL keeps an unnamed index-0 member on every ENUM, reachable
    // whenever a write happens with sql_mode='' — the configuration of
    // the cPanel/CloudLinux hosts this project runs on. Such a row reads
    // back as '': the public badge is grey and unlabelled, and
    // consistenzaTestata() counts it neither as owned nor as a gap, so
    // the fascicolo disappears from the holdings statement.
    $insTestata = $db->prepare('INSERT INTO emeroteca_testate (titolo, tipo) VALUES (?, ?)');
    check($insTestata !== false, 'stato fixture: testata insert prepared');
    $insTestata->bind_param('ss', $TITLE_STATO, $tipo);
    check($insTestata->execute(), 'stato fixture: testata inserted');
    $statoTestataId = (int) $db->insert_id;
    $insTestata->close();
    $statoAnnataId = $insertAnnata($statoTestataId, 2051, '');

    $insFascicolo = static function (int $annataId, string $numero, ?string $inventario) use ($db): int {
        $stmt = $db->prepare(
            "INSERT INTO emeroteca_fascicoli (annata_id, numero, stato, numero_inventario)
             VALUES (?, ?, 'posseduto', ?)"
        );
        if ($stmt === false) {
            throw new \RuntimeException('fascicolo insert prepare failed: ' . $db->error);
        }
        $stmt->bind_param('iss', $annataId, $numero, $inventario);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException("fascicolo insert failed: {$error}");
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    // One issue carries evidence of possession (an inventory number),
    // the other is a bare placeholder — the two branches of the rule.
    $fascOwned = $insFascicolo($statoAnnataId, '1', "INV-{$RUN}");
    $fascBare  = $insFascicolo($statoAnnataId, '2', null);

    $modeRow = $db->query('SELECT @@SESSION.sql_mode AS m');
    $previousSqlMode = $modeRow instanceof \mysqli_result ? (string) ($modeRow->fetch_assoc()['m'] ?? '') : '';
    check($db->query("SET SESSION sql_mode=''") !== false, 'stato fixture: session sql_mode relaxed');
    check(
        $db->query("UPDATE emeroteca_fascicoli SET stato = '' WHERE id IN ({$fascOwned}, {$fascBare})") !== false,
        'stato fixture: two fascicoli written with an out-of-ENUM stato'
    );
    $db->query("SET SESSION sql_mode='" . $db->real_escape_string($previousSqlMode) . "'");

    $outOfSet = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli WHERE id IN ({$fascOwned}, {$fascBare}) AND stato = ''"
    );
    check(
        $outOfSet instanceof \mysqli_result && (int) ($outOfSet->fetch_assoc()['c'] ?? -1) === 2,
        'stato fixture: both rows really landed in the empty index-0 slot'
    );

    $resultStato = $plugin->ensureSchema();
    check(
        ($resultStato['failed'] ?? ['x']) === [],
        'ensureSchema() repairs the out-of-set stato without failures ('
        . implode(',', $resultStato['failed'] ?? []) . ')'
    );

    $statoRows = [];
    $statoRes = $db->query(
        "SELECT id, stato FROM emeroteca_fascicoli WHERE id IN ({$fascOwned}, {$fascBare})"
    );
    while ($statoRes instanceof \mysqli_result && ($row = $statoRes->fetch_assoc())) {
        $statoRows[(int) $row['id']] = (string) $row['stato'];
    }
    check(
        ($statoRows[$fascOwned] ?? '') === 'posseduto',
        "an out-of-set fascicolo WITH possession data becomes 'posseduto' (got '"
        . ($statoRows[$fascOwned] ?? '') . "')"
    );
    check(
        ($statoRows[$fascBare] ?? '') === 'mancante',
        "a bare out-of-set fascicolo becomes 'mancante' (got '" . ($statoRows[$fascBare] ?? '') . "')"
    );

    // The repaired rows are now counted by the holdings statement — the
    // point of the fix: before it, this testata read '—'.
    $consistenzaStato = EmerotecaPlugin::consistenzaTestata($db, $statoTestataId);
    check(
        str_contains($consistenzaStato, '2051'),
        "the repaired fascicoli reappear in consistenzaTestata (got '{$consistenzaStato}')"
    );

    // ── 8. Inherited fascicolo barcodes are cleared ───────────────────
    // An earlier build of the issue form pre-filled the fascicolo barcode
    // with the TITLE's barcode_base and saved it. That base is the EAN-13
    // of the serial, shared by every issue: the polluted rows all answer
    // to the same code, so a scan at the desk resolves to whichever one
    // comes back first. The form no longer copies it; the rows already
    // written have to be cleaned by the migration.
    $BARCODE_BASE = '977' . substr((string) abs(crc32($RUN)), 0, 9) . '1';
    $BARCODE_BASE = substr($BARCODE_BASE . '0000000000000', 0, 13);
    $updBase = $db->prepare('UPDATE emeroteca_testate SET barcode_base = ? WHERE id = ?');
    check($updBase !== false, 'barcode fixture: barcode_base update prepared');
    $updBase->bind_param('si', $BARCODE_BASE, $statoTestataId);
    check($updBase->execute(), 'barcode fixture: barcode_base set on the testata');
    $updBase->close();

    $legitBarcode = $BARCODE_BASE . '07';   // base + EAN add-on = the issue's own code
    $foreignBase  = substr('9779999999999', 0, 13);
    $updOther = $db->prepare('UPDATE emeroteca_testate SET barcode_base = ? WHERE id = ?');
    check($updOther !== false, 'barcode fixture: foreign barcode_base update prepared');
    $updOther->bind_param('si', $foreignBase, $volTestataId);
    check($updOther->execute(), 'barcode fixture: a DIFFERENT testata gets its own barcode_base');
    $updOther->close();

    $setBarcode = $db->prepare('UPDATE emeroteca_fascicoli SET barcode = ? WHERE id = ?');
    check($setBarcode !== false, 'barcode fixture: fascicolo barcode update prepared');
    // 1) inherited from its OWN testata → must be cleared
    $setBarcode->bind_param('si', $BARCODE_BASE, $fascOwned);
    check($setBarcode->execute(), 'barcode fixture: one fascicolo inherited its own testata barcode_base');
    // 2) its own legitimate code (base + add-on) → must survive
    $setBarcode->bind_param('si', $legitBarcode, $fascBare);
    check($setBarcode->execute(), 'barcode fixture: one fascicolo carries its own barcode (base + add-on)');
    $setBarcode->close();

    // 3) a code equal to ANOTHER title's base is not evidence of the copy
    //    bug — it must be left alone.
    $foreignAnnataId = $insertAnnata($statoTestataId, 2052, '');
    $fascForeign = $insFascicolo($foreignAnnataId, '1', null);
    $setForeign = $db->prepare('UPDATE emeroteca_fascicoli SET barcode = ? WHERE id = ?');
    check($setForeign !== false, 'barcode fixture: foreign-base barcode update prepared');
    $setForeign->bind_param('si', $foreignBase, $fascForeign);
    check($setForeign->execute(), "barcode fixture: one fascicolo carries ANOTHER title's base");
    $setForeign->close();

    $resultBarcode = $plugin->ensureSchema();
    check(
        ($resultBarcode['failed'] ?? ['x']) === [],
        'ensureSchema() runs the inherited-barcode cleanup without failures ('
        . implode(',', $resultBarcode['failed'] ?? []) . ')'
    );

    $barcodes = [];
    $bcRes = $db->query(
        "SELECT id, barcode FROM emeroteca_fascicoli WHERE id IN ({$fascOwned}, {$fascBare}, {$fascForeign})"
    );
    while ($bcRes instanceof \mysqli_result && ($row = $bcRes->fetch_assoc())) {
        $barcodes[(int) $row['id']] = $row['barcode'];
    }
    // array_key_exists, not ??: the expected value IS null, which ?? would
    // read as "row missing" and turn into a green test for a red result.
    check(
        array_key_exists($fascOwned, $barcodes) && $barcodes[$fascOwned] === null,
        'a fascicolo barcode copied from its OWN testata barcode_base is cleared to NULL'
    );
    check(
        ($barcodes[$fascBare] ?? null) === $legitBarcode,
        'a legitimate issue barcode (base + add-on) is NOT touched'
    );
    check(
        ($barcodes[$fascForeign] ?? null) === $foreignBase,
        "a barcode equal to ANOTHER title's base is NOT touched"
    );

    // Idempotent: a second run has nothing left to clean and changes nothing.
    $resultBarcode2 = $plugin->ensureSchema();
    check(($resultBarcode2['failed'] ?? ['x']) === [], 'second run of the barcode cleanup reports no failures');
    $stillClean = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli
          WHERE id IN ({$fascBare}, {$fascForeign}) AND barcode IS NULL"
    );
    check(
        $stillClean instanceof \mysqli_result && (int) ($stillClean->fetch_assoc()['c'] ?? -1) === 0,
        'the second run does not erase the barcodes it correctly left alone (idempotent)'
    );
} finally {
    $cleanup();
    $db->close();
}

printf("\nALL %d PASS\n", $TESTNO);
