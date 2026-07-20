<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Safe same-origin redirect derivation from an untrusted Referer header.
 *
 * The one audited implementation reused by every "bounce the user back to
 * where they came from" redirect (authors/copies/… admin lists). Only the
 * referer's PATH (+ query) is ever used — its scheme and host are ALWAYS
 * discarded — so a spoofed Host or Referer can never turn the redirect into
 * an open redirect, and there is no host/port comparison to get wrong on
 * non-standard-port or reverse-proxied deployments.
 */
final class RefererGuard
{
    /**
     * Return a safe local redirect derived from $referer, or url($default)
     * when the referer has no usable local path (or its path does not contain
     * $mustContain, when that gate is supplied).
     *
     * @param string $referer     Raw Referer header value (untrusted).
     * @param string $default     App-relative fallback route (run through url()).
     * @param string $mustContain When non-empty, the referer path must contain
     *                            this substring to be accepted (e.g. '/admin/authors').
     */
    public static function localPath(string $referer, string $default, string $mustContain = ''): string
    {
        $fallback = url($default);

        // Reject empties and CRLF (header-injection) outright.
        if ($referer === '' || strpbrk($referer, "\r\n") !== false) {
            return $fallback;
        }

        $parsed = parse_url($referer);
        if ($parsed === false) {
            return $fallback;
        }

        $path = (string) ($parsed['path'] ?? '');

        // Must be a clean root-relative path: a single leading '/', and NOT a
        // protocol-relative ('//') or backslash-tricked ('/\') form that a
        // browser would normalise into an off-site redirect. A scheme-relative
        // referer like "https:evil.tld/admin/authors" parses to a host-less,
        // non-leading-'/' path and is rejected here too.
        if ($path === ''
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_starts_with($path, '/\\')) {
            return $fallback;
        }

        if ($mustContain !== '' && !str_contains($path, $mustContain)) {
            return $fallback;
        }

        return $path . (isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '');
    }
}
