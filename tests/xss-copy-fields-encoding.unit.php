<?php
declare(strict_types=1);

/**
 * Security guard for the per-copy fields rendered into the copy edit/delete
 * buttons on the admin book-detail page (app/Views/libri/scheda_libro.php).
 *
 * numero_inventario / stato / note are staff-writable free text (copy-tracking
 * #238). They used to be emitted into `onclick="fn(id, '<value>')"`, a JS string
 * inside an HTML attribute, where htmlspecialchars() alone is the wrong encoding
 * (the browser HTML-decodes the attribute before the JS parser runs). The CSP now
 * forbids inline handlers (script-src-attr 'none'), so the values travel in
 * data-copy-* attributes and the page script reads them through `dataset`: there
 * is no JS parsing of the value any more, only HTML attribute decoding, for which
 * htmlspecialchars(ENT_QUOTES) is the right and sufficient encoding.
 *
 * This test reproduces that exact expression, asserts every payload round-trips
 * through attribute decoding unchanged and cannot terminate the attribute, and
 * checks that the view really renders the copy buttons that way.
 *
 * Run:  php tests/xss-copy-fields-encoding.unit.php   (exit 0 iff all pass)
 */

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

/** The exact PHP expression scheda_libro.php now uses for a copy field. */
$encode = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

echo "A. data-* attribute context — no breakout\n";
$payloads = [
    'string-break-alert'  => "');alert(document.domain)//",
    'double-quote-break'  => '");alert(1)//',
    'tag-inject'          => '</script><script>alert(1)</script>',
    'img-onerror'         => '<img src=x onerror=alert(1)>',
    'attr-break'          => '"><svg onload=alert(1)>',
    'backslash'           => "\\');alert(1)//",
    'newline'             => "a\n');alert(1)//",
    'unicode-quote'       => "\u{2028}');alert(1)//",
    'plain-code'          => 'INV-001',
    'quotes-mix'          => 'O\'Brien "the" <b>bold</b>',
];

foreach ($payloads as $name => $raw) {
    $attr = $encode($raw);

    // 1. Nothing in the value can end the double-quoted attribute or open a tag.
    $check(!str_contains($attr, '"') && !str_contains($attr, '<') && !str_contains($attr, '>'),
        "payload '{$name}': no raw quote/angle-bracket in attribute");

    // 2. The browser HTML-decodes the attribute; dataset hands the script exactly
    //    the original string, which is only ever used as data.
    $check(html_entity_decode($attr, ENT_QUOTES | ENT_HTML5, 'UTF-8') === $raw,
        "payload '{$name}': attribute round-trips to the original value");
}

echo "\nB. the view renders copy buttons without inline handlers\n";
$view = (string) file_get_contents(__DIR__ . '/../app/Views/libri/scheda_libro.php');
$check(!preg_match('/\son[a-z]+\s*=/i', $view), 'scheda_libro.php has no inline on* handler');
$check(str_contains($view, 'data-copy-stato="<?php echo htmlspecialchars((string) ($copia[\'stato\'] ?? \'\'), ENT_QUOTES, \'UTF-8\'); ?>"'),
    'copy stato travels in an escaped data attribute');
$check(str_contains($view, 'data-copy-note="<?php echo htmlspecialchars((string) ($copia[\'note\'] ?? \'\'), ENT_QUOTES, \'UTF-8\'); ?>"'),
    'copy note travels in an escaped data attribute');
$check(str_contains($view, 'data-copy-inventario="<?php echo htmlspecialchars((string) ($copia[\'numero_inventario\'] ?? \'\'), ENT_QUOTES, \'UTF-8\'); ?>"'),
    'copy inventory number travels in an escaped data attribute');
$check(str_contains($view, "PinakesActions.register('book-copy-edit'") && str_contains($view, "PinakesActions.register('book-copy-delete'"),
    'copy edit/delete verbs are registered by the page script');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
