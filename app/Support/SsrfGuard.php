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
     * True only for a genuinely public IP. Beyond the standard private/reserved
     * filter this also rejects IPv4-mapped IPv6 (::ffff:a.b.c.d) and NAT64
     * (64:ff9b::/96) which embed an IPv4 address the base filter does not re-check
     * (e.g. 64:ff9b::a00:1 == 10.0.0.1, ::ffff:127.0.0.1 == loopback).
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
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

        return true;
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
                // Pin BOTH default ports to the validated IP so cURL never re-resolves.
                CURLOPT_RESOLVE         => ["$host:443:$pin", "$host:80:$pin"],
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https,http');
            } else {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
            }

            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

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
