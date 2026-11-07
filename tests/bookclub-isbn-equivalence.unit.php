<?php
declare(strict_types=1);

/** Equivalent ISBN shapes must produce the same catalogue lookup keys. No network or DB. */
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/storage/plugins/book-club/src/Repo.php';

$repo = (new ReflectionClass(App\Plugins\BookClub\Repo::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($repo, 'normalizedIsbnParts');
$checks = 0;
$failures = 0;
$cases = [
    ['0306406152', ['9780306406157', '0306406152']],
    ['0-306-40615-2', ['9780306406157', '0306406152']],
    ['9780306406157', ['9780306406157', '0306406152']],
    ['978-0-306-40615-7', ['9780306406157', '0306406152']],
    ['080442957x', ['9780804429573', '080442957X']],
    ['9791090636071', ['9791090636071', null]],
    ['0306406153', [null, null]],
    ['9780306406158', [null, null]],
    ['978X0306406157', [null, null]],
    ['0720642442524', [null, null]],
    ['', [null, null]],
    [null, [null, null]],
];
foreach ($cases as [$input, $expected]) {
    $ok = $method->invoke($repo, $input) === $expected;
    $checks++;
    if (!$ok) { $failures++; }
    echo ($ok ? 'PASS ' : 'FAIL ') . 'lookup variants for ' . var_export($input, true) . PHP_EOL;
}
echo "$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
