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
}
