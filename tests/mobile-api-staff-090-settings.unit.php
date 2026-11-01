<?php
declare(strict_types=1);

/**
 * Mobile API 1.10.0 — library settings areas: the pure pieces.
 *
 *   A. SettingsKit money conversions: decimal strings ↔ cents, empty = null.
 *   B. SettingsKit input checks: JSON types and ranges, absent = fine.
 *   C. The API field maps cover every field of the web forms they post to,
 *      so a column added to the web form cannot be silently reset by a
 *      partial update from the app (the form would post it empty).
 *
 * Run: php tests/mobile-api-staff-090-settings.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$plugin = __DIR__ . '/../storage/plugins/mobile-api/src';
require_once $plugin . '/Support/ResponseEnvelope.php';
require_once $plugin . '/Support/Delegation.php';
require_once $plugin . '/Staff090/Support/SettingsKit.php';
require_once $plugin . '/Staff090/Controllers/LibrarySettingsController.php';
require_once $plugin . '/Staff090/Controllers/CirculationRulesController.php';

use App\Controllers\CirculationSettingsController;
use App\Plugins\MobileApi\Staff090\Controllers\CirculationRulesController;
use App\Plugins\MobileApi\Staff090\Controllers\LibrarySettingsController;
use App\Plugins\MobileApi\Staff090\Support\SettingsKit;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $label . "\n";
    $condition ? $passed++ : $failed++;
};

echo "A. Money conversions\n";
$check(SettingsKit::cents('2.50') === 250, '"2.50" is 250 cents');
$check(SettingsKit::cents('2,5') === 250, '"2,5" (decimal comma) is 250 cents');
$check(SettingsKit::cents('') === null && SettingsKit::cents(null) === null, 'empty / null is null');
$check(SettingsKit::cents('abc') === null, 'malformed is null');
$check(SettingsKit::decimal(250) === '2.50' && SettingsKit::decimal(5) === '0.05', 'cents to the form decimal');
$check(SettingsKit::decimal(null) === '', 'null cents is an empty field');
$check(SettingsKit::floatCents(1.5) === 150 && SettingsKit::floatCents(null) === null, 'resolved float to cents');
$check(SettingsKit::intOrNull('7') === 7 && SettingsKit::intOrNull(null) === null && SettingsKit::intOrNull('') === null, 'stored nullable int');
$check(SettingsKit::formInt(null) === '' && SettingsKit::formInt(0) === '0', 'nullable int as a form value');

echo "B. Input checks\n";
$check(SettingsKit::checkInt([], 'x', 1, 5) === null, 'absent int passes');
$check(SettingsKit::checkInt(['x' => null], 'x', 1, 5) === null, 'null int passes when nullable');
$check(SettingsKit::checkInt(['x' => null], 'x', 1, 5, false) !== null, 'null int refused when not nullable');
$check(SettingsKit::checkInt(['x' => '3'], 'x', 1, 5) !== null, 'a numeric string is not an integer');
$check(SettingsKit::checkInt(['x' => 6], 'x', 1, 5) !== null && SettingsKit::checkInt(['x' => 0], 'x', 1, 5) !== null, 'out of range refused');
$check(SettingsKit::checkInt(['x' => 5], 'x', 1, 5) === null, 'bound accepted');
$check(SettingsKit::checkBool(['x' => 1], 'x') !== null && SettingsKit::checkBool(['x' => false], 'x') === null, 'booleans only');
$check(SettingsKit::checkCents(['x' => 999999], 'x', 'L') === null, 'the maximum amount is accepted');
$check(SettingsKit::checkCents(['x' => 1000000], 'x', 'Multa') !== null && str_contains((string) SettingsKit::checkCents(['x' => 1000000], 'x', 'Multa'), 'Multa'), 'above the maximum: the message names the field');
$check(SettingsKit::checkCents(['x' => -1], 'x', 'L') !== null && SettingsKit::checkCents(['x' => 1.5], 'x', 'L') !== null, 'negative / fractional cents refused');
$check(SettingsKit::checkString(['x' => str_repeat('a', 11)], 'x', 10) !== null && SettingsKit::checkString(['x' => 'abc'], 'x', 10) === null, 'string length');
$check(SettingsKit::checkString(['x' => 5], 'x', 10) !== null, 'non-string refused');

echo "C. Field maps cover the web forms\n";
$typeColumns = array_merge(array_values(CirculationRulesController::TYPE_INTS), array_values(CirculationRulesController::TYPE_MONEY));
$webTypeColumns = array_merge(array_keys(CirculationSettingsController::TYPE_INT_FIELDS), CirculationSettingsController::TYPE_MONEY_FIELDS);
sort($typeColumns);
sort($webTypeColumns);
$check($typeColumns === $webTypeColumns, 'patron type: every integer and money column has an API field');
$ruleKeys = array_merge(array_values(CirculationRulesController::RULE_INTS), array_values(CirculationRulesController::RULE_MONEY));
$webRuleKeys = array_merge(array_keys(CirculationSettingsController::RULE_FIELDS), array_keys(CirculationSettingsController::RULE_MONEY_FIELDS));
sort($ruleKeys);
sort($webRuleKeys);
$check($ruleKeys === $webRuleKeys, 'rules matrix: every cell field has an API field');

// Loans: every field the web form saves is either a Staff field or an admin field of the API.
$source = (string) file_get_contents(__DIR__ . '/../app/Controllers/SettingsController.php');
$start = strpos($source, 'public function updateLoansSettings(');
$end = strpos($source, 'public function updateModules(');
$body = substr($source, (int) $start, (int) $end - (int) $start);
preg_match_all('/\$data\[\'([a-z_]+)\'\]/', $body, $m);
$webFields = array_values(array_unique($m[1]));
$apiFields = array_keys(LibrarySettingsController::LOAN_FIELDS);
foreach (LibrarySettingsController::LOAN_ADMIN_FIELDS as $field) {
    $apiFields[] = str_ends_with($field, '_cents') ? substr($field, 0, -6) : $field;
}
$missing = array_diff($webFields, $apiFields);
$check($webFields !== [] && $missing === [], 'loans: every posted field is mapped (missing: ' . implode(', ', $missing) . ')');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
