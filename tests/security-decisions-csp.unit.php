<?php
declare(strict_types=1);

/**
 * Security audit 2026-11, decision [39] — CSP nonce only on the templates'
 * own script/style elements (phase 1).
 *
 *   A. The view helper: a template tag rendered through csp_nonce_attr()
 *      carries the request nonce; a <script> that reaches the page through
 *      stored content (no helper) does not, and the response is no longer
 *      rewritten as a whole.
 *   B. Shared cache: headerForCachedHtml() whitelists by hash only the
 *      inline blocks carrying the request nonce, never an injected block
 *      (with no nonce or a guessed one); LiteSpeedCacheMiddleware refuses to
 *      share a page whose CSP announces no nonce.
 *   C. Source guard: every <script>/<style> start tag the templates emit as
 *      HTML (app/Views, plugin views) goes through the helper, except JSON
 *      data blocks, which the browser never executes.
 *
 * Run: php tests/security-decisions-csp.unit.php
 */

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';

use App\Middleware\LiteSpeedCacheMiddleware;
use App\Support\ConfigStore;
use App\Support\ContentSecurityPolicy;
use App\Support\LiteSpeedCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$scratch = sys_get_temp_dir() . '/pinakes-csp-' . bin2hex(random_bytes(4));
mkdir($scratch, 0700, true);
$render = static function (string $template, array $vars = []) use ($scratch): string {
    $file = $scratch . '/view-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, $template);
    extract($vars);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

echo "A. Only the templates' own tags carry the nonce\n";
$check(function_exists('csp_nonce') && function_exists('csp_nonce_attr'), 'csp_nonce() and csp_nonce_attr() view helpers exist');
$nonce = ContentSecurityPolicy::beginRequest();
$check(csp_nonce() === $nonce, 'csp_nonce() returns the nonce generated for the request');
$check(csp_nonce_attr() === ' nonce="' . $nonce . '"', 'csp_nonce_attr() returns the ready-to-use attribute');
$check(
    str_contains(ContentSecurityPolicy::header($nonce), "'nonce-{$nonce}'"),
    'the CSP header announces the same nonce the templates stamp'
);

$storedDescription = '<p>Nice book</p><script>fetch("/steal?c="+document.cookie)</script><style>body{display:none}</style>';
$page = $render(
    "<style<?= csp_nonce_attr() ?>>.card{color:red}</style>\n"
    . "<div class=\"description\"><?= \$description ?></div>\n"
    . "<script<?= csp_nonce_attr() ?>>window.templateCode = 1;</script>\n",
    ['description' => $storedDescription]
);
$check(str_contains($page, '<script nonce="' . $nonce . '">window.templateCode = 1;</script>'), 'a template <script> rendered through the helper carries the nonce');
$check(str_contains($page, '<style nonce="' . $nonce . '">.card{color:red}</style>'), 'a template <style> rendered through the helper carries the nonce');
$check(str_contains($page, '<script>fetch("/steal?c="+document.cookie)</script>'), 'a <script> injected through stored content carries no nonce');
$check(str_contains($page, '<style>body{display:none}</style>'), 'a <style> injected through stored content carries no nonce');
$check(substr_count($page, 'nonce=') === 2, 'nothing but the two template tags is nonced');

// A real view: the shared scroll-to-top partial.
$scroll = $render(file_get_contents($ROOT . '/app/Views/partials/scroll-to-top.php'));
$check(str_starts_with(ltrim($scroll), '<script nonce="' . $nonce . '">'), 'a real partial (scroll-to-top) emits its script with the request nonce');

$check(!(new ReflectionClass(ContentSecurityPolicy::class))->hasMethod('addNonceAttributes'), 'the whole-response nonce stamper is gone');
$frontController = (string) file_get_contents($ROOT . '/public/index.php');
$check(
    !str_contains($frontController, 'addNonceAttributes') && str_contains($frontController, 'ContentSecurityPolicy::beginRequest()'),
    'public/index.php generates the request nonce and no longer rewrites the response body'
);

echo "B. Shared cache hashes only nonced inline blocks\n";
$templateScript = 'window.templateCode = 1;';
$templateStyle = '.card{color:red}';
$injectedScript = 'fetch("/steal?c="+document.cookie)';
$guessedScript = 'window.guessed = 1;';
$sha = static fn(string $body): string => "'sha256-" . base64_encode(hash('sha256', $body, true)) . "'";
$rendered = $page . '<script nonce="00000000-00000000-00000000-00000000">' . $guessedScript . '</script>';
$cachedCsp = ContentSecurityPolicy::headerForCachedHtml($rendered, $nonce);
$check(str_contains($cachedCsp, $sha($templateScript)), 'the template script is whitelisted by hash');
$check(str_contains($cachedCsp, $sha($templateStyle)), 'the template style is whitelisted by hash');
$check(!str_contains($cachedCsp, $sha($injectedScript)), 'an injected un-nonced script is NOT whitelisted');
$check(!str_contains($cachedCsp, $sha('body{display:none}')), 'an injected un-nonced style is NOT whitelisted');
$check(!str_contains($cachedCsp, $sha($guessedScript)), 'a script carrying a different nonce is NOT whitelisted');
$check(substr_count($cachedCsp, "'sha256-") === 2, 'exactly the two template blocks are hashed');
$check(!str_contains($cachedCsp, 'nonce-'), 'the cached policy carries no nonce');
$singleQuoted = ContentSecurityPolicy::headerForCachedHtml("<script nonce='{$nonce}'>{$templateScript}</script>", $nonce);
$check(str_contains($singleQuoted, $sha($templateScript)), 'a single-quoted nonce attribute is recognised too');
$dataNonce = ContentSecurityPolicy::headerForCachedHtml('<script data-nonce="' . $nonce . '">' . $injectedScript . '</script>', $nonce);
$check(!str_contains($dataNonce, $sha($injectedScript)), 'a data-nonce attribute is not mistaken for the nonce');
$rejected = false;
try {
    ContentSecurityPolicy::headerForCachedHtml($rendered, 'not-a-nonce');
} catch (\InvalidArgumentException) {
    $rejected = true;
}
$check($rejected, 'headerForCachedHtml() refuses a malformed nonce');
$check(ContentSecurityPolicy::nonceFromHeader("default-src 'self'") === null, 'a policy without a nonce yields none');

$configProperty = new ReflectionProperty(ConfigStore::class, 'runtimeCache');
$configProperty->setValue(null, [
    'cache' => [
        'litespeed_enabled' => true,
        'litespeed_home_ttl' => 300,
        'litespeed_catalog_ttl' => 120,
        'litespeed_book_ttl' => 600,
    ],
    'advanced' => ['private_mode' => false],
]);
$handler = static function (string $body, string $csp): RequestHandlerInterface {
    return new class ($body, $csp) implements RequestHandlerInterface {
        public function __construct(private string $body, private string $csp)
        {
        }

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $response = new Response(200);
            $response->getBody()->write($this->body);
            return $response
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withHeader('Content-Security-Policy', $this->csp)
                ->withHeader(LiteSpeedCache::MARKER_HEADER, 'catalog');
        }
    };
};
$request = (new ServerRequestFactory())->createServerRequest('GET', 'https://library.example/catalog');
$middleware = new LiteSpeedCacheMiddleware();
$document = '<!doctype html><html><body>' . $rendered . '</body></html>';
$edge = $middleware->process($request, $handler($document, ContentSecurityPolicy::header($nonce)));
if ($edge->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache') {
    // Container runtimes force a bypass (see ContainerRuntime); the hash
    // contract above is what matters there.
    echo "  SKIP edge-cache response checks: LSCache bypassed in this runtime\n";
} else {
    $edgeCsp = $edge->getHeaderLine('Content-Security-Policy');
    $check(str_contains($edgeCsp, $sha($templateScript)), 'edge-cached page: the template script stays allowed');
    $check(!str_contains($edgeCsp, $sha($injectedScript)), 'edge-cached page: the injected script stays blocked');
    $check(!str_contains((string) $edge->getBody(), 'nonce='), 'edge-cached body carries no reusable nonce');
    $noNonce = $middleware->process($request, $handler($document, "default-src 'self'"));
    $check($noNonce->getHeaderLine('X-LiteSpeed-Cache-Control') === 'no-cache', 'a page whose CSP announces no nonce is never shared-cached');
}

echo "C. Every template <script>/<style> goes through the helper\n";
$files = [];
foreach ([$ROOT . '/app/Views', $ROOT . '/storage/plugins'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if (str_ends_with($path, '.php') && !str_contains($path, '/vendor/')) {
            $files[] = $path;
        }
    }
}
$missing = [];
$stamped = [];
foreach ($files as $path) {
    $tokens = token_get_all((string) file_get_contents($path));
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_INLINE_HTML) {
            continue;
        }
        if (!preg_match_all('/<(script|style)(?=[\s>]|\z)([^>]*)/i', $token[1], $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m as $match) {
            $attrs = $match[2][0];
            if (preg_match('/\btype\s*=\s*["\']application\/(?:ld\+)?json["\']/i', $attrs)) {
                continue; // data block: never executed, no nonce needed
            }
            $before = substr($token[1], 0, $match[0][1]);
            $lineStart = strrpos($before, "\n");
            $prefix = substr($before, $lineStart === false ? 0 : $lineStart + 1);
            if (preg_match('#^\s*(?://|\*)#', $prefix)) {
                continue; // a JS comment mentioning the tag is not markup
            }
            // `<script` + helper echo: the inline HTML ends right after
            // the tag name and the next PHP echo is the helper call.
            $atEnd = $match[0][1] + strlen($match[0][0]) === strlen($token[1]);
            $next = [];
            for ($j = $i + 1; $j < count($tokens) && count($next) < 2; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                $next[] = $tokens[$j];
            }
            if ($atEnd
                && isset($next[0], $next[1])
                && is_array($next[0]) && $next[0][0] === T_OPEN_TAG_WITH_ECHO
                && is_array($next[1]) && $next[1][1] === 'csp_nonce_attr'
            ) {
                $stamped[] = $path;
                continue;
            }
            $missing[] = substr($path, strlen($ROOT) + 1) . ':' . ($token[2] + substr_count($before, "\n"));
        }
    }
}
$check(count($stamped) > 200, 'the templates stamp their tags through the helper (' . count($stamped) . ' found)');
$check($missing === [], 'no template <script>/<style> is emitted without the nonce' . ($missing === [] ? '' : ': ' . implode(', ', array_slice($missing, 0, 10))));

echo "D. No inline event handlers; the policy forbids them\n";
$policy = \App\Support\ContentSecurityPolicy::header(\App\Support\ContentSecurityPolicy::createNonce(), false);
$check(str_contains($policy, "script-src-attr 'none'") && !str_contains($policy, "script-src-attr 'unsafe-inline'"),
    "the live policy sends script-src-attr 'none'");
$cachedPolicy = \App\Support\ContentSecurityPolicy::headerForCachedHtml('<p>x</p>', \App\Support\ContentSecurityPolicy::createNonce(), false);
$check(str_contains($cachedPolicy, "script-src-attr 'none'"), "the shared-cache policy sends script-src-attr 'none' too");
$handlerHits = [];
$scanRoots = [$ROOT . '/app/Views', $ROOT . '/storage/plugins', $ROOT . '/public/assets/js'];
foreach ($scanRoots as $scanRoot) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = (string) $f;
        if (!preg_match('/\.(php|js)$/', $path) || preg_match('#/(vendor|node_modules)/|\.min\.js$|bundle\.js$#', $path)) {
            continue;
        }
        foreach (file($path) ?: [] as $n => $line) {
            // An attribute: whitespace, on<event>, optional spaces, =, then a quote or a value.
            if (preg_match('/\son(?:click|change|submit|input|load|error|keyup|keydown|keypress|mouseover|mouseout|mouseenter|mouseleave|focus|blur|dblclick)\s*=\s*["\'\\\\]/i', $line)
                && !preg_match('#^\s*(//|\*|/\*)#', $line)) {
                $handlerHits[] = substr($path, strlen($ROOT) + 1) . ':' . ($n + 1);
            }
        }
    }
}
$check($handlerHits === [], 'no inline on*= handler left in views, plugins or page scripts' . ($handlerHits === [] ? '' : ': ' . implode(', ', array_slice($handlerHits, 0, 10))));

array_map('unlink', glob($scratch . '/*') ?: []);
@rmdir($scratch);

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
