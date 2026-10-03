<?php
declare(strict_types=1);

/**
 * The donation form carries a `return_to` value that ends up verbatim in a
 * Location header. DesiderataPlugin::returnPath() is the allow-list that keeps
 * it a local path, and this suite is the proof that it is one.
 *
 * The case that matters, and that an eyeballed regex misses: a browser REMOVES
 * tab, LF and CR from a URL wherever they appear before it parses it (WHATWG
 * URL Standard, "URL parsing" — they are not merely trimmed from the ends).
 * So a filter that inspects the raw bytes is judging a string the browser will
 * never see. "/\t\evil.example" contains no leading "//" and no "/\" pair in
 * the bytes, yet the browser reads it as "/\evil.example", a network-path
 * reference — an open redirect to whatever host the attacker named.
 *
 * Each case below therefore states BOTH what the filter must answer and, where
 * the two differ, what the browser would make of the raw input. A case is only
 * meaningful because it was seen to fail against the pre-fix filter; the tab
 * vectors did exactly that.
 *
 * No database and no HTTP: the unit under test is a pure string function, and
 * reaching it through a real POST would make a failure ambiguous between the
 * filter, the route, the CSRF token and the rate limiter.
 *
 * Run:  php tests/desiderata-return-path.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$method = new ReflectionMethod(DesiderataPlugin::class, 'returnPath');
$method->setAccessible(true);
$returnPath = static fn(mixed $raw): mixed => $method->invoke(null, $raw);

/** Readable rendering of control bytes, so a failure line is diagnosable. */
$show = static function (mixed $v): string {
    if (!is_string($v)) {
        return gettype($v);
    }
    return '"' . str_replace(["\t", "\n", "\r", "\0"], ['\t', '\n', '\r', '\0'], $v) . '"';
};

echo "A. What must be accepted — the feature still has to work\n";

$accepted = [
    '/desiderata',
    '/desiderata?page=3',
    '/libro/1234',
    '/desiderata#requested-books',
    '/a',
];
foreach ($accepted as $path) {
    $got = $returnPath($path);
    $check($got === $path, "accepts {$show($path)} unchanged (got {$show($got)})");
}

echo "\nB. What must be refused — every one of these would leave the site\n";

$refused = [
    // The classic protocol-relative form and its backslash variants.
    '//evil.example'        => 'protocol-relative',
    '/\\evil.example'       => 'backslash network-path',
    '/\\\\evil.example'     => 'double backslash',
    // The bypasses this suite exists for: a byte the browser deletes, placed
    // where it breaks up the pattern the filter is looking for.
    "/\t/evil.example"      => 'TAB then slash — browser sees //evil.example',
    "/\t\\evil.example"     => 'TAB then backslash — browser sees /\\evil.example',
    "/\n/evil.example"      => 'LF then slash',
    "/\r/evil.example"      => 'CR then slash',
    "/\t\t\\evil.example"   => 'two TABs then backslash',
    // Header splitting and truncation.
    "/ok\r\nLocation: https://evil.example" => 'CRLF header injection',
    "/ok\0/evil.example"    => 'NUL truncation',
    // Absolute URLs and scheme tricks.
    'https://evil.example'  => 'absolute https',
    'javascript:alert(1)'   => 'javascript scheme',
    'desiderata'            => 'relative, no leading slash',
    ''                      => 'empty',
];
foreach ($refused as $raw => $why) {
    $got = $returnPath($raw);
    $check($got === '', "refuses {$show($raw)} — {$why} (got {$show($got)})");
}

echo "\nC. Non-strings never reach the header\n";

foreach ([null, 42, 3.5, true, [], new stdClass()] as $value) {
    $got = $returnPath($value);
    $check($got === '', 'refuses a ' . gettype($value) . " (got {$show($got)})");
}

echo "\nD. What comes back is what the browser will see\n";

// Whatever the filter accepts must already be in its final form: if a browser
// would still rewrite the returned value, then the string that was judged and
// the string that takes effect are two different things, and the judgement
// says nothing about the one that matters.
foreach ($accepted as $path) {
    $got = $returnPath($path);
    $check(
        is_string($got) && $got === str_replace(["\t", "\n", "\r"], '', $got),
        "the accepted value {$show($got)} carries nothing a browser would strip"
    );
}

// The ceiling is 254 characters AFTER the leading slash, i.e. 255 in total —
// stated here in the form the pattern actually expresses, because getting this
// off by one is how a boundary test ends up asserting the wrong boundary.
$atCeiling = '/' . str_repeat('a', 254);
$pastCeiling = '/' . str_repeat('a', 255);
$check($returnPath($atCeiling) === $atCeiling, 'accepts 254 characters after the slash');
$check($returnPath($pastCeiling) === '', 'refuses 255 characters after the slash');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
