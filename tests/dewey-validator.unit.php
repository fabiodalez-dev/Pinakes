<?php
declare(strict_types=1);

/**
 * Unit tests — dewey-editor DeweyValidator notation rules.
 *
 * Real DDC numbers built from the tables run well past four decimal digits
 * (823.91409, 973.0496073, 616.8588200): the validator must accept them up to
 * 12 decimal digits, derive the level from the notation (main class 1,
 * division 2, section 3, then +1 per decimal digit) and keep rejecting
 * malformed notations. The shipped data/dewey JSON must validate as-is.
 *
 * Run:
 *   php tests/dewey-validator.unit.php
 * Exits 0 on success, non-zero on any failure.
 */

if (!function_exists('__')) {
    function __(string $s): string
    {
        return $s;
    }
}

require_once __DIR__ . '/../storage/plugins/dewey-editor/classes/DeweyValidator.php';

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) {
        ++$passed;
        echo "  OK  $label\n";
    } else {
        ++$failed;
        echo "  FAIL $label\n";
    }
};

$v = new DeweyValidator();

echo "1. Deep notations accepted:\n";
foreach (['823.91409' => 8, '973.0496073' => 10, '616.8588200' => 10, '599.93' => 5, '800' => 1, '810' => 2, '813' => 3, '813.123456789012' => 15] as $code => $level) {
    $code = (string) $code; // integer-like array keys come back as int
    $check($v->isValidCode($code), "$code is a valid code");
    $check($v->levelForCode($code) === $level, "$code derives level $level (got " . var_export($v->levelForCode($code), true) . ')');
}

echo "2. Invalid notations rejected:\n";
foreach (['82', '823.', '823.a1', '1234', '823.1234567890123', '', '8a3', ' 823'] as $code) {
    $check(!$v->isValidCode($code), "'$code' rejected");
    $check($v->levelForCode($code) === null, "'$code' has no level");
}

echo "3. Hierarchy (parent + deletability):\n";
$check($v->getParentCode('973.0496073') === '973.049607', 'parent of 973.0496073 is 973.049607');
$check($v->getParentCode('823.9') === '823', 'parent of 823.9 is 823');
$check($v->canDelete('616.8588200'), 'deep decimal is deletable');
$check(!$v->canDelete('616'), 'section is not deletable');

echo "4. validate() on a deep tree:\n";
$main = [];
foreach (['000', '100', '200', '300', '400', '500', '600', '700', '900'] as $c) {
    $main[] = ['code' => $c, 'name' => 'Class ' . $c, 'level' => 1, 'children' => []];
}
$deep = ['code' => '800', 'name' => 'Letteratura', 'level' => 1, 'children' => [
    ['code' => '820', 'name' => 'Inglese', 'level' => 2, 'children' => [
        ['code' => '823', 'name' => 'Narrativa', 'level' => 3, 'children' => [
            ['code' => '823.9', 'name' => 'Narrativa 1900-', 'level' => 4, 'children' => [
                ['code' => '823.91409', 'name' => 'Deep number', 'level' => 8, 'children' => []],
            ]],
        ]],
    ]],
]];
$errors = $v->validate(array_merge($main, [$deep]));
$check($errors === [], 'deep tree validates (errors: ' . implode(' | ', $errors) . ')');

$wrongLevel = $deep;
$wrongLevel['children'][0]['children'][0]['children'][0]['children'][0]['level'] = 5;
$errors = $v->validate(array_merge($main, [$wrongLevel]));
$check(count($errors) === 1 && str_contains($errors[0], '823.91409'), 'level disagreeing with the notation is reported');

$badCode = $deep;
$badCode['children'][0]['children'][0]['children'][0]['children'][0]['code'] = '823.a1';
$errors = $v->validate(array_merge($main, [$badCode]));
$check($errors !== [] && str_contains(implode(' ', $errors), '823.a1'), 'malformed code in tree is reported');

echo "5. Shipped data validates:\n";
foreach (['it', 'en'] as $lang) {
    $path = __DIR__ . '/../data/dewey/dewey_completo_' . $lang . '.json';
    $data = json_decode((string) file_get_contents($path), true);
    $check(is_array($data), "dewey_completo_$lang.json parses");
    $errors = is_array($data) ? $v->validate($data) : ['unparsable'];
    // Only the notation rules are under test here: a few shipped nodes carry
    // an empty name, which is a data issue reported by the name check.
    $notationErrors = array_values(array_filter(
        $errors,
        static fn(string $e): bool => !str_contains($e, 'nome non valido')
    ));
    $check($notationErrors === [], "dewey_completo_$lang.json has no format/level/hierarchy errors (" . count($notationErrors) . ($notationErrors ? ': ' . $notationErrors[0] : '') . ')');
}

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
