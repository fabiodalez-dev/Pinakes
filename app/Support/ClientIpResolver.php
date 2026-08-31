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
        $remoteAddr = trim($remoteAddr);
        if (!self::isValidIp($remoteAddr)) {
            $remoteAddr = 'unknown';
        }

        // Forwarding headers are client-controlled unless the immediate peer is
        // explicitly trusted. This is the security boundary for the limiter.
        if ($remoteAddr === 'unknown' || !HtmlHelper::isTrustedProxyIp($remoteAddr)) {
            return $remoteAddr;
        }

        $headers = [];
        foreach ($forwardedHeaders as $name => $value) {
            $headers[strtolower($name)] = trim($value);
        }

        $forwardedFor = $headers['x-forwarded-for'] ?? '';
        if ($forwardedFor !== '') {
            $chain = array_map('trim', explode(',', $forwardedFor));

            // Reject the whole malformed chain. Skipping bad entries can join
            // two unrelated sections and attribute a request to the wrong hop.
            foreach ($chain as $hop) {
                if (!self::isValidIp($hop)) {
                    $chain = [];
                    break;
                }
            }

            if ($chain !== []) {
                // Walk from the direct peer towards the client and strip only
                // configured trusted proxies. The first untrusted hop is the
                // client boundary. If every hop is trusted, the leftmost value
                // remains the best client address supplied by the proxy chain.
                $candidate = $remoteAddr;
                foreach (array_reverse($chain) as $hop) {
                    $candidate = $hop;
                    if (!HtmlHelper::isTrustedProxyIp($hop)) {
                        return $hop;
                    }
                }

                return $candidate;
            }
        }

        // Single-value headers support common proxies that do not emit XFF.
        // Private and reserved client ranges are valid here: once the direct
        // peer is trusted, rejecting LAN addresses would collapse every patron
        // back onto the proxy's shared rate-limit bucket.
        foreach (['x-real-ip', 'cf-connecting-ip', 'true-client-ip', 'x-client-ip'] as $name) {
            $value = $headers[$name] ?? '';
            if (self::isValidIp($value)) {
                return $value;
            }
        }

        return $remoteAddr;
    }

    private static function isValidIp(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
