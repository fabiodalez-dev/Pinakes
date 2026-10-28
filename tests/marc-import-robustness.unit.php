<?php
declare(strict_types=1);

/**
 * MARC import edge cases raised in review of the bulk import (#505):
 *  - once a book is committed, a failure of the extra MARC columns or of the
 *    search index is a warning on an imported record, not a failed record;
 *  - a cover copied from this installation keeps the extension of its bytes
 *    (WebP stays .webp, GIF stays .gif);
 *  - a re-import full of duplicates lists at most MAX_LISTED_DUPLICATES of
 *    them in the session state and the import log;
 *  - in fill mode a family is written as soon as one of its columns is empty
 *    and no field the book has is overwritten.
 *
 * The database part touches only the book it creates (title prefixed ZZMR)
 * and removes it with the probe trigger.
 * Run: php tests/marc-import-robustness.unit.php   (exit 0 iff every check passes)
 */

use App\Controllers\MarcImportController;
use App\Support\Marc\MarcBookImporter;
use App\Support\Marc\MarcCoverFetcher;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/app/helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

// 1. Duplicates listed in the report are capped.
$state = ['errors' => []];
for ($i = 1; $i <= MarcImportController::MAX_LISTED_DUPLICATES + 50; $i++) {
    MarcImportController::listSkippedDuplicate($state, $i, 'Book ' . $i, $i);
}
$check(count($state['errors']) === MarcImportController::MAX_LISTED_DUPLICATES, 'duplicates beyond the cap are not listed one by one');
$check(($state['errors'][0]['type'] ?? '') === 'duplicate' && ($state['errors'][0]['line'] ?? 0) === 1, 'the first duplicates keep their position and type');

// 2. Local cover copies take their extension from the image bytes.
$coversDir = $root . '/public/uploads/copertine';
$copyLocal = new ReflectionMethod(MarcCoverFetcher::class, 'copyLocal');
$created = [];
if (!function_exists('imagecreatetruecolor')) {
    echo "  SKIP cover extension checks: GD not available\n";
} else {
    if (!is_dir($coversDir)) {
        mkdir($coversDir, 0775, true);
    }
    $img = imagecreatetruecolor(4, 4);
    $writers = ['png' => 'imagepng', 'gif' => 'imagegif', 'jpg' => 'imagejpeg'];
    if (function_exists('imagewebp')) {
        $writers['webp'] = 'imagewebp';
    }
    foreach ($writers as $ext => $writer) {
        // Source named .jpg on purpose: the stored name must follow the bytes.
        $source = $coversDir . '/zzmr_source_' . bin2hex(random_bytes(3)) . '.jpg';
        $writer($img, $source);
        $created[] = $source;
        try {
            $stored = (string) $copyLocal->invoke(new MarcCoverFetcher(), $source);
            $created[] = $root . '/public' . $stored;
            $check(str_ends_with($stored, '.' . $ext), "a {$ext} image is stored as .{$ext}");
        } catch (Throwable $e) {
            $check(false, "a {$ext} image is stored as .{$ext} (" . $e->getMessage() . ')');
        }
    }
    $bogus = $coversDir . '/zzmr_bogus_' . bin2hex(random_bytes(3)) . '.png';
    file_put_contents($bogus, 'not an image');
    $created[] = $bogus;
    try {
        $copyLocal->invoke(new MarcCoverFetcher(), $bogus);
        $check(false, 'a file that is not an image is refused');
    } catch (RuntimeException) {
        $check(true, 'a file that is not an image is refused');
    }
}
foreach ($created as $file) {
    @unlink($file);
}

// 3. Fill mode never overwrites a field the book has; a family is filled as
//    soon as one of its columns is empty (pure: MarcBookImporter::plan()).
$existing = [
    'id' => 7, 'isbn13' => '9788845292613', 'isbn10' => '', 'ean' => '', 'titolo' => 'Old title', 'sottotitolo' => '',
    'anno_pubblicazione' => null, 'lingua' => 'Italiano', 'edizione' => '', 'numero_pagine' => null,
    'collana' => 'Serie', 'numero_serie' => '', 'classificazione_dewey' => '', 'parole_chiave' => 'old',
    'descrizione' => '', 'editore_id' => 5, 'genere_id' => null, 'traduttore' => 'Old Translator', 'illustratore' => '',
    'curatore' => '', '_principal_authors' => 1,
];
$data = \App\Support\Marc\MarcBookData::empty();
$data = [
    'titolo' => 'New title', 'isbn13' => '9780140449136', 'isbn10' => '0140449132', 'collana' => 'Altra', 'numero_serie' => '3',
    'traduttore' => ['New Translator'], 'illustratore' => ['Ill Ustrator'], 'autori' => ['Some Author'], 'editori' => ['Pub'],
    'genere' => 'Narrativa', 'anno_pubblicazione' => '2001', 'parole_chiave' => ['new'],
] + $data;
$csv = new \App\Controllers\CsvImportController();
$parsed = $csv->normalizeImportRow(\App\Support\Marc\MarcBookData::toImportRow($data));
[$fill, $row] = MarcBookImporter::plan(MarcBookImporter::MODE_FILL, $data, $parsed, $existing);
$check($fill['isbn'] === true && $row['isbn13'] === '9788845292613' && $row['isbn10'] === '0140449132', 'fill: the ISBN family is written for the empty ISBN-10, the book\'s ISBN-13 is kept');
$check($fill['collana'] === true && $row['collana'] === 'Serie' && $row['numero_serie'] === '3', 'fill: the series number is filled, the series name kept');
$check($fill['contributors'] === true && $row['traduttore'] === 'Old Translator' && $row['illustratore'] === 'Ill Ustrator', 'fill: the illustrator is filled, the translator kept');
$check($fill['titolo'] === false && $fill['keywords'] === false && $fill['authors'] === false && $fill['publisher'] === false, 'fill: title, keywords, authors and publisher the book has are not touched');
$check($fill['anno'] === true && $fill['genre'] === true && $fill['sottotitolo'] === false && $fill['formato'] === false, 'fill: an empty year and genre are filled; a family the record does not carry, and the format, are not');
[$over, $row] = MarcBookImporter::plan(MarcBookImporter::MODE_OVERWRITE, $data, $parsed, $existing);
$check($over['isbn'] === true && $over['titolo'] === true && $row['isbn13'] === '9780140449136' && $row['titolo'] === 'New title' && $row['traduttore'] === 'New Translator', 'overwrite: the record\'s values replace the book\'s');
$partial = ['isbn13' => '9780140449136', 'titolo' => 'New title'] + \App\Support\Marc\MarcBookData::empty();
[$over, $row] = MarcBookImporter::plan(MarcBookImporter::MODE_OVERWRITE, $partial, $csv->normalizeImportRow(\App\Support\Marc\MarcBookData::toImportRow($partial)), ['isbn10' => '8845292614', 'traduttore' => 'Old Translator'] + $existing);
$check($row['isbn10'] === '8845292614' && $row['traduttore'] === 'Old Translator' && $over['contributors'] === false, 'overwrite: a column the record leaves empty keeps the book\'s value');

// 4. Post-commit failures are warnings, not failed records.
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    echo "  SKIP database checks: no .env\n";
} else {
    $env = Dotenv\Dotenv::parse((string) file_get_contents($envFile));
    $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
    $db->set_charset('utf8mb4');
    $hasColumn = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'dimensioni'")->num_rows === 1;
    if (!$hasColumn) {
        if (getenv('CI_STRICT_TESTS') === '1') {
            $check(false, 'libri.dimensioni exists');
        } else {
            echo "  SKIP database checks: libri.dimensioni missing\n";
        }
    } else {
        $run = strtoupper(bin2hex(random_bytes(3)));
        $title = 'ZZMR' . $run . ' post-commit';
        $trigger = 'zzmr_probe_' . strtolower($run);
        $db->query("CREATE TRIGGER {$trigger} BEFORE UPDATE ON libri FOR EACH ROW BEGIN IF NEW.dimensioni = 'ZZFAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'probe failure'; END IF; END");
        try {
            $counters = [];
            $warnings = [];
            $importer = new MarcBookImporter($db, MarcBookImporter::MODE_SKIP, false);
            $threw = false;
            $result = null;
            try {
                $result = $importer->import(['titolo' => $title, 'dimensioni' => 'ZZFAIL'], $counters, $warnings);
            } catch (Throwable $e) {
                $threw = true;
                echo '       (threw: ' . $e->getMessage() . ")\n";
            }
            $check(!$threw && ($result['action'] ?? '') === 'created', 'a failing extra-column write does not fail the record');
            $check((int) ($counters['imported'] ?? 0) === 1, 'the record is counted once, as imported');
            $check(count($warnings) === 1 && str_contains($warnings[0], 'probe failure'), 'the failure is reported as a warning');
            $stmt = $db->prepare('SELECT COUNT(*) FROM libri WHERE titolo = ? AND deleted_at IS NULL');
            $stmt->bind_param('s', $title);
            $stmt->execute();
            $check((int) $stmt->get_result()->fetch_row()[0] === 1, 'the book is in the catalogue, matching the report');
            $stmt->close();
        } finally {
            $db->query("DROP TRIGGER IF EXISTS {$trigger}");
            $like = 'ZZMR' . $run . '%';
            $stmt = $db->prepare('SELECT id FROM libri WHERE titolo LIKE ?');
            $stmt->bind_param('s', $like);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all() as [$bookId]) {
                $bookId = (int) $bookId;
                $db->query("DELETE FROM libri_autori WHERE libro_id = {$bookId}");
                $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$bookId}");
                $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
                $db->query("DELETE FROM libri WHERE id = {$bookId}");
            }
            $stmt->close();
        }
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
