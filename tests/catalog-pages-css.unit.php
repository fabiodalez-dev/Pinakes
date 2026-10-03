<?php
declare(strict_types=1);

/**
 * public/assets/catalog-pages.css, shared by /catalogo and /emeroteca/articoli.
 *
 * Two rules the stylesheet must keep:
 *  - no declaration block sets the same property twice, except a fallback
 *    chain whose later value needs a newer CSS function: the duplicates left by
 *    the extraction from catalog.php were harmless only by luck of order;
 *  - a :focus rule that removes the outline has a :focus-visible rule for the
 *    same selector that draws one, so keyboard users still see where they are.
 */

$css = file_get_contents(dirname(__DIR__) . '/public/assets/catalog-pages.css');
if ($css === false) {
    fwrite(STDERR, "FAIL catalog-pages.css is readable\n");
    exit(1);
}
$css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

$failures = 0;
$checks = 0;
/** Record one check and print its outcome. */
function check(bool $ok, string $label): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
}

// Innermost blocks only: selector { declarations } with no nested braces, which
// also reaches the rules inside @media.
preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $blocks, PREG_SET_ORDER);
check(count($blocks) > 100, 'the stylesheet was parsed into rule blocks (' . count($blocks) . ')');

$duplicates = [];
$rules = [];
foreach ($blocks as [, $selector, $body]) {
    $selector = trim((string) preg_replace('/\s+/', ' ', $selector));
    $properties = [];
    foreach (explode(';', $body) as $declaration) {
        if (!str_contains($declaration, ':')) {
            continue;
        }
        [$property, $value] = array_map('trim', explode(':', $declaration, 2));
        $property = strtolower($property);
        $value = (string) preg_replace('/\s+/', ' ', $value);
        // A repeated property is a fallback chain when the later value uses a
        // CSS function older browsers may not know (color-mix(), clamp() …):
        // they keep the first value. The same value twice is only a leftover.
        $fallback = isset($properties[$property])
            && $properties[$property] !== $value
            && preg_match('/\b(color-mix|clamp|min|max)\(/i', $value) === 1;
        if (isset($properties[$property]) && !$fallback) {
            $duplicates[] = "{$selector} → {$property}";
        }
        $properties[$property] = $value;
    }
    $rules[$selector] = ($rules[$selector] ?? '') . $body;
}
check($duplicates === [], 'no block declares a property twice' . ($duplicates ? ': ' . implode(', ', $duplicates) : ''));

$unfocused = [];
foreach ($rules as $selector => $body) {
    if (!preg_match('/outline\s*:\s*none/i', $body)) {
        continue;
    }
    foreach (array_map('trim', explode(',', $selector)) as $single) {
        if (!str_ends_with($single, ':focus')) {
            continue;
        }
        $visible = substr($single, 0, -strlen(':focus')) . ':focus-visible';
        $ring = false;
        foreach ($rules as $other => $otherBody) {
            if (in_array($visible, array_map('trim', explode(',', $other)), true)
                && preg_match('/outline\s*:\s*(?!none)[^;]*\d/i', $otherBody)) {
                $ring = true;
                break;
            }
        }
        if (!$ring) {
            $unfocused[] = $single;
        }
    }
}
check($unfocused === [], 'every :focus that drops the outline has a :focus-visible ring' . ($unfocused ? ': ' . implode(', ', $unfocused) : ''));
check(isset($rules['.search-box input:focus-visible']), 'the article and catalogue search box has its keyboard ring');

echo "\n" . ($failures === 0 ? "SUCCESS {$checks} checks" : "FAILURE {$failures} of {$checks} checks failed") . "\n";
exit($failures === 0 ? 0 : 1);
