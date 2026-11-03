<?php
declare(strict_types=1);

/**
 * HolidayPresets::missingIds(): the chosen holiday ids a country's rule
 * table or dataset no longer knows. apply() filters them out of the stored
 * selection, LibraryCalendar never evaluates them, and the calendar page
 * names them so the librarian can tick their replacements, instead of a
 * ticked holiday silently turning into an open day after a dataset
 * regeneration accepted with --accept-removed.
 *
 * Run: php tests/holiday-presets-missing.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Holidays\HolidayCalculator;
use App\Support\Holidays\HolidayPresets;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    $checks++;
    echo "OK {$label}\n";
};

$italian = array_map(static fn (array $rule): string => 'IT:' . (string) $rule['id'], HolidayCalculator::rulesFor('IT', ''));
$check(in_array('IT:christmas', $italian, true), 'the Italian table knows christmas');

$check(HolidayPresets::missingIds('IT', '', ['IT:new_year', 'IT:christmas']) === [], 'known ids are not missing');
$check(HolidayPresets::missingIds('IT', '', []) === [], 'an empty selection has nothing missing');
$check(HolidayPresets::missingIds('IT', '', ['IT:christmas', 'IT:zz_vanished', 'IT:gone_too']) === ['IT:zz_vanished', 'IT:gone_too'], 'ids the table does not know are listed, in order');
$check(HolidayPresets::missingIds('it', '', ['IT:zz_vanished']) === ['IT:zz_vanished'], 'the country code is read case-insensitively');
$check(HolidayPresets::missingIds('IT', '', ['IT:zz_vanished', 'IT:zz_vanished']) === ['IT:zz_vanished'], 'a duplicate is reported once');
$check(HolidayPresets::missingIds('IT', '', ['DE:neujahr', 'IT:christmas']) === [], 'ids of another country are not reported: apply() never stores them');
$check(HolidayPresets::missingIds('IT', '', ['it:christmas']) === [], 'a lower-case id of another spelling is not one of ours');

// A dataset country (no hand-written table): the same rule applies to its ids.
$dataset = null;
foreach (array_keys(HolidayCalculator::countryNames()) as $code) {
    if (($rules = HolidayCalculator::rulesFor($code, '')) !== [] && (HolidayCalculator::country($code)['dataset'] ?? false)) {
        $dataset = [$code, $code . ':' . (string) $rules[0]['id']];
        break;
    }
}
$check($dataset !== null, 'a dataset country with holidays exists');
[$code, $firstId] = $dataset;
$check(HolidayPresets::missingIds($code, '', [$firstId, $code . ':zz_vanished']) === [$code . ':zz_vanished'], "{$code}: a dataset id is known, a vanished one is listed");

// filterIds() and missingIds() split the stored list between them: nothing is lost in between.
$stored = ['IT:christmas', 'IT:zz_vanished', 'IT:new_year'];
$kept = HolidayPresets::filterIds('IT', '', $stored);
$check(count($kept) + count(HolidayPresets::missingIds('IT', '', $stored)) === count($stored), 'every stored id is either kept or reported missing');

echo "SUCCESS {$checks} missing holiday id checks\n";
