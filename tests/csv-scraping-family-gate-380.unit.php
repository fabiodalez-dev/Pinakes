<?php
declare(strict_types=1);

/**
 * #380 — the scraping enrichment must respect the per-family update selection.
 *
 * enrichBookWithScrapedData() fills fields the CSV left empty; but on an
 * EXISTING book an empty CSV cell may mean "family unchecked → preserve", and
 * without the gate the scraper overwrote fields the user explicitly excluded
 * (description, year, authors, publisher, …). This suite calls the private
 * method directly via reflection with a FAKE scraped payload — no network —
 * against a sandbox book, and proves:
 *   - unchecked family + empty CSV cell → the existing value SURVIVES scraping;
 *   - checked family + empty CSV cell → scraping fills as before (no
 *     over-tightening);
 *   - action='created' ignores the selection entirely (new books enrich fully).
 *
 * Discriminating: on the pre-fix code (no $allow gate) checks 01/03/05 fail.
 *
 * Run: php tests/csv-scraping-family-gate-380.unit.php
 */

use App\Controllers\CsvImportController;
use App\Support\DateHelper;

require dirname(__DIR__) . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$e2e = static function (string $key): string {
    $value = getenv($key);
    return $value === false || $value === 'undefined' ? '' : $value;
};
$socket = $e2e('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $e2e('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), $e2e('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), $e2e('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli($e2e('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $e2e('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), $e2e('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), $e2e('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) ($e2e('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — scraping family-gate suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(6));
$title = "ZZ_SCRGATE_{$run}";
$bookId = 0;
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$cleanup = static function () use ($db, &$bookId, $run): void {
    if ($bookId > 0) {
        $db->query("DELETE FROM libri_autori WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM libri_editori WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM libri WHERE id = {$bookId}");
    }
    $db->query("DELETE FROM autori WHERE nome LIKE 'ZZScrAutore {$run}%'");
    $db->query("DELETE FROM editori WHERE nome LIKE 'ZZScrEditore {$run}%'");
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL);
    $db->close();
    exit(1);
});

// Sandbox book with EXISTING values in every gated field.
$stmt = $db->prepare(
    "INSERT INTO libri (titolo, descrizione, anno_pubblicazione, parole_chiave, copie_totali, copie_disponibili, stato)
     VALUES (?, 'Descrizione originale', 1999, 'kw-originale', 1, 1, 'disponibile')"
);
$stmt->bind_param('s', $title);
$stmt->execute();
$bookId = (int) $db->insert_id;
$stmt->close();

$controller = (new ReflectionClass(CsvImportController::class))->newInstanceWithoutConstructor();
$enrich = new ReflectionMethod(CsvImportController::class, 'enrichBookWithScrapedData');
$enrich->setAccessible(true);

// CSV row with EMPTY cells for the gated fields (the pre-fix trigger condition).
$csvEmpty = [
    'copertina_url' => '', 'descrizione' => '', 'sottotitolo' => '',
    'prezzo' => null, 'numero_pagine' => '', 'classificazione_dewey' => '',
    'anno_pubblicazione' => '', 'lingua' => '', 'parole_chiave' => '',
    'autori' => '', 'editore' => '',
];
$scraped = [
    'description' => 'Descrizione scrappata',
    'year' => '2021',
    'keywords' => 'kw-scrappata',
    'authors' => ["ZZScrAutore {$run}"],
    'publisher' => "ZZScrEditore {$run}",
];

$field = static function (string $col) use ($db, $bookId): ?string {
    $row = $db->query("SELECT {$col} AS v FROM libri WHERE id = {$bookId}")->fetch_assoc();
    return $row['v'] === null ? null : (string) $row['v'];
};
$principalCount = static fn (): int => (int) $db->query(
    "SELECT COUNT(*) AS c FROM libri_autori WHERE libro_id = {$bookId} AND ruolo = 'principale'"
)->fetch_assoc()['c'];

// ── 1-4: UNCHECKED families on an existing book → scraping must NOT touch ────
$allOff = ['description' => false, 'anno' => false, 'keywords' => false, 'authors' => false, 'publisher' => false];
$enrich->invoke($controller, $db, $bookId, $csvEmpty, $scraped, $allOff, 'updated');
$check($field('descrizione') === 'Descrizione originale', '01 unchecked description survives scraping on an existing book');
$check($field('anno_pubblicazione') === '1999', '02 unchecked anno survives scraping');
$check($field('parole_chiave') === 'kw-originale', '03 unchecked keywords survive scraping');
$check($principalCount() === 0 && $field('editore_id') === null,
    '04 unchecked authors/publisher: no links created, no publisher set');

// ── 5-6: CHECKED families still fill (no over-tightening) ────────────────────
$someOn = ['description' => true, 'anno' => false, 'keywords' => true, 'authors' => false, 'publisher' => false];
$enrich->invoke($controller, $db, $bookId, $csvEmpty, $scraped, $someOn, 'updated');
$check($field('descrizione') === 'Descrizione scrappata' && $field('parole_chiave') === 'kw-scrappata',
    '05 checked families are still enriched from scraping');
$check($field('anno_pubblicazione') === '1999', '06 the still-unchecked anno keeps resisting');

// ── 7: created books enrich fully regardless of the selection ────────────────
$db->query("UPDATE libri SET descrizione = NULL, anno_pubblicazione = NULL WHERE id = {$bookId}");
$enrich->invoke($controller, $db, $bookId, $csvEmpty, $scraped, $allOff, 'created');
$check($field('descrizione') === 'Descrizione scrappata' && $field('anno_pubblicazione') === '2021',
    '07 action=created ignores the selection: a new book is fully enriched');

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
