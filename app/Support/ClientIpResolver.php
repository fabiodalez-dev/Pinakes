<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Resolve a client IP without trusting caller-controlled forwarding headers.
 */
final class ClientIpResolver
{
    /**
     * @param array<string, string> $forwardedHeaders Header names are
     *        case-insensitive; values may contain comma-separated proxy hops.
     */
    public static function resolve(string $remoteAddr, array $forwardedHeaders): string
    {
        $remoteAddr = self::normalizeIp($remoteAddr);
        if ($remoteAddr === '') {
            return 'unknown';
        }

        // Forwarding headers are client-controlled unless the immediate peer is
        // explicitly trusted. This is the security boundary for the limiter.
        if (!HtmlHelper::isTrustedProxyIp($remoteAddr)) {
            return $remoteAddr;
        }

        $headers = [];
        foreach ($forwardedHeaders as $name => $value) {
            $headers[strtolower($name)] = trim($value);
        }

        $forwardedFor = $headers['x-forwarded-for'] ?? '';
        if ($forwardedFor !== '') {
            // X-Forwarded-For is authoritative once present: it decides the
            // client alone. A present-but-malformed chain fails CLOSED to the
            // trusted peer, NOT through to a spoofable single-value header —
            // otherwise a caller could poison XFF with one junk hop to escape
            // onto X-Real-IP and mint an unlimited number of fresh buckets.
            // Rejecting the whole chain on any bad entry also avoids joining two
            // unrelated sections and attributing a request to the wrong hop.
            $chain = [];
            foreach (explode(',', $forwardedFor) as $rawHop) {
                $hop = self::normalizeIp($rawHop);
                if ($hop === '') {
                    return $remoteAddr;
                }
                $chain[] = $hop;
            }

            // Walk from the direct peer towards the client and strip only
            // configured trusted proxies. The first untrusted hop is the client
            // boundary. If every hop is trusted, the leftmost value remains the
            // best client address supplied by the proxy chain.
            $candidate = $remoteAddr;
            foreach (array_reverse($chain) as $hop) {
                $candidate = $hop;
                if (!HtmlHelper::isTrustedProxyIp($hop)) {
                    return $hop;
                }
            }

            return $candidate;
        }

        // Single-value headers support common proxies that do not emit XFF
        // (e.g. Cloudflare's CF-Connecting-IP). They are consulted ONLY when XFF
        // is absent — never as a fallback from a rejected XFF. Private and
        // reserved client ranges are valid here: once the direct peer is
        // trusted, rejecting LAN addresses would collapse every patron back onto
        // the proxy's shared rate-limit bucket.
        foreach (['x-real-ip', 'cf-connecting-ip', 'true-client-ip', 'x-client-ip'] as $name) {
            $value = self::normalizeIp($headers[$name] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return $remoteAddr;
    }

    /**
     * Normalise a single address token to a bare, valid IP, or '' when it is
     * not a usable IP. Strips a bracketed IPv6 host and an IPv4 `:port` suffix
     * so proxy-supplied hops like `[2001:db8::1]:443` or `203.0.113.9:51000`
     * are not rejected as malformed.
     */
    private static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }

        if ($ip[0] === '[') {
            // Bracketed IPv6, with or without a trailing :port.
            $end = strpos($ip, ']');
            if ($end === false) {
                return '';
            }
            $ip = substr($ip, 1, $end - 1);
        } elseif (substr_count($ip, ':') === 1) {
            // Exactly one colon → IPv4:port, never a bare IPv6.
            $ip = substr($ip, 0, (int) strpos($ip, ':'));
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }
}
