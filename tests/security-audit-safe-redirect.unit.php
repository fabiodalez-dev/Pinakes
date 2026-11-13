<?php
declare(strict_types=1);

/**
 * Security audit 2026-11, finding
 * open-redirect:control-char-bypass:app/Controllers/AuthController.php:sanitizeReturnUrl+app/Controllers/LanguageController.php:sanitizeRedirect.
 *
 * The same-origin redirect sanitizers rejected only CR/LF and checked the second
 * character literally, so '/<TAB>/evil.example' passed. Browsers following the
 * WHATWG URL parser (Chromium) strip the tab and resolve '//evil.example', a
 * cross-origin open redirect from the public /language/{locale}?redirect= route.
 * All three request-driven redirects now share App\Support\SafeRedirect.
 *
 * DB-free.  Run:  php tests/security-audit-safe-redirect.unit.php   (exit 0 iff all pass)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\AuthController;
use App\Controllers\LanguageController;
use App\Controllers\PrestitiController;
use App\Support\SafeRedirect;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$private = static function (string $class, string $method): Closure {
    $reflection = new ReflectionMethod($class, $method);
    $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    return static fn (mixed $value): mixed => $reflection->invoke($instance, $value);
};
$language = $private(LanguageController::class, 'sanitizeRedirect');   // falls back to '/'
$login = $private(AuthController::class, 'sanitizeReturnUrl');          // falls back to null
$desk = $private(PrestitiController::class, 'sanitizeRedirect');        // falls back to null

// Values as they reach the controllers: query/body parameters are already URL-decoded.
$offSite = [
    "/\t/evil.example/path" => 'embedded TAB (Chromium strips it: //evil.example)',
    "/\n/evil.example" => 'embedded LF',
    "/\r/evil.example" => 'embedded CR',
    "/\x0B/evil.example" => 'vertical tab',
    "/\x0C/evil.example" => 'form feed',
    "/\x00/evil.example" => 'NUL',
    "/\x1F/evil.example" => 'unit separator (C0)',
    "/\x7F/evil.example" => 'DEL',
    "/x\t/evil.example" => 'TAB further along the value',
    '//evil.example' => 'protocol-relative',
    '/\\evil.example' => 'backslash authority',
    '/a\\..\\\\evil.example' => 'backslash anywhere',
    'https://evil.example/' => 'absolute URL',
    'javascript:alert(1)' => 'script scheme',
    'evil.example/path' => 'relative without leading slash',
    '' => 'empty',
];
foreach ($offSite as $value => $label) {
    $value = (string) $value;
    $check(SafeRedirect::localPath($value) === null, "SafeRedirect refuses {$label}");
    $check($language($value) === '/', "language switch falls back to / for {$label}");
    $check($login($value) === null, "login return_url drops {$label}");
    $check($desk($value) === null, "loan-desk redirect_to drops {$label}");
}
$check($language(['/admin']) === '/' && SafeRedirect::localPath(null) === null, 'non-string values are refused');

$local = ['/', '/catalogo', '/admin/loans?stato=in_corso&page=2', '/libro/12/il-nome-della-rosa#copie', '/profilo?tab=prestiti'];
foreach ($local as $value) {
    $check(SafeRedirect::localPath($value) === $value, "SafeRedirect keeps {$value}");
    $check($language($value) === $value, "language switch keeps {$value}");
    $check($login($value) === $value, "login return_url keeps {$value}");
    $check($desk($value) === $value, "loan-desk redirect_to keeps {$value}");
}
$check($login("  /profilo \n") === '/profilo', 'login return_url still trims surrounding whitespace');
$check($desk('/admin//loans') === '/admin/loans', 'loan-desk redirect_to still collapses repeated slashes');
$check($desk('/admin/loans?next=https://evil.example') === null, 'loan-desk redirect_to still refuses an embedded scheme');

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
