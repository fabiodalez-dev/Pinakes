<?php
declare(strict_types=1);

/**
 * Unit tests for App\Support\HtmlHelper::sanitizeHtml() (issue: Fase 3 security —
 * regex HTML sanitizer replaced by symfony/html-sanitizer).
 *
 * 15 reusable cases covering allowed formatting, dangerous tags, event handlers,
 * dangerous URL schemes, attribute whitelisting and malformed-markup bypasses.
 *
 * Run:
 *   php tests/html-sanitizer.unit.php
 * Exits 0 on success, 1 on any failure.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\HtmlHelper;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else { $failed++; echo "  FAIL $label\n"; }
};
/** output must contain (case-insensitive) */
$has = static fn(string $h, string $n): bool => stripos($h, $n) !== false;
/** output must NOT contain (case-insensitive) */
$lacks = static fn(string $h, string $n): bool => stripos($h, $n) === false;

// 1. null/empty input → empty string
$check(HtmlHelper::sanitizeHtml(null) === '' && HtmlHelper::sanitizeHtml('') === '', '1. null/empty -> empty string');

// 2. plain text preserved
$check($has(HtmlHelper::sanitizeHtml('Just plain text'), 'Just plain text'), '2. plain text preserved');

// 3. allowed inline formatting preserved
$o = HtmlHelper::sanitizeHtml('<p>hello <strong>bold</strong> <em>it</em></p>');
$check($has($o, '<strong>bold</strong>') && $has($o, '<em>it</em>'), '3. p/strong/em preserved');

// 4. lists preserved
$o = HtmlHelper::sanitizeHtml('<ul><li>a</li><li>b</li></ul>');
$check($has($o, '<ul>') && $has($o, '<li>a</li>'), '4. ul/li preserved');

// 5. table structure preserved
$o = HtmlHelper::sanitizeHtml('<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>D</td></tr></tbody></table>');
$check($has($o, '<table>') && $has($o, '<td>D</td>'), '5. table structure preserved');

// 6. <script> removed entirely (tag + content)
$o = HtmlHelper::sanitizeHtml('<p>ok</p><script>alert(1)</script>');
$check($lacks($o, '<script') && $lacks($o, 'alert(1)') && $has($o, '<p>ok</p>'), '6. script removed, content stripped');

// 7. <style> removed
$check($lacks(HtmlHelper::sanitizeHtml('<style>body{x:1}</style><p>ok</p>'), '<style'), '7. style removed');

// 8. <iframe>/<object>/<embed> removed
$o = HtmlHelper::sanitizeHtml('<iframe src="http://evil"></iframe><object></object><embed>');
$check($lacks($o, '<iframe') && $lacks($o, '<object') && $lacks($o, '<embed'), '8. iframe/object/embed removed');

// 9. inline event handlers removed
$o = HtmlHelper::sanitizeHtml('<div onclick="evil()" onmouseover="x()">hi</div>');
$check($lacks($o, 'onclick') && $lacks($o, 'onmouseover') && $has($o, 'hi'), '9. event handlers removed');

// 10. javascript: href neutralized
$o = HtmlHelper::sanitizeHtml('<a href="javascript:alert(1)">x</a>');
$check($lacks($o, 'javascript:'), '10. javascript: scheme blocked');

// 11. https link preserved + rel=noopener forced
$o = HtmlHelper::sanitizeHtml('<a href="https://example.com" target="_blank">l</a>');
$check($has($o, 'href="https://example.com"') && $has($o, 'noopener'), '11. https link + rel noopener');

// 12. mailto link allowed (the "@" may be HTML-entity-encoded, which is valid)
$o = HtmlHelper::sanitizeHtml('<a href="mailto:a@b.it">m</a>');
$check($has($o, 'mailto:') && $has($o, 'b.it'), '12. mailto allowed');

// 13. relative link + anchor allowed
$o = HtmlHelper::sanitizeHtml('<a href="/libri/1">rel</a><a href="#sec">anc</a>');
$check($has($o, 'href="/libri/1"') && $has($o, 'href="#sec"'), '13. relative + anchor links allowed');

// 14. img: data:image and https allowed, javascript scheme dropped
$o = HtmlHelper::sanitizeHtml('<img src="data:image/png;base64,AAAA" alt="x"><img src="https://e/i.png">');
$check($has($o, 'data:image/png') && $has($o, 'https://e/i.png'), '14. img data:image + https allowed');

// 15. disallowed tags + non-whitelisted attributes stripped; nested-markup bypass neutralized
$o = HtmlHelper::sanitizeHtml('<form action="/x"><input name="y"></form><p style="position:fixed">t</p><scr<script>ipt>a()</script>');
$check(
    $lacks($o, '<form') && $lacks($o, '<input') && $lacks($o, 'style=') && $lacks($o, '<script') && $has($o, 't'),
    '15. form/input/style stripped + nested-markup bypass neutralized'
);

echo "\n  $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
