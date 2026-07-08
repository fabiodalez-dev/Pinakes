<?php
declare(strict_types=1);

/**
 * Behavioral test for App\Plugins\MobileApi\Support\ProxyTrust — the gate that decides
 * whether X-Forwarded-Proto from a reverse proxy is honoured on the /api/v1 HTTPS check.
 *
 * The mobile_api.trusted_proxies admin setting (added so operators behind a NAS/reverse
 * proxy can fix the 426 from the UI) is read straight from system_settings — ConfigStore
 * only exposes a fixed set of known paths — and matched against the request's REMOTE_ADDR
 * with exact-IP and CIDR support. This asserts that read + the matching, then restores the
 * original setting.
 *
 * Run:  php tests/mobile-api-proxy-trust.unit.php   Exit 0 on "ALL <n> PASS".
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/storage/plugins/mobile-api/src/Support/ProxyTrust.php';

use App\Plugins\MobileApi\Support\ProxyTrust;
use App\Support\ConfigStore;
use Slim\Psr7\Factory\ServerRequestFactory;

// Load .env so ConfigStore/ProxyTrust can reach the DB; skip cleanly if unavailable.
try {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
} catch (\Throwable $e) {
    // safeLoad never throws on a missing file, but be defensive.
}
if (($_ENV['DB_NAME'] ?? getenv('DB_NAME')) === false && ($_ENV['DB_NAME'] ?? '') === '') {
    echo "SKIP: no DB config in environment\n";
    exit(0);
}

$TESTNO = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO;
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}

// Preserve whatever the instance already has, so the test is non-destructive. Read it with
// a direct query — ConfigStore::get doesn't expose this path, and calling ProxyTrust here
// would prime its per-request cache before we write the test value.
$readOriginal = static function (): string {
    $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
    $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '');
    $user = (string) ($_ENV['DB_USER'] ?? getenv('DB_USER') ?: '');
    $pass = (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''));
    $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
    $sock = (string) ($_ENV['DB_SOCKET'] ?? getenv('DB_SOCKET') ?: '');
    try {
        $db = $sock !== '' ? new \mysqli(null, $user, $pass, $name, 0, $sock) : new \mysqli($host, $user, $pass, $name, $port);
        $r = $db->query("SELECT setting_value FROM system_settings WHERE category='mobile_api' AND setting_key='trusted_proxies' LIMIT 1");
        $row = $r ? $r->fetch_row() : null;
        $db->close();
        return ($row !== null && is_string($row[0])) ? $row[0] : '';
    } catch (\Throwable $e) {
        return '';
    }
};
$original = $readOriginal();
$restore = static function () use ($original): void {
    try {
        ConfigStore::set('mobile_api.trusted_proxies', $original);
    } catch (\Throwable $ignored) {
    }
};
set_exception_handler(static function (\Throwable $e) use ($restore): void {
    $restore();
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
});

try {
    ConfigStore::set('mobile_api.trusted_proxies', '203.0.113.7, 10.1.0.0/16, 2001:db8::/32');
} catch (\Throwable $e) {
    echo "SKIP: cannot write settings (" . $e->getMessage() . ")\n";
    exit(0);
}

// The per-request static cache must be primed against the value we just wrote — it is null
// on first read here, so trustedEntries() will query system_settings fresh.
$entries = ProxyTrust::trustedEntries();
check(in_array('203.0.113.7', $entries, true), "trustedEntries() reads the DB setting (exact IPv4)");
check(in_array('10.1.0.0/16', $entries, true), "trustedEntries() reads the DB setting (CIDR)");

$fac = new ServerRequestFactory();
$peer = static function (string $ip) use ($fac) {
    return $fac->createServerRequest('GET', 'https://host/api/v1/health', ['REMOTE_ADDR' => $ip]);
};

check(ProxyTrust::isTrustedProxy($peer('203.0.113.7')) === true, "exact IPv4 match is trusted");
check(ProxyTrust::isTrustedProxy($peer('10.1.2.3')) === true, "an IPv4 inside 10.1.0.0/16 is trusted");
check(ProxyTrust::isTrustedProxy($peer('10.2.0.1')) === false, "an IPv4 outside the CIDR is not trusted");
check(ProxyTrust::isTrustedProxy($peer('8.8.8.8')) === false, "an unrelated IPv4 is not trusted");
check(ProxyTrust::isTrustedProxy($peer('2001:db8::1')) === true, "an IPv6 inside 2001:db8::/32 is trusted");
check(ProxyTrust::isTrustedProxy($peer('')) === false, "an empty REMOTE_ADDR is never trusted");

$restore();
printf("\nALL %d PASS\n", $TESTNO);
exit(0);
