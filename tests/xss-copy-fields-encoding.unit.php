<?php
declare(strict_types=1);

/**
 * Security guard for the per-copy fields rendered into inline onclick handlers
 * on the admin book-detail page (app/Views/libri/scheda_libro.php).
 *
 * numero_inventario / stato / note are staff-writable free text (copy-tracking
 * #238). They were emitted into `onclick="fn(id, '<value>')"` with only
 * htmlspecialchars(ENT_QUOTES). That is the WRONG encoding for a JS string
 * inside an HTML attribute: the browser HTML-decodes the attribute (`&#039;`→`'`)
 * BEFORE the JS parser runs, so a value like `');alert(1)//` breaks out of the
 * string and executes — a stored XSS that fires in another admin's browser when
 * they click the copy edit/delete button.
 *
 * The fix wraps the value in json_encode() with the JSON_HEX_* flags (so the JS
 * string cannot be broken) and then htmlspecialchars() (so the JSON's delimiting
 * quotes cannot terminate the HTML attribute). This test reproduces that exact
 * expression and asserts the rendered handler is inert for every payload.
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
$encode = static fn (string $value): string => htmlspecialchars(
    (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE),
    ENT_QUOTES,
    'UTF-8'
);

/**
 * Emulate a browser: the HTML attribute value is HTML-decoded, then the JS
 * argument is parsed. We assert the decoded-then-evaluated argument equals the
 * original string exactly (no breakout), and that neither the raw nor the
 * HTML-decoded attribute can terminate the attribute or the JS string early.
 */
echo "A. onclick JS-string context — no breakout\n";
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

    // 1. The rendered attribute value must not contain a raw " that would end
    //    the double-quoted onclick="…" attribute early.
    $check(!str_contains($attr, '"'), "payload '{$name}': no raw double-quote in attribute");

    // 2. HTML-decode the attribute the way a browser does before running the JS.
    $decoded = html_entity_decode($attr, ENT_QUOTES, 'UTF-8');

    // 3. The decoded text is a JS expression argument: `"<escaped>"`. It must be
    //    a single well-formed JS string literal — i.e. exactly one opening and
    //    one closing unescaped double quote, with nothing after it. json_decode
    //    parses JS/JSON string escapes identically, so it recovers the original.
    $recovered = json_decode($decoded, true);
    $check($recovered === $raw,
        "payload '{$name}': JS string literal round-trips to the original (no breakout)");

    // 4. Belt-and-suspenders: the decoded literal has no unescaped `'`/`"`/`<`
    //    that could break the string or the surrounding markup, apart from the
    //    two delimiting quotes.
    $inner = substr($decoded, 1, -1); // strip the delimiting quotes
    $check(!preg_match('/(?<!\\\\)["\']/', $inner) && !str_contains($inner, '<') && !str_contains($inner, '>'),
        "payload '{$name}': inner JS string has no live quote/angle-bracket");
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
