<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\ContentSecurityPolicy;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$nonce = ContentSecurityPolicy::createNonce();
$header = ContentSecurityPolicy::header($nonce);
$html = '<style>.x{color:red}</style><script>window.ok=true</script>'
    . '<script nonce="0123456789abcdef0123456789abcdef">window.existing=true</script>';
$rewritten = ContentSecurityPolicy::addNonceAttributes($html, $nonce);

$check((bool) preg_match('/^[a-f0-9]{32}$/', $nonce), 'nonce is cryptographically sized and CSP-safe');
$check(str_contains($header, "script-src 'self' 'nonce-{$nonce}'"), 'script elements require the response nonce');
$check(str_contains($header, "style-src 'self' 'nonce-{$nonce}'"), 'style elements require the response nonce');
$check(!str_contains($header, "script-src 'self' 'unsafe-inline'"), 'script-src does not permit arbitrary inline scripts');
$check(!str_contains($header, "style-src 'self' 'unsafe-inline'"), 'style-src does not permit arbitrary inline stylesheets');
$check(!preg_match('/(?:img|script|style)-src[^;]*(?:https?:|\*)\s*(?:;|$)/', $header), 'source directives contain no scheme-wide or star wildcard');
$check(str_contains($header, "object-src 'none'"), 'object embedding is denied');
$check(str_contains($header, "base-uri 'self'") && str_contains($header, "form-action 'self'"), 'no-fallback navigation directives are explicit');
$check(substr_count($rewritten, 'nonce="' . $nonce . '"') === 2, 'nonce is attached to every untrusted script/style element');
$check(substr_count($rewritten, 'nonce="0123456789abcdef0123456789abcdef"') === 1, 'an existing nonce is never overwritten');
$check(str_ends_with(ContentSecurityPolicy::header($nonce, true), '; upgrade-insecure-requests'), 'HTTPS production policy upgrades insecure requests');

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
