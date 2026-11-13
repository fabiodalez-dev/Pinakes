<?php
declare(strict_types=1);

namespace App\Support;

/**
 * SSRF-safe remote image fetcher (issue #173 hardening).
 *
 * The cover allow-list is intentionally WIDE (any public host) — so the real
 * boundary is the IP layer, and it must be airtight:
 *   - every redirect hop is followed MANUALLY (CURLOPT_FOLLOWLOCATION is off) so
 *     each target host can be validated before the request is issued;
 *   - the host is resolved and ALL of its A/AAAA records must be public — if any
 *     resolves to a private/reserved/NAT64/IPv4-mapped address the fetch aborts;
 *   - the connection is PINNED to the validated IP via CURLOPT_RESOLVE, so a DNS
 *     rebind between the check and the connect cannot reach a different address.
 */
final class SsrfGuard
{
    /**
     * Special-purpose ranges that FILTER_FLAG_GLOBAL_RANGE still lets through
     * (verified on PHP 8.4 and 8.5), or that only some PHP builds reject. They are
     * denied explicitly so the answer does not depend on the PHP version:
     *   - fec0::/10      deprecated IPv6 site-local (RFC 3879), routed internally;
     *   - 64:ff9b:1::/48 local-use NAT64 prefix (RFC 8215), translates to internal v4;
     *   - 2002::/16      6to4 (RFC 3056), embeds an arbitrary IPv4 address;
     *   - 3fff::/20      IPv6 documentation (RFC 9637);
     *   - ff00::/8       IPv6 multicast;
     *   - 224.0.0.0/4    IPv4 multicast;
     *   - 100.64.0.0/10  shared address space / CGNAT (RFC 6598);
     *   - 198.18.0.0/15  benchmarking (RFC 2544);
     *   - 192.0.0.0/24   IETF protocol assignments (RFC 6890).
     *
     * @var list<string>
     */
    private const DENIED_CIDRS = [
        'fec0::/10',
        '64:ff9b:1::/48',
        '2002::/16',
        '3fff::/20',
        'ff00::/8',
        '224.0.0.0/4',
        '100.64.0.0/10',
        '198.18.0.0/15',
        '192.0.0.0/24',
    ];

    /**
     * True only for a genuinely public IP. The base test is FILTER_FLAG_GLOBAL_RANGE
     * (RFC 6890 "globally reachable"), on top of the private/reserved filter, plus the
     * explicit DENIED_CIDRS list for the ranges GLOBAL_RANGE misses. It also rejects
     * IPv4-mapped IPv6 (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96) whose embedded IPv4
     * address is not public (e.g. 64:ff9b::a00:1 == 10.0.0.1, ::ffff:127.0.0.1 ==
     * loopback).
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach (self::DENIED_CIDRS as $cidr) {
            if (self::packedInCidr($packed, $cidr)) {
                return false;
            }
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped ::ffff:0:0/96
            if (str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
                $embedded = inet_ntop(substr($packed, 12));
                return $embedded !== false && self::isPublicIp($embedded);
            }
            // NAT64 64:ff9b::/96 (well-known prefix)
            if (str_starts_with($packed, "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00")) {
                $embedded = inet_ntop(substr($packed, 12));
                return $embedded !== false && self::isPublicIp($embedded);
            }
        }

        // RFC 6890 global reachability. Checked after the embedded-IPv4 branches on
        // purpose: GLOBAL_RANGE rejects all of ::ffff:0:0/96, so a mapped public
        // address must be judged by its embedded IPv4 above, not here.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * True when the packed address (inet_pton output) falls inside $cidr. Families
     * never match each other: an IPv4 CIDR only matches a 4-byte address.
     */
    private static function packedInCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $net = @inet_pton($network);
        if ($net === false || strlen($net) !== strlen($packed)) {
            return false;
        }
        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($packed, 0, $fullBytes) !== substr($net, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($packed[$fullBytes]) & $mask) === (ord($net[$fullBytes]) & $mask);
    }

    /**
     * Resolve a host to a single validated public IP for pinning. Returns null if
     * the host does not resolve, or if ANY resolved address is non-public (strict:
     * one private record poisons the whole host, defeating round-robin rebind).
     * IPv4 is preferred for the pin; an IP literal is validated directly.
     */
    public static function resolvePinnedIp(string $host): ?string
    {
        $host = strtolower($host);

        // Already a literal IP.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? $host : null;
        }

        $v4 = @gethostbynamel($host) ?: [];
        $v6 = [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $rec) {
            if (!empty($rec['ipv6'])) {
                $v6[] = (string) $rec['ipv6'];
            }
        }

        $all = array_merge($v4, $v6);
        if ($all === []) {
            return null; // unresolvable
        }
        foreach ($all as $ip) {
            if (!self::isPublicIp($ip)) {
                return null; // one private record blocks the whole host
            }
        }

        return $v4[0] ?? $v6[0] ?? null;
    }

    /**
     * Fetch a remote image with manual, per-hop-validated, IP-pinned redirects.
     *
     * @return array{0:string,1:string}|null [rawBytes, mimeType] on success, null on
     *         any failure (non-public host, non-image, too large, redirect loop, …).
     */
    public static function fetchImage(string $url, int $maxBytes, int $maxHops = 4): ?array
    {
        if (!extension_loaded('curl')) {
            return null;
        }

        $current = $url;
        for ($hop = 0; $hop <= $maxHops; $hop++) {
            $parts = parse_url($current);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host   = strtolower((string) ($parts['host'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                return null;
            }
            // Every hop must use a default port. CURLOPT_RESOLVE below pins only the
            // port the hop actually uses, and cover fetches never need anything else:
            // a hop to host:8080 would otherwise make cURL resolve the host itself
            // after validation, which re-opens DNS rebinding to internal services.
            $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
            if ($port !== 80 && $port !== 443) {
                SecureLogger::warning('SSRF guard: cover hop on a non-default port refused', ['host' => $host, 'port' => $port]);
                return null;
            }

            $pin = self::resolvePinnedIp($host);
            if ($pin === null) {
                SecureLogger::warning('SSRF guard: cover host did not resolve to a public IP', ['host' => $host]);
                return null;
            }

            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_FOLLOWLOCATION  => false,      // follow manually, validate each hop
                CURLOPT_TIMEOUT         => 20,
                CURLOPT_CONNECTTIMEOUT  => 10,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                CURLOPT_USERAGENT       => 'PinakesCoverBot/1.0',
                // Pin the hop's port (always 80 or 443, enforced above) to the
                // validated IP so cURL never re-resolves the host.
                CURLOPT_RESOLVE         => ["$host:$port:$pin"],
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https,http');
            } else {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
            }

            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            /* curl_close(): no-op since PHP 8.0, deprecated 8.5 */

            if ($body === false) {
                return null;
            }

            if ($code >= 300 && $code < 400) {
                if ($redirectUrl === '') {
                    return null;
                }
                $current = $redirectUrl; // absolute; re-validated at the top of the loop
                continue;
            }

            if ($code !== 200) {
                return null;
            }
            $len = strlen($body);
            if ($len < 100 || $len > $maxBytes) {
                return null;
            }
            $info = @getimagesizefromstring($body);
            if ($info === false || empty($info['mime'])) {
                return null;
            }

            return [$body, (string) $info['mime']];
        }

        return null; // too many redirects
    }
}
