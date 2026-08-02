<?php
declare(strict_types=1);

/**
 * Reusable guards for the issue #313 fix (OPAC description double-spacing).
 * Complements richtext-nl2br-313.unit.php with edge cases and a render-site
 * source guard so the description field can't silently regress to raw nl2br().
 *
 * Run:  php tests/bookdescription-render-313.unit.php   (exit 0 iff all pass)
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

// 1. Windows CRLF plain text keeps one <br> per line break (not two).
$crlf = "Prima riga\r\nSeconda riga";
$out = HtmlHelper::bookDescription($crlf);
$check(substr_count($out, '<br') === 1, "01 CRLF plain text yields exactly one <br> per newline");

// 2. HTML with attributes on the block tag is still detected as HTML (no <br>).
$attr = "<p class=\"lead\">Uno.</p>\n<p>Due.</p>";
$out = HtmlHelper::bookDescription($attr);
$check(!str_contains($out, '<br'), "02 block tag with attributes is treated as HTML (no injected <br>)");

// 3. A leading plain sentence before a block still counts as HTML overall
//    (has a block marker), so its internal newline is not turned into <br>.
$mixed = "Intro\n<p>Blocco.</p>";
$out = HtmlHelper::bookDescription($mixed);
$check(!str_contains($out, '<br'), "03 value containing any block marker suppresses nl2br");

// 4. Render source guard: the OPAC book detail renders the description through
//    the helper, never raw nl2br() — the exact regression #313 fixed.
$bookDetail = (string) file_get_contents($root . '/app/Views/frontend/book-detail.php');
$check(
    str_contains($bookDetail, 'HtmlHelper::bookDescription($book[\'descrizione\'])')
        && !preg_match('/nl2br\(\s*\$book\[\'descrizione\'\]/', $bookDetail),
    "04 book-detail renders \$book['descrizione'] via bookDescription(), not raw nl2br()"
);

// 5. Same guard for the admin book sheet.
$scheda = (string) file_get_contents($root . '/app/Views/libri/scheda_libro.php');
$check(
    str_contains($scheda, 'HtmlHelper::bookDescription($libro[\'descrizione\'])')
        && !preg_match('/nl2br\(\s*\$libro\[\'descrizione\'\]/', $scheda),
    "05 admin scheda renders \$libro['descrizione'] via bookDescription(), not raw nl2br()"
);

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
