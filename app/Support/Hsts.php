<?php
declare(strict_types=1);

namespace App\Support;

/**
 * HTTP Strict Transport Security, opt-in.
 *
 * Sent only when the administrator enabled it (Settings > Advanced >
 * "Abilita HSTS", stored as advanced.enable_hsts) AND the request reached the
 * application over HTTPS. Never on plain HTTP (browsers ignore it there, and a
 * spoofed X-Forwarded-Proto must not be able to make a shared cache store it).
 *
 * One year, without includeSubDomains or preload: those reach beyond this
 * application (sibling hosts, the browsers' preload list) and cannot be undone
 * quickly, so they are left to the operator's web server configuration.
 */
final class Hsts
{
    public const HEADER_VALUE = 'max-age=31536000';

    /**
     * @param mixed $enabledSetting raw advanced.enable_hsts ('1'/'0', or bool after ConfigStore::set())
     * @param bool  $isHttps        HTTPS as seen directly or via a TRUSTED proxy
     */
    public static function headerValue(mixed $enabledSetting, bool $isHttps): ?string
    {
        if (!$isHttps) {
            return null;
        }
        $enabled = $enabledSetting === true
            || (is_scalar($enabledSetting) && in_array(strtolower((string) $enabledSetting), ['1', 'true', 'yes', 'on'], true));
        return $enabled ? self::HEADER_VALUE : null;
    }

    /**
     * True when the request looks like HTTPS only through forwarded headers
     * that do not count: X-Forwarded-Proto: https (or X-Forwarded-Ssl: on)
     * from a peer outside TRUSTED_PROXIES, on an install whose
     * APP_CANONICAL_URL does not say https either. Behind such an unconfigured
     * reverse proxy HSTS and upgrade-insecure-requests are never sent, so the
     * settings page warns the administrator.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function untrustedForwardedHttps(array $server, bool $peerTrusted, string $canonicalUrl): bool
    {
        $direct = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
        if ($direct || $peerTrusted || stripos(trim($canonicalUrl), 'https://') === 0) {
            return false;
        }
        $proto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $ssl = strtolower(trim((string) ($server['HTTP_X_FORWARDED_SSL'] ?? '')));
        return $proto === 'https' || $ssl === 'on';
    }
}
