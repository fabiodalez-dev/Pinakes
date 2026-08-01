<?php
declare(strict_types=1);

/**
 * Regression test for issue #313 — the OPAC injected <br> between the <p>
 * paragraphs of a book description.
 *
 * Root cause: the book description (and custom rich-text fields) were rendered
 * with sanitizeHtml(nl2br($value, false)). Editor HTML separates its <p> blocks
 * with a literal "\n", so nl2br turned every inter-block newline into a stray
 * <br>, double-spacing the output. HtmlHelper::richText() applies nl2br only to
 * plain-text values and leaves block HTML untouched.
 *
 * Run:  php tests/richtext-nl2br-313.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\HtmlHelper;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

// ── 1. The bug: editor HTML must NOT gain <br> between paragraphs ────────────
$html = "<p>Para uno.</p>\n<p>Para due.</p>\n<p>Para tre.</p>";
$out = HtmlHelper::richText($html);
$check(!str_contains($out, '<br'), "01 HTML paragraphs render without an injected <br> (issue #313)");
$check(substr_count($out, '<p>') === 3, "02 all three paragraphs are preserved");

// ── 2. Plain text still gets its line breaks ────────────────────────────────
$plain = "Prima riga\nSeconda riga\nTerza riga";
$out = HtmlHelper::richText($plain);
$check(substr_count($out, '<br') === 2, "03 plain text keeps its newlines as <br>");

// A single-block value that only uses <br> is already HTML → left as-is (no
// doubling): the two <br> stay two, they are not turned into four.
$brHtml = "Riga uno<br>Riga due";
$out = HtmlHelper::richText($brHtml);
$check(substr_count($out, '<br') === 1, "04 a <br>-only value is treated as HTML, not re-broken");

// Other block markers are detected too (div / ul / heading).
foreach (['<div>x</div>', '<ul><li>x</li></ul>', '<h2>x</h2>'] as $i => $block) {
    $withNl = $block . "\ntail";
    $out = HtmlHelper::richText($withNl);
    // The trailing "\ntail" newline must not become a <br> when the value is HTML.
    $check(!str_contains($out, '<br'), sprintf("0%d block HTML (%s) suppresses nl2br", 5 + $i, strtok($block, '>') . '>'));
}

// ── 3. Sanitisation is still applied ────────────────────────────────────────
$out = HtmlHelper::richText('<p>ok</p><script>alert(1)</script>');
$check(!str_contains($out, '<script'), "08 script tags are still stripped (sanitised)");

$out = HtmlHelper::richText("plain <script>alert(1)</script> text");
$check(!str_contains($out, '<script'), "09 script in plain-text path is stripped too");

// ── 4. Empty / null ─────────────────────────────────────────────────────────
$check(HtmlHelper::richText(null) === '', "10 null => empty string");
$check(HtmlHelper::richText('') === '', "11 empty => empty string");

// ── 5. Cyrillic content (the reporter's data) survives ──────────────────────
$cyr = "<p>Америка&hellip;</p>\n<p>Однако&hellip;</p>";
$out = HtmlHelper::richText($cyr);
$check(str_contains($out, 'Америка') && !str_contains($out, '<br'), "12 Cyrillic paragraphs render clean (reporter's case)");

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
