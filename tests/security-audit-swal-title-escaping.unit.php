<?php
/**
 * Security audit follow-up (PR review): SweetAlert2 renders `title`,
 * `confirmButtonText`, `cancelButtonText`, `denyButtonText` and the validation
 * message as HTML. Translations are admin-editable, so they must be escaped
 * before reaching those options, and a title that carries user data must not
 * be shown with HTML entities when the native confirm() fallback is used.
 *
 * Runs the real scripts in node with a stubbed Swal and a hostile __():
 *   A. SwalApp.confirmDelete({titleText}) escapes the title for Swal only and
 *      hands confirm() the plain text; {title} keeps meaning HTML.
 *   B. The event delete dialog (events/index.php) passes the raw title.
 *   C. The duplicate-book / increase-copies dialogs (book_form.php) and the
 *      copy dialogs (scheda_libro.php) escape every HTML option.
 *
 * DB-free. Needs node (CI_STRICT_TESTS=1 turns a missing node into a failure).
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$node = trim((string) @shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: node not available (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "SKIP: node not available\n";
    exit(0);
}

/** Runs $js in node and returns what it printed as JSON. */
$run = static function (string $js) use ($node): mixed {
    $base = (string) tempnam(sys_get_temp_dir(), 'swal_title_');
    $file = $base . '.js';
    file_put_contents($file, $js);
    $out = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test temp file
    unlink($base); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test temp file
    $decoded = json_decode($out, true);
    if (!is_array($decoded)) {
        echo "    node output: {$out}\n";
    }
    return $decoded;
};

$between = static function (string $src, string $from, string $to): string {
    $a = strpos($src, $from);
    $b = $a === false ? false : strpos($src, $to, $a);
    return ($a === false || $b === false) ? '' : substr($src, $a, $b - $a);
};

$payload = '<img src=x onerror=alert(1)>';
$swalConfig = (string) file_get_contents($root . '/public/assets/js/swal-config.js');
$escapeHtml = 'function escapeHtml(s){return String(s ?? "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\'/g,"&#039;");}';

// ── A ──
echo "A. SwalApp.confirmDelete\n";
$out = $run('const window = globalThis; let confirmed = null; window.confirm = (m) => { confirmed = m; return false; };'
    . "\n" . $swalConfig . "\n"
    . 'const fired = []; (async () => {'
    . ' await window.SwalApp.confirmDelete({ titleText: "Delete \"Tom & Jerry\" <b>?</b>", text: "t" });'
    . ' const nativeMsg = confirmed;'
    . ' globalThis.Swal = { fire: (o) => { fired.push(o); return Promise.resolve({}); }, mixin: () => {} };'
    . ' await window.SwalApp.confirmDelete({ titleText: "Delete \"Tom & Jerry\" <b>?</b>", text: "t" });'
    . ' await window.SwalApp.confirmDelete({ title: "<b>kept</b>", text: "t" });'
    . ' console.log(JSON.stringify({ nativeMsg, swalTitle: fired[0].title, htmlTitle: fired[1].title }));'
    . '})();');
$check(is_array($out) && str_starts_with((string) $out['nativeMsg'], 'Delete "Tom & Jerry" <b>?</b>'),
    'the native confirm() shows the plain title, without entities');
$check(is_array($out) && $out['swalTitle'] === 'Delete &quot;Tom &amp; Jerry&quot; &lt;b&gt;?&lt;/b&gt;',
    'the Swal title gets the same text HTML-escaped');
$check(is_array($out) && $out['htmlTitle'] === '<b>kept</b>', 'a {title} option is still passed as HTML (existing callers)');

// ── B ──
echo "B. Event delete dialog\n";
$events = (string) file_get_contents($root . '/app/Views/events/index.php');
$script = $between($events, 'function confirmDelete(eventId, eventTitle)', '</script>');
$check($script !== '', 'the confirmDelete() script is found');
$script = (string) preg_replace_callback('/<\?=\s*json_encode\(__\(("(?:[^"\\\\]|\\\\.)*")\)(?:,[^)]*)?\)\s*\?>/',
    static fn (array $m): string => json_encode(stripcslashes(substr($m[1], 1, -1)) . $payload), $script);
$check(!str_contains($script, '<?'), 'every PHP echo in it is replaced by a hostile translation');
$out = $run('const window = globalThis; let confirmed = null; window.confirm = (m) => { confirmed = m; return false; };'
    . "\n" . $swalConfig . "\n" . $script . "\n"
    . 'const fired = []; (async () => {'
    . ' confirmDelete(7, "Tom & \"Jerry\""); await new Promise(r => setTimeout(r, 0));'
    . ' const nativeMsg = confirmed;'
    . ' globalThis.Swal = { fire: (o) => { fired.push(o); return Promise.resolve({ isConfirmed: false }); }, mixin: () => {} };'
    . ' confirmDelete(7, "Tom & \"Jerry\""); await new Promise(r => setTimeout(r, 0));'
    . ' console.log(JSON.stringify({ nativeMsg, swalTitle: fired[0].title }));'
    . '})();');
$check(is_array($out) && str_contains((string) $out['nativeMsg'], '"Tom & "Jerry""') && !str_contains((string) $out['nativeMsg'], '&quot;')
    && !str_contains((string) $out['nativeMsg'], '&amp;'), 'the native fallback shows the event title as typed (' . (is_array($out) ? $out['nativeMsg'] : '') . ')');
$check(is_array($out) && !str_contains((string) $out['swalTitle'], '<img') && str_contains((string) $out['swalTitle'], 'Tom &amp; &quot;Jerry&quot;'),
    'the Swal title escapes both the translation and the event title');

// ── C ──
/**
 * Runs $snippet with every Swal.fire() recorded, then reports each HTML option
 * that still carries the raw payload.
 */
$swalProbe = static function (string $snippet, string $drive) use ($run, $payload, $escapeHtml): ?array {
    $out = $run('const window = globalThis; window.BASE_PATH = ""; window.location = { href: "", reload() {} };'
        . ' const CSRF_TOKEN = "t"; const __ = (k) => ' . json_encode($payload) . ' + k;'
        . ' globalThis.setTimeout = () => 0; console.error = () => {}; ' . $escapeHtml
        . ' const fired = []; const validation = []; let answers = [];'
        . ' globalThis.Swal = { fire: (o) => { fired.push(o); return Promise.resolve(answers.shift() || {}); },'
        . '   showLoading() {}, showValidationMessage: (m) => validation.push(m) };'
        . ' globalThis.document = { getElementById: () => ({ value: "0" }), createElement: () => ({ appendChild() {}, submit() {} }), body: { appendChild() {} } };'
        . "\n" . $snippet . "\n"
        . '(async () => { ' . $drive . ' await new Promise(r => setImmediate(r));'
        . ' const raw = [];'
        . ' fired.forEach((o, i) => ["title", "confirmButtonText", "cancelButtonText", "denyButtonText"].forEach((k) => {'
        . '   if (typeof o[k] === "string" && o[k].includes("<img")) raw.push(i + ":" + k + "=" + o[k]); }));'
        . ' validation.forEach((m) => { if (m.includes("<img")) raw.push("validation=" + m); });'
        . ' console.log(JSON.stringify({ dialogs: fired.length, validations: validation.length, raw }));'
        . '})();');
    return is_array($out) ? $out : null;
};

echo "C. Book form: duplicate and increase-copies dialogs\n";
$form = (string) file_get_contents($root . '/app/Views/libri/partials/book_form.php');
$snippet = $between($form, 'async function handleDuplicateBook(existingBook)', '// Initialize Form Validation');
$check($snippet !== '' && !str_contains($snippet, '<?'), 'the dialogs are found and contain no PHP');
$drive = ' globalThis.fetch = async () => ({ ok: true, json: async () => ({ success: true, copie_totali: 3, copie_disponibili: 2 }) });'
    . ' answers = [{ isConfirmed: true }, { value: 2 }];'
    . ' const p = handleDuplicateBook({ id: 1, title: "T" });'
    . ' await new Promise(r => setImmediate(r)); await p;'
    . ' const inc = fired.find((o) => typeof o.preConfirm === "function"); if (inc) inc.preConfirm();'
    . ' globalThis.fetch = async () => ({ ok: false, json: async () => ({ error: "x" }) }); answers = [{ value: 1 }]; await increaseCopies({ id: 1, title: "T" });'
    . ' globalThis.fetch = async () => ({ ok: true, json: async () => ({ code: "CSRF_INVALID" }) }); answers = [{ value: 1 }]; await increaseCopies({ id: 1, title: "T" });'
    . ' globalThis.fetch = async () => ({ ok: true, json: async () => ({ success: false, message: "m" }) }); answers = [{ value: 1 }]; await increaseCopies({ id: 1, title: "T" });'
    . ' globalThis.fetch = async () => { throw new Error("net"); }; answers = [{ value: 1 }]; await increaseCopies({ id: 1, title: "T" });';
$out = $swalProbe($snippet, $drive);
$check($out !== null && $out['dialogs'] >= 12 && $out['validations'] === 1, 'every dialog of the flow was opened (' . ($out['dialogs'] ?? 0) . ' dialogs, ' . ($out['validations'] ?? 0) . ' validation message)');
$check($out !== null && $out['raw'] === [], 'no title, button or validation message carries a raw translation' . ($out !== null && $out['raw'] !== [] ? ': ' . implode(' | ', $out['raw']) : ''));

echo "D. Book page: edit-copy and delete-copy dialogs\n";
$page = (string) file_get_contents($root . '/app/Views/libri/scheda_libro.php');
$snippet = $between($page, "editCopyForm?.addEventListener('submit'", '</script>');
$check($snippet !== '' && str_contains($snippet, 'function confirmDeleteCopy('), 'the dialogs are found');
$snippet = (string) preg_replace('/<\?php.*?\?>/s', 'csrf', $snippet);
$drive = ' editCopyListener({ preventDefault() {}, target: { submit() {} } }); confirmDeleteCopy(5, "INV-1");';
$out = $swalProbe('let editCopyListener = null; const editCopyForm = { addEventListener: (e, f) => { editCopyListener = f; } };' . "\n" . $snippet, $drive);
$check($out !== null && $out['dialogs'] === 2, 'both dialogs were opened');
$check($out !== null && $out['raw'] === [], 'no title or button carries a raw translation' . ($out !== null && $out['raw'] !== [] ? ': ' . implode(' | ', $out['raw']) : ''));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
