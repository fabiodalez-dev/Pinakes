<?php
declare(strict_types=1);

/**
 * z39-server: client IP used by the SRU and SBN rate limiters.
 *
 * Both callers (endpoint.php getClientIp() and the /api/sbn/search route)
 * share RateLimiter::resolveClientIp(). Behind a trusted proxy the client is
 * the RIGHTMOST untrusted X-Forwarded-For hop; the leftmost entry is chosen
 * by the client and must never decide the rate-limit bucket.
 *
 * Run:
 *   php tests/z39-client-ip.unit.php
 * Exits 0 on success, 1 on any failure.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/z39-server/endpoint.php';

use Z39Server\RateLimiter;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else { $failed++; echo "  FAIL $label\n"; }
};

$setTrusted = static function (string $value): void {
    $_ENV['TRUSTED_PROXIES'] = $value;
    putenv('TRUSTED_PROXIES=' . $value);
};

echo "z39-server client IP resolution:\n";

// No trusted proxy configured: headers are client-controlled and ignored.
$setTrusted('');
$check(
    RateLimiter::resolveClientIp(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6']) === '203.0.113.5',
    'without TRUSTED_PROXIES the forwarded header is ignored'
);

$setTrusted('10.0.0.0/8');
$check(
    RateLimiter::resolveClientIp(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6']) === '203.0.113.5',
    'a peer that is not a trusted proxy cannot pick its own IP'
);
$check(
    RateLimiter::resolveClientIp(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7']) === '198.51.100.7',
    'a spoofed leftmost X-Forwarded-For entry does not win'
);
$check(
    RateLimiter::resolveClientIp(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.3']) === '198.51.100.7',
    'trusted proxy hops on the right are skipped'
);
$check(
    RateLimiter::resolveClientIp(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'junk, 198.51.100.7']) === '10.0.0.2',
    'a malformed chain fails closed to the direct peer'
);
$check(
    RateLimiter::resolveClientIp(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_REAL_IP' => '198.51.100.8']) === '198.51.100.8',
    'X-Real-IP is used when the trusted proxy sends no X-Forwarded-For'
);
$check(
    RateLimiter::resolveClientIp([]) === 'unknown',
    'a missing REMOTE_ADDR resolves to unknown'
);

// SBN route: loopback is trusted in addition to TRUSTED_PROXIES.
$setTrusted('');
$check(
    RateLimiter::resolveClientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.9'], ['127.0.0.1', '::1']) === '198.51.100.9',
    'SBN route behind a loopback proxy takes the rightmost hop'
);

// endpoint.php getClientIp() goes through the same helper.
$setTrusted('10.0.0.0/8');
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.7';
$check(getClientIp() === '198.51.100.7', 'SRU endpoint getClientIp() ignores the spoofed leftmost entry');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);

$setTrusted('');

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
