<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Trusted-proxy gate for forwarded headers.
 *
 * Mirrors core's TRUSTED_PROXIES convention (see App\Support\HtmlHelper):
 * forwarded headers like X-Forwarded-Proto are only honoured when the genuine
 * TCP peer (REMOTE_ADDR) is one of the configured proxies. An empty/unset
 * TRUSTED_PROXIES (the default) means no proxy is trusted, so a client cannot
 * spoof X-Forwarded-Proto: https to make a cleartext request look secure.
 *
 * Supports exact IPv4/IPv6 and CIDR ranges for both families.
 */
final class ProxyTrust
{
    /**
     * Whether the request's TCP peer is in the configured TRUSTED_PROXIES list.
     */
    public static function isTrustedProxy(Request $request): bool
    {
        $trustedRaw = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');
        $trustedEnv = is_string($trustedRaw) ? trim($trustedRaw) : '';
        if ($trustedEnv === '') {
            return false;
        }

        $server     = $request->getServerParams();
        $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr === '') {
            return false;
        }

        $remoteIp = inet_pton($remoteAddr);
        if ($remoteIp === false) {
            return false;
        }

        $entries = array_map('trim', explode(',', $trustedEnv));
        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }

            if (strpos($entry, '/') !== false) {
                [$network, $prefixLen] = explode('/', $entry, 2);
                if (!is_numeric($prefixLen)) {
                    continue;
                }
                $prefixLen = (int) $prefixLen;
                $networkIp = inet_pton($network);
                if ($networkIp === false) {
                    continue;
                }
                $networkLen = strlen($networkIp);
                if ($networkLen !== strlen($remoteIp)) {
                    continue; // mixed family — cannot match
                }
                $maxPrefix = $networkLen * 8;
                if ($prefixLen < 0 || $prefixLen > $maxPrefix) {
                    continue;
                }
                $mask          = '';
                $fullBytes     = intdiv($prefixLen, 8);
                $remainderBits = $prefixLen % 8;
                if ($fullBytes > 0) {
                    $mask .= str_repeat("\xFF", $fullBytes);
                }
                if ($remainderBits > 0) {
                    $mask .= chr((0xFF << (8 - $remainderBits)) & 0xFF);
                }
                if (strlen($mask) < $networkLen) {
                    $mask .= str_repeat("\x00", $networkLen - strlen($mask));
                }
                if (($remoteIp & $mask) === ($networkIp & $mask)) {
                    return true;
                }
                continue;
            }

            $entryIp = inet_pton($entry);
            if ($entryIp !== false && $remoteIp === $entryIp) {
                return true;
            }
        }

        return false;
    }
}
