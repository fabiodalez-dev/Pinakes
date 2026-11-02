<?php
declare(strict_types=1);

/**
 * Inventory stocktake (plan §8) against the real database: the snapshot taken
 * at opening is independent of later catalogue edits, every scan lands in the
 * right bucket, the buckets add up, and closing with "mark as lost" changes
 * only the missing copies (never one lent, waiting for pickup or damaged),
 * recalculates availability and writes the audit log. Once closed, the
 * report is frozen: loans and returns after the close change nothing.
 *
 * Touches only rows it creates (titles/codes prefixed ZZST) and removes them.
 * Run: php tests/stocktake-snapshot.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
// The module under test is on, whatever features.* says in the database
// this runs against (Settings → Modules / Loans).
\App\Support\Features::override('stocktake', true);
require_once dirname(__DIR__) . '/app/helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

use App\Services\StocktakeService;

$env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');

if ($db->query("SHOW TABLES LIKE 'inventario_sessioni'")->num_rows === 0) {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL inventario_sessioni missing (schema not installed)\n");
        exit(1);
    }
    echo "SKIP: inventario_sessioni table missing — apply migrate_0.9.0-rc.1.sql first\n";
    exit(0);
}

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};
$one = static function (string $sql) use ($db): ?string {
    $row = $db->query($sql)->fetch_row();
    return $row === null || $row[0] === null ? null : (string) $row[0];
};

$run = strtoupper(bin2hex(random_bytes(3)));
$p = 'ZZST' . $run;
$shelves = [];
$books = [];
$sessionIds = [];
$triggers = [];
$borrowerId = 0;
$other = null;
try {
    foreach (['X', 'Y'] as $tag) {
        $db->query("INSERT INTO scaffali (codice, nome, lettera) VALUES ('{$p}{$tag}', 'Stocktake {$tag}', '{$tag}')");
        $shelves[$tag] = (int) $db->insert_id;
    }
    $addBook = static function (string $title, int $shelf) use ($db, $p, &$books): int {
        $db->query("INSERT INTO libri (titolo, scaffale_id, collocazione, copie_totali, copie_disponibili) VALUES ('{$p} {$title}', {$shelf}, '{$p}.{$title}', 1, 1)");
        $books[$title] = (int) $db->insert_id;
        return $books[$title];
    };
    // Created an hour ago: a copy changed in the very second of a close counts
    // as changed after it, and a fast run closes within the second it starts.
    $addCopy = static function (int $book, string $code, string $state = 'disponibile') use ($db, $p): int {
        $db->query("INSERT INTO copie (libro_id, numero_inventario, stato, updated_at) VALUES ({$book}, '{$p}-{$code}', '{$state}', NOW() - INTERVAL 1 HOUR)");
        return (int) $db->insert_id;
    };
    $b1 = $addBook('B1', $shelves['X']);
    $b2 = $addBook('B2', $shelves['X']);
    $b3 = $addBook('B3', $shelves['Y']);
    $c1 = $addCopy($b1, 'C1');
    $c2 = $addCopy($b1, 'C2');
    $c6 = $addCopy($b1, 'C6');
    $c3 = $addCopy($b2, 'C3', 'prestato');
    $c4 = $addCopy($b3, 'C4');
    // C7 is on the pickup shelf for a reader: 'prenotato' with a 'da_ritirare' loan. C8 gets damaged during the stocktake.
    $c7 = $addCopy($b2, 'C7', 'prenotato');
    $c8 = $addCopy($b1, 'C8');
    // C9 and C10 are already out of circulation when the session opens: nobody expects them on the shelf.
    $c9 = $addCopy($b1, 'C9', 'perso');
    $c10 = $addCopy($b1, 'C10', 'in_restauro');
    $db->query("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, locale) VALUES ('{$p}', 'Reader', '{$p}', '{$p}@stocktake.test.local', 'x', 'standard', 'attivo', 1, 1, 'it_IT')");
    $borrowerId = (int) $db->insert_id;
    $db->query("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, pickup_deadline, stato, origine, attivo) VALUES ({$b2}, {$c7}, {$borrowerId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'da_ritirare', 'prenotazione', 1)");
    $holdId = (int) $db->insert_id;
    $db->query("UPDATE libri SET copie_totali = 6, copie_disponibili = 4 WHERE id = {$b1}");
    $db->query("UPDATE libri SET copie_totali = 2, copie_disponibili = 0 WHERE id = {$b2}");

    $service = new StocktakeService($db);
    $sessionId = $service->open('ZZST ' . $run, null, ['scaffale_id' => (string) $shelves['X']], null);
    $sessionIds[] = $sessionId;
    $check((int) $one("SELECT totale_attese FROM inventario_sessioni WHERE id = {$sessionId}") === 6, 'the snapshot holds the six copies on shelf X');
    $check($one("SELECT GROUP_CONCAT(copia_id ORDER BY copia_id) FROM inventario_attese WHERE sessione_id = {$sessionId}") === implode(',', [$c1, $c2, $c6, $c3, $c7, $c8]), 'exactly the shelf-X copies are expected: a copy already lost or at the restorer\'s at opening is not');
    $check($one("SELECT stato_all_apertura FROM inventario_attese WHERE sessione_id = {$sessionId} AND copia_id = {$c3}") === 'prestato' && $one("SELECT stato_all_apertura FROM inventario_attese WHERE sessione_id = {$sessionId} AND copia_id = {$c7}") === 'prenotato', 'the snapshot records each copy state at opening');

    // Catalogue edits after opening must not change what the session expects.
    $db->query("UPDATE libri SET scaffale_id = {$shelves['Y']} WHERE id = {$b1}");
    $c5 = $addCopy($b2, 'C5');
    $db->query("DELETE FROM copie WHERE id = {$c2}");
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$b3}");
    $db->query("UPDATE copie SET stato = 'danneggiato' WHERE id = {$c8}");
    $check((int) $one("SELECT COUNT(*) FROM inventario_attese WHERE sessione_id = {$sessionId}") === 6, 'moving a book, adding and deleting copies leaves the snapshot intact');
    $check((int) $one("SELECT scaffale_atteso FROM inventario_attese WHERE sessione_id = {$sessionId} AND copia_id = {$c1}") === $shelves['X'], 'the expected shelf is the one at opening, not the current one');

    $x = $shelves['X'];
    $check($service->scan($sessionId, "{$p}-C1\r", null, $x)['esito'] === 'trovata', 'C1 scanned on shelf X is found although its book was moved since');
    $c5Scan = $service->scan($sessionId, "{$p}-C5", null, $x);
    $check($c5Scan['esito'] === 'fuori_ambito' && $c5Scan['fuori_circolazione'] === false, 'a copy added after opening is out of scope, and not flagged as out of circulation');
    $check($service->scan($sessionId, "{$p}-C4", null, $x)['esito'] === 'sconosciuta', 'a copy of a book removed from the catalogue is unknown');
    $check($service->scan($sessionId, "{$p}-NOPE", null, $x)['esito'] === 'sconosciuta', 'an unknown code is unknown');
    $check($service->scan($sessionId, strtolower("{$p}-c1"), null, $x)['esito'] === 'duplicata', 'scanning C1 again (any case) is a duplicate');
    $check($service->scan($sessionId, "{$p}-C3", null, $x)['esito'] === 'in_prestito', 'a copy on loan is reported as on loan');
    $check((int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$sessionId} AND copia_id = {$c1}") === 1, 'the UNIQUE (sessione_id, copia_id) row stays single: duplicates are stored without a copy id');

    $summary = $service->summary($sessionId);
    $check($summary['attese'] === 6 && $summary['trovate'] === 1 && $summary['fuori_posto'] === 0 && $summary['in_prestito'] === 2 && $summary['mancanti'] === 3, 'found 1, on loan 2 (C3 lent, C7 waiting for pickup), missing 3 (C2 deleted, C6 unscanned, C8 damaged)');
    $check($summary['trovate'] + $summary['fuori_posto'] + $summary['in_prestito'] + $summary['mancanti'] === $summary['attese'], 'the four buckets add up to the expected copies');
    $check($summary['sconosciute'] === 2 && $summary['fuori_ambito'] === 1 && $summary['duplicate'] === 1 && $summary['scansioni'] === 6, 'side counters: 2 unknown, 1 out of scope, 1 duplicate, 6 scans');
    $check($summary['perse'] === 0 && (int) $one("SELECT COUNT(*) FROM inventario_attese WHERE sessione_id = {$sessionId} AND esito_chiusura IS NULL") === 6, 'while the session is open no bucket is frozen and nothing is marked lost');

    $categories = array_count_values(array_column($service->reportRows($sessionId), 'categoria'));
    $check(($categories['mancante'] ?? 0) === 3 && ($categories['trovata'] ?? 0) === 1 && ($categories['in_prestito'] ?? 0) === 2, 'the report rows list the same buckets');
    $c2Rows = array_values(array_filter($service->reportRows($sessionId), static fn (array $r): bool => $r['categoria'] === 'mancante' && $r['codice'] === "{$p}-C2"));
    $check(count($c2Rows) === 1 && $c2Rows[0]['titolo'] === "{$p} B1", 'a copy deleted during the stocktake keeps the code and title frozen at opening in the report');
    $csvBody = (string) (new App\Controllers\InventoryController())->exportCsv((new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/'), new Slim\Psr7\Response(), $db, $sessionId)->getBody();
    $csvRows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(preg_split('/\r\n|\n/', substr($csvBody, 3)) ?: [], static fn (string $l): bool => $l !== '')));
    $mediaByCode = array_column(array_slice($csvRows, 1), 8, 1);
    $check(count($csvRows[0]) === 9 && $csvRows[0][8] === __('Tipo media'), 'the CSV export ends with the media type column');
    $check(($mediaByCode["{$p}-C1"] ?? null) === __('Libro') && ($mediaByCode["{$p}-NOPE"] ?? null) === '', 'a copy carries its media type, an unknown code an empty cell rather than a default');

    // Probe: record the session state at the instant a copy turns 'perso'.
    // close() must claim the session before it marks anything, so scans that
    // arrive while copies are being marked are refused (review #504).
    $probe = 'zzst_probe_' . strtolower($run);
    $db->query("SET @zzst_session = {$sessionId}, @zzst_state_at_lost = NULL");
    $db->query("CREATE TRIGGER {$probe} BEFORE UPDATE ON copie FOR EACH ROW BEGIN IF NEW.stato = 'perso' AND OLD.stato <> 'perso' AND @zzst_state_at_lost IS NULL THEN SET @zzst_state_at_lost = (SELECT stato FROM inventario_sessioni WHERE id = @zzst_session); END IF; END");
    $triggers[] = $probe;
    $result = $service->close($sessionId, true, null);
    $db->query("DROP TRIGGER IF EXISTS {$probe}");
    $check($one('SELECT @zzst_state_at_lost') === 'chiusa', 'the session is already closed when the first copy is marked lost');
    $check($result === ['marked' => 1, 'failed' => 0], 'closing with "mark lost" marks only C6 (C2 no longer exists, C3 is lent, C7 waits for pickup, C8 is damaged)');
    $check($one("SELECT stato FROM copie WHERE id = {$c6}") === 'perso', 'C6 is now lost');
    $check($one("SELECT stato FROM copie WHERE id = {$c1}") === 'disponibile' && $one("SELECT stato FROM copie WHERE id = {$c3}") === 'prestato', 'found and lent copies are untouched');
    $check($one("SELECT stato FROM copie WHERE id = {$c7}") === 'prenotato' && $one("SELECT CONCAT(stato, ':', attivo, ':', copia_id) FROM prestiti WHERE id = {$holdId}") === "da_ritirare:1:{$c7}", 'the copy on the pickup shelf keeps its state and its hold is not reassigned');
    $check($one("SELECT stato FROM copie WHERE id = {$c8}") === 'danneggiato' && $one("SELECT stato FROM copie WHERE id = {$c9}") === 'perso' && $one("SELECT stato FROM copie WHERE id = {$c10}") === 'in_restauro', 'a damaged copy is not overwritten to lost; copies out of circulation at opening are untouched');
    $check((int) $one("SELECT copie_disponibili FROM libri WHERE id = {$b1}") === 1, 'book availability is recalculated (1 of the 3 remaining copies)');
    $check((int) $one("SELECT COUNT(*) FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$b1} AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) = 'copy.inventory_lost'") === 1, 'an ActivityLog entry records the copy declared lost');
    $check($one("SELECT CONCAT(stato, ':', azione_mancanti) FROM inventario_sessioni WHERE id = {$sessionId}") === 'chiusa:perso', 'the session is closed and remembers it was closed with "mark lost"');
    $check($one("SELECT GROUP_CONCAT(CONCAT(copia_id, ':', esito_chiusura) ORDER BY copia_id) FROM inventario_attese WHERE sessione_id = {$sessionId}") === "{$c1}:trovata,{$c2}:mancante,{$c6}:persa,{$c3}:in_prestito,{$c7}:in_prestito,{$c8}:mancante", 'closing freezes each expected copy\'s bucket: the scan outcome, on loan, missing, or lost when the close marked it');
    $closedSummary = $service->summary($sessionId);
    $check($closedSummary['trovate'] === 1 && $closedSummary['fuori_posto'] === 0 && $closedSummary['in_prestito'] === 2 && $closedSummary['mancanti'] === 3 && $closedSummary['perse'] === 1 && $closedSummary['attese'] === 6, 'the closed summary reads found 1, on loan 2, missing 3 of which 1 marked lost');
    $check($closedSummary['trovate'] + $closedSummary['fuori_posto'] + $closedSummary['in_prestito'] + $closedSummary['mancanti'] === $closedSummary['attese'], 'the frozen buckets add up to the expected copies');
    try {
        $service->scan($sessionId, "{$p}-C6", null, null);
        $check(false, 'a closed session refuses scans');
    } catch (DomainException) {
        $check(true, 'a closed session refuses scans');
    }

    // Upload path + cancel: nothing is modified.
    $second = $service->open('ZZST2 ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $second;
    $check((int) $one("SELECT totale_attese FROM inventario_sessioni WHERE id = {$second}") === 3, 'a collocation range opens on B2 alone (C3, C5, C7)');
    // The index lists every open session; the limit only trims closed history.
    $listed = array_map(static fn (array $r): int => (int) $r['id'], $service->listSessions(1));
    $openIds = array_map('intval', array_column($db->query("SELECT id FROM inventario_sessioni WHERE stato = 'aperta'")->fetch_all(), 0));
    $check(array_diff($openIds, $listed) === [] && in_array($second, $listed, true), 'listSessions() returns every open session whatever the limit');
    $check(count($listed) - count($openIds) <= 1, 'the limit applies to closed and cancelled sessions only');
    $import = $service->importCodes($second, StocktakeService::parseCodeList("codice\n{$p}-C5\n{$p}-C5\n{$p}-C1\n{$p}-C7\n"), null, null);
    $check($import['total'] === 4 && $import['outcomes']['trovata'] === 1 && $import['outcomes']['duplicata'] === 1 && $import['outcomes']['fuori_ambito'] === 1 && $import['outcomes']['in_prestito'] === 1, 'an uploaded list is classified line by line; a copy waiting for pickup is reported with the lent ones');
    // A copy recorded as lost is outside every snapshot; when it turns up the
    // operator is told it was found, not that it is outside the filter (review #504).
    $c9Scan = $service->scan($second, "{$p}-C9", null, null);
    $check($c9Scan['esito'] === 'fuori_ambito' && $c9Scan['fuori_circolazione'] === true && $c9Scan['stato_copia'] === 'perso', 'a lost copy that turns up is flagged as out of circulation with its recorded state');
    // A code longer than the column is refused, never cut down to the code of
    // another copy (review #504): here the first 100 characters are C100's code.
    $longCode = $addCopy($b1, str_repeat('L', StocktakeService::MAX_CODE_LENGTH - strlen($p) - 1));
    $longText = (string) $one("SELECT numero_inventario FROM copie WHERE id = {$longCode}");
    $scansBefore = (int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$second}");
    $tooLong = static function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    };
    $check(mb_strlen($longText) === StocktakeService::MAX_CODE_LENGTH && $tooLong(static fn () => $service->scan($second, $longText . 'Z', null, null)), 'a scanned code one character longer than a stored code is refused');
    $check($tooLong(static fn () => $service->importCodes($second, ["{$p}-C3", $longText . 'Z'], null, null)), 'an uploaded list holding a code that is too long is refused');
    $check((int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$second}") === $scansBefore, 'a refused code records nothing, and neither do the codes before it in the file');
    // An upload is one unit (review #504): a write that fails on the second
    // code takes the first one back, so the retry does not find it duplicate.
    $importFailure = 'zzst_import_' . strtolower($run);
    $db->query("CREATE TRIGGER {$importFailure} BEFORE INSERT ON inventario_scansioni FOR EACH ROW BEGIN IF NEW.codice_letto = '{$p}-BOOM' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ZZST simulated scan failure'; END IF; END");
    $triggers[] = $importFailure;
    $importFailed = false;
    try {
        $service->importCodes($second, ["{$p}-C3", "{$p}-BOOM"], null, null);
    } catch (mysqli_sql_exception) {
        $importFailed = true;
    }
    $db->query("DROP TRIGGER {$importFailure}");
    $check($importFailed && (int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$second}") === $scansBefore, 'an upload that fails halfway records none of its codes');
    $retry = $service->importCodes($second, ["{$p}-C3"], null, null);
    $check($retry['outcomes']['in_prestito'] === 1 && $retry['outcomes']['duplicata'] === 0, 'uploading the file again records its codes, not duplicates');
    $service->cancel($second, null);
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$second}") === 'annullata' && $one("SELECT stato FROM copie WHERE id = {$c5}") === 'disponibile', 'cancelling leaves every copy as it was');
    // The PDF header dates the end of a session by what ended it (review #504).
    $pdfGenerator = new App\Support\StocktakePdfGenerator();
    $cancelledMeta = implode(' | ', $pdfGenerator->metaLines((array) $service->find($second)));
    $closedMeta = implode(' | ', $pdfGenerator->metaLines((array) $service->find($sessionId)));
    $check(str_contains($cancelledMeta, __('Annullata il') . ': ') && !str_contains($cancelledMeta, __('Chiusa il')), 'the PDF of a cancelled session says when it was cancelled, not closed');
    $check(str_contains($closedMeta, __('Chiusa il') . ': ') && !str_contains($closedMeta, __('Annullata il')), 'the PDF of a closed session says when it was closed');
    $check(str_starts_with($pdfGenerator->generate((array) $service->find($second), $service->summary($second), $service->reportRows($second), [], []), '%PDF'), 'the PDF of a cancelled session is still generated');
    $check($service->reportRowCount($sessionId) === count($service->reportRows($sessionId)) && $service->reportRowCount($second) === count($service->reportRows($second)), 'reportRowCount() counts exactly the report rows');
    // The app reads the report a page at a time, keyset-paged in the
    // database (review #518): the pages put together are the report, across
    // the seam between the expected copies and the scans, and a category
    // filter pages and counts only that category.
    $readReport = static function (int $sid, ?string $category, int $limit, int $upTo, ?callable $afterFirst = null) use ($service): array {
        $rows = [];
        $after = null;
        $pages = 0;
        do {
            $page = $service->reportPage($sid, $category, $limit, $upTo, $after);
            if (count($page['rows']) > $limit) {
                throw new RuntimeException('FAIL a report page holds more rows than asked');
            }
            $rows = array_merge($rows, $page['rows']);
            $after = $page['next'];
            if (++$pages === 1 && $afterFirst !== null) {
                $afterFirst();
            }
        } while ($after !== null && $pages < 100000);
        return $rows;
    };
    // The API pages in its own stable order: compare the rows, not their order.
    $sorted = static function (array $rows): array {
        $keys = array_map(static fn (array $r): string => json_encode($r, JSON_THROW_ON_ERROR), $rows);
        sort($keys);
        return $keys;
    };
    foreach ([$sessionId => 'closed', $second => 'cancelled'] as $sid => $what) {
        $all = $service->reportRows($sid);
        $upTo = $service->latestScanId($sid);
        $paged = $readReport($sid, null, 2, $upTo);
        $check(count($paged) === count($all) && $sorted($paged) === $sorted($all), "reportPage() pages of 2 put together are the whole report of the {$what} session");
        // The order the API promises: the unscanned copies first, by the
        // inventory number frozen at opening and then the copy id (review #518).
        $expectedOrder = array_column($db->query(
            "SELECT COALESCE(c.numero_inventario, a.codice_atteso) FROM inventario_attese a "
            . "LEFT JOIN inventario_scansioni sc ON sc.sessione_id = a.sessione_id AND sc.copia_id = a.copia_id "
            . "LEFT JOIN copie c ON c.id = a.copia_id "
            . "WHERE a.sessione_id = {$sid} AND sc.id IS NULL ORDER BY COALESCE(a.codice_atteso, ''), a.copia_id"
        )->fetch_all(), 0);
        $check(($what !== 'closed' || count($expectedOrder) >= 2) && array_column(array_slice($paged, 0, count($expectedOrder)), 'codice') === $expectedOrder && array_column(array_slice($paged, 0, count($expectedOrder)), 'quando_ts') === array_fill(0, count($expectedOrder), ''), "the unscanned copies of the {$what} session come first, by inventory number at opening then copy id");
        foreach (array_unique(array_merge(array_column($all, 'categoria'), ['mancante', 'in_prestito', 'trovata'])) as $category) {
            $only = array_values(array_filter($all, static fn (array $r): bool => $r['categoria'] === $category));
            $check($sorted($readReport($sid, $category, 1, $upTo)) === $sorted($only) && $service->reportRowCount($sid, $category, $upTo) === count($only), "reportPage() and reportRowCount() of the {$what} session keep only '{$category}' rows");
        }
    }
    // The scan list is pinned to its first page: a scan recorded later stays out.
    $latest = $service->latestScanId($second);
    $check($latest === (int) $one("SELECT MAX(id) FROM inventario_scansioni WHERE sessione_id = {$second}"), 'latestScanId() is the newest scan of the session');
    // A scan in progress on another connection (the session row in share
    // mode, its id taken, not committed yet): latestScanId() waits for it
    // instead of pinning below it (review #518). The wait is cut to a second
    // here, so it shows as a lock timeout; once committed, it is seen.
    $other = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
    $other->set_charset('utf8mb4');
    $other->begin_transaction();
    $other->query("SELECT stato FROM inventario_sessioni WHERE id = {$second} LOCK IN SHARE MODE")->fetch_all();
    $other->query("INSERT INTO inventario_scansioni (sessione_id, codice_letto, esito, scansionato_il) VALUES ({$second}, '{$p}-INFLIGHT', 'sconosciuta', NOW())");
    $inFlight = (int) $other->insert_id;
    $db->query('SET SESSION innodb_lock_wait_timeout = 1');
    $waited = false;
    try {
        $service->latestScanId($second);
    } catch (mysqli_sql_exception $e) {
        $waited = $e->getCode() === 1205;
    } finally {
        $db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    }
    $other->commit();
    $other->close();
    $check($waited, 'latestScanId() waits for a scan still being written instead of returning the committed maximum');
    $check($service->latestScanId($second) === $inFlight, 'once that scan commits, latestScanId() returns its id');
    $db->query("DELETE FROM inventario_scansioni WHERE id = {$inFlight}");
    $check(!in_array($latest, array_map(static fn (array $r): int => (int) $r['id'], $service->scans($second, null, 200, 0, null, $latest - 1)), true) && $service->scanCount($second, null, null, $latest - 1) === $service->scanCount($second) - 1, 'scans() and scanCount() with $upToId leave out the scans after it');
    // An open session's report pinned to the scans of the first page
    // (review #518): a scan between two pages leaves the copy among the
    // expected rows of that report, so nothing is skipped or repeated.
    $pin = $service->open('ZZST pin ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $pin;
    $pinFull = $service->reportRows($pin);
    $pinUpTo = $service->latestScanId($pin);
    $pinScanId = 0;
    $pinRead = $readReport($pin, null, 2, $pinUpTo, static function () use ($db, $service, $pin, $p, &$pinScanId, $check): void {
        // The instants are the database's own: a connection in another zone
        // writes NOW() in it and UNIX_TIMESTAMP() reads it back in it.
        $db->query("SET SESSION time_zone = '+05:00'");
        try {
            $before = time();
            $pinScanId = (int) $service->scan($pin, "{$p}-C5", null, null)['scansione_id'];
            $pinRow = (array) $service->scanRow($pin, $pinScanId);
            $check(abs((int) $pinRow['scansionato_il_ts'] - $before) <= 5, 'a scan instant is the real one whatever zone the connection writes and reads in');
        } finally {
            $db->query('SET SESSION time_zone = DEFAULT');
        }
    });
    $check($service->reportRowCount($pin) === count($pinFull) && $service->reportRowCount($pin, 'mancante') + $service->reportRowCount($pin, 'in_prestito') === count($pinFull) - 1, 'the scan moved one copy from the expected rows to the scans (same total)');
    $check($sorted($pinRead) === $sorted($pinFull) && $service->reportRowCount($pin, null, $pinUpTo) === count($pinFull), 'pinned pages put together are the report of the first page, with nothing skipped or repeated');
    // A session closed between two pages: the pages read after the close
    // continue the same report (the copy scanned after the first page keeps
    // the bucket of a copy not scanned, not its frozen scan outcome).
    $pc = $service->open('ZZST pinclose ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $pc;
    $pcFull = $service->reportRows($pc);
    $pcUpTo = $service->latestScanId($pc);
    $pcRead = $readReport($pc, null, 1, $pcUpTo, static function () use ($service, $pc, $p): void {
        $service->scan($pc, "{$p}-C5", null, null);
        $service->close($pc, false, null);
    });
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$pc}") === 'chiusa' && $sorted($pcRead) === $sorted($pcFull), 'a report paged across the close of its session is the report of the first page');
    $check($service->reportRowCount($pc, 'mancante', $pcUpTo) === count(array_filter($pcFull, static fn (array $r): bool => $r['categoria'] === 'mancante')), 'and its category counts are those of the first page');

    // The session list pinned to a snapshot (review #518): the sessions open
    // in it stay open whatever happens later, one opened later is not listed.
    $snapshot = $service->sessionSnapshot();
    $snapAll = $service->sessionIds(null, 500, 0, $snapshot);
    $snapOpen = $service->sessionIds('aperta', 500, 0, $snapshot);
    $snapCount = $service->sessionCount(null, $snapshot);
    $later = $service->open('ZZST later ' . $run, null, ['collocazione_da' => "{$p}.none", 'collocazione_a' => "{$p}.none"], null);
    $sessionIds[] = $later;
    $service->cancel($pin, null);
    $check($service->sessionIds(null, 500, 0, $snapshot) === $snapAll && $service->sessionCount(null, $snapshot) === $snapCount && $service->sessionCount(null) === $snapCount + 1, 'the pinned list is the same after a session was opened and another cancelled');
    $check(!in_array($later, $service->sessionIds(null, 500, 0, $snapshot), true) && in_array($later, $service->sessionIds(null, 500), true), 'the session opened after the snapshot is not in the pinned list, only in a fresh one');
    $check(in_array($pin, $snapOpen, true) && $service->sessionIds('aperta', 500, 0, $snapshot) === $snapOpen && !in_array($pin, $service->sessionIds('aperta', 500), true), 'the session cancelled after the snapshot keeps its place among the open ones');
    // A close stamped before the snapshot but committed after it (its
    // chiusa_il is earlier than the first page), or in the very same second:
    // the session was open in the snapshot and stays open in every page.
    foreach (['NOW()', 'NOW() - INTERVAL 1 HOUR'] as $stamp) {
        $db->query("UPDATE inventario_sessioni SET chiusa_il = {$stamp} WHERE id = {$pin}");
        $check($service->sessionIds('aperta', 500, 0, $snapshot) === $snapOpen && $service->sessionCount('aperta', $snapshot) === count($snapOpen), "a session closed at {$stamp} but open in the snapshot is still listed and counted as open");
    }
    // A session whose id was taken before the snapshot but whose row commits
    // after it: not visible to the first page, so never listed in the pinned
    // pages. Simulated with a free id below the snapshot's newest one.
    $gap = $service->open('ZZST gap ' . $run, null, ['collocazione_da' => "{$p}.none", 'collocazione_a' => "{$p}.none"], null);
    $db->query("DELETE FROM inventario_sessioni WHERE id = {$gap}");
    $afterGap = $service->open('ZZST aftergap ' . $run, null, ['collocazione_da' => "{$p}.none", 'collocazione_a' => "{$p}.none"], null);
    $sessionIds[] = $afterGap;
    $gapSnapshot = $service->sessionSnapshot();
    $gapList = $service->sessionIds(null, 500, 0, $gapSnapshot);
    $db->query("INSERT INTO inventario_sessioni (id, nome, stato, aperta_il) VALUES ({$gap}, 'ZZST late {$run}', 'aperta', NOW())");
    $sessionIds[] = $gap;
    $check($gap < $gapSnapshot['u'] && $service->sessionIds(null, 500, 0, $gapSnapshot) === $gapList && !in_array($gap, $service->sessionIds('aperta', 500, 0, $gapSnapshot), true) && in_array($gap, $service->sessionIds('aperta', 500), true), 'a session committed after the snapshot with a lower id is not in the pinned list');
    // ...and stays out once it is closed before the next page: it was not
    // visible to the snapshot, so it never joins the pinned history either.
    // Closed in the very second of the snapshot, the case a time bound cannot tell apart.
    $check(array_filter($gapSnapshot['g'], static fn (array $r): bool => $r[0] <= $gap && $gap <= $r[1]) !== [], 'the late session id is in a gap range of the snapshot');
    $check(count($gapSnapshot['g']) <= StocktakeService::MAX_GAP_RANGES && strlen(implode(',', StocktakeService::snapshotToCursor($gapSnapshot))) < 4096, 'the snapshot carries at most MAX_GAP_RANGES gap ranges, so its cursor stays small');
    $db->query("UPDATE inventario_sessioni SET stato = 'annullata', chiusa_il = NOW() WHERE id = {$gap}");
    $check($service->sessionIds(null, 500, 0, $gapSnapshot) === $gapList && $service->sessionCount(null, $gapSnapshot) === count($gapList) && !in_array($gap, $service->sessionIds('annullata', 500, 0, $gapSnapshot), true), 'a late session closed between two pages does not join the pinned history');
    // A session closed before the snapshot is still in the pinned history.
    $check(in_array($second, $service->sessionIds('annullata', 500, 0, $gapSnapshot), true), 'a session closed before the snapshot is in the pinned history');
    // The snapshot a cursor carries back is checked before it reaches the SQL.
    $check(StocktakeService::snapshotFrom(5, '1,3', '2') === ['u' => 5, 'a' => [1, 3], 'g' => [[2, 2]]] && StocktakeService::snapshotFrom(5, '', '') === ['u' => 5, 'a' => [], 'g' => []]
        && StocktakeService::snapshotFrom(9, '1', '2-4,7') === ['u' => 9, 'a' => [1], 'g' => [[2, 4], [7, 7]]]
        && StocktakeService::snapshotFrom(5, '1,x', '') === null && StocktakeService::snapshotFrom(5, "1) OR (1=1", '') === null && StocktakeService::snapshotFrom(5, '9', '') === null && StocktakeService::snapshotFrom('5', '1', '') === null
        && StocktakeService::snapshotFrom(5, '1', null) === null && StocktakeService::snapshotFrom(5, '1', '2) OR (1=1') === null && StocktakeService::snapshotFrom(5, '1', '7') === null
        && StocktakeService::snapshotFrom(9, '1', '4-2') === null && StocktakeService::snapshotFrom(9, '1', '5,3') === null && StocktakeService::snapshotFrom(9, '1', '2-4,4') === null && StocktakeService::snapshotFrom(9, '1', '2-12') === null
        && StocktakeService::snapshotFrom(5, ...array_values(array_slice(StocktakeService::snapshotToCursor(['u' => 5, 'a' => [2, 4], 'g' => [[3, 3]]]), 1))) === ['u' => 5, 'a' => [2, 4], 'g' => [[3, 3]]], 'snapshotFrom() accepts only the id lists snapshotToCursor() writes');
    $manyRanges = implode(',', array_map(static fn (int $i): string => (string) (2 * $i), range(1, StocktakeService::MAX_GAP_RANGES + 1)));
    $check(StocktakeService::snapshotToCursor(['u' => 10, 'a' => [], 'g' => [[2, 4], [7, 7]]])['g'] === '2-4,7' && StocktakeService::snapshotFrom(1000, '', $manyRanges) === null, 'gaps travel as ranges, at most MAX_GAP_RANGES of them');

    // The app pages through every session, not the history listSessions() trims.
    $sessionTotal = $service->sessionCount(null);
    $check($sessionTotal === (int) $one('SELECT COUNT(*) FROM inventario_sessioni'), 'sessionCount() counts every session');
    $everyId = $service->sessionIds(null, 500);
    $pagedIds = array_merge($service->sessionIds(null, 2, 0), $service->sessionIds(null, 2, 2), $service->sessionIds(null, 500, 4));
    $check($pagedIds === $everyId && count($everyId) === min(500, $sessionTotal), 'sessionIds() pages put together are the whole list, in one order');
    $cancelledIds = $service->sessionIds('annullata', 500);
    $check(in_array($second, $cancelledIds, true) && !in_array($sessionId, $cancelledIds, true) && count($cancelledIds) === min(500, $service->sessionCount('annullata')), 'sessionIds() and sessionCount() filter by state');
    $ranks = array_map(static fn (int $id): int => ['aperta' => 0, 'chiusa' => 1, 'annullata' => 2][(string) $one("SELECT stato FROM inventario_sessioni WHERE id = {$id}")] ?? 3, $everyId);
    $sortedRanks = $ranks;
    sort($sortedRanks);
    $check($ranks === $sortedRanks, 'open sessions come first, then closed, then cancelled, as on the admin list');
    // A report too large for one TCPDF request is refused (review #504),
    // pointing at the CSV export instead of exhausting memory.
    $big = $service->open('ZZST big ' . $run, null, ['collocazione_da' => "{$p}.none", 'collocazione_a' => "{$p}.none"], null);
    $sessionIds[] = $big;
    $bigRows = App\Support\StocktakePdfGenerator::MAX_ROWS + 1;
    // Placeholder rows from a digits cross join (no recursive CTE: MySQL and
    // MariaDB cap its depth with different variables).
    $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
    $db->query("INSERT INTO inventario_attese (sessione_id, copia_id, codice_atteso, titolo_atteso) SELECT {$big}, 2000000000 + t.n, CONCAT('{$p}-BIG-', t.n), 'ZZST big' FROM (SELECT d1.d + 10 * d2.d + 100 * d3.d + 1000 * d4.d + 10000 * d5.d AS n FROM {$digits} d1, {$digits} d2, {$digits} d3, {$digits} d4, {$digits} d5) t WHERE t.n < {$bigRows}");
    $check((int) $one("SELECT COUNT(*) FROM inventario_attese WHERE sessione_id = {$big}") === $bigRows, 'the large session holds one row over the PDF limit');
    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'admin']];
    $pdfResponse = (new App\Controllers\InventoryController())->exportPdf((new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/'), new Slim\Psr7\Response(), $db, $big);
    $check($pdfResponse->getStatusCode() === 302 && str_ends_with($pdfResponse->getHeaderLine('Location'), '/admin/inventory/' . $big) && (string) $pdfResponse->getBody() === '', 'a report over the PDF row limit is not rendered: the export goes back to the session');
    $check(($_SESSION['error_message'] ?? '') === sprintf(__('Il report ha troppe righe per il PDF (%d, al massimo %d): scarica il report CSV.'), $bigRows, App\Support\StocktakePdfGenerator::MAX_ROWS), 'with a message that points to the CSV report');
    // The rows checked against the limit are the rows the PDF is built from
    // (review #504): the generator itself refuses more, and the export reads
    // the report once, one row past the limit, instead of counting it apart.
    $overLimit = false;
    try {
        $pdfGenerator->generate((array) $service->find($big), [], $service->reportRows($big), [], []);
    } catch (LengthException $e) {
        $overLimit = true;
    }
    $check($overLimit, 'the PDF generator refuses more rows than MAX_ROWS instead of building them');
    $check(count($service->reportRows($big, App\Support\StocktakePdfGenerator::MAX_ROWS + 1)) === App\Support\StocktakePdfGenerator::MAX_ROWS + 1 && count($service->reportRows($sessionId, 2)) === 2, 'reportRows() with a limit reads no more rows than asked');
    $bigFirst = $service->reportPage($big, 'mancante', 50, 0);
    $bigNext = $service->reportPage($big, 'mancante', 50, 0, $bigFirst['next']);
    $check(count($bigFirst['rows']) === 50 && count($bigNext['rows']) === 50 && array_intersect(array_column($bigFirst['rows'], 'codice'), array_column($bigNext['rows'], 'codice')) === [] && count($readReport($big, null, 4000, 0)) === $bigRows, 'a large report pages by key, no more rows than asked, every row once');
    // The CSV export writes the report row by row into a temporary stream
    // (review #504), so a report too large for the PDF is still exported whole.
    memory_reset_peak_usage();
    $memBefore = memory_get_usage();
    $bigCsv = (new App\Controllers\InventoryController())->exportCsv((new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/'), new Slim\Psr7\Response(), $db, $big);
    $csvPeak = memory_get_peak_usage() - $memBefore;
    $bigCsvBody = (string) $bigCsv->getBody();
    // Holding every row as a PHP array took about 19 times the file size; streaming them takes about 2.
    $check($csvPeak < 5 * strlen($bigCsvBody), 'exporting the CSV never holds the whole report in memory: the peak stays within a few times the file size');
    $check(str_starts_with($bigCsvBody, "\xEF\xBB\xBF") && substr_count($bigCsvBody, "\n") === $bigRows + 1 && str_contains($bigCsvBody, "{$p}-BIG-" . ($bigRows - 1)), 'the streamed CSV keeps the BOM, the header and every one of the rows over the PDF limit');
    $smallResponse = (new App\Controllers\InventoryController())->exportPdf((new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/'), new Slim\Psr7\Response(), $db, $second);
    $check($smallResponse->getStatusCode() === 200 && str_starts_with((string) $smallResponse->getBody(), '%PDF'), 'a report within the limit is still exported as PDF');

    // A close or cancel that loses the race against another transition does nothing.
    $refused = static function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (DomainException) {
            return true;
        }
    };
    $check($refused(static fn () => $service->close($second, true, null)), 'closing a cancelled session is refused');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$second}") === 'annullata' && $one("SELECT stato FROM copie WHERE id = {$c5}") === 'disponibile', 'the refused close marks nothing and keeps the cancelled state');
    $check($refused(static fn () => $service->cancel($sessionId, null)), 'cancelling a closed session is refused');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$sessionId}") === 'chiusa', 'the closed session stays closed');
    $check((int) $one("SELECT COUNT(*) FROM log_modifiche WHERE tabella = 'inventario_sessioni' AND record_id IN ({$sessionId}, {$second}) AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) IN ('inventory.closed', 'inventory.cancelled')") === 2, 'only the winning transitions are logged');
    // Those events have a reader (review #504): the book feeds filter on
    // tabella = 'libri', so the session page shows its own history.
    $eventsOf = static fn (int $sid): array => array_column(App\Support\ActivityLog::forEntity($db, 'inventario_sessioni', $sid), 'event');
    $check($eventsOf($sessionId) === ['inventory.closed', 'inventory.opened'] && $eventsOf($second) === ['inventory.cancelled', 'inventory.opened'], 'forEntity() returns a session\'s own events, newest first');
    $check(App\Support\ActivityLog::forEntity($db, 'libri', $b1) === [] && count(App\Support\ActivityLog::forEntity($db, 'inventario_sessioni', $sessionId, 1)) === 1, 'forEntity() never reads book rows and honours its limit');
    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'admin']];
    $page = (string) (new App\Controllers\InventoryController())->show((new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/admin/inventory/' . $sessionId), new Slim\Psr7\Response(), $db, $sessionId)->getBody();
    $check(str_contains($page, 'id="inventory-history"') && str_contains($page, 'data-event="inventory.closed"') && str_contains($page, htmlspecialchars(__('Revisione inventariale chiusa'), ENT_QUOTES, 'UTF-8')), 'the session page lists who closed it and when');

    // A scan in flight while another connection is closing the session waits
    // for the close instead of landing on a closed session.
    $third = $service->open('ZZST3 ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $third;
    $other = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
    $other->begin_transaction();
    $other->query("UPDATE inventario_sessioni SET stato = 'chiusa' WHERE id = {$third} AND stato = 'aperta'");
    $db->query('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        $service->scan($third, "{$p}-C5", null, null);
        $blocked = false;
    } catch (\Throwable) {
        $blocked = true;
    }
    $db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    $other->commit();
    $other->close();
    $other = null;
    $check($blocked && (int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$third}") === 0, 'a scan racing a pending close is not recorded');
    $check($refused(static fn () => $service->scan($third, "{$p}-C5", null, null)), 'once the close commits the scan is refused');

    // A loan at the desk holds the copy row (review #504): a single scan of it
    // waits for the loan, a file upload does not lock circulation rows at all.
    $locked = $service->open('ZZST locks ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $locked;
    $other = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
    $other->begin_transaction();
    $other->query("SELECT id FROM copie WHERE id = {$c5} FOR UPDATE");
    $db->query('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        $service->scan($locked, "{$p}-C5", null, null);
        $scanWaited = false;
    } catch (\Throwable) {
        $scanWaited = true;
    }
    try {
        $uploadTotal = $service->importCodes($locked, ["{$p}-C5"], null, null)['total'];
    } catch (\Throwable) {
        $uploadTotal = -1;
    }
    $db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    $other->rollback();
    $other->close();
    $other = null;
    $check($scanWaited, 'a single scan of a copy a loan holds waits for the loan');
    $check($uploadTotal === 1 && (int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$locked}") === 1, 'a file upload of the same copy is recorded without waiting on the loan');

    // Closing with "mark lost" from the page must run to the end: the session
    // is claimed first, so a request cut off halfway could not be retried.
    $fourth = $service->open('ZZST4 ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $fourth;
    ignore_user_abort(false);
    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'admin']];
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/inventory/' . $fourth . '/close')
        ->withParsedBody(['azione_mancanti' => 'perso']);
    (new App\Controllers\InventoryController())->close($request, new Slim\Psr7\Response(), $db, $fourth);
    $check(ignore_user_abort() === 1, 'a mark-lost close keeps running if the browser goes away');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$fourth}") === 'chiusa', 'and closes the session');
    $check($one("SELECT GROUP_CONCAT(CONCAT(copia_id, ':', esito_chiusura) ORDER BY copia_id) FROM inventario_attese WHERE sessione_id = {$fourth}") === "{$c3}:in_prestito,{$c7}:in_prestito,{$c5}:persa", 'the close from the page freezes the buckets too');

    // The report of a closed session is frozen at close (review #504): the
    // loan desk keeps working, the report keeps saying what the stocktake found.
    $buckets = static fn (array $rows): array => array_map(static fn (array $r): array => [$r['categoria'], $r['codice']], $rows);
    $closedRows = $buckets($service->reportRows($sessionId));
    $closedCategories = array_count_values(array_column($closedRows, 0));
    ksort($closedCategories);
    $check($closedCategories === ['duplicata' => 1, 'fuori_ambito' => 1, 'in_prestito' => 2, 'mancante' => 3, 'sconosciuta' => 2, 'trovata' => 1], 'the closed report lists the frozen buckets, the copy marked lost among the missing');
    // C7's hold is picked up and returned, C6 turns up on a shelf, C8 is repaired and lent.
    $db->query("UPDATE prestiti SET stato = 'restituito', attivo = 0 WHERE id = {$holdId}");
    $db->query("UPDATE copie SET stato = 'disponibile' WHERE id IN ({$c7}, {$c6})");
    $db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$c8}");
    $check($one("SELECT stato FROM copie WHERE id = {$c7}") === 'disponibile' && $one("SELECT stato FROM copie WHERE id = {$c8}") === 'prestato', 'the loan desk moved on after the close');
    $check($service->summary($sessionId) === $closedSummary, 'a return, a loan and a found copy after the close leave the summary as it was');
    $check($buckets($service->reportRows($sessionId)) === $closedRows, 'and every report row keeps its bucket');
    $check($one("SELECT esito_chiusura FROM inventario_attese WHERE sessione_id = {$sessionId} AND copia_id = {$c6}") === 'persa' && $service->summary($sessionId)['perse'] === 1, 'the copy the close marked lost is still reported as such');

    // A failed snapshot write must leave the session open and retryable.
    $atomicBook = $addBook('Atomic', $shelves['X']);
    $atomicCopy = $addCopy($atomicBook, 'ATOMIC');
    $atomicSession = $service->open('ZZST atomic ' . $run, null, ['collocazione_da' => "{$p}.Atomic", 'collocazione_a' => "{$p}.Atomic"], null);
    $sessionIds[] = $atomicSession;
    $freezeFailure = 'zzst_freeze_' . strtolower($run);
    $db->query("CREATE TRIGGER {$freezeFailure} BEFORE UPDATE ON inventario_attese FOR EACH ROW BEGIN IF NEW.sessione_id = {$atomicSession} AND NEW.esito_chiusura IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ZZST simulated snapshot failure'; END IF; END");
    $triggers[] = $freezeFailure;
    $snapshotFailed = false;
    try {
        $service->close($atomicSession, false, null);
    } catch (mysqli_sql_exception) {
        $snapshotFailed = true;
    }
    $db->query("DROP TRIGGER {$freezeFailure}");
    $check($snapshotFailed, 'the snapshot write failure reaches the caller');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$atomicSession}") === 'aperta', 'a failed snapshot rolls back the close so it can be retried');
    $check($one("SELECT esito_chiusura FROM inventario_attese WHERE sessione_id = {$atomicSession} AND copia_id = {$atomicCopy}") === null, 'a failed close leaves the expected copy unfrozen');

    // Losing a copy and recording its frozen bucket are one per-book unit:
    // neither the copy nor its availability may change if the bucket fails.
    $lostFailure = 'zzst_lost_' . strtolower($run);
    $db->query("CREATE TRIGGER {$lostFailure} BEFORE UPDATE ON inventario_attese FOR EACH ROW BEGIN IF NEW.sessione_id = {$atomicSession} AND NEW.esito_chiusura = 'persa' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ZZST simulated lost bucket failure'; END IF; END");
    $triggers[] = $lostFailure;
    $result = $service->close($atomicSession, true, null);
    $db->query("DROP TRIGGER {$lostFailure}");
    $check($result === ['marked' => 0, 'failed' => 1], 'a lost bucket failure is reported as a failed book update');
    $check($one("SELECT stato FROM copie WHERE id = {$atomicCopy}") === 'disponibile', 'a failed lost bucket rolls back the copy state');
    $check($one("SELECT copie_disponibili FROM libri WHERE id = {$atomicBook}") === '1', 'a failed lost bucket rolls back book availability');
    $check($one("SELECT esito_chiusura FROM inventario_attese WHERE sessione_id = {$atomicSession} AND copia_id = {$atomicCopy}") === 'mancante', 'the frozen report stays missing when marking lost fails');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$atomicSession}") === 'chiusa', 'the retried close succeeds even when marking a book lost fails');
    $check((int) $one("SELECT COUNT(*) FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$atomicBook} AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) = 'copy.inventory_lost'") === 0, 'the rolled-back loss writes no copy audit event');

    // The session is closed, so close() cannot be repeated: the copies the
    // mark-lost close did not reach are completed from the closed session.
    // pendingLostCount() counts in SQL what missingToMark() lists (review #518): same answer at every step below.
    $pendingMatches = static function (int $sid) use ($service): bool {
        return $service->pendingLostCount($sid) === array_sum(array_map('count', (new ReflectionMethod($service, 'missingToMark'))->invoke($service, $sid)));
    };
    $check($pendingMatches($atomicSession) && $service->pendingLostCount($atomicSession) === 1, 'the copy the close failed to mark is pending');
    $check($refused(static fn () => $service->resumeMarkLost($second, null)), 'a cancelled session cannot resume marking');
    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'admin']];
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/inventory/' . $atomicSession . '/close')
        ->withParsedBody(['azione_mancanti' => 'perso']);
    (new App\Controllers\InventoryController())->close($request, new Slim\Psr7\Response(), $db, $atomicSession);
    $check($one("SELECT stato FROM copie WHERE id = {$atomicCopy}") === 'perso', 'posting "mark lost" again on the closed session marks the copy it missed');
    $check($one("SELECT esito_chiusura FROM inventario_attese WHERE sessione_id = {$atomicSession} AND copia_id = {$atomicCopy}") === 'persa' && $one("SELECT copie_disponibili FROM libri WHERE id = {$atomicBook}") === '0', 'the resumed marking freezes the bucket and recalculates availability');
    $check($pendingMatches($atomicSession) && $service->pendingLostCount($atomicSession) === 0 && $service->resumeMarkLost($atomicSession, null) === ['marked' => 0, 'failed' => 0], 'resuming again finds nothing left to mark');
    // A copy is handled after the close: the resume must not undo it. The
    // close is moved an hour back so "changed after the close" does not hang
    // on both writes landing in the same second.
    $db->query("UPDATE inventario_sessioni SET chiusa_il = chiusa_il - INTERVAL 1 HOUR WHERE id = {$atomicSession}");
    $db->query("UPDATE copie SET stato = 'disponibile' WHERE id = {$atomicCopy}");
    $check($pendingMatches($atomicSession) && $service->pendingLostCount($atomicSession) === 0 && $service->resumeMarkLost($atomicSession, null) === ['marked' => 0, 'failed' => 0] && $one("SELECT stato FROM copie WHERE id = {$atomicCopy}") === 'disponibile', 'a lost copy found and put back after the close is not marked lost again');
    $db->query("UPDATE inventario_attese SET esito_chiusura = 'mancante' WHERE sessione_id = {$atomicSession} AND copia_id = {$atomicCopy}");
    $check($pendingMatches($atomicSession) && $service->pendingLostCount($atomicSession) === 0 && $service->resumeMarkLost($atomicSession, null) === ['marked' => 0, 'failed' => 0] && $one("SELECT stato FROM copie WHERE id = {$atomicCopy}") === 'disponibile', 'a missing copy changed after the close (repaired, put back) is not pending');
    // The copy was last changed before the close: pending again.
    $db->query("UPDATE copie c JOIN inventario_sessioni s ON s.id = {$atomicSession} SET c.updated_at = s.chiusa_il - INTERVAL 1 MINUTE WHERE c.id = {$atomicCopy}");
    $check($pendingMatches($atomicSession) && $service->pendingLostCount($atomicSession) === 1, 'a missing copy last changed before the close is pending');
    // An operator edits it between the pending list and the book lock (review
    // #504): the marking re-checks the change under the lock and leaves it.
    $toMark = (new ReflectionMethod($service, 'missingToMark'))->invoke($service, $atomicSession);
    $db->query("UPDATE copie SET note = 'ZZST edited after the close' WHERE id = {$atomicCopy}");
    $markedNow = (new ReflectionMethod($service, 'markBookCopiesLost'))->invoke($service, $atomicBook, $toMark[$atomicBook] ?? [], $atomicSession, null);
    $check(isset($toMark[$atomicBook]) && $markedNow === [] && $one("SELECT stato FROM copie WHERE id = {$atomicCopy}") === 'disponibile' && $one("SELECT esito_chiusura FROM inventario_attese WHERE sessione_id = {$atomicSession} AND copia_id = {$atomicCopy}") === 'mancante', 'a copy edited after the close while the marking runs is not marked lost');
    // Changed in the very second of the close: ambiguous, so it is left alone.
    $db->query("UPDATE copie c JOIN inventario_sessioni s ON s.id = {$atomicSession} SET c.updated_at = s.chiusa_il WHERE c.id = {$atomicCopy}");
    $check($pendingMatches($atomicSession) && $service->pendingLostCount($atomicSession) === 0 && $service->resumeMarkLost($atomicSession, null) === ['marked' => 0, 'failed' => 0] && $one("SELECT stato FROM copie WHERE id = {$atomicCopy}") === 'disponibile', 'a copy changed in the same second as the close is not marked lost');
    // Two missing copies of one book are two pending copies, not one book.
    $twoBook = $addBook('PL', $shelves['X']);
    $addCopy($twoBook, 'PL1');
    $addCopy($twoBook, 'PL2');
    $twoSession = $service->open('ZZST two ' . $run, null, ['collocazione_da' => "{$p}.PL", 'collocazione_a' => "{$p}.PL"], null);
    $sessionIds[] = $twoSession;
    $service->close($twoSession, false, null);
    $twoToMark = (new ReflectionMethod($service, 'missingToMark'))->invoke($service, $twoSession);
    $check($service->pendingLostCount($twoSession) === 2 && array_sum(array_map('count', $twoToMark)) === 2 && array_keys($twoToMark) === [$twoBook], 'two missing copies of the same book count as two pending copies');

    // A hold the reassignment cannot take off the copy (review #504): a
    // trigger puts the copy back on every release. Marking it lost must roll
    // back instead of committing a lost copy that still carries the hold.
    $holdBook = $addBook('Hold', $shelves['X']);
    $holdCopy = $addCopy($holdBook, 'HOLD');
    $db->query("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES ({$holdBook}, {$holdCopy}, {$borrowerId}, DATE_ADD(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 19 DAY), 'prenotato', 'prenotazione', 1)");
    $stuckHold = (int) $db->insert_id;
    $holdSession = $service->open('ZZST hold ' . $run, null, ['collocazione_da' => "{$p}.Hold", 'collocazione_a' => "{$p}.Hold"], null);
    $sessionIds[] = $holdSession;
    $stuck = 'zzst_stuck_' . strtolower($run);
    $db->query("CREATE TRIGGER {$stuck} BEFORE UPDATE ON prestiti FOR EACH ROW BEGIN IF OLD.id = {$stuckHold} AND NEW.copia_id IS NULL THEN SET NEW.copia_id = OLD.copia_id; END IF; END");
    $triggers[] = $stuck;
    $result = $service->close($holdSession, true, null);
    $db->query("DROP TRIGGER {$stuck}");
    $check($result === ['marked' => 0, 'failed' => 1], 'a copy whose hold cannot be moved is reported as a failed book update');
    $check($one("SELECT stato FROM copie WHERE id = {$holdCopy}") === 'disponibile' && $one("SELECT copia_id FROM prestiti WHERE id = {$stuckHold}") === (string) $holdCopy && $one("SELECT esito_chiusura FROM inventario_attese WHERE sessione_id = {$holdSession}") === 'mancante', 'and nothing of it is committed: the copy is not lost and keeps its hold');

    // The audit entry is part of the loss (review #504): when it cannot be
    // written the copy is not lost either, and a resume writes both.
    $auditBook = $addBook('Audit', $shelves['X']);
    $auditCopy = $addCopy($auditBook, 'AUDIT');
    $auditSession = $service->open('ZZST audit ' . $run, null, ['collocazione_da' => "{$p}.Audit", 'collocazione_a' => "{$p}.Audit"], null);
    $sessionIds[] = $auditSession;
    $auditFailure = 'zzst_audit_' . strtolower($run);
    $db->query("CREATE TRIGGER {$auditFailure} BEFORE INSERT ON log_modifiche FOR EACH ROW BEGIN IF NEW.tabella = 'libri' AND NEW.record_id = {$auditBook} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ZZST simulated audit failure'; END IF; END");
    $triggers[] = $auditFailure;
    $result = $service->close($auditSession, true, null);
    $db->query("DROP TRIGGER {$auditFailure}");
    $check($result === ['marked' => 0, 'failed' => 1] && $one("SELECT stato FROM copie WHERE id = {$auditCopy}") === 'disponibile' && $one("SELECT esito_chiusura FROM inventario_attese WHERE sessione_id = {$auditSession}") === 'mancante', 'a copy whose audit entry cannot be written is not marked lost');
    $check($service->resumeMarkLost($auditSession, null) === ['marked' => 1, 'failed' => 0] && $one("SELECT stato FROM copie WHERE id = {$auditCopy}") === 'perso' && (int) $one("SELECT COUNT(*) FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$auditBook} AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) = 'copy.inventory_lost'") === 1, 'the resume marks it and writes its audit entry with it');
    $fifth = $service->open('ZZST5 ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $fifth;
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/inventory/' . $fifth . '/close')
        ->withParsedBody(['azione_mancanti' => 'bogus']);
    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'admin']];
    (new App\Controllers\InventoryController())->close($request, new Slim\Psr7\Response(), $db, $fifth);
    $check(($_SESSION['error_message'] ?? '') === __('Azione non valida.') && $one("SELECT stato FROM inventario_sessioni WHERE id = {$fifth}") === 'aperta', 'an unknown missing-copies action is refused and the session stays open');

    // A scan reads the loan status under the locks a return takes (review
    // #504): while a return of the copy is still uncommitted the scan waits
    // for it, instead of recording the copy as on loan from the state before.
    $returnBook = $addBook('B9', $shelves['X']);
    $returnCopy = $addCopy($returnBook, 'R1', 'prestato');
    $db->query("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES ({$returnBook}, {$returnCopy}, {$borrowerId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 'diretto', 1)");
    $returnLoan = (int) $db->insert_id;
    $returnSession = $service->open('ZZST return ' . $run, null, ['collocazione_da' => "{$p}.B9", 'collocazione_a' => "{$p}.B9"], null);
    $sessionIds[] = $returnSession;
    $other = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
    $other->begin_transaction();
    $other->query("SELECT id FROM prestiti WHERE id = {$returnLoan} FOR UPDATE");
    $other->query("UPDATE prestiti SET stato = 'restituito', attivo = 0, data_restituzione = CURDATE() WHERE id = {$returnLoan}");
    $other->query("UPDATE copie SET stato = 'disponibile' WHERE id = {$returnCopy}");
    $db->query('SET SESSION innodb_lock_wait_timeout = 1');
    $waited = false;
    try {
        $service->scan($returnSession, "{$p}-R1", null, $shelves['X']);
    } catch (mysqli_sql_exception $e) {
        $waited = $e->getCode() === 1205;
    }
    $db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    $check($waited && (int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$returnSession}") === 0, 'a scan during an uncommitted return of the copy waits for it and records nothing from the state before the return');
    $other->commit();
    $other->close();
    $other = null;
    $check($service->scan($returnSession, "{$p}-R1", null, $shelves['X'])['esito'] === 'trovata', 'once the return is committed the same scan finds the copy on the shelf');

    echo "SUCCESS {$checks} stocktake snapshot checks\n";
} finally {
    if ($other instanceof mysqli) {
        $other->rollback(); // a failed run must not leave the return holding its locks
        $other->close();
    }
    foreach ($triggers as $trigger) {
        $db->query("DROP TRIGGER IF EXISTS {$trigger}");
    }
    foreach ($sessionIds as $sid) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'inventario_sessioni' AND record_id = {$sid}");
        $db->query("DELETE FROM inventario_sessioni WHERE id = {$sid}");
    }
    foreach ($books as $bid) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$bid}");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$bid}");
        $db->query("DELETE FROM copie WHERE libro_id = {$bid}");
        $db->query("DELETE FROM libri WHERE id = {$bid}");
    }
    foreach ($shelves as $sid) {
        $db->query("DELETE FROM scaffali WHERE id = {$sid}");
    }
    if ($borrowerId > 0) {
        $db->query("DELETE FROM utenti WHERE id = {$borrowerId}");
    }
}
