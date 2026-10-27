<?php
declare(strict_types=1);

/**
 * Inventory stocktake (plan §8) — the pure rules: how a scan is classified,
 * which filter values a session keeps, and how an uploaded code list is read.
 *
 * Run: php tests/stocktake-classification.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\StocktakeService as S;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    $checks++;
    echo "OK {$label}\n";
};

// classify(copyExists, alreadyScanned, expected, onLoan, expectedShelf, scanShelf)
$check(S::classify(false, false, false, false, null, null) === 'sconosciuta', 'a code that matches no copy is unknown');
$check(S::classify(false, true, true, true, 3, 4) === 'sconosciuta', 'unknown wins over every other signal');
$check(S::classify(true, false, true, false, 3, null) === 'trovata', 'an expected copy, no shelf declared, is found');
$check(S::classify(true, false, true, false, 3, 3) === 'trovata', 'an expected copy on its own shelf is found');
$check(S::classify(true, false, true, false, 3, 4) === 'fuori_posto', 'an expected copy on another shelf is misplaced');
$check(S::classify(true, false, true, false, null, 4) === 'fuori_posto', 'a copy with no recorded shelf found on a shelf is misplaced');
$check(S::classify(true, false, true, false, 3, 0) === 'trovata', 'shelf 0 means "not declared"');
$check(S::classify(true, false, true, true, 3, 4) === 'in_prestito', 'an expected copy that is on loan is reported as on loan, not misplaced');
$check(S::classify(true, false, false, false, 3, 3) === 'fuori_ambito', 'a copy outside the snapshot is out of scope');
$check(S::classify(true, false, false, true, null, null) === 'fuori_ambito', 'out of scope wins over on loan');
$check(S::classify(true, true, true, false, 3, 3) === 'duplicata', 'a second scan of the same copy is a duplicate');
$check(S::classify(true, true, false, false, null, null) === 'duplicata', 'a second scan of an out-of-scope copy is a duplicate too');
foreach ([[true, false], [false, true]] as [$exists, $expected]) {
    $outcome = S::classify($exists, false, $expected, false, null, null);
    $check(in_array($outcome, S::OUTCOMES, true), "outcome {$outcome} is one of the stored ENUM values");
}

// normalizeFilter
$filter = S::normalizeFilter([
    'scaffale_id' => '7', 'mensola_id' => '0', 'genere_id' => 'abc', 'tipo_media' => 'dvd',
    'collocazione_da' => '  A.1 ', 'collocazione_a' => '', 'nome' => 'ignored', 'csrf_token' => 'x',
]);
$check($filter === ['scaffale_id' => 7, 'tipo_media' => 'dvd', 'collocazione_da' => 'A.1'], 'the filter keeps only valid, known keys');
$check(S::normalizeFilter(['tipo_media' => 'vinile']) === [], 'an unknown media type is dropped');
$check(S::normalizeFilter(['scaffale_id' => '-3', 'genere_id' => '2.5']) === [], 'negative and non-integer ids are dropped');
$check(mb_strlen(S::normalizeFilter(['collocazione_a' => str_repeat('Z', 80)])['collocazione_a']) === 50, 'collocation bounds are capped to the column width');
$check(S::normalizeFilter(['scaffale_id' => ['1']]) === [], 'an array value is not a filter');

// normalizeCode
$check(S::normalizeCode("  INV-001\r\n") === 'INV-001', 'a wedge scanner suffix (CR/LF) is stripped');
$check(S::normalizeCode("\x02INV-002\x03") === 'INV-002', 'STX/ETX framing is stripped');
$check(mb_strlen(S::normalizeCode(str_repeat('9', 300))) === S::MAX_CODE_LENGTH, 'a code is capped to the column width');

// parseCodeList
$txt = "\xEF\xBB\xBFINV-1\nINV-2\r\n\r\nINV-1\n   \nINV-3";
$check(S::parseCodeList($txt) === ['INV-1', 'INV-2', 'INV-1', 'INV-3'], 'a .txt list keeps order and repeats, skipping blank lines and the BOM');
$csv = "codice;titolo\n\"INV-10\";\"Il nome della rosa\"\nINV-11,Altro\n\tINV-12\tx";
$check(S::parseCodeList($csv) === ['INV-10', 'INV-11', 'INV-12'], 'a .csv list takes the first non-empty cell and skips the header');
$check(S::parseCodeList("Barcode\n123") === ['123'], 'an English header is skipped too');
$check(S::parseCodeList("codice-1\ncodice") === ['codice-1', 'codice'], 'only the first line can be a header');
$latin1 = "INV-\xE9";
$check(S::parseCodeList($latin1) === ['INV-é'], 'a Latin-1 export is read as UTF-8');
$check(S::parseCodeList('') === [], 'an empty file yields no codes');

echo "SUCCESS {$checks} stocktake classification checks\n";
