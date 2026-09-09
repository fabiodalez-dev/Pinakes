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
 *      non-numeric designations verbatim, and yields to a curator-written
 *      consistenza_dichiarata when present;
 *   7. atteso / reclamato / scartato / smarrito count neither as owned nor as
 *      a gap;
 *   8. the ACNP CSV row is a single physical line with RFC 4180 quoting and
 *      the per-year holdings statement;
 *   9. labels: the PDF is non-empty and really is a PDF, the issue's own
 *      barcode wins over the title's barcode_base, an issue without either
 *      still gets a label, and the HTML sheet escapes a title carrying
 *      `"><script>` (no executable markup reaches the page).
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

$supportDir = __DIR__ . '/../storage/plugins/emeroteca/src/Support';
require_once $supportDir . '/IssnHelper.php';
require_once $supportDir . '/KbartExporter.php';
require_once $supportDir . '/IssueLabelRenderer.php';

use App\Plugins\Emeroteca\Support\IssnHelper;
use App\Plugins\Emeroteca\Support\IssueLabelRenderer;
use App\Plugins\Emeroteca\Support\KbartExporter;

// ── fixtures ─────────────────────────────────────────────────────────
$RUN = 'zz_emuexp_' . bin2hex(random_bytes(4));
$TITLE_MAIN   = "zz_Rivista Export {$RUN}";
$TITLE_PREV   = "zz_Rivista Precedente {$RUN}";
$TITLE_XSS    = "zz_XSS {$RUN} \"><script>alert(1)</script>";
$PUBLISHER    = "zz_Editore Export {$RUN}";
// Real, checksum-valid ISSNs (0378-5955 is the ISO 3297 textbook example).
$ISSN  = '0378-5955';
$EISSN = '1476-4687';

$cleanup = static function () use ($db, $TITLE_MAIN, $TITLE_PREV, $TITLE_XSS, $PUBLISHER): void {
    $titles = implode(',', array_map(
        static fn (string $t): string => "'" . $db->real_escape_string($t) . "'",
        [$TITLE_MAIN, $TITLE_PREV, $TITLE_XSS]
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

    $declared = '1998: annata rilegata, completa';
    $updDecl = $db->prepare('UPDATE emeroteca_annate SET consistenza_dichiarata = ? WHERE id = ?');
    check($updDecl !== false, 'declared-consistency fixture: update prepared');
    $updDecl->bind_param('si', $declared, $annata1998);
    check($updDecl->execute(), 'declared-consistency fixture: consistenza_dichiarata written');
    $updDecl->close();
    check(
        KbartExporter::consistenzaAnnata($db, $annata1998) === $declared,
        'a curator-written consistenza_dichiarata wins over the computed statement'
    );
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
} catch (\Throwable $e) {
    $cleanup();
    $db->close();
    fwrite(STDERR, "\nFAIL: " . $e->getMessage() . "\n");
    exit(1);
}

$cleanup();
$db->close();

printf("\nALL %d PASS\n", $TESTNO);
