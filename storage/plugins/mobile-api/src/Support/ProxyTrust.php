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
     * The configured trusted-proxy entries (exact IPs / CIDRs), from BOTH the
     * TRUSTED_PROXIES env var AND the `mobile_api.trusted_proxies` admin setting.
     * Either source is enough — so an operator behind a reverse proxy (QNAP/Synology/
     * nginx/Docker) can fix the "HTTPS required / 426" case from the settings UI without
     * editing .env.
     *
     * @return list<string>
     */
    /** Per-request cache of the DB setting so the middleware doesn't re-query per call. */
    private static ?string $settingCache = null;

    public static function trustedEntries(): array
    {
        $sources = [];
        $env = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');
        if (is_string($env)) {
            $sources[] = $env;
        }
        // The mobile_api.trusted_proxies admin setting lives in system_settings; ConfigStore
        // only exposes a fixed set of known paths, so read it directly (mirrors how the
        // plugin persists it via its settings repo).
        $sources[] = self::settingValue();

        $entries = [];
        foreach ($sources as $raw) {
            foreach (explode(',', $raw) as $entry) {
                $entry = trim($entry);
                if ($entry !== '') {
                    $entries[] = $entry;
                }
            }
        }
        return $entries;
    }

    /**
     * Read mobile_api.trusted_proxies straight from system_settings (ConfigStore only
     * exposes a fixed set of known paths). Cached per request. Any failure yields '' — a
     * settings read must never turn the HTTPS gate into a hard error.
     */
    private static function settingValue(): string
    {
        if (self::$settingCache !== null) {
            return self::$settingCache;
        }
        self::$settingCache = '';

        $host   = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
        $name   = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '');
        $user   = (string) ($_ENV['DB_USER'] ?? getenv('DB_USER') ?: '');
        $pass   = (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''));
        $port   = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        $socket = (string) ($_ENV['DB_SOCKET'] ?? getenv('DB_SOCKET') ?: '');
        if ($name === '' || $user === '') {
            return self::$settingCache;
        }

        try {
            $db = $socket !== ''
                ? new \mysqli(null, $user, $pass, $name, 0, $socket)
                : new \mysqli($host, $user, $pass, $name, $port);
            $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE category = 'mobile_api' AND setting_key = 'trusted_proxies' LIMIT 1");
            if ($stmt !== false) {
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_row() : null;
                if ($row !== null && is_string($row[0])) {
                    self::$settingCache = $row[0];
                }
                $stmt->close();
            }
            $db->close();
        } catch (\Throwable $e) {
            // stay with '' — never break the HTTPS gate on a settings-read failure
        }

        return self::$settingCache;
    }

    /**
     * Whether the request's TCP peer is in the configured trusted-proxy list
     * (TRUSTED_PROXIES env + mobile_api.trusted_proxies setting).
     */
    public static function isTrustedProxy(Request $request): bool
    {
        $server     = $request->getServerParams();
        $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr === '') {
            return false;
        }

        $remoteIp = inet_pton($remoteAddr);
        if ($remoteIp === false) {
            return false;
        }

        $entries = self::trustedEntries();
        if ($entries === []) {
            return false;
        }

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
