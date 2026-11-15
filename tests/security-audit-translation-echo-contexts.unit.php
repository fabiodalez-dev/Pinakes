<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11 follow-up): translations are admin-editable
 * (locale overrides) and __() returns them unescaped. TranslationSanitizer
 * filters stored values, but the views must be safe on their own: a translation
 * may never reach a JS string, a template literal or an HTML attribute raw.
 *
 * A. Scanner self-test: every pre-fix sink shape is reported, every accepted
 *    escaping shape is not.
 * B. Every view under app/Views and every bundled plugin view: no PHP echo of a
 *    translation inside a JS string/template literal, no translation echoed into
 *    JS code without json_encode(), no translation in an HTML attribute without
 *    an HTML escape (json_encode + HTML escape for on* handlers), no JS __()
 *    interpolated raw into a template literal. Exceptions are listed below with
 *    the reason they are safe.
 * C. Three of the fixed views rendered with a hostile override on every key
 *    they use: the payload stays text (no extra element, no handler attribute,
 *    no early end of a <script>) and inside a script it only ever appears inside
 *    a JSON string literal; with node available, every script still parses.
 *
 * DB-free. Run:  php tests/security-audit-translation-echo-contexts.unit.php [alternative-root]
 * (exit 0 iff all pass). The optional root points the scanner and the renders at
 * another copy of the tree, e.g. the pre-fix views, to prove the test bites.
 */

$root = dirname(__DIR__);
$scanRoot = isset($argv[1]) && is_dir($argv[1]) ? rtrim((string) realpath($argv[1]), '/') : $root;
require $root . '/vendor/autoload.php';
require_once $root . '/app/helpers.php';

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

const TX_CALL = '/(?<![\w>:$])__n?\s*\(/';

/** @param list<mixed> $toks */
function tx_join(array $toks): string
{
    $s = '';
    foreach ($toks as $t) { $s .= is_array($t) ? $t[1] : $t; }
    return $s;
}

function tx_strip(string $e): string
{
    return trim((string) preg_replace('/;\s*$/', '', trim($e)));
}

/** The outermost call spans the whole expression (nothing concatenated after it). */
function tx_wraps_whole(string $e): bool
{
    $open = strpos($e, '(');
    if ($open === false) { return false; }
    $toks = @token_get_all('<?php ' . substr($e, $open) . ';');
    array_shift($toks);
    $depth = 0; $started = false; $rest = '';
    foreach ($toks as $t) {
        $v = is_array($t) ? $t[1] : $t;
        if ($started && $depth === 0) { $rest .= $v; continue; }
        if ($v === '(') { $depth++; $started = true; }
        elseif ($v === ')') { $depth--; }
    }
    return trim($rest) === ';';
}

/**
 * Escaping helpers a view defines for itself: closures that HTML-escape
 * ("html"), closures that HTML-escape a json_encode() ("js"), and callables
 * that build a JS statement from a JSON literal ("jsbuild").
 * @return array{html: list<string>, js: list<string>, jsbuild: list<string>}
 */
function tx_escapers(string $src): array
{
    $out = ['html' => [], 'js' => [], 'jsbuild' => []];
    if (preg_match_all('/\$(\w+)\s*=\s*(?:static\s+)?(?:fn|function)\b(.{0,300}?);/s', $src, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as [, $name, $body]) {
            if (str_contains($body, 'htmlspecialchars(')) {
                $out[str_contains($body, 'json_encode(') ? 'js' : 'html'][] = $name;
            }
        }
    }
    // [DesiderataPlugin::class, 'e'] / [DesiderataPlugin::class, 'confirmJs']
    if (preg_match_all('/\$(\w+)\s*=\s*\[\s*[\\\\\w]+::class\s*,\s*\'(e|confirmJs)\'\s*\]/', $src, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as [, $name, $method]) {
            $out[$method === 'e' ? 'html' : 'jsbuild'][] = $name;
        }
    }
    return $out;
}

/** @param list<string> $names */
function tx_alt(array $names): string
{
    return $names === [] ? '(?!)' : '\$(?:' . implode('|', array_map(static fn(string $n): string => preg_quote($n, '/'), $names)) . ')';
}

/** Check each branch of a top-level ternary that holds a translation. */
function tx_branches_ok(string $e, callable $ok): bool
{
    $e = tx_strip($e);
    $toks = @token_get_all('<?php ' . $e . ';');
    array_shift($toks);
    $depth = 0; $parts = []; $buf = '';
    foreach ($toks as $t) {
        $v = is_array($t) ? $t[1] : $t;
        if ($v === '(' || $v === '[' || $v === '{') { $depth++; }
        elseif ($v === ')' || $v === ']' || $v === '}') { $depth--; }
        if ($depth === 0 && (($v === '?' && count($parts) === 0) || ($v === ':' && count($parts) === 1))) {
            $parts[] = $buf; $buf = ''; continue;
        }
        $buf .= $v;
    }
    $parts[] = $buf;
    if (count($parts) === 3) {
        foreach ([$parts[1], $parts[2]] as $branch) {
            if (preg_match(TX_CALL, $branch) === 1 && !tx_branches_ok($branch, $ok)) { return false; }
        }
        return true;
    }
    return $ok($e);
}

/** @param array{html: list<string>, js: list<string>, jsbuild: list<string>} $esc */
function tx_html_escaped(string $e, array $esc): bool
{
    $e = tx_strip($e);
    $fn = '(?:\\\\?htmlspecialchars|\\\\?htmlentities|(?:\\\\?App\\\\Support\\\\)?HtmlHelper::(?:e|escape)|' . tx_alt(array_merge($esc['html'], $esc['js'])) . ')';
    return preg_match('/^' . $fn . '\s*\(/', $e) === 1 && tx_wraps_whole($e);
}

/** @param array{html: list<string>, js: list<string>, jsbuild: list<string>} $esc */
function tx_attr_js_escaped(string $e, array $esc): bool
{
    $e = tx_strip($e);
    if (preg_match('/^' . tx_alt($esc['js']) . '\s*\(/', $e) === 1 && tx_wraps_whole($e)) {
        return true;
    }
    $inner = '(?:\\\\?json_encode\s*\(|' . tx_alt($esc['jsbuild']) . '\s*\(|[\\\\\w]*::confirmJs\s*\()';
    return tx_html_escaped($e, $esc) && preg_match('/^[\\\\\w:$]+\s*\(\s*' . $inner . '/', $e) === 1;
}

function tx_js_literal(string $e): bool
{
    $e = tx_strip($e);
    return preg_match('/^\\\\?json_encode\s*\(/', $e) === 1 && tx_wraps_whole($e);
}

/**
 * Report every translation that reaches a script or an attribute unescaped.
 * @return list<array{line: int, context: string, expr: string}>
 */
function tx_scan(string $src): array
{
    $esc = tx_escapers($src);
    $toks = @token_get_all($src);
    // Skeleton of the page: inline HTML verbatim, each PHP block that echoes a
    // translation replaced by \x01, every other PHP block by blanks (newlines
    // kept so skeleton lines are source lines).
    $sk = '';
    $regions = [];
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        $t = $toks[$i];
        if (is_array($t) && $t[0] === T_INLINE_HTML) { $sk .= $t[1]; continue; }
        if (!is_array($t) || ($t[0] !== T_OPEN_TAG && $t[0] !== T_OPEN_TAG_WITH_ECHO)) { $sk .= is_array($t) ? $t[1] : $t; continue; }
        $isEcho = $t[0] === T_OPEN_TAG_WITH_ECHO;
        $line = $t[2];
        $raw = $t[1];
        $body = [];
        for ($i++; $i < $n && !(is_array($toks[$i]) && $toks[$i][0] === T_CLOSE_TAG); $i++) {
            $body[] = $toks[$i];
            $raw .= is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
        }
        if ($i < $n) { $raw .= $toks[$i][1]; }
        $exprs = [];
        if ($isEcho) {
            $exprs[] = tx_join($body);
        } else {
            $cur = null; $nest = 0;
            foreach ($body as $bt) {
                if ($cur === null) {
                    if (is_array($bt) && ($bt[0] === T_ECHO || $bt[0] === T_PRINT)) { $cur = []; $nest = 0; }
                    continue;
                }
                if ($bt === '(' || $bt === '[' || $bt === '{') { $nest++; }
                if ($bt === ')' || $bt === ']' || $bt === '}') { $nest--; }
                if (($bt === ';' && $nest <= 0) || $nest < 0) { $exprs[] = tx_join($cur); $cur = null; continue; }
                $cur[] = $bt;
            }
            if ($cur !== null) { $exprs[] = tx_join($cur); }
        }
        $exprs = array_values(array_filter(array_map('trim', $exprs), static fn(string $x): bool => preg_match(TX_CALL, $x) === 1));
        if ($exprs !== []) {
            $regions[strlen($sk)] = [$line, $exprs];
            $sk .= "\x01" . str_repeat("\n", substr_count($raw, "\n"));
        } else {
            $sk .= str_repeat("\n", substr_count($raw, "\n")) . ' ';
        }
    }

    $found = [];
    $flag = static function (int $pos, string $ctx) use (&$found, $regions): void {
        [$line, $exprs] = $regions[$pos];
        foreach ($exprs as $x) { $found[] = ['line' => $line, 'context' => $ctx, 'expr' => $x]; }
    };
    $verify = static function (int $pos, string $ctx) use ($regions, $flag, $esc): void {
        foreach ($regions[$pos][1] as $x) {
            $ok = match ($ctx) {
                'attribute' => tx_branches_ok($x, static fn(string $b): bool => tx_html_escaped($b, $esc)),
                'event-handler attribute' => tx_branches_ok($x, static fn(string $b): bool => tx_attr_js_escaped($b, $esc)),
                default => tx_branches_ok($x, static fn(string $b): bool => tx_js_literal($b)),
            };
            if (!$ok) { $flag($pos, $ctx); return; }
        }
    };

    $L = strlen($sk);
    $mode = 'text';
    $tag = ''; $closing = false; $attr = ''; $quote = ''; $valStart = 0; $type = '';
    $rawEnd = '';
    $js = null;
    /** @var list<int> $braces brace depth inside each open ${ … } */
    $braces = [];
    for ($p = 0; $p < $L; $p++) {
        $c = $sk[$p];
        if ($mode === 'text') {
            if ($c === '<') {
                if (substr($sk, $p, 4) === '<!--') { $mode = 'comment'; $p += 3; continue; }
                if (preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9-]*)/', $sk, $m, 0, $p) === 1) {
                    $closing = $m[1] === '/';
                    $tag = strtolower($m[2]);
                    $type = '';
                    $mode = 'tag';
                    $p += strlen($m[0]) - 1;
                }
            }
            continue; // a translation in a text node is out of scope here
        }
        if ($mode === 'comment') {
            if (substr($sk, $p, 3) === '-->') { $mode = 'text'; $p += 2; }
            continue;
        }
        if ($mode === 'tag') {
            if ($c === "\x01") { $flag($p, 'tag (outside any attribute value)'); continue; }
            if ($c === '>') {
                $mode = 'text';
                if (!$closing && $tag === 'script') {
                    $isJs = $type === '' || preg_match('#javascript|module|json#i', $type) === 1;
                    $mode = $isJs ? 'script' : 'rawtext';
                    $rawEnd = '</script';
                    $js = null;
                    /** @var list<int> $braces */
                    $braces = [];
                } elseif (!$closing && in_array($tag, ['style', 'textarea', 'title'], true)) {
                    $mode = 'rawtext';
                    $rawEnd = '</' . $tag;
                }
                continue;
            }
            if (preg_match('/\G([^\s"\'>\/=\x01]+)\s*=\s*(["\']?)/', $sk, $m, 0, $p) === 1) {
                $attr = strtolower($m[1]);
                $quote = $m[2];
                $p += strlen($m[0]) - 1;
                $valStart = $p + 1;
                $mode = 'attr';
            }
            continue;
        }
        if ($mode === 'attr') {
            $end = $quote === '' ? (ctype_space($c) || $c === '>') : $c === $quote;
            if ($end) {
                if ($tag === 'script' && $attr === 'type') { $type = substr($sk, $valStart, $p - $valStart); }
                $mode = 'tag';
                if ($quote === '' && $c === '>') { $p--; }
                continue;
            }
            if ($c === "\x01") { $verify($p, str_starts_with($attr, 'on') ? 'event-handler attribute' : 'attribute'); }
            continue;
        }
        if ($mode === 'rawtext') {
            if (strncasecmp(substr($sk, $p, strlen($rawEnd)), $rawEnd, strlen($rawEnd)) === 0) {
                $mode = 'tag'; $closing = true; $tag = substr($rawEnd, 2); $p += strlen($rawEnd) - 1;
            }
            continue;
        }
        // script
        if (strncasecmp(substr($sk, $p, 8), '</script', 8) === 0) { $mode = 'tag'; $closing = true; $tag = 'script'; $p += 7; continue; }
        if ($c === "\x01") {
            if ($js !== null) { $flag($p, $js === '`' ? 'JS template literal' : 'JS string'); }
            else { $verify($p, 'JS code'); }
            continue;
        }
        if ($js === null) {
            if (substr($sk, $p, 2) === '//') { $k = strpos($sk, "\n", $p); $p = $k === false ? $L : $k; continue; }
            if (substr($sk, $p, 2) === '/*') { $k = strpos($sk, '*/', $p); $p = $k === false ? $L : $k + 1; continue; }
            if ($c === '/') {
                $q = $p - 1;
                while ($q >= 0 && ctype_space($sk[$q])) { $q--; }
                if (strpos('(,=:[!&|?{};+', $q >= 0 ? $sk[$q] : '(') !== false) { // regex literal
                    $class = false;
                    for ($p++; $p < $L; $p++) {
                        $rc = $sk[$p];
                        if ($rc === '\\') { $p++; continue; }
                        if ($rc === '[') { $class = true; }
                        elseif ($rc === ']') { $class = false; }
                        elseif (($rc === '/' && !$class) || $rc === "\n") { break; }
                    }
                    continue;
                }
            }
            if ($c === '`' || $c === '"' || $c === "'") { $js = $c; }
            elseif ($c === '{' && $braces !== []) { $braces[count($braces) - 1]++; }
            elseif ($c === '}' && $braces !== []) {
                if ($braces[count($braces) - 1] === 0) { array_pop($braces); $js = '`'; }
                else { $braces[count($braces) - 1]--; }
            }
            continue;
        }
        if ($c === '\\') { $p++; continue; }
        if ($js === '`' && substr($sk, $p, 2) === '${') {
            if (preg_match('/\G\$\{\s*(?:window\.)?__n?\(/', $sk, $m, 0, $p) === 1) {
                $found[] = ['line' => substr_count($sk, "\n", 0, $p) + 1, 'context' => 'JS __() in a template literal',
                    'expr' => substr($sk, $p, (int) strcspn($sk, "}\n", $p) + 1)];
            }
            $braces[] = 0; $js = null; $p++;
            continue;
        }
        if ($c === $js || ($c === "\n" && $js !== '`')) { $js = null; }
    }
    return $found;
}

// ── A ────────────────────────────────────────────────────────────────────────
echo "A. Scanner self-test\n";
$bad = [
    'raw echo in a template literal'         => "<script>el.innerHTML = `<p><?= __('Salva') ?></p>`;</script>",
    'htmlspecialchars in a template literal' => "<script>el.innerHTML = `<b title=\"<?= htmlspecialchars(__('Salva'), ENT_QUOTES, 'UTF-8') ?>\">`;</script>",
    'raw echo in a JS string'                => "<script>btn.innerHTML = '<i></i><?= __('Salva') ?>';</script>",
    'htmlspecialchars in a JS string'        => "<script>h = '<b aria-label=\"<?= htmlspecialchars(__('Chiudi'), ENT_QUOTES, 'UTF-8') ?>\">';</script>",
    'JS code without json_encode'            => "<script>const t = <?= __('Salva') ?>;</script>",
    'php echo statement in a JS string'      => "<script>const t = '<?php echo __('Salva'); ?>';</script>",
    'JS __() in a template literal'          => "<script>el.innerHTML = `<p>\${__('Salva')}</p>`;</script>",
    'window.__() in a template literal'      => "<script>el.innerHTML = `<p>\${window.__('Salva')}</p>`;</script>",
    'raw echo in an attribute'               => "<input placeholder=\"<?= __('Cerca') ?>\">",
    'concatenation after the escape'         => "<input placeholder=\"<?= htmlspecialchars(__('Cerca'), ENT_QUOTES, 'UTF-8') . __('x') ?>\">",
    'htmlspecialchars alone in onclick'      => "<button onclick=\"return confirm('<?= htmlspecialchars(__('Sicuro?'), ENT_QUOTES, 'UTF-8') ?>')\">x</button>",
    'json_encode alone in onclick'           => "<button onclick=\"return confirm(<?= json_encode(__('Sicuro?')) ?>)\">x</button>",
    'unescaped ternary branch'               => "<a title=\"<?= \$x ? htmlspecialchars(__('A'), ENT_QUOTES, 'UTF-8') : __('B') ?>\">x</a>",
    'echo between attributes'                => "<div <?= __('x') ?>>y</div>",
];
foreach ($bad as $label => $snippet) {
    $check(tx_scan($snippet) !== [], "reported: {$label}");
}
$good = [
    'json_encode in JS code'                 => "<script>const t = <?= json_encode(__('Salva'), JSON_HEX_TAG) ?>;</script>",
    'escapeHtml(json literal) in a template' => "<script>el.innerHTML = `<p>\${escapeHtml(<?= json_encode(__('Salva'), JSON_HEX_TAG) ?>)}</p>`;</script>",
    'json(htmlspecialchars) in a template'   => "<script>el.innerHTML = `<p>\${<?= json_encode(htmlspecialchars(__('Salva'), ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG) ?>}</p>`;</script>",
    'escapeHtml(__()) in a template'         => "<script>el.innerHTML = `<p>\${escapeHtml(__('Salva'))}</p>`;</script>",
    'escaped attribute'                      => "<input placeholder=\"<?= htmlspecialchars(__('Cerca'), ENT_QUOTES, 'UTF-8') ?>\">",
    'closure-escaped attribute'              => "<?php \$e = static fn(mixed \$v): string => htmlspecialchars((string) \$v, ENT_QUOTES, 'UTF-8'); ?><input placeholder=\"<?= \$e(__('Cerca')) ?>\">",
    'escaped json in onclick'                => "<button onclick=\"return confirm(<?= htmlspecialchars(json_encode(__('Sicuro?'), JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>)\">x</button>",
    'escaped ternary'                        => "<a title=\"<?= \$x ? htmlspecialchars(__('A'), ENT_QUOTES, 'UTF-8') : 'B' ?>\">x</a>",
    'text node'                              => "<p><?= __('Testo con <strong>markup</strong>') ?></p>",
    'regex literal with a quote'             => "<script>const r = /'/g; const t = <?= json_encode(__('Salva')) ?>;</script>",
];
foreach ($good as $label => $snippet) {
    $issues = tx_scan($snippet);
    $check($issues === [], "accepted: {$label}" . ($issues === [] ? '' : ' (' . $issues[0]['context'] . ')'));
}

// ── B ────────────────────────────────────────────────────────────────────────
echo "B. No raw translation in a script or an attribute, across all views ({$scanRoot})\n";

// Reported shapes that are safe where they stand: [file, source text on the line, reason].
$exceptions = [
    ['app/Views/layout.php', "sidebarBadge.title = `v\${data.latest} \${window.__('disponibile')}`",
        'assigned to the title DOM property: plain text, never parsed as HTML'],
    ['app/Views/libri/scheda_libro.php', "if (confirm(`\${__('Sei sicuro di voler eliminare la copia')}",
        'window.confirm() shows plain text'],
];

$files = [];
foreach ([$scanRoot . '/app/Views', $scanRoot . '/storage/plugins'] as $dir) {
    if (!is_dir($dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = (string) $f;
        if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/')) { continue; }
        $rel = substr($path, strlen($scanRoot) + 1);
        if (str_starts_with($rel, 'storage/plugins/') && preg_match('#/(views|templates)/#i', $rel) !== 1) { continue; }
        $files[strtolower((string) realpath($path))] = $rel; // case-insensitive filesystems list Views/ and views/ twice
    }
}
ksort($files);
$check(count($files) > 250, 'scanned ' . count($files) . ' view files');

$usedExceptions = [];
$offenders = [];
foreach ($files as $rel) {
    $src = (string) file_get_contents($scanRoot . '/' . $rel);
    if (preg_match(TX_CALL, $src) !== 1) { continue; }
    $lines = explode("\n", $src);
    foreach (tx_scan($src) as $hit) {
        $text = $lines[$hit['line'] - 1] ?? '';
        foreach ($exceptions as $k => [$file, $needle]) {
            if ($rel === $file && str_contains($text, $needle)) { $usedExceptions[$k] = true; continue 2; }
        }
        $offenders[] = "{$rel}:{$hit['line']} [{$hit['context']}] " . substr((string) preg_replace('/\s+/', ' ', $hit['expr']), 0, 100);
    }
}
$check($offenders === [], 'every translation in a script or an attribute is escaped for its context'
    . ($offenders === [] ? '' : ' (' . count($offenders) . " sinks)\n        " . implode("\n        ", $offenders)));
foreach ($exceptions as $k => [$file, , $reason]) {
    $check(isset($usedExceptions[$k]), "exception still matches a reported line in {$file} ({$reason})");
}

// ── C ────────────────────────────────────────────────────────────────────────
echo "C. Fixed views rendered with a hostile translation on every key\n";

// A tab makes the raw payload invalid JSON, so a fragment of it can never pass
// for a json_encode() literal in the script check below.
$payload = "x\" onmouseover=\"alert(1)\t</script><img src=x onerror=alert(1)>`\${alert(1)}`'\\";
$runner = <<<'RUNNER'
<?php
[$root, $view, $payload] = [(string) getenv('TX_ROOT'), (string) getenv('TX_VIEW'), (string) getenv('TX_PAYLOAD')];
require getenv('TX_APP') . '/vendor/autoload.php';
require_once getenv('TX_APP') . '/app/helpers.php';
set_error_handler(static fn(): bool => true);
$src = (string) file_get_contents($root . '/' . $view);
if ($payload !== '') {
    // Only keys echoed inside a <script> or an attribute value, and nowhere
    // else, get the payload: text nodes are trusted markup by convention.
    $keyRe = '/(?<![\w>:$])__n?\(\s*(\x27(?:[^\x27\\\\]|\\\\.)*\x27|"(?:[^"\\\\]|\\\\.)*")/';
    $ctxRe = '#<script\b[^>]*>.*?</script>|=\s*"[^"]*?<\?(?:=|php\s+echo).*?\?>#s';
    preg_match_all($ctxRe, $src, $ctx);
    preg_match_all($keyRe, implode("\n", $ctx[0]), $in);
    preg_match_all($keyRe, (string) preg_replace($ctxRe, '', $src), $out);
    $map = [];
    // eval() reads a PHP string literal matched in the repository view source.
    foreach (array_diff($in[1], $out[1]) as $literal) { $map[(string) eval('return ' . $literal . ';')] = $payload; }
    fwrite(STDOUT, json_encode(array_keys($map)) . "\n");
    (new ReflectionProperty(App\Support\I18n::class, 'translations'))->setValue(null, $map);
    (new ReflectionProperty(App\Support\I18n::class, 'translationsLoaded'))->setValue(null, true);
}
$_SESSION = [];
$club = ['id' => 1, 'name' => 'Club', 'slug' => 'club'];
$states = $members = $roles = $books = $polls = $meetings = [];
$flash = null;
$csrfToken = 'token';
ob_start();
try {
    include $root . '/' . $view;
} catch (Throwable $t) {
    ob_end_clean();
    fwrite(STDERR, get_class($t) . ': ' . $t->getMessage());
    exit(2);
}
echo ob_get_clean();
RUNNER;

/** @return array{0: list<string>, 1: string}|null  hostile keys, page */
$render = static function (string $view, string $payload) use ($runner, $scanRoot, $root): ?array {
    $proc = proc_open([PHP_BINARY], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root,
        array_merge(getenv(), ['TX_ROOT' => $scanRoot, 'TX_APP' => $root, 'TX_VIEW' => $view, 'TX_PAYLOAD' => $payload]));
    if (!is_resource($proc)) { return null; }
    fwrite($pipes[0], $runner);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) { echo "        render error: {$err}\n"; return null; }
    if ($payload === '') { return [[], $out]; }
    [$keys, $page] = explode("\n", $out, 2) + [1 => ''];
    return [(array) json_decode($keys, true), $page];
};

/** @return array{scripts: list<string>, handlers: list<string>, injected: int} */
$inspect = static function (string $html): array {
    $doc = new DOMDocument();
    @$doc->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
    $scripts = [];
    $handlers = [];
    $injected = 0;
    foreach ($doc->getElementsByTagName('*') as $el) {
        if ($el->nodeName === 'script') { $scripts[] = $el->textContent; }
        if ($el->nodeName === 'img' && $el->getAttribute('src') === 'x') { $injected++; }
        foreach ($el->attributes ?? [] as $a) {
            if (str_starts_with($a->nodeName, 'on') && str_contains($a->nodeValue ?? '', 'alert(1)')) { $handlers[] = $el->nodeName . '[' . $a->nodeName . ']'; }
        }
    }
    return ['scripts' => $scripts, 'handlers' => $handlers, 'injected' => $injected];
};

// Every alert(1) left in a script must sit inside a JSON string literal holding
// the whole payload (or its HTML-escaped form): then it is data, not code. Each
// double quote is tried as the start of a literal, since HTML inside JS strings
// carries quotes of its own; the raw payload holds a tab, which JSON forbids.
$scriptLeak = static function (string $js) use ($payload): bool {
    $forms = [$payload, htmlspecialchars($payload, ENT_QUOTES, 'UTF-8')];
    $safe = [];
    $n = strlen($js);
    for ($i = strpos($js, '"'); $i !== false; $i = strpos($js, '"', $i + 1)) {
        for ($k = $i + 1; $k < $n; $k++) {
            if ($js[$k] === '\\') { $k++; continue; }
            if ($js[$k] === '"' || ord($js[$k]) < 0x20) { break; }
        }
        if ($k >= $n || $js[$k] !== '"') { continue; }
        $v = json_decode(substr($js, $i, $k - $i + 1));
        if (!is_string($v)) { continue; }
        foreach ($forms as $f) {
            if (str_contains($v, $f)) { $safe[] = [$i, $k]; break; }
        }
    }
    for ($at = strpos($js, 'alert(1)'); $at !== false; $at = strpos($js, 'alert(1)', $at + 1)) {
        $inside = false;
        foreach ($safe as [$a, $b]) { if ($at > $a && $at < $b) { $inside = true; break; } }
        if (!$inside) { return true; }
    }
    return false;
};

$node = trim((string) @shell_exec('command -v node 2>/dev/null'));
$nodeCheck = static function (string $js) use ($node): bool {
    $tmp = tempnam(sys_get_temp_dir(), 'txjs');
    if ($tmp === false) { return false; }
    file_put_contents($tmp . '.js', $js);
    exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp . '.js') . ' 2>&1', $o, $rc);
    @unlink($tmp . '.js');
    @unlink($tmp);
    return $rc === 0;
};
if ($node === '') {
    $check(getenv('CI_STRICT_TESTS') !== '1', 'node is available for the script parse check (required under CI_STRICT_TESTS)');
    echo "  --  node not found: script parse check not run\n";
}

// view => keys of the sinks fixed in it that must carry the payload
foreach ([
    'app/Views/admin/stats.php' => ['Nessun prestito disponibile per generare il grafico'],
    'storage/plugins/book-club/views/admin/show.php' => ['Chiudi'],
    'storage/plugins/dewey-editor/views/editor.php' => ['Nessun dato.', 'Modifica', 'Aggiungi decimale', 'Nome della classificazione',
        'Il codice deve iniziare con le stesse tre cifre della classe principale', 'Salvataggio...', 'Backup disponibili', 'Ripristina'],
] as $view => $sinkKeys) {
    $benignRun = $render($view, '');
    $hostileRun = $render($view, $payload);
    if ($benignRun === null || $hostileRun === null) { $check(false, "{$view}: renders"); continue; }
    [, $benign] = $benignRun;
    [$hostileKeys, $hostile] = $hostileRun;
    $missing = array_diff($sinkKeys, $hostileKeys);
    $check($missing === [], "{$view}: the payload is set on the fixed sinks' keys (" . count($hostileKeys) . ' keys)' . ($missing === [] ? '' : ': missing ' . implode(', ', $missing)));
    $b = $inspect($benign);
    $h = $inspect($hostile);
    $check(substr_count($hostile, 'alert(1)') > 0, "{$view}: the hostile translation reaches the page");
    $check(count($h['scripts']) === count($b['scripts']) && $h['scripts'] !== [],
        "{$view}: no <script> ends early (" . count($b['scripts']) . ' benign, ' . count($h['scripts']) . ' hostile)');
    $check($h['injected'] === 0, "{$view}: no element injected (img src=x: {$h['injected']})");
    $check($h['handlers'] === [], "{$view}: no handler attribute injected" . ($h['handlers'] === [] ? '' : ' (' . implode(', ', $h['handlers']) . ')'));
    $leaks = array_keys(array_filter($h['scripts'], $scriptLeak));
    $check($leaks === [], "{$view}: inside scripts the payload is only ever a JSON string literal" . ($leaks === [] ? '' : ' (script #' . implode(', #', $leaks) . ')'));
    if ($node !== '') {
        $broken = [];
        foreach ($h['scripts'] as $k => $js) { if (!$nodeCheck($js)) { $broken[] = $k; } }
        $check($broken === [], "{$view}: every script parses under the hostile translation" . ($broken === [] ? '' : ' (script #' . implode(', #', $broken) . ')'));
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
