<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11, pinakes:xss:translation-override-unescaped-in-js-and-attribute-context).
 *
 * A staff account uploaded a translation override (POST /admin/languages/{code}
 * had no admin check), the filter kept quotes, backticks and "${", and the users
 * list interpolated the value into title="${...}" of HTML assigned by innerHTML:
 * the injected handler ran in an administrator's browser.
 *
 * A. Language management writes are admin-only: a staff request is turned away
 *    before the model or the filesystem is touched; an administrator goes on.
 * B. The upload filter no longer lets a translation introduce a double quote, a
 *    backtick or "${" that its source string does not have.
 * C. The admin views named by the finding escape translations for the context
 *    they land in: no raw PHP echo of a translation inside a JS string or
 *    template literal, no unescaped translation interpolated into HTML built
 *    in JS, and the users-list labels reach their title/aria-label attributes
 *    without a usable quote.
 *
 * DB-free. Run:  php tests/security-audit-translation-override-xss.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/helpers/security-audit-fake-db.php';

use App\Controllers\Admin\LanguagesController;
use App\Support\I18n;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$payload = 'Dettagli" style="animation:fa-spin 1s" onanimationstart="window.__audit=document.domain" x="';

// ── A ────────────────────────────────────────────────────────────────────────
echo "A. Language writes are admin-only\n";

$writes = [
    'store' => [],
    'update' => ['code' => 'en_US'],
    'delete' => ['code' => 'en_US'],
    'toggleActive' => ['code' => 'en_US'],
    'setDefault' => ['code' => 'en_US'],
    'refreshStats' => [],
    'updateRoutes' => ['code' => 'en_US'],
];
foreach ($writes as $action => $args) {
    $_SESSION = ['user' => ['id' => 2, 'tipo_utente' => 'staff', 'stato' => 'attivo']];
    $db = new SecurityAuditFakeDb([], 0);
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/languages')
        ->withParsedBody(['code' => 'en_US', 'name' => 'English', 'native_name' => 'English', 'is_active' => '1']);
    $status = null;
    $location = '';
    try {
        $out = (new LanguagesController())->{$action}($request, (new ResponseFactory())->createResponse(), $db, $args);
        $status = $out->getStatusCode();
        $location = $out->getHeaderLine('Location');
    } catch (SecurityAuditPastGuard) {
    }
    $check($status === 302 && str_ends_with($location, '/admin/languages') && $db->calls === []
        && ($_SESSION['flash_error'] ?? '') !== '',
        "staff {$action}(): turned away with a flash error, nothing touched");
}

$_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
foreach (['update', 'delete', 'toggleActive'] as $action) {
    $db = new SecurityAuditFakeDb([], 0);
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/languages/en_US')
        ->withParsedBody(['name' => 'English', 'native_name' => 'English']);
    try {
        (new LanguagesController())->{$action}($request, (new ResponseFactory())->createResponse(), $db, ['code' => 'en_US']);
    } catch (SecurityAuditPastGuard) {
    }
    $check($db->calls !== [], "administrator {$action}() reaches the language model");
}
$_SESSION = [];

// ── B ────────────────────────────────────────────────────────────────────────
echo "B. The upload filter keeps translations inside their context\n";

$sanitize = new ReflectionMethod(LanguagesController::class, 'sanitizeTranslations');
$controller = new LanguagesController();
$out = $sanitize->invoke($controller, [
    'Visualizza dettagli' => $payload,
    'Modifica' => 'Edit` + alert(1) + `',
    'Elimina' => 'Delete ${alert(1)}',
    'Importa gli articoli (valore rilevato: "%s")' => 'Import the articles (detected value: "%s")',
    'Autore "${normalizedLabel}" pronto' => 'Author "${normalizedLabel}" ready',
    'Titolo' => '<img src=x onerror=alert(1)>Title',
    7 => 'numeric key',
]);
$check(!str_contains($out['Visualizza dettagli'], '"'), 'a quote the source string lacks is neutralised (got: ' . $out['Visualizza dettagli'] . ')');
$check(str_contains($out['Visualizza dettagli'], 'Dettagli'), 'the text of the translation is kept');
$check(!str_contains($out['Modifica'], '`'), 'a backtick the source string lacks is neutralised');
$check(!str_contains($out['Elimina'], '${'), 'a "${" the source string lacks is neutralised');
$check($out['Importa gli articoli (valore rilevato: "%s")'] === 'Import the articles (detected value: "%s")',
    'quotes are kept where the source string has them');
$check($out['Autore "${normalizedLabel}" pronto'] === 'Author "${normalizedLabel}" ready',
    'placeholders are kept where the source string has them');
$check($out['Titolo'] === 'Title', 'markup is still stripped');
$check(!array_key_exists(7, $out), 'non-string keys are still dropped');

// ── C ────────────────────────────────────────────────────────────────────────
echo "C. Translations are escaped for the context they land in\n";

/**
 * Walk every <script> of a view and report PHP echoes of a translation that sit
 * inside a JS string or template literal, or that are not a json_encode() value.
 * Also report window.__()/__() interpolated straight into a template literal.
 *
 * @return list<string>
 */
$scan = static function (string $file): array {
    $src = (string) file_get_contents($file);
    // Blank non-echo PHP blocks (comments in them can mention "<script>").
    $src = (string) preg_replace_callback('/<\?php(?!\s+echo\b).*?\?>/s', static fn(array $m): string => preg_replace('/[^\n]/', ' ', $m[0]), $src);
    $issues = [];
    preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $src, $blocks, PREG_OFFSET_CAPTURE);
    foreach ($blocks[1] as [$body, $base]) {
        $state = null;      // null, '`', "'", '"'
        $depth = [];        // brace depth per open ${ … }
        $n = strlen($body);
        for ($i = 0; $i < $n; $i++) {
            if (substr($body, $i, 2) === '<?') {
                $end = strpos($body, '?>', $i);
                $end = $end === false ? $n : $end + 2;
                $php = substr($body, $i, $end - $i);
                $expr = str_starts_with($php, '<?=') ? trim(substr($php, 3, -2)) : trim((string) preg_replace('/^<\?php\s+echo/', '', substr($php, 0, -2)));
                if (str_contains($expr, '__(')) {
                    $line = substr_count($src, "\n", 0, $base + $i) + 1;
                    if ($state !== null) {
                        $issues[] = basename($file) . ":{$line} translation echoed inside a JS " . ($state === '`' ? 'template literal' : 'string') . ': ' . substr($expr, 0, 60);
                    } elseif (!str_starts_with($expr, 'json_encode(')) {
                        $issues[] = basename($file) . ":{$line} translation echoed into JS without json_encode(): " . substr($expr, 0, 60);
                    }
                }
                $i = $end - 1;
                continue;
            }
            $c = $body[$i];
            if ($state === null) {
                if (substr($body, $i, 2) === '//') { $k = strpos($body, "\n", $i); $i = $k === false ? $n : $k; continue; }
                if (substr($body, $i, 2) === '/*') { $k = strpos($body, '*/', $i); $i = $k === false ? $n : $k + 1; continue; }
                if ($c === '`' || $c === '"' || $c === "'") { $state = $c; }
                elseif ($c === '{' && $depth !== []) { $depth[count($depth) - 1]++; }
                elseif ($c === '}' && $depth !== []) {
                    if ($depth[count($depth) - 1] === 0) { array_pop($depth); $state = '`'; }
                    else { $depth[count($depth) - 1]--; }
                }
            } else {
                if ($c === '\\') { $i++; continue; }
                if ($state === '`' && substr($body, $i, 2) === '${') {
                    if (preg_match('/^\$\{\s*(window\.)?__\(/', substr($body, $i, 20)) === 1) {
                        $line = substr_count($src, "\n", 0, $base + $i) + 1;
                        $issues[] = basename($file) . ":{$line} JS translation interpolated into HTML without escaping";
                    }
                    $depth[] = 0; $state = null; $i++;
                    continue;
                }
                if ($c === $state) { $state = null; }
                elseif ($c === "\n" && $state !== '`') { $state = null; }
            }
        }
    }
    return $issues;
};

$views = [
    'app/Views/utenti/index.php',
    'app/Views/settings/messages-tab.php',
    'app/Views/prestiti/index.php',
    'app/Views/admin/theme-customize.php',
    'app/Views/admin/plugins.php',
];
foreach ($views as $view) {
    $issues = $scan($root . '/' . $view);
    $check($issues === [], "{$view}: every translation in its scripts is context-escaped"
        . ($issues === [] ? '' : "\n        " . implode("\n        ", $issues)));
}

// The sink of the reproduced attack: the users-list action labels. Evaluate the
// PHP that defines them with the attacker's translation in place, read the JS
// string as the browser would, and build the attribute the renderer builds.
$translations = new ReflectionProperty(I18n::class, 'translations');
$loaded = new ReflectionProperty(I18n::class, 'translationsLoaded');
$translations->setValue(null, ['Visualizza dettagli' => $payload, 'Modifica' => $payload, 'Elimina' => $payload]);
$loaded->setValue(null, true);
$usersView = (string) file_get_contents($root . '/app/Views/utenti/index.php');
foreach (['i18nViewDetails', 'i18nEdit', 'i18nDelete'] as $const) {
    if (preg_match('/const ' . $const . ' = <\?= (.+?) \?>;/', $usersView, $m) !== 1) {
        $check(false, "utenti/index.php defines {$const}");
        continue;
    }
    // eval() runs the view's own PHP expression (repository source, matched by
    // the regex above), the only way to test what that line emits without
    // rendering the whole page. No external input reaches it.
    $jsLiteral = (string) eval('return ' . $m[1] . ';');
    $runtime = json_decode($jsLiteral, true);
    $html = '<a href="/x" title="' . $runtime . '" aria-label="' . $runtime . '">x</a>';
    $dom = new DOMDocument();
    @$dom->loadHTML('<!doctype html><body>' . $html . '</body>');
    $a = $dom->getElementsByTagName('a')->item(0);
    $attrs = [];
    foreach ($a?->attributes ?? [] as $attr) { $attrs[] = $attr->nodeName; }
    sort($attrs);
    $check($attrs === ['aria-label', 'href', 'title'] && $a?->getAttribute('title') === $payload,
        "{$const}: a translated quote stays inside title/aria-label (attributes: " . implode(',', $attrs) . ')');
}
$translations->setValue(null, []);
$loaded->setValue(null, false);

// The per-request loader skips the full filter for values with no risky
// character; it must produce exactly what the full filter produces.
$hostile = [
    'Annulla' => 'x" onmouseover="alert(1)',
    'Salva' => '<img src=x onerror=alert(1)>',
    'Elimina' => '`; alert(1); `',
    'Modifica' => '${alert(1)}',
    'Chiudi' => "a\0b",
    'Indietro' => 'plain value',
];
$check(\App\Support\TranslationSanitizer::sanitizeLoaded($hostile) === \App\Support\TranslationSanitizer::sanitize($hostile),
    'sanitizeLoaded() matches sanitize() on hostile values');
foreach (glob($root . '/locale/[a-z][a-z]_[A-Z][A-Z].json') ?: [] as $localeFile) {
    $shipped = array_map(
        static fn ($v) => is_string($v) ? trim($v) : $v,
        (array) json_decode((string) file_get_contents($localeFile), true)
    );
    $check(\App\Support\TranslationSanitizer::sanitizeLoaded($shipped) === \App\Support\TranslationSanitizer::sanitize($shipped),
        'sanitizeLoaded() matches sanitize() on ' . basename($localeFile));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
