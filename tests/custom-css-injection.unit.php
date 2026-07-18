<?php
declare(strict_types=1);

/**
 * Security guard for the "Custom CSS" appearance setting.
 *
 * The admin-only `advanced.custom_header_css` field is rendered verbatim
 * inside an inline <style>…</style> block on every frontend page
 * (app/Views/frontend/layout.php). Content inside <style> is HTML
 * "raw text": the ONLY way to escape it into script execution is the
 * literal end tag `</style` (ASCII case-insensitive). Before the fix the
 * sanitiser only normalised Google-Fonts URLs, so a value such as
 *   </style><script>alert(document.cookie)</script>
 * broke out of the style context and executed arbitrary JS for every
 * visitor — a stored XSS gated behind admin write.
 *
 * ContentSanitizer::sanitizeCustomCss() now neutralises every
 * <style>/<script> tag boundary (plus HTML comment / CDATA markers) while
 * leaving valid CSS untouched. This test proves both halves:
 *   A. Every known breakout payload no longer contains a live
 *      `</style` or `<script` sequence after sanitisation.
 *   B. Real-world CSS (selectors, content strings, media queries, even
 *      stray `<`/`>` inside values) survives byte-for-byte where it must.
 *
 * Run:  php tests/custom-css-injection.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\ContentSanitizer;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/** True iff the sanitised string can still break out of a <style> block. */
$breaksOut = static function (string $css): bool {
    // A browser closes <style> raw-text at `</style` (case-insensitive),
    // and a <script> opener would start executable content.
    return (bool) preg_match('#<\s*/?\s*(?:style|script)\b#i', $css);
};

// ── A. Every breakout payload is neutralised ─────────────────────────────
echo "A. Breakout payloads neutralised\n";
$payloads = [
    'classic'              => '</style><script>alert(1)</script>',
    'uppercase'            => '</STYLE><SCRIPT>alert(1)</SCRIPT>',
    'mixed-case'           => '</StYlE><ScRiPt>alert(1)</ScRiPt>',
    'space-before-gt'      => '</style ><script >alert(1)</script >',
    'self-closing-style'   => '</style/><script/>alert(1)',
    'attrs-on-tag'         => '</style><script src="//evil.tld/x.js"></script>',
    'style-then-img'       => 'a{color:red}</style><img src=x onerror=alert(1)>',
    'style-then-svg'       => 'a{}</style><svg/onload=alert(1)>',
    'newline-in-close'     => "body{}</style\n><script>alert(1)</script>",
    'tab-in-close'         => "body{}</style\t><script>alert(1)</script>",
    'leading-open-style'   => '<style>body{background:url(javascript:alert(1))}</style>',
    'nested-comment'       => '/* */</style><script>/*x*/alert(1)</script>',
    'html-comment-wrap'    => '<!--</style><script>alert(1)</script>-->',
    'cdata-wrap'           => '<![CDATA[</style><script>alert(1)</script>]]>',
    'only-script-open'     => 'body{}<script>alert(1)',
    'only-style-close'     => 'body{}</style>',
];
foreach ($payloads as $name => $raw) {
    $out = ContentSanitizer::sanitizeCustomCss($raw);
    $check(!$breaksOut($out), "payload '{$name}' can no longer break out (got: " . str_replace("\n", '\\n', $out) . ')');
}

// Belt-and-suspenders: no literal "<script" and no "</style" substring survives.
foreach ($payloads as $name => $raw) {
    $out = strtolower(ContentSanitizer::sanitizeCustomCss($raw));
    $check(!str_contains($out, '<script') && !str_contains($out, '</style'),
        "payload '{$name}' leaves no <script / </style substring");
}

// ── B. Legitimate CSS is preserved ───────────────────────────────────────
echo "B. Legitimate CSS preserved\n";
$legit = [
    'simple-rule'      => 'body { color: red; background: #fff; }',
    'pseudo-content'   => '.foo::before { content: "hello"; }',
    'media-query'      => '@media (max-width: 600px) { .nav { display: none; } }',
    'css-var'          => ':root { --brand: #123abc; } a { color: var(--brand); }',
    'lt-gt-in-value'   => '.q::after { content: "a < b > c"; }',
    'combinator'       => 'ul > li + li { margin-top: .5rem; }',
    'keyframes'        => '@keyframes spin { from { transform: rotate(0) } to { transform: rotate(360deg) } }',
    'important'        => 'p { color: green !important; }',
];
foreach ($legit as $name => $css) {
    $out = ContentSanitizer::sanitizeCustomCss($css);
    $check($out === $css, "legit CSS '{$name}' unchanged");
}

// Google Fonts normalisation (pre-existing behaviour) still runs.
$fontImport = "@import url(http://fonts.googleapis.com/css?family=Roboto);\nbody{font-family:Roboto}";
$out = ContentSanitizer::sanitizeCustomCss($fontImport);
$check(!str_contains($out, 'http://fonts.googleapis.com') && str_contains($out, 'font-family:Roboto'),
    'Google Fonts http import still normalised, rest of CSS kept');

// Empty input is a no-op.
$check(ContentSanitizer::sanitizeCustomCss('') === '', 'empty CSS stays empty');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
