<?php
declare(strict_types=1);

/**
 * Pure-logic checks for the late 0.9.0 review fixes and for zero-config
 * equivalence with 0.8.9, plus a source guard that keeps every new text
 * search of the stack on ExactSearch (#501).
 *
 *  1-4   MARC 21 name entries: X10 keeps $b/$e, X11 reads $e as the unit and
 *        $j as the relator, no double period after an initial
 *  5-7   MARC ISO 2709 export: full record (with its own field cuts) or reduced, never null
 *  8-10  MARC import: RDA overrides on a media type change
 *  11-14 zero configuration: no fines, no block, plain due dates
 *  15-16 fine arithmetic with closed days and the grace threshold
 *  17    stocktake classification order (a turned-up copy stays out of scope)
 *  18-19 source guard: new searches go through ExactSearch, the suppliers
 *        box applies the quote rule in the browser
 *  20    the suppliers box tokenises like ExactSearch::tokenize() (inch marks,
 *        unclosed quotes, typographic quotes) and lower-cases locale-free;
 *        the parity half runs the view's own script under node when present
 *
 * Run: php tests/stack-090-review-fixes.unit.php   (exit 0 iff every check passes)
 */

use App\Controllers\MarcExportController;
use App\Services\CirculationPolicy;
use App\Services\FineCalculator;
use App\Services\FineParameters;
use App\Services\StocktakeService;
use App\Support\Marc\Iso2709Reader;
use App\Support\Marc\Marc21LibriMapper;
use App\Support\Marc\MarcBookData;
use App\Support\Marc\MarcBookImporter;
use App\Support\ExactSearch;
use App\Support\Marc\MarcRecord;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
// The 0.9.0 modules under test are on, whatever features.* says in the
// database this runs against (Settings → Modules / Loans).
\App\Support\Features::overrideAll(true);
require_once $root . '/app/helpers.php';

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

echo "MARC 21 name entries\n";
$map = static function (array $fields): array {
    $record = new MarcRecord();
    $record->addDataField('245', '1', '0', [['a', 'Titolo']]);
    foreach ($fields as [$tag, $ind1, $subfields]) {
        $record->addDataField($tag, $ind1, ' ', $subfields);
    }
    return (new Marc21LibriMapper())->map($record);
};
$m = $map([['110', '1', [['a', 'United States.'], ['b', 'Congress.'], ['b', 'Senate.']]], ['710', '2', [['a', 'Società Dante Alighieri.'], ['b', 'Comitato di Roma.'], ['e', 'editor.']]]]);
$check($m['autori'] === ['United States. Congress. Senate'] && $m['curatore'] === ['Società Dante Alighieri. Comitato di Roma'],
    '1 corporate entries (110/710): $b units joined to the name with the final periods dropped, $e is the relator');
$m = $map([['711', '2', [['a', 'International Congress of Mathematicians.'], ['e', 'Organizing Committee.'], ['j', 'editor.']]], ['711', '2', [['a', 'Giornate di studio.'], ['e', 'Sezione storica.'], ['4', 'edt']]]]);
$check($m['curatore'] === ['International Congress of Mathematicians. Organizing Committee', 'Giornate di studio. Sezione storica'] && $m['autori'] === [],
    '2 meeting entries (711): $e is part of the name, the editor role comes from $j or from $4');
$m = $map([['111', '2', [['a', 'Congresso nazionale.'], ['n', '3.'], ['d', '(2001 :'], ['c', 'Milano)']]]]);
$check($m['autori'] === ['Congresso nazionale'], '3 a 111 with only number, date and place is an author named by its $a');
$m = $map([['110', '2', [['a', 'Istituto X.'], ['b', 'Ufficio studi.'], ['e', 'editor.']]]]);
$check($m['curatore'] === ['Istituto X. Ufficio studi'], '4 a name part that keeps the period of an initial is not followed by a second period');

echo "MARC ISO 2709 export fallbacks\n";
$cuts = ['stale'];
$bytes = MarcExportController::serializeIso('marc21', ['id' => 7, 'titolo' => 'Piccolo libro', 'descrizione' => 'Breve.'], [['nome' => 'Mario Rossi', 'ruolo' => 'principale']], [['nome' => 'Editrice']], null, $cuts, $level);
$check($bytes !== null && $level === 'full' && $cuts === [] && Iso2709Reader::parse(substr($bytes, 0, -1))->subfield('245', 'a') === 'Piccolo libro',
    '5 a normal book is written as the full record, with no cut reported');
$long = ['id' => 8, 'titolo' => 'T', 'descrizione' => str_repeat('lunga ', 3500)];
$cuts = [];
$bytes = MarcExportController::serializeIso('unimarc', $long, [], [], null, $cuts, $level);
$check($bytes !== null && $level === 'full' && count($cuts) === 1 && str_contains($cuts[0], 'field 330') && strlen($bytes) < 99999,
    '6 UNIMARC: one description over the field limit is cut inside the full record, and that cut is the one reported');
$crowd = array_map(static fn (int $i): array => ['nome' => 'Autore ' . str_repeat('n', 200) . " {$i}", 'ruolo' => $i === 1 ? 'principale' : 'co-autore'], range(1, 700));
$pubs = array_map(static fn (int $i): array => ['nome' => "Editore {$i}"], range(1, 30));
$cuts = [];
$bytes = MarcExportController::serializeIso('marc21', $long, $crowd, $pubs, null, $cuts, $level);
$reduced = $bytes !== null ? Iso2709Reader::parse(substr($bytes, 0, -1)) : null;
$names = [];
$publishers = 0;
foreach ($reduced !== null ? $reduced->fields() : [] as $field) {
    if (in_array($field->tag, ['100', '700'], true)) {
        $names[] = (string) $field->subfield('a');
    }
    if (in_array($field->tag, ['260', '264'], true)) {
        $publishers += count($field->subfieldValues('b'));
    }
}
$check($level === 'reduced' && count($names) >= 1 && count($names) <= 20 && str_ends_with($names[0], ' 1') && $publishers >= 1 && $publishers <= 5,
    '7 700 authors and 30 publishers: the reduced record keeps the first 20 authors and 5 publishers instead of dropping the book');

echo "MARC import: RDA overrides and the media type\n";
$all = static fn (string $column): bool => true;
$dvd = ['tipo_media' => 'dvd', 'rda_media_type' => 'v', 'rda_carrier_type' => 'vf'] + MarcBookData::empty();
$check(MarcBookImporter::extraColumnUpdates(MarcBookImporter::MODE_OVERWRITE, $dvd, null, $all) === ['rda_media_type' => 'v', 'rda_carrier_type' => 'vf'],
    '8 a new book takes the record\'s overrides and nothing is cleared');
$plainBook = ['tipo_media' => 'libro', 'rda_content_type' => null, 'rda_media_type' => '', 'rda_carrier_type' => null];
$check(MarcBookImporter::extraColumnUpdates(MarcBookImporter::MODE_OVERWRITE, ['tipo_media' => 'dvd'] + MarcBookData::empty(), $plainBook, $all) === [],
    '9 a media type change on a book with no override writes nothing (no pointless NULLs)');
$printed = ['tipo_media' => 'libro', 'rda_content_type' => 'tct', 'rda_media_type' => 'h', 'rda_carrier_type' => 'nb'];
$check(MarcBookImporter::extraColumnUpdates(MarcBookImporter::MODE_OVERWRITE, $dvd, $printed, $all) === ['rda_content_type' => null, 'rda_media_type' => 'v', 'rda_carrier_type' => 'vf'],
    '10 a media type change: the record\'s overrides replace the old ones and the one it lacks is cleared');

echo "Zero configuration (0.8.9 behaviour)\n";
$zero = FineParameters::fromSettings([]);
$calc = new FineCalculator();
$check(!$zero->finesEnabled() && !$zero->debtBlockEnabled() && $calc->lateFineCents($zero, '2026-01-01', '2026-04-10') === 0,
    '11 no fine settings: fines and the debt block are off, 99 days late cost nothing');
$zeroes = FineParameters::fromSettings(['daily_fine' => '0', 'fine_grace_days' => '0', 'fine_max_per_loan' => '0', 'debt_block_threshold' => '0']);
$check(!$zeroes->finesEnabled() && !$zeroes->debtBlockEnabled() && $zeroes->maxFinePerLoanCents === null,
    '12 settings saved as 0 mean "off", exactly as empty ones');
$check(CirculationPolicy::dueDateFor('2026-01-30', 30) === '2026-03-01' && CirculationPolicy::dueDateFor('2026-03-28', 14) === '2026-04-11',
    '13 without a calendar the due date is start + days, across month ends and the DST change');
$check(CirculationPolicy::dueDateFor('2026-05-10', 0) === '2026-05-10' && CirculationPolicy::dueDateFor('2026-05-10', -3) === '2026-05-10',
    '14 a typed date (0 days) is kept as it is, a negative length never moves it back');

echo "Fines with closed days\n";
$weekendsClosed = new FineCalculator(static fn (string $ymd, ?int $branch): bool => (int) (new DateTimeImmutable($ymd))->format('N') < 6);
$daily = FineParameters::fromSettings(['daily_fine' => '0,50', 'fine_grace_days' => '3']);
// Due Friday 2026-10-02, returned Monday 2026-10-12: 10 calendar days, 6 open (Mon-Fri + Mon).
$check($weekendsClosed->daysLate('2026-10-02', '2026-10-12') === 6 && $weekendsClosed->lateFineCents($daily, '2026-10-02', '2026-10-12') === 300,
    '15 closed weekends are not late days: 6 open late days at 0,50 over a grace of 3 = 3,00 from day one');
$check($weekendsClosed->lateFineCents($daily, '2026-10-02', '2026-10-07') === 0,
    '16 3 open late days (weekend skipped) stay within the grace of 3');

echo "Stocktake classification\n";
$check(StocktakeService::classify(true, false, false, true, null, null) === 'fuori_ambito'
    && StocktakeService::classify(true, true, false, false, null, null) === 'duplicata'
    && StocktakeService::classify(false, true, true, true, 1, 2) === 'sconosciuta'
    && StocktakeService::classify(true, false, true, true, 1, 2) === 'in_prestito'
    && StocktakeService::classify(true, false, true, false, 1, 2) === 'fuori_posto'
    && StocktakeService::classify(true, false, true, false, 1, null) === 'trovata',
    '17 order: unknown > duplicate > out of snapshot (a turned-up lost copy) > on loan > misplaced > found');

echo "Source guard: new searches go through ExactSearch\n";
// Every 0.9.0 controller/service with a user-typed search: a bare LIKE on
// user input would ignore the quote rule. The collocation range of the
// stocktake filter is a prefix range on a shelf mark, not a text search.
$files = [
    'app/Controllers/SuppliersController.php', 'app/Controllers/InventoryController.php', 'app/Services/StocktakeService.php',
    'app/Controllers/AttendanceController.php', 'app/Controllers/PatronLedgerController.php', 'app/Controllers/CirculationSettingsController.php',
    'app/Controllers/Admin/LibraryCalendarController.php', 'app/Support/UserImport.php', 'app/Support/PatronTypeImport.php',
    'app/Support/PatronTypeAssignment.php', 'app/Controllers/MarcExportController.php', 'app/Support/Marc/MarcBookImporter.php',
    'app/Services/PatronLedger.php', 'app/Models/AttendanceRepository.php',
];
$allowed = [
    'app/Services/StocktakeService.php' => ['(l.collocazione <= ? OR l.collocazione LIKE ?)'],
];
$offenders = [];
foreach ($files as $rel) {
    $src = (string) @file_get_contents($root . '/' . $rel);
    if ($src === '') {
        $offenders[] = "{$rel} (missing)";
        continue;
    }
    if (preg_match_all('/[^\n]*\bLIKE\s+\?[^\n]*/i', $src, $hits) > 0) {
        foreach ($hits[0] as $line) {
            $ok = false;
            foreach ($allowed[$rel] ?? [] as $fragment) {
                $ok = $ok || str_contains($line, $fragment);
            }
            if (!$ok) {
                $offenders[] = $rel . ': ' . trim($line);
            }
        }
    }
}
$check($offenders === [], '18 no bare "LIKE ?" on typed input in the 0.9.0 search code' . ($offenders !== [] ? ' — ' . implode(' | ', $offenders) : ''));
$view = (string) file_get_contents($root . '/app/Views/fornitori/index.php');
$check(!str_contains($view, ".includes(q)") && str_contains($view, '[^"“”„«»]') && str_contains($view, "normalize('NFD')") && str_contains($view, 'search_fornitore_hint'),
    '19 the suppliers box tokenises quoted groups, folds accents for plain words and shows the quote hint');

// 20: the browser tokenizer of the suppliers box against ExactSearch.
$guardOk = !str_contains($view, 'toLocaleLowerCase') && str_contains($view, 'protectInchMarks');
$parityNote = 'node not found, source guard only';
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($guardOk && $node !== '') {
    $start = strpos($view, '    const QUOTES');
    $end = strpos($view, "    search.addEventListener('input'");
    $cases = ['12"', 'monitor 24" lcd', '"1984"', '"Thomas Mann" buddenbrooks', '„Häffner“ rolf', '"unclosed run  to end',
        '5” floppy', '«Il  Mulino» 3"', 'abc "12" x', 'IKEA', '"ACME  Books"', 'x 7""y z"', '"12" monitor'];
    $expected = [];
    foreach ($cases as $case) {
        $row = [];
        foreach (ExactSearch::tokenize($case) as ['term' => $term, 'exact' => $exact]) {
            $row[] = ['term' => $exact
                ? mb_strtolower((string) \Normalizer::normalize($term, \Normalizer::FORM_C))
                : mb_strtolower((string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($term, \Normalizer::FORM_D))), 'exact' => $exact];
        }
        $expected[] = $row;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'supplier-tok');
    $script = $start !== false && $end !== false && $end > $start ? substr($view, $start, $end - $start) : 'throw new Error("script not found")';
    file_put_contents($tmp, 'const terms = (function () {' . $script . '; return terms; })();'
        . 'process.stdout.write(JSON.stringify(' . json_encode($cases, JSON_UNESCAPED_UNICODE) . '.map(terms)));');
    $actual = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1'), true);
    @unlink($tmp);
    $guardOk = $actual === $expected;
    $parityNote = $guardOk ? 'node parity on ' . count($cases) . ' queries' : 'node output differs from ExactSearch::tokenize()';
}
$check($guardOk, '20 the suppliers box tokenises like ExactSearch (inch marks, unclosed quotes) and lower-cases locale-free (' . $parityNote . ')');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
