<?php
declare(strict_types=1);
/**
 * $additional_css is printed inside the layout's own <style>. A view that wraps
 * it in another <style> turns that tag into the first selector, so the browser
 * drops the first rule, and its </style> closes the layout's block early. The
 * events pages lost their listing padding and the event cover's margins this way.
 *
 * The assignment is read with PHP's tokenizer, not a regex: a CSS declaration
 * ends in ";" at the end of a line, and a pattern that stops there would miss a
 * <style> further down the same string.
 */
$root = dirname(__DIR__);

/**
 * The string literals of every `$additional_css = ...;` statement in $src.
 *
 * @return list<string>
 */
function additionalCssValues(string $src): array
{
    $tokens = token_get_all($src);
    $values = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$additional_css') {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '=' && !(is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_CONCAT_EQUAL)) {
            continue;
        }
        $text = '';
        $depth = 0;
        for ($j++; $j < $n; $j++) {
            $t = $tokens[$j];
            if ($t === '(' || $t === '[') { $depth++; }
            if ($t === ')' || $t === ']') { $depth--; }
            if ($t === ';' && $depth === 0) {
                break;
            }
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text .= $t[1];
            }
        }
        $values[] = $text;
        $i = $j;
    }
    return $values;
}

$fail = static function (string $message): never {
    fwrite(STDERR, 'FAIL ' . $message . "\n");
    exit(1);
};

// The scanner itself: a declaration ending in ";" at the end of a line must not
// end the statement, and a concatenated part must be read too.
$fixture = "<?php\n\$additional_css = \"\n    .a { color: red; }\n<style>\n\" . 'x';\n";
$read = additionalCssValues($fixture);
if (count($read) !== 1 || stripos($read[0], '<style') === false) {
    $fail('the scanner does not read a whole $additional_css statement');
}

$count = 0;
$views = array_merge(glob($root . '/app/Views/frontend/*.php') ?: [], glob($root . '/app/Views/frontend/partials/*.php') ?: []);
foreach ($views as $file) {
    foreach (additionalCssValues((string) file_get_contents($file)) as $value) {
        $count++;
        if (stripos($value, '<style') !== false || stripos($value, '</style') !== false) {
            $fail(basename($file) . ': $additional_css carries its own <style> tag');
        }
    }
}
if ($count < 5) {
    $fail("only $count \$additional_css assignments found: the scan is not reading the views");
}
$shared = require $root . '/app/Views/frontend/partials/static-page-css.php';
if (!is_string($shared) || !str_contains($shared, '.static-content') || stripos($shared, '<style') !== false) {
    $fail('the shared text-page CSS partial must return bare CSS');
}
echo "SUCCESS $count \$additional_css assignments carry bare CSS\n";
