<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — SsrfGuard::isPublicIp special-purpose ranges.
 *
 * isPublicIp() is the only boundary on cover redirect hops, on the plugin image
 * proxy and on UnifiedPush endpoints. The old predicate (NO_PRIV_RANGE |
 * NO_RES_RANGE only) let through RFC 6598 shared space (100.64.0.0/10, the
 * cloud metadata range on several providers), benchmarking 198.18.0.0/15,
 * 192.0.0.0/24, 6to4, local-use NAT64 and IPv6 site-local. PHP's
 * FILTER_FLAG_GLOBAL_RANGE still accepts fec0::/10 and 64:ff9b:1::/48 (checked
 * on PHP 8.4 and 8.5), so those must be denied explicitly.
 *
 * Run:  php tests/security-audit-ssrf-ip-ranges.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\SsrfGuard;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

echo "A. Special-purpose addresses are NOT public\n";
$mustReject = [
    '100.64.0.1'            => 'RFC 6598 shared address space (low end)',
    '100.100.100.200'       => 'RFC 6598 shared address space (cloud metadata)',
    '100.127.255.254'       => 'RFC 6598 shared address space (high end)',
    '198.18.0.1'            => 'RFC 2544 benchmarking',
    '198.19.255.1'          => 'RFC 2544 benchmarking (upper /16)',
    '192.0.0.170'           => 'RFC 6890 IETF protocol assignments',
    '224.0.0.1'             => 'IPv4 multicast',
    '239.255.255.250'       => 'IPv4 multicast (SSDP)',
    'fec0::1'               => 'IPv6 site-local fec0::/10 (GLOBAL_RANGE accepts it)',
    'feff::1'               => 'IPv6 site-local fec0::/10 upper edge',
    '64:ff9b:1::a9fe:a9fe'  => 'local-use NAT64 64:ff9b:1::/48 (GLOBAL_RANGE accepts it)',
    '64:ff9b:1:ffff::1'     => 'local-use NAT64 64:ff9b:1::/48, other host',
    '2002:7f00:1::'         => '6to4 2002::/16 embedding 127.0.0.1',
    '2002:808:808::1'       => '6to4 2002::/16 embedding a public v4 is still refused',
    '3fff::1'               => 'IPv6 documentation 3fff::/20',
    'ff02::1'               => 'IPv6 multicast',
    // Regression guard: everything the old filter already refused.
    '127.0.0.1'             => 'loopback',
    '10.0.0.1'              => 'RFC 1918',
    '172.16.5.4'            => 'RFC 1918',
    '192.168.1.1'           => 'RFC 1918',
    '169.254.169.254'       => 'link-local metadata',
    '0.0.0.0'               => 'unspecified',
    '::1'                   => 'IPv6 loopback',
    'fe80::1'               => 'IPv6 link-local',
    'fc00::1'               => 'IPv6 ULA',
    '::ffff:127.0.0.1'      => 'IPv4-mapped loopback',
    '::ffff:100.64.0.1'     => 'IPv4-mapped shared address space',
    '64:ff9b::a00:1'        => 'NAT64 well-known prefix embedding 10.0.0.1',
    '64:ff9b::6440:1'       => 'NAT64 well-known prefix embedding 100.64.0.1',
    'not-an-ip'             => 'garbage',
];
foreach ($mustReject as $ip => $why) {
    $check(SsrfGuard::isPublicIp($ip) === false, "{$ip} rejected ({$why})");
}

echo "B. Genuinely public addresses stay public\n";
$mustAccept = [
    '8.8.8.8'               => 'public IPv4',
    '93.184.216.34'         => 'public IPv4',
    '100.63.255.255'        => 'just below 100.64.0.0/10',
    '100.128.0.1'           => 'just above 100.64.0.0/10',
    '198.17.255.255'        => 'just below 198.18.0.0/15',
    '198.20.0.1'            => 'just above 198.18.0.0/15',
    '2606:4700:4700::1111'  => 'public IPv6',
    '2a00:1450:4001:80b::200e' => 'public IPv6',
    '64:ff9b::808:808'      => 'NAT64 well-known prefix embedding 8.8.8.8',
];
foreach ($mustAccept as $ip => $why) {
    $check(SsrfGuard::isPublicIp($ip) === true, "{$ip} accepted ({$why})");
}

echo "C. resolvePinnedIp() refuses the same literals as pins\n";
foreach (['100.64.0.1', 'fec0::1', '64:ff9b:1::a9fe:a9fe', '2002:7f00:1::', '198.18.0.1'] as $ip) {
    $check(SsrfGuard::resolvePinnedIp($ip) === null, "{$ip} is never returned as a pin");
}
$check(SsrfGuard::resolvePinnedIp('8.8.8.8') === '8.8.8.8', 'a public literal is pinned as-is');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
