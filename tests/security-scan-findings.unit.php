<?php
declare(strict_types=1);

/**
 * Regression guards for the 2026-07-25 security scan findings.
 *
 *   F1 (CWE-807) — RateLimitMiddleware must NOT trust client forwarding headers
 *                  unless REMOTE_ADDR is a configured trusted proxy, otherwise a
 *                  rotating X-Forwarded-For mints unlimited rate-limit buckets.
 *   F6 (CWE-807) — same rule for RememberMeService audit IP.
 *   F3 (CWE-862) — private mode must NOT allow-list /feed.xml, /sitemap, /llms.txt
 *                  (they leak catalog content to unauthenticated visitors).
 *   F2 (CWE-312) — the installer's inline SMTP-password encryption must round-trip
 *                  through the app's SettingsEncryption::decrypt().
 *
 * Run: php tests/security-scan-findings.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Middleware\RateLimitMiddleware;
use App\Middleware\PrivateModeMiddleware;
use App\Support\ClientIpResolver;
use App\Support\RememberMeService;
use App\Support\SettingsEncryption;

$passed = 0;
$failed = 0;
$check = static function (bool $cond, string $label) use (&$passed, &$failed): void {
    if ($cond) { $passed++; echo "  OK  {$label}\n"; return; }
    $failed++; echo "  FAIL {$label}\n";
};

$invoke = static function (object $obj, string $method, ...$args) {
    $r = new ReflectionMethod($obj, $method);
    $r->setAccessible(true);
    return $r->invoke($obj, ...$args);
};

// Clean proxy env for the default (Apache-direct) posture.
putenv('TRUSTED_PROXIES');
unset($_ENV['TRUSTED_PROXIES']);

echo "F1 — RateLimitMiddleware trusted-proxy gate\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$factory = new \Slim\Psr7\Factory\ServerRequestFactory();
$req = $factory->createServerRequest('POST', '/accedi', ['REMOTE_ADDR' => '203.0.113.9'])
    ->withHeader('X-Forwarded-For', '8.8.8.8');
$mw = new RateLimitMiddleware(15, 300, 'login');

$check($invoke($mw, 'getClientIP', $req) === '203.0.113.9',
    'no trusted proxy: spoofed X-Forwarded-For is ignored, keys on REMOTE_ADDR');

putenv('TRUSTED_PROXIES=203.0.113.9');
$_ENV['TRUSTED_PROXIES'] = '203.0.113.9';
$check($invoke($mw, 'getClientIP', $req) === '8.8.8.8',
    'peer is a trusted proxy: forwarded header is honored');

putenv('TRUSTED_PROXIES=172.21.0.0/16,10.0.0.0/8');
$_ENV['TRUSTED_PROXIES'] = '172.21.0.0/16,10.0.0.0/8';
$privateReq = $factory->createServerRequest('POST', '/registrati', ['REMOTE_ADDR' => '172.21.0.4'])
    ->withHeader('X-Forwarded-For', '192.168.1.42');
$check($invoke($mw, 'getClientIP', $privateReq) === '192.168.1.42',
    'trusted Docker proxy: a private LAN client gets its own rate-limit bucket');

$chainReq = $factory->createServerRequest('POST', '/registrati', ['REMOTE_ADDR' => '172.21.0.4'])
    ->withHeader('X-Forwarded-For', '198.51.100.27, 10.0.0.8');
$check($invoke($mw, 'getClientIP', $chainReq) === '198.51.100.27',
    'forwarded chain is walked right-to-left and trusted intermediate proxies are stripped');

$spoofedPrefixReq = $factory->createServerRequest('POST', '/registrati', ['REMOTE_ADDR' => '172.21.0.4'])
    ->withHeader('X-Forwarded-For', '8.8.8.8, 198.51.100.27');
$check($invoke($mw, 'getClientIP', $spoofedPrefixReq) === '198.51.100.27',
    'untrusted boundary prevents a caller-prepended XFF value becoming the bucket key');

$check(ClientIpResolver::resolve('172.21.0.4', ['X-Forwarded-For' => 'invalid, 10.0.0.8']) === '172.21.0.4',
    'malformed XFF chain fails safely to the direct peer');
putenv('TRUSTED_PROXIES');
unset($_ENV['TRUSTED_PROXIES']);

echo "F6 — RememberMeService audit IP trusted-proxy gate\n";
// getClientIP() reads $_SERVER; construct without touching the DB via reflection.
$ref = new ReflectionClass(RememberMeService::class);
$svc = $ref->newInstanceWithoutConstructor();
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.4.4';
$check($invoke($svc, 'getClientIP') === '203.0.113.50',
    'no trusted proxy: forged X-Forwarded-For not written to audit IP');
putenv('TRUSTED_PROXIES=203.0.113.50');
$_ENV['TRUSTED_PROXIES'] = '203.0.113.50';
$check($invoke($svc, 'getClientIP') === '8.8.4.4',
    'peer is a trusted proxy: forwarded audit IP honored');
$_SERVER['REMOTE_ADDR'] = '172.21.0.4';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.43';
putenv('TRUSTED_PROXIES=172.21.0.0/16');
$_ENV['TRUSTED_PROXIES'] = '172.21.0.0/16';
$check($invoke($svc, 'getClientIP') === '192.168.1.43',
    'trusted proxy: private LAN address is preserved in session audit data');
putenv('TRUSTED_PROXIES');
unset($_ENV['TRUSTED_PROXIES'], $_SERVER['HTTP_X_FORWARDED_FOR']);

echo "F3 — private mode does not allow-list content endpoints\n";
$pm = new PrivateModeMiddleware();
foreach (['/feed.xml' => false, '/sitemap.xml' => false, '/sitemap' => false, '/llms.txt' => false,
          '/assets/app.css' => true, '/favicon.ico' => true, '/robots.txt' => true] as $path => $expected) {
    $check($invoke($pm, 'isAllowed', $path) === $expected,
        "isAllowed('{$path}') === " . ($expected ? 'true' : 'false'));
}

echo "F2 — installer inline cipher round-trips through the app decrypt\n";
putenv('PLUGIN_ENCRYPTION_KEY=regression-key-xyz');
$_ENV['PLUGIN_ENCRYPTION_KEY'] = 'regression-key-xyz';
SettingsEncryption::resetKeyCache();
$plain = 'p@ss"w0rd,\'; DROP';
// Mirror step6.php exactly.
$encKey = hash('sha256', 'regression-key-xyz', true);
$iv = random_bytes(12);
$tag = '';
$cipher = openssl_encrypt($plain, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);
$stored = 'ENC:' . base64_encode($iv . $tag . $cipher);
$check(str_starts_with($stored, 'ENC:') && SettingsEncryption::decrypt($stored) === $plain,
    'installer-encrypted SMTP password decrypts to the original plaintext');
SettingsEncryption::resetKeyCache();
putenv('PLUGIN_ENCRYPTION_KEY');
unset($_ENV['PLUGIN_ENCRYPTION_KEY']);

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
