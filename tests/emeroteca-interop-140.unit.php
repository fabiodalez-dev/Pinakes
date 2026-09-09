<?php
declare(strict_types=1);

/**
 * Behavioral unit tests for the Emeroteca ↔ interop bridges (issue #140)
 * against the REAL dev DB.
 *
 * Covers:
 *   1. OAI-PMH ListSets advertises the `periodicals` set while the Emeroteca
 *      plugin is ACTIVE, and drops it the moment the plugin is deactivated;
 *   2. ListRecords&set=periodicals&metadataPrefix=oai_dc emits the seeded
 *      masthead as well-formed Dublin Core with the exact title, the ISSN
 *      URNs, publisher, language, place, date range and the absolute public
 *      URL, under the `periodicals` setSpec;
 *   3. GetRecord resolves oai:{host}:periodical:{id}, answers idDoesNotExist
 *      for an unknown id, and cannotDisseminateFormat for a non-DC prefix;
 *   4. resumption tokens really page the set (>PAGE_SIZE mastheads seeded:
 *      page 1 = 100 records + token, page 2 continues at cursor=100 with no
 *      overlap and no leftover token);
 *   5. degradation with Emeroteca deactivated: no set in ListSets,
 *      noRecordsMatch on set=periodicals, idDoesNotExist on GetRecord, and the
 *      SRU serials arm silently disappears;
 *   6. Z39.50/SRU searchRetrieve finds the masthead by ISSN (bath.issn, the
 *      Bath/bib-1 Use 8 index) in Dublin Core and MARCXML (022/245/310/362),
 *      while an ISBN search never returns serials;
 *   7. MobileModule GET /periodicals/years/{id}/issues reports meta.truncated
 *      = true past the 400-issue cap (and returns exactly the cap) and false
 *      below it.
 *
 * Conventions follow tests/emeroteca-schema-140.unit.php (env parsing, DB
 * connection, check()/pass() helpers, zz_* fixtures, FK-ordered cleanup).
 * This suite FAILS HARD (exit 1) when the DB is unreachable.
 */

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
require_once __DIR__ . '/helpers/oai-metadata-fault.php';

require_once $root . '/storage/plugins/emeroteca/EmerotecaPlugin.php';
require_once $root . '/storage/plugins/emeroteca/src/Modules/MobileModule.php';
require_once $root . '/storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';

foreach ([
    'Exceptions/CQLException.php',
    'Exceptions/InvalidCQLSyntaxException.php',
    'Exceptions/UnsupportedIndexException.php',
    'Exceptions/UnsupportedRelationException.php',
    'Exceptions/SRUQueryException.php',
    'Exceptions/DatabaseException.php',
    'CQLParser.php',
    'RecordFormatter.php',
    'DublinCoreFormatter.php',
    'MARCXMLFormatter.php',
    'MODSFormatter.php',
    'UNIMARCXMLFormatter.php',
    'SRUServer.php',
] as $z39File) {
    require_once $root . '/storage/plugins/z39-server/classes/' . $z39File;
}

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

function eiv_env(string $path): array
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

$env    = eiv_env(__DIR__ . '/../.env');
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

$_SESSION = [];

$hm     = new \App\Support\HookManager($db);
$emero  = new EmerotecaPlugin($db, $hm);

// Fixtures — unique marker so cleanup never touches pre-existing data.
$RUN         = 'zz-int140-' . bin2hex(random_bytes(4));
$TITLE_MAIN  = "zz Interop140 Gazzetta {$RUN}";
$SUBTITLE    = "cronache & varietà {$RUN}";       // '&' proves XML escaping
$TITLE_TRUNC = "zz Interop140 Truncation {$RUN}";
$TITLE_PAGE  = "zz Interop140 Page {$RUN}";        // + ' #NNN'
$EDITORE     = "zz Interop140 Editore {$RUN}";
$GENERE      = "zz Interop140 Genere {$RUN}";
$ISSN        = '0378-5955';
$E_ISSN      = '0002-936X';
$ISSN_L      = '0378-5955';
$PLACE       = "zz Interop140 Bassano {$RUN}";
$ISSUES_CAP  = 400;

/** Titles owned by this run (LIKE-matched so the 101 paging rows are caught). */
$titleLike = 'zz Interop140 %' . $RUN . '%';

$BOOK_TITLE = "zz Interop140 Monografia con ISSN {$RUN}";
$BOOK_ISSN  = '1234-5678';

/**
 * Highest emeroteca_testate id that existed BEFORE this run. Every fixture id
 * is above it, which is what lets the cleanup reclaim tombstones left by rows
 * the run deleted on purpose (section 11) without ever touching a tombstone
 * that belongs to real data.
 */
$baseTestataId = 0;

$cleanup = static function () use ($db, $titleLike, $EDITORE, $GENERE, $BOOK_TITLE, &$baseTestataId): void {
    $like = $db->real_escape_string($titleLike);

    // Tombstones first: the AFTER DELETE trigger on emeroteca_testate fires for
    // every fixture masthead this cleanup removes, so the ids have to be read
    // BEFORE the rows disappear. Guarded — the table only exists once the
    // oai-pmh-server plugin's ensureSchema() has run.
    $tombIds = [];
    $tombRes = @$db->query("SELECT id FROM emeroteca_testate WHERE titolo LIKE '{$like}'");
    if ($tombRes instanceof \mysqli_result) {
        while ($tombRow = $tombRes->fetch_row()) {
            $tombIds[] = (int) $tombRow[0];
        }
        $tombRes->free();
    }

    @$db->query(
        "DELETE ar FROM emeroteca_articoli ar
           JOIN emeroteca_fascicoli f ON ar.fascicolo_id = f.id
           JOIN emeroteca_annate a ON f.annata_id = a.id
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo LIKE '{$like}'"
    );
    @$db->query(
        "DELETE f FROM emeroteca_fascicoli f
           JOIN emeroteca_annate a ON f.annata_id = a.id
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo LIKE '{$like}'"
    );
    @$db->query(
        "DELETE a FROM emeroteca_annate a
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo LIKE '{$like}'"
    );
    @$db->query("DELETE FROM emeroteca_testate WHERE titolo LIKE '{$like}'");
    @$db->query("DELETE FROM editori WHERE nome = '" . $db->real_escape_string($EDITORE) . "'");
    @$db->query("DELETE FROM generi  WHERE nome = '" . $db->real_escape_string($GENERE) . "'");
    $bookIds = [];
    $bookRows = @$db->query("SELECT id FROM libri WHERE titolo = '" . $db->real_escape_string($BOOK_TITLE) . "' OR titolo LIKE '" . $db->real_escape_string($BOOK_TITLE) . " page %'");
    if ($bookRows instanceof \mysqli_result) {
        while ($bookRow = $bookRows->fetch_row()) {
            $bookIds[] = (int) $bookRow[0];
        }
        $bookRows->free();
    }
    @$db->query("DELETE FROM libri WHERE titolo = '" . $db->real_escape_string($BOOK_TITLE) . "' OR titolo LIKE '" . $db->real_escape_string($BOOK_TITLE) . " page %'");
    if ($bookIds !== []) {
        @$db->query("DELETE FROM oai_deleted_records WHERE entity_type = 'book' AND entity_id IN (" . implode(',', $bookIds) . ')');
    }
    if ($tombIds !== []) {
        @$db->query(
            'DELETE FROM oai_deleted_periodicals WHERE entity_id IN (' . implode(',', $tombIds) . ')'
        );
    }
    // Sweep: a masthead this run deleted deliberately is already gone by now,
    // so its id never reaches $tombIds. Anything above the pre-run high-water
    // mark with no surviving row belongs to this run.
    if ($baseTestataId > 0) {
        @$db->query(
            'DELETE d FROM oai_deleted_periodicals d
               LEFT JOIN emeroteca_testate t ON t.id = d.entity_id
              WHERE d.entity_id > ' . (int) $baseTestataId . ' AND t.id IS NULL'
        );
    }
};

/** Emeroteca activation flag, restored verbatim in the finally block. */
$originalActive = null;
$setEmerotecaActive = static function (bool $active) use ($db): void {
    $flag = $active ? 1 : 0;
    $stmt = $db->prepare('UPDATE plugins SET is_active = ? WHERE name = ?');
    if ($stmt !== false) {
        $pluginName = 'emeroteca';
        $stmt->bind_param('is', $flag, $pluginName);
        $stmt->execute();
        $stmt->close();
    }
    // PluginManager memoizes isActive() per process — the gates under test
    // read through it, so a stale cache would silently fake the result.
    \App\Support\PluginManager::clearIsActiveCache();
};

// ── OAI helpers ───────────────────────────────────────────────────────
// Every call builds a FRESH plugin instance: the exposure gates memoize
// per instance (one instance = one HTTP request in production).
$oaiCall = static function (array $params) use ($db, $hm): string {
    $plugin  = new \App\Plugins\OaiPmhServer\OaiPmhServerPlugin($db, $hm);
    $request = (new ServerRequestFactory())
        ->createServerRequest('GET', '/oai')
        ->withQueryParams($params);
    $response = $plugin->oaiPmhAction($request, new SlimResponse());
    $body = $response->getBody();
    $body->rewind();

    return $body->getContents();
};

$xpathOf = static function (string $xml, string $what): \DOMXPath {
    $doc = new \DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $doc->loadXML($xml);
    libxml_use_internal_errors($prev);
    if ($loaded === false) {
        throw new \RuntimeException("{$what}: response is not well-formed XML");
    }
    $xp = new \DOMXPath($doc);
    $xp->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
    $xp->registerNamespace('oai_dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
    $xp->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
    $xp->registerNamespace('srw', 'http://www.loc.gov/zing/srw/');
    $xp->registerNamespace('marc', 'http://www.loc.gov/MARC21/slim');

    return $xp;
};

/** @return list<string> */
$textList = static function (\DOMXPath $xp, string $query, ?\DOMNode $ctx = null): array {
    $out = [];
    $nodes = $ctx === null ? $xp->query($query) : $xp->query($query, $ctx);
    if ($nodes instanceof \DOMNodeList) {
        foreach ($nodes as $node) {
            $out[] = trim((string) $node->textContent);
        }
    }
    return $out;
};

$host  = parse_url((string) absoluteUrl('/oai'), PHP_URL_HOST) ?: 'localhost';
$sruSettings = [
    'server_host'       => 'localhost',
    'server_port'       => '80',
    'server_database'   => 'catalog',
    'supported_formats' => 'marcxml,dc,mods,unimarcxml',
    'default_format'    => 'dc',
    'default_records'   => '10',
    'max_records'       => '100',
    'enable_logging'    => 'false',
];

try {
    // Schema must exist before fixtures (idempotent).
    $schema = $emero->ensureSchema();
    check(($schema['failed'] ?? ['x']) === [], 'emeroteca ensureSchema() reports no failed tables');

    $row = $db->query("SELECT is_active FROM plugins WHERE name = 'emeroteca' LIMIT 1");
    $originalActive = ($row instanceof \mysqli_result && ($r = $row->fetch_assoc()) !== null)
        ? (int) $r['is_active']
        : null;
    check($originalActive !== null, 'the emeroteca plugin is registered in the plugins table');
    $setEmerotecaActive(true);

    $cleanup(); // paranoid: a previous crashed run cannot poison this one

    $baseRes = $db->query('SELECT COALESCE(MAX(id), 0) AS m FROM emeroteca_testate');
    $baseTestataId = ($baseRes instanceof \mysqli_result) ? (int) ($baseRes->fetch_assoc()['m'] ?? 0) : 0;
    check($baseTestataId >= 0, "pre-run masthead high-water mark recorded ({$baseTestataId})");

    // ── Fixtures ──────────────────────────────────────────────────────
    $exec = static function (string $sql, string $types = '', array $params = []) use ($db): int {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('fixture prepare failed: ' . $db->error . ' — ' . $sql);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            throw new \RuntimeException('fixture insert failed: ' . $stmt->error);
        }
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };

    $editoreId = $exec('INSERT INTO editori (nome) VALUES (?)', 's', [$EDITORE]);
    $genereId  = $exec('INSERT INTO generi (nome) VALUES (?)', 's', [$GENERE]);

    $mainId = $exec(
        "INSERT INTO emeroteca_testate
            (titolo, sottotitolo, issn, e_issn, issn_l, editore_id, luogo_pubblicazione,
             lingua, periodicita, tipo, anno_inizio, anno_fine, genere_id, descrizione, stato_raccolta)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'italiano', 'mensile', 'rivista', 1950, NULL, ?, ?, 'attiva')",
        'sssssissi',
        [
            $TITLE_MAIN, $SUBTITLE, $ISSN, $E_ISSN, $ISSN_L, $editoreId, $PLACE,
            $genereId, "Descrizione di prova <b>140</b> {$RUN}",
        ]
    );
    check($mainId > 0, "masthead fixture created (id {$mainId}, ISSN {$ISSN})");

    $oaiId = 'oai:' . $host . ':periodical:' . $mainId;

    // ── 1. ListSets advertises the periodicals set ────────────────────
    $listSets = $oaiCall(['verb' => 'ListSets']);
    $xp = $xpathOf($listSets, 'ListSets');
    $specs = $textList($xp, '//oai:ListSets/oai:set/oai:setSpec');
    check(in_array('books', $specs, true), 'ListSets still advertises the books set');
    check(in_array('periodicals', $specs, true), 'ListSets advertises the periodicals set while emeroteca is active');
    check(
        $textList($xp, '//oai:error') === [],
        'ListSets response carries no OAI error'
    );

    // ── 2. ListRecords on the set exposes the masthead as Dublin Core ──
    // Harvest the whole set through resumption tokens: the dev DB may hold
    // more mastheads than one page.
    $harvest = static function (array $params, int $maxPages = 30) use ($oaiCall, $xpathOf): array {
        $identifiers = [];
        $recordsXml  = [];
        $pages       = 0;
        $token       = null;
        do {
            $call = $token === null ? $params : ['verb' => $params['verb'], 'resumptionToken' => $token];
            $xml  = $oaiCall($call);
            $xp   = $xpathOf($xml, 'ListRecords');
            $nodes = $xp->query('//oai:ListRecords/oai:record');
            if ($nodes instanceof \DOMNodeList) {
                foreach ($nodes as $record) {
                    $idNode = $xp->query('./oai:header/oai:identifier', $record);
                    $id = ($idNode instanceof \DOMNodeList && $idNode->length > 0)
                        ? trim((string) $idNode->item(0)->textContent)
                        : '';
                    $identifiers[] = $id;
                    // Keep the live node + its XPath: serialising the subtree
                    // would drop the inherited default namespace and make every
                    // prefixed query silently miss.
                    $recordsXml[$id] = ['xp' => $xp, 'node' => $record];
                }
            }
            $tokenNodes = $xp->query('//oai:resumptionToken');
            $token = ($tokenNodes instanceof \DOMNodeList && $tokenNodes->length > 0)
                ? trim((string) $tokenNodes->item(0)->textContent)
                : '';
            $token = $token === '' ? null : $token;
            $pages++;
        } while ($token !== null && $pages < $maxPages);

        return ['identifiers' => $identifiers, 'records' => $recordsXml, 'pages' => $pages];
    };

    $set = $harvest(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc', 'set' => 'periodicals']);
    check(
        in_array($oaiId, $set['identifiers'], true),
        "ListRecords&set=periodicals returns the seeded masthead ({$oaiId})"
    );

    $found = $set['records'][$oaiId] ?? null;
    check(is_array($found), 'the seeded masthead record is retrievable from the harvest');
    /** @var \DOMXPath $rx */
    $rx   = $found['xp'];
    $node = $found['node'];
    check(
        $textList($rx, './oai:header/oai:setSpec', $node) === ['periodicals'],
        'the masthead record header carries setSpec=periodicals'
    );
    $datestamps = $textList($rx, './oai:header/oai:datestamp', $node);
    check(
        count($datestamps) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $datestamps[0]) === 1,
        'the record datestamp uses the repository granularity (' . ($datestamps[0] ?? '-') . ')'
    );
    check(
        $textList($rx, './/dc:title', $node) === [$TITLE_MAIN . ' : ' . $SUBTITLE],
        'dc:title carries title and subtitle, XML-escaped (& survived the round trip)'
    );
    $identifiers = $textList($rx, './/dc:identifier', $node);
    check(in_array($oaiId, $identifiers, true), 'dc:identifier repeats the OAI identifier');
    check(in_array('urn:ISSN:' . $ISSN, $identifiers, true), "dc:identifier carries urn:ISSN:{$ISSN}");
    check(in_array('urn:ISSN:' . $E_ISSN, $identifiers, true), "dc:identifier carries the e-ISSN urn:ISSN:{$E_ISSN}");
    check(
        count(array_filter($identifiers, static fn (string $v): bool => $v === 'urn:ISSN:' . $ISSN)) === 1,
        'the ISSN-L duplicate of the print ISSN is emitted only once'
    );
    check(
        in_array((string) absoluteUrl('/emeroteca/' . $mainId), $identifiers, true),
        'dc:identifier carries the absolute public URL of the masthead'
    );
    check($textList($rx, './/dc:publisher', $node) === [$EDITORE], 'dc:publisher resolves through the core editori registry');
    check($textList($rx, './/dc:subject', $node) === [$GENERE], 'dc:subject resolves through the core generi registry');
    check($textList($rx, './/dc:language', $node) === ['italiano'], 'dc:language is exported');
    check($textList($rx, './/dc:coverage', $node) === [$PLACE], 'dc:coverage carries the place of publication');
    check($textList($rx, './/dc:date', $node) === ['1950-'], 'dc:date states an open-ended run for a live title');
    $types = $textList($rx, './/dc:type', $node);
    check(
        in_array('Periodical', $types, true) && in_array('Rivista', $types, true),
        'dc:type states both the generic Periodical and the local flavour'
    );
    check(
        in_array('Periodicity: mensile', $textList($rx, './/dc:description', $node), true),
        'the frequency travels as a qualified dc:description'
    );

    // ListIdentifiers must expose the same header without metadata.
    $listIds = $oaiCall(['verb' => 'ListIdentifiers', 'metadataPrefix' => 'oai_dc', 'set' => 'periodicals']);
    $xpIds = $xpathOf($listIds, 'ListIdentifiers');
    check(
        $textList($xpIds, '//oai:ListIdentifiers/oai:header/oai:identifier') !== []
        && $textList($xpIds, '//oai:metadata') === [],
        'ListIdentifiers on the set returns headers only'
    );

    // ── 3. GetRecord ──────────────────────────────────────────────────
    $get = $oaiCall(['verb' => 'GetRecord', 'identifier' => $oaiId, 'metadataPrefix' => 'oai_dc']);
    $xpGet = $xpathOf($get, 'GetRecord');
    check(
        $textList($xpGet, '//oai:GetRecord/oai:record/oai:header/oai:identifier') === [$oaiId],
        'GetRecord resolves the periodical identifier'
    );
    check(
        $textList($xpGet, '//oai:GetRecord/oai:record/oai:header/oai:setSpec') === ['periodicals'],
        'GetRecord reports the periodicals setSpec'
    );
    check(
        $textList($xpGet, '//dc:title') === [$TITLE_MAIN . ' : ' . $SUBTITLE],
        'GetRecord renders the same Dublin Core as ListRecords'
    );

    $missingId = 'oai:' . $host . ':periodical:' . ($mainId + 99000000);
    $getMissing = $oaiCall(['verb' => 'GetRecord', 'identifier' => $missingId, 'metadataPrefix' => 'oai_dc']);
    $xpMissing = $xpathOf($getMissing, 'GetRecord missing');
    $errNodes = $xpMissing->query('//oai:error');
    $errCode = ($errNodes instanceof \DOMNodeList && $errNodes->length > 0)
        ? (string) ($errNodes->item(0)->attributes?->getNamedItem('code')?->nodeValue ?? '')
        : '';
    check($errCode === 'idDoesNotExist', "GetRecord on an unknown periodical id answers idDoesNotExist (got '{$errCode}')");

    $getWrongFormat = $oaiCall(['verb' => 'GetRecord', 'identifier' => $oaiId, 'metadataPrefix' => 'marcxml']);
    $xpWrong = $xpathOf($getWrongFormat, 'GetRecord marcxml');
    $wrongNodes = $xpWrong->query('//oai:error');
    $wrongCode = ($wrongNodes instanceof \DOMNodeList && $wrongNodes->length > 0)
        ? (string) ($wrongNodes->item(0)->attributes?->getNamedItem('code')?->nodeValue ?? '')
        : '';
    check(
        $wrongCode === 'cannotDisseminateFormat',
        "a non-DC metadataPrefix on a masthead answers cannotDisseminateFormat (got '{$wrongCode}')"
    );

    // ListMetadataFormats for a masthead identifier advertises oai_dc only.
    $formats = $oaiCall(['verb' => 'ListMetadataFormats', 'identifier' => $oaiId]);
    $xpFmt = $xpathOf($formats, 'ListMetadataFormats');
    check(
        $textList($xpFmt, '//oai:metadataFormat/oai:metadataPrefix') === ['oai_dc'],
        'ListMetadataFormats advertises oai_dc only for a masthead identifier'
    );

    // ── 4. Resumption tokens page the set ─────────────────────────────
    $pageIds = [];
    $pageStmt = $db->prepare(
        "INSERT INTO emeroteca_testate (titolo, tipo, anno_inizio) VALUES (?, 'rivista', 2000)"
    );
    check($pageStmt !== false, 'paging fixture prepared');
    for ($i = 1; $i <= 101; $i++) {
        $pageTitle = $TITLE_PAGE . ' #' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $pageStmt->bind_param('s', $pageTitle);
        if (!$pageStmt->execute()) {
            throw new \RuntimeException('paging fixture insert failed: ' . $pageStmt->error);
        }
        $pageIds[] = (int) $db->insert_id;
    }
    $pageStmt->close();
    check(count($pageIds) === 101, '101 extra mastheads seeded to force pagination (PAGE_SIZE = 100)');

    $page1 = $oaiCall(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc', 'set' => 'periodicals']);
    $xp1 = $xpathOf($page1, 'ListRecords page 1');
    $ids1 = $textList($xp1, '//oai:ListRecords/oai:record/oai:header/oai:identifier');
    check(count($ids1) === 100, 'the first page returns exactly PAGE_SIZE records (got ' . count($ids1) . ')');
    $tokenNodes = $xp1->query('//oai:resumptionToken');
    $token = ($tokenNodes instanceof \DOMNodeList && $tokenNodes->length > 0)
        ? trim((string) $tokenNodes->item(0)->textContent)
        : '';
    $cursor1 = ($tokenNodes instanceof \DOMNodeList && $tokenNodes->length > 0)
        ? (string) ($tokenNodes->item(0)->attributes?->getNamedItem('cursor')?->nodeValue ?? '')
        : '';
    check($token !== '', 'the first page emits a non-empty resumptionToken');
    check($cursor1 === '0', "the first page reports cursor=0 (got '{$cursor1}')");

    $page2 = $oaiCall(['verb' => 'ListRecords', 'resumptionToken' => $token]);
    $xp2 = $xpathOf($page2, 'ListRecords page 2');
    $ids2 = $textList($xp2, '//oai:ListRecords/oai:record/oai:header/oai:identifier');
    $cursor2Nodes = $xp2->query('//oai:resumptionToken');
    $cursor2 = ($cursor2Nodes instanceof \DOMNodeList && $cursor2Nodes->length > 0)
        ? (string) ($cursor2Nodes->item(0)->attributes?->getNamedItem('cursor')?->nodeValue ?? '')
        : '';
    check($ids2 !== [], 'the resumption token yields a second page of records');
    check($cursor2 === '100', "the second page reports cursor=100 (got '{$cursor2}')");
    check(
        array_intersect($ids1, $ids2) === [],
        'the two pages do not overlap (the token pages the set, it does not restart it)'
    );
    check(
        count(array_unique(array_merge($ids1, $ids2))) === count($ids1) + count($ids2),
        'no identifier is served twice across the paged harvest'
    );

    // ── 5. Degradation when emeroteca is not active ───────────────────
    $setEmerotecaActive(false);

    $listSetsOff = $oaiCall(['verb' => 'ListSets']);
    $xpOff = $xpathOf($listSetsOff, 'ListSets (emeroteca off)');
    $specsOff = $textList($xpOff, '//oai:ListSets/oai:set/oai:setSpec');
    check(
        !in_array('periodicals', $specsOff, true) && in_array('books', $specsOff, true),
        'ListSets drops the periodicals set when emeroteca is deactivated, books untouched'
    );

    $listOff = $oaiCall(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc', 'set' => 'periodicals']);
    $xpListOff = $xpathOf($listOff, 'ListRecords (emeroteca off)');
    $offNodes = $xpListOff->query('//oai:error');
    $offCode = ($offNodes instanceof \DOMNodeList && $offNodes->length > 0)
        ? (string) ($offNodes->item(0)->attributes?->getNamedItem('code')?->nodeValue ?? '')
        : '';
    check($offCode === 'noRecordsMatch', "set=periodicals answers noRecordsMatch when the plugin is off (got '{$offCode}')");

    $getOff = $oaiCall(['verb' => 'GetRecord', 'identifier' => $oaiId, 'metadataPrefix' => 'oai_dc']);
    $xpGetOff = $xpathOf($getOff, 'GetRecord (emeroteca off)');
    $getOffNodes = $xpGetOff->query('//oai:error');
    $getOffCode = ($getOffNodes instanceof \DOMNodeList && $getOffNodes->length > 0)
        ? (string) ($getOffNodes->item(0)->attributes?->getNamedItem('code')?->nodeValue ?? '')
        : '';
    check(
        $getOffCode === 'idDoesNotExist',
        "a masthead identifier is unresolvable while the plugin is off (got '{$getOffCode}')"
    );

    $sruOff = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation'     => 'searchRetrieve',
        'version'       => '1.2',
        'query'         => 'bath.issn=' . $ISSN,
        'recordSchema'  => 'dc',
        'maximumRecords' => 50,
    ]);
    $xpSruOff = $xpathOf($sruOff, 'SRU (emeroteca off)');
    check(
        !str_contains($sruOff, $TITLE_MAIN),
        'SRU does not surface mastheads while emeroteca is deactivated'
    );
    check(
        $textList($xpSruOff, '//srw:numberOfRecords') === ['0'],
        'SRU reports zero records for the ISSN while the serials arm is closed'
    );

    $setEmerotecaActive(true);

    // ── 6. SRU / Z39.50 serial records ────────────────────────────────
    $sruDc = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation'      => 'searchRetrieve',
        'version'        => '1.2',
        'query'          => 'bath.issn=' . $ISSN,
        'recordSchema'   => 'dc',
        'maximumRecords' => 50,
    ]);
    $xpDc = $xpathOf($sruDc, 'SRU DC');
    check(
        (int) ($textList($xpDc, '//srw:numberOfRecords')[0] ?? 0) >= 1,
        'SRU searchRetrieve on bath.issn (bib-1 Use 8) finds the masthead'
    );
    check(
        in_array($TITLE_MAIN . ' : ' . $SUBTITLE, $textList($xpDc, '//dc:title'), true),
        'the SRU Dublin Core record carries the masthead title'
    );
    check(
        in_array('urn:ISSN:' . $ISSN, $textList($xpDc, '//dc:identifier'), true),
        'the SRU Dublin Core record carries the ISSN as a URN'
    );
    check(
        in_array('Periodical', $textList($xpDc, '//dc:type'), true),
        'the SRU Dublin Core record is typed as a Periodical'
    );
    check(
        in_array($EDITORE, $textList($xpDc, '//dc:publisher'), true)
        && in_array($PLACE, $textList($xpDc, '//dc:coverage'), true),
        'publisher and place of publication reach the SRU Dublin Core record'
    );

    // ISSN normalisation: the compact form resolves the same record.
    $sruCompact = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation'      => 'searchRetrieve',
        'version'        => '1.2',
        'query'          => 'bath.issn=' . str_replace('-', '', $ISSN),
        'recordSchema'   => 'dc',
        'maximumRecords' => 50,
    ]);
    check(
        str_contains($sruCompact, $TITLE_MAIN),
        'the hyphen-less ISSN form resolves the same masthead'
    );

    // Title search reaches the serials arm too.
    $sruTitle = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation'      => 'searchRetrieve',
        'version'        => '1.2',
        'query'          => 'dc.title="Interop140 Gazzetta ' . $RUN . '"',
        'recordSchema'   => 'dc',
        'maximumRecords' => 50,
    ]);
    check(str_contains($sruTitle, $TITLE_MAIN), 'SRU dc.title search reaches periodical mastheads');

    // MARCXML: the serial-specific fields.
    $sruMarc = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation'      => 'searchRetrieve',
        'version'        => '1.2',
        'query'          => 'bath.issn=' . $ISSN,
        'recordSchema'   => 'marcxml',
        'maximumRecords' => 50,
    ]);
    $xpMarc = $xpathOf($sruMarc, 'SRU MARCXML');
    check(
        in_array($ISSN, $textList($xpMarc, '//marc:datafield[@tag="022"]/marc:subfield[@code="a"]'), true),
        'MARCXML 022 $a carries the ISSN'
    );
    check(
        in_array($ISSN_L, $textList($xpMarc, '//marc:datafield[@tag="022"]/marc:subfield[@code="l"]'), true),
        'MARCXML 022 $l carries the linking ISSN (ISSN-L)'
    );
    check(
        in_array($TITLE_MAIN, $textList($xpMarc, '//marc:datafield[@tag="245"]/marc:subfield[@code="a"]'), true),
        'MARCXML 245 $a carries the masthead title'
    );
    check(
        in_array($PLACE, $textList($xpMarc, '//marc:datafield[@tag="264"]/marc:subfield[@code="a"]'), true)
        && in_array($EDITORE, $textList($xpMarc, '//marc:datafield[@tag="264"]/marc:subfield[@code="b"]'), true),
        'MARCXML 264 carries place ($a) and publisher ($b)'
    );
    check(
        in_array('mensile', $textList($xpMarc, '//marc:datafield[@tag="310"]/marc:subfield[@code="a"]'), true),
        'MARCXML 310 $a carries the publication frequency'
    );
    $leaders = $textList($xpMarc, '//marc:leader');
    check(
        $leaders !== [] && ($leaders[0][7] ?? '') === 's',
        'the MARCXML leader states bibliographic level "s" (serial)'
    );

    // An ISBN search must NEVER reach the serials arm.
    $sruIsbn = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation'      => 'searchRetrieve',
        'version'        => '1.2',
        'query'          => 'bath.isbn=' . str_replace('-', '', $ISSN),
        'recordSchema'   => 'dc',
        'maximumRecords' => 50,
    ]);
    check(
        !str_contains($sruIsbn, $TITLE_MAIN),
        'an ISBN search never returns mastheads (the serials arm ignores monograph-only indexes)'
    );

    $sruOr = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation' => 'searchRetrieve', 'version' => '1.2',
        'query' => 'dc.title="' . $TITLE_MAIN . '" OR bath.isbn="9780000000000"',
        'recordSchema' => 'dc', 'maximumRecords' => 50,
    ]);
    check(array_filter($textList($xpathOf($sruOr, 'SRU mixed OR'), '//*[local-name()="recordData"]//*[local-name()="title"]'),
        static fn(string $title): bool => str_contains($title, $TITLE_MAIN)) !== [],
        'SRU mixed OR retains matching serials when the other index is book-only');
    $sruAnd = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation' => 'searchRetrieve', 'version' => '1.2',
        'query' => 'dc.title="' . $TITLE_MAIN . '" AND bath.isbn="9780000000000"',
        'recordSchema' => 'dc', 'maximumRecords' => 50,
    ]);
    check(array_filter($textList($xpathOf($sruAnd, 'SRU mixed AND'), '//*[local-name()="recordData"]//*[local-name()="title"]'),
        static fn(string $title): bool => str_contains($title, $TITLE_MAIN)) === [],
        'SRU mixed AND still excludes serials without an ISBN');

    // Explain advertises the ISSN index.
    $explain = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation' => 'explain',
        'version'   => '1.2',
    ]);
    check(str_contains($explain, 'bath.issn'), 'SRU explain advertises the bath.issn index');

    // ── 7. MobileModule meta.truncated ────────────────────────────────
    $truncTestataId = $exec(
        "INSERT INTO emeroteca_testate (titolo, tipo) VALUES (?, 'rivista')",
        's',
        [$TITLE_TRUNC]
    );
    $bigYear = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 2001, '')",
        'i',
        [$truncTestataId]
    );
    $smallYear = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 2002, '')",
        'i',
        [$truncTestataId]
    );

    $tuples = [];
    for ($i = 1; $i <= $ISSUES_CAP + 1; $i++) {
        $tuples[] = '(' . $bigYear . ", '" . $i . "', '" . $i . "')";
    }
    $inserted = $db->query(
        'INSERT INTO emeroteca_fascicoli (annata_id, numero, numero_progressivo) VALUES '
        . implode(',', $tuples)
    );
    check($inserted !== false, ($ISSUES_CAP + 1) . ' issues seeded in one year (cap + 1)');
    for ($i = 1; $i <= 3; $i++) {
        $exec(
            'INSERT INTO emeroteca_fascicoli (annata_id, numero) VALUES (?, ?)',
            'is',
            [$smallYear, (string) $i]
        );
    }

    $mobile = new \App\Plugins\Emeroteca\Modules\MobileModule($db);
    $callIssues = static function (int $yearId) use ($mobile): array {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/periodicals/years/' . $yearId . '/issues'
        );
        $response = $mobile->yearIssues($request, new SlimResponse(), $yearId);
        $body = $response->getBody();
        $body->rewind();
        $decoded = json_decode($body->getContents(), true);

        return is_array($decoded) ? $decoded : [];
    };

    $big = $callIssues($bigYear);
    check(is_array($big['data'] ?? null), 'the issues endpoint answers a core envelope for the oversized year');
    check(
        count($big['data']) === $ISSUES_CAP,
        'the oversized year returns exactly the cap (' . count($big['data']) . ' of ' . ($ISSUES_CAP + 1) . ')'
    );
    check(($big['meta']['count'] ?? -1) === $ISSUES_CAP, 'meta.count matches the returned rows');
    check(($big['meta']['limit'] ?? null) === $ISSUES_CAP, 'meta.limit reports the cap');
    check(($big['meta']['truncated'] ?? null) === true, 'meta.truncated is TRUE past the cap');

    $small = $callIssues($smallYear);
    check(count($small['data'] ?? []) === 3, 'the small year returns all of its issues');
    check(($small['meta']['truncated'] ?? null) === false, 'meta.truncated is FALSE below the cap');
    check(
        array_key_exists('truncated', $small['meta'] ?? []),
        'meta.truncated is always present (additive field, never conditional)'
    );

    // The extra probe row must never leak into the payload.
    $numbers = array_map(static fn (array $issue): string => (string) ($issue['number'] ?? ''), $big['data']);
    check(
        count(array_unique($numbers)) === $ISSUES_CAP,
        'the cap+1 probe row is dropped, not returned as a duplicate'
    );

    // ══ Adversarial-review regressions (issue #140 review) ═════════════
    $errorCode = static function (string $xml, string $what) use ($xpathOf): string {
        $nodes = $xpathOf($xml, $what)->query('//oai:error');

        return ($nodes instanceof \DOMNodeList && $nodes->length > 0)
            ? (string) ($nodes->item(0)->attributes?->getNamedItem('code')?->nodeValue ?? '')
            : '';
    };

    // The window is derived from MySQL NOW(), not PHP: the OAI code compares
    // `from` against updated_at without any TZ conversion, so a PHP-side
    // timestamp would drift on an install where the two disagree.
    $sinceRes = $db->query("SELECT DATE_FORMAT(NOW() - INTERVAL 10 MINUTE, '%Y-%m-%dT%H:%i:%sZ') AS s");
    $since = ($sinceRes instanceof \mysqli_result)
        ? (string) ($sinceRes->fetch_assoc()['s'] ?? '')
        : '';
    check($since !== '', "harvest window derived from MySQL NOW() ({$since})");

    // A monograph that really carries an ISSN — libri.issn exists (it arrives
    // with migrate_0.4.7) and the SRU book query pulls it in via SELECT l.*.
    $bookId = $exec(
        "INSERT INTO libri (titolo, issn, anno_pubblicazione, lingua, tipo_media)
         VALUES (?, ?, 1999, 'italiano', 'libro')",
        'ss',
        [$BOOK_TITLE, $BOOK_ISSN]
    );
    check($bookId > 0, "monograph fixture with an ISSN created (id {$bookId}, ISSN {$BOOK_ISSN})");

    // ── 8. Unqualified harvest: the arms must match the format ────────
    // An unqualified ListRecords in a monograph-only format must not pull
    // mastheads into the UNION. If it did they would reach writeMetadata(),
    // throw cannotDisseminateFormat and be dropped one by one — and because
    // the page is ordered by datestamp, mastheads inserted in one session
    // cluster, so a whole page could end up with a resumptionToken and zero
    // <record> children, which the OAI-PMH XSD rejects (record minOccurs=1).
    foreach (['marcxml', 'mods', 'unimarc', 'mag'] as $monographFormat) {
        $unqualified = $oaiCall([
            'verb' => 'ListRecords',
            'metadataPrefix' => $monographFormat,
            'from' => $since,
        ]);
        $xpUnq = $xpathOf($unqualified, "ListRecords {$monographFormat} (unqualified)");
        $unqIds = $textList($xpUnq, '//oai:ListRecords/oai:record/oai:header/oai:identifier');
        check(
            array_filter($unqIds, static fn (string $id): bool => str_contains($id, ':periodical:')) === [],
            "set='' + metadataPrefix={$monographFormat} never yields a periodical record"
        );
        $lists = $xpUnq->query('//oai:ListRecords');
        $listCount = $lists instanceof \DOMNodeList ? $lists->length : 0;
        check(
            $listCount === 0 || $unqIds !== [],
            "set='' + metadataPrefix={$monographFormat} never emits a ListRecords page without records"
        );
    }

    // ListIdentifiers must announce exactly what GetRecord will serve.
    $unqIdentifiers = $oaiCall([
        'verb' => 'ListIdentifiers',
        'metadataPrefix' => 'marcxml',
        'from' => $since,
    ]);
    $xpUnqId = $xpathOf($unqIdentifiers, 'ListIdentifiers marcxml (unqualified)');
    $announced = $textList($xpUnqId, '//oai:ListIdentifiers/oai:header/oai:identifier');
    check(
        array_filter($announced, static fn (string $id): bool => str_contains($id, ':periodical:')) === [],
        "ListIdentifiers set='' + marcxml never announces a header GetRecord would refuse"
    );

    // …while oai_dc, which mastheads DO speak, still returns them unqualified.
    $unqDc = $oaiCall(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc', 'from' => $since]);
    $xpUnqDc = $xpathOf($unqDc, 'ListRecords oai_dc (unqualified)');
    $unqDcIds = $textList($xpUnqDc, '//oai:ListRecords/oai:record/oai:header/oai:identifier');
    check(
        array_filter($unqDcIds, static fn (string $id): bool => str_contains($id, ':periodical:')) !== [],
        "set='' + oai_dc still harvests mastheads (the arm guard restricts the format, not the set)"
    );
    // The book sorts after 100+ mastheads inserted in this same run, so the
    // union has to be followed through its resumption tokens to see it.
    $unqDcAll = $harvest(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc', 'from' => $since], 8);
    check(
        in_array('oai:' . $host . ':book:' . $bookId, $unqDcAll['identifiers'], true)
            && array_filter(
                $unqDcAll['identifiers'],
                static fn (string $id): bool => str_contains($id, ':periodical:')
            ) !== [],
        "set='' + oai_dc harvests books and mastheads in the same union"
    );

    // ── 9. Resumption tokens are bound to the union composition ───────
    // A token is an OFFSET into a UNION whose arms depend on plugin
    // activation. Toggling a content module mid-harvest shifts rows across
    // the offset and silently drops whatever crossed it.
    // Seed our own book pages: a fresh CI catalogue has fewer than PAGE_SIZE.
    for ($i = 0; $i < 101; $i++) {
        $exec("INSERT INTO libri (titolo, tipo_media) VALUES (?, 'libro')", 's', [$BOOK_TITLE . ' page ' . $i]);
    }
    $setEmerotecaActive(false);
    $compPage1 = $oaiCall(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc']);
    $xpComp1 = $xpathOf($compPage1, 'ListRecords page 1 (emeroteca off)');
    $compTokenNodes = $xpComp1->query('//oai:resumptionToken');
    $compToken = ($compTokenNodes instanceof \DOMNodeList && $compTokenNodes->length > 0)
        ? trim((string) $compTokenNodes->item(0)->textContent)
        : '';
    check($compToken !== '', 'an unqualified oai_dc harvest issues a resumption token');

    // Control: the very same token still resumes while nothing has changed.
    $compResume = $oaiCall(['verb' => 'ListRecords', 'resumptionToken' => $compToken]);
    check(
        $errorCode($compResume, 'resume (unchanged)') === '',
        'a resumption token resumes normally while the repository composition is unchanged'
    );

    // Now activate Emeroteca mid-harvest and reuse the token.
    $setEmerotecaActive(true);
    $compAfter = $oaiCall(['verb' => 'ListRecords', 'resumptionToken' => $compToken]);
    $compCode = $errorCode($compAfter, 'resume (composition changed)');
    check(
        $compCode === 'badResumptionToken',
        'a token reused after a content module was activated answers badResumptionToken '
        . "(got '{$compCode}') — the harvest restarts instead of skipping records"
    );

    // A token minted with the new composition resumes cleanly.
    $compPage1b = $oaiCall(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc']);
    $xpComp1b = $xpathOf($compPage1b, 'ListRecords page 1 (emeroteca on)');
    $compTokenNodesB = $xpComp1b->query('//oai:resumptionToken');
    $compTokenB = ($compTokenNodesB instanceof \DOMNodeList && $compTokenNodesB->length > 0)
        ? trim((string) $compTokenNodesB->item(0)->textContent)
        : '';
    check($compTokenB !== '' && $compTokenB !== $compToken, 'a fresh token is minted for the new composition');
    check(
        $errorCode($oaiCall(['verb' => 'ListRecords', 'resumptionToken' => $compTokenB]), 'resume (fresh)') === '',
        'the freshly minted token resumes without error'
    );

    // ── 10. ISSN fields are serial-only in the Z39.50/SRU formatters ──
    // Direct formatter checks: a monograph record carrying an ISSN must not
    // grow a MARC 022, a UNIMARC 011 or a urn:ISSN dc:identifier — those
    // describe a continuing resource, and books never emitted them before
    // the serials work.
    $monographRow = [
        'id'          => $bookId,
        'titolo'      => $BOOK_TITLE,
        'issn'        => $BOOK_ISSN,
        'e_issn'      => '9876-5432',
        'issn_l'      => $BOOK_ISSN,
        'lingua'      => 'italiano',
        'anno_pubblicazione' => 1999,
        'tipo_media'  => 'libro',
    ];
    $serialRow = $monographRow;
    $serialRow['_record_type'] = 'periodical';

    $formatXml = static function (string $format, array $row): string {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->appendChild(\Z39Server\RecordFormatter::create($format, $doc)->format($row));

        return (string) $doc->saveXML();
    };

    $bookMarc = $formatXml('marcxml', $monographRow);
    check(!str_contains($bookMarc, 'tag="022"'), 'MARCXML: a monograph with an ISSN emits no 022');
    check(str_contains($bookMarc, '00000nam'), 'MARCXML: the monograph leader still states bibliographic level "m"');
    $serialMarc = $formatXml('marcxml', $serialRow);
    check(str_contains($serialMarc, 'tag="022"'), 'MARCXML: a serial with the same ISSN still emits 022');

    $bookUnimarc = $formatXml('unimarcxml', $monographRow);
    check(!str_contains($bookUnimarc, 'tag="011"'), 'UNIMARC: a monograph with an ISSN emits no 011');
    $serialUnimarc = $formatXml('unimarcxml', $serialRow);
    check(str_contains($serialUnimarc, 'tag="011"'), 'UNIMARC: a serial with the same ISSN still emits 011');

    $bookDc = $formatXml('dc', $monographRow);
    check(!str_contains($bookDc, 'urn:ISSN:'), 'Dublin Core: a monograph with an ISSN emits no urn:ISSN identifier');
    $serialDc = $formatXml('dc', $serialRow);
    check(str_contains($serialDc, 'urn:ISSN:'), 'Dublin Core: a serial with the same ISSN still emits urn:ISSN');

    $monographRow['numerazione'] = '1999-2020';
    $serialRow['numerazione'] = '1999-2020';
    $bookMods = $formatXml('mods', $monographRow);
    check(!str_contains($bookMods, 'type="issn') && !str_contains($bookMods, 'type="numbering"'),
        'MODS monographs emit neither serial identifiers nor serial numbering');
    $serialMods = $formatXml('mods', $serialRow);
    check(str_contains($serialMods, 'type="issn"') && str_contains($serialMods, 'type="issn-e"')
        && str_contains($serialMods, 'type="issn-l"') && str_contains($serialMods, 'type="numbering"'),
        'MODS serials retain all three ISSN types and numbering');
    $field100 = static function (string $xml): string {
        $doc = new \DOMDocument(); $doc->loadXML($xml);
        return (string) (new \DOMXPath($doc))->evaluate('string(//*[local-name()="controlfield"][@tag="100"])');
    };
    check(substr($field100($serialUnimarc), 8, 9) === 'a19999999',
        'UNIMARC ongoing serial has date code a and end date 9999 (IFLA 100)');
    $closedUnimarc = $formatXml('unimarcxml', $serialRow + ['anno_fine' => 2020]);
    check(substr($field100($closedUnimarc), 8, 9) === 'b19992020',
        'UNIMARC ceased serial has date code b and the actual closing year');
    check(substr($field100($bookUnimarc), 8, 9) === 'a1999    ',
        'UNIMARC monograph date encoding is unchanged');

    // …and end to end, through the real SRU book query (SELECT l.*).
    $sruBook = (new \Z39Server\SRUServer($db, $sruSettings))->handleRequest([
        'operation'      => 'searchRetrieve',
        'version'        => '1.2',
        'query'          => 'dc.title="' . $BOOK_TITLE . '"',
        'recordSchema'   => 'marcxml',
        'maximumRecords' => 5,
    ]);
    check(str_contains($sruBook, $BOOK_TITLE), 'SRU finds the monograph fixture by title');
    check(
        !str_contains($sruBook, 'tag="022"'),
        'SRU marcxml: the monograph record carries no 022, exactly as before the serials work'
    );

    // ── 11. Periodical tombstones (deletedRecord=persistent) ──────────
    $oaiPlugin  = new \App\Plugins\OaiPmhServer\OaiPmhServerPlugin($db, $hm);
    $oaiSchema  = $oaiPlugin->ensureSchema();
    check(($oaiSchema['failed'] ?? ['x']) === [], 'oai-pmh-server ensureSchema() reports no failed tables');

    $trgRes = $db->query(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_emeroteca_hard_delete'"
    );
    $trgCount = ($trgRes instanceof \mysqli_result) ? (int) ($trgRes->fetch_assoc()['c'] ?? 0) : 0;
    check($trgCount === 1, 'the AFTER DELETE tombstone trigger is installed on emeroteca_testate');

    $doomedId = $pageIds[0];
    $doomedOaiId = 'oai:' . $host . ':periodical:' . $doomedId;
    check(
        $db->query('DELETE FROM emeroteca_testate WHERE id = ' . $doomedId) !== false,
        "masthead {$doomedId} hard-deleted the way the Emeroteca admin deletes it"
    );
    $tombRes = $db->query('SELECT COUNT(*) AS c FROM oai_deleted_periodicals WHERE entity_id = ' . $doomedId);
    $tombCount = ($tombRes instanceof \mysqli_result) ? (int) ($tombRes->fetch_assoc()['c'] ?? 0) : 0;
    check($tombCount === 1, 'the hard delete left a tombstone, so deletedRecord=persistent is not a lie');

    $getDeleted = $oaiCall([
        'verb' => 'GetRecord',
        'identifier' => $doomedOaiId,
        'metadataPrefix' => 'oai_dc',
    ]);
    $xpDeleted = $xpathOf($getDeleted, 'GetRecord (deleted masthead)');
    $deletedStatus = $xpDeleted->query('//oai:GetRecord/oai:record/oai:header/@status');
    check(
        $deletedStatus instanceof \DOMNodeList
            && $deletedStatus->length === 1
            && trim((string) $deletedStatus->item(0)->nodeValue) === 'deleted',
        'GetRecord reports the deleted masthead with status="deleted" instead of idDoesNotExist'
    );

    $identifyXml = $oaiCall(['verb' => 'Identify']);
    $xpIdentify = $xpathOf($identifyXml, 'Identify');
    check(
        $textList($xpIdentify, '//oai:Identify/oai:deletedRecord') === ['persistent'],
        'Identify still advertises deletedRecord=persistent, now truthfully for every set'
    );

    // ── 12. SRU survives an install without libri.issn ────────────────
    // libri.issn arrives with migrate_0.4.7 — the core BookRepository guards
    // every use of it behind hasColumn() for exactly this reason. Referencing
    // it unguarded from cql.anywhere (the DEFAULT index) would make nearly
    // every SRU request die with "Unknown column 'l.issn'". Simulated by
    // priming the probe cache, which is the branch that decides.
    $sruProbe = new \Z39Server\SRUServer($db, $sruSettings);
    $ref = new \ReflectionClass($sruProbe);
    $cacheProp = $ref->getProperty('columnProbeCache');
    $cacheProp->setAccessible(true);
    $cacheProp->setValue($sruProbe, ['libri.issn' => false]);

    $compile = $ref->getMethod('compileConditionClause');
    $compile->setAccessible(true);
    $anywhereSql = (string) $compile->invoke($sruProbe, 'cql.anywhere', '=', 'qualunque');
    check(
        !str_contains($anywhereSql, 'l.issn'),
        'cql.anywhere drops l.issn from the WHERE clause when the column is absent'
    );
    check(
        str_contains($anywhereSql, 'l.titolo'),
        'cql.anywhere keeps every column that does exist'
    );
    $issnSql = (string) $compile->invoke($sruProbe, 'bath.issn', '=', '1234-5678');
    check(
        $issnSql === '1=0',
        "bath.issn degrades to a no-match clause instead of invalid SQL (got '{$issnSql}')"
    );
    // The clause must also be executable — a syntactically valid no-match.
    $probeRes = $db->query('SELECT COUNT(*) AS c FROM libri l WHERE l.deleted_at IS NULL AND (' . $issnSql . ')');
    check(
        $probeRes instanceof \mysqli_result && (int) ($probeRes->fetch_assoc()['c'] ?? -1) === 0,
        'the degraded bath.issn clause is valid SQL and matches nothing'
    );

    // The serial boolean compiler must never accept an operator it cannot
    // render: 'a NOT b' is not SQL.
    $serialBool = $ref->getMethod('buildSerialWhereClause');
    $serialBool->setAccessible(true);
    $notNode = [
        'type' => 'boolean',
        'operator' => 'NOT',
        'left'  => ['type' => 'condition', 'index' => 'dc.title', 'relation' => '=', 'value' => 'a'],
        'right' => ['type' => 'condition', 'index' => 'dc.title', 'relation' => '=', 'value' => 'b'],
    ];
    check(
        $serialBool->invoke($sruProbe, $notNode) === null,
        "a 'boolean' node with operator NOT is rejected, not compiled to 'a NOT b'"
    );
    $andNode = $notNode;
    $andNode['operator'] = 'AND';
    $andSql = $serialBool->invoke($sruProbe, $andNode);
    check(
        is_string($andSql) && str_contains($andSql, ' AND '),
        'AND/OR boolean nodes still compile on the serials arm'
    );
    $realNot = $serialBool->invoke($sruProbe, [
        'type' => 'not',
        'operand' => ['type' => 'condition', 'index' => 'dc.title', 'relation' => '=', 'value' => 'a'],
    ]);
    check(
        is_string($realNot) && str_starts_with($realNot, '(NOT '),
        'real negation still works — it is a `not` node, which is what CQLParser emits'
    );
    // More than six fully broken pages must not hide the first valid record.
    // Only the test namespace's strip_tags wrapper throws; real DB paging and
    // the production per-record XML buffering are exercised unchanged.
    $badStamp = '2003-04-05 06:07:08';
    for ($i = 0; $i < 601; $i++) {
        $exec("INSERT INTO libri (titolo, descrizione, tipo_media, updated_at) VALUES (?, ?, 'libro', ?)",
            'sss', [$BOOK_TITLE . ' page malformed ' . $i, 'zz-oai419-unrenderable:' . $i, $badStamp]);
    }
    $lastGood = $exec("INSERT INTO libri (titolo, tipo_media, updated_at) VALUES (?, 'libro', ?)",
        'ss', [$BOOK_TITLE . ' page last valid', $badStamp]);
    $afterBadPages = $oaiCall(['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc', 'set' => 'books',
        'from' => '2003-04-05T06:07:08Z', 'until' => '2003-04-05T06:07:08Z']);
    check($errorCode($afterBadPages, 'harvest after malformed pages') === '',
        'OAI does not falsely terminate after six unrenderable pages');
    check(str_contains($afterBadPages, ':book:' . $lastGood . '</identifier>'),
        'the first valid record beyond the former skip limit reaches the harvester');

} finally {
    $cleanup();
    if ($originalActive !== null) {
        $setEmerotecaActive($originalActive === 1);
    }
    $db->close();
}

printf("\nALL %d PASS\n", $TESTNO);
