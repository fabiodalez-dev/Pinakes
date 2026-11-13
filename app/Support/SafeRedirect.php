<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Same-origin validation for redirect targets that come from the request
 * (post-login return_url, language-switch redirect, loan-desk redirect_to).
 *
 * The one implementation every such redirect goes through. A browser resolves
 * a Location value with the WHATWG URL parser, which removes ASCII tab and
 * newline anywhere in the string and treats '\' as '/', so '/<TAB>/evil.example'
 * or '/\evil.example' become the scheme-relative '//evil.example'. Checking
 * only the second character, as the earlier per-controller copies did, misses
 * the first form. Here any C0 control, DEL or backslash rejects the value
 * outright instead of being stripped: a value that needed cleaning was not
 * produced by the application.
 */
final class SafeRedirect
{
    /**
     * Return $value when it is a root-relative path on this origin, or null.
     *
     * Accepted: a single leading '/', not followed by another '/', with no
     * control character, DEL or backslash anywhere, and no scheme or host once
     * parsed. Query string and fragment are kept as they are.
     */
    public static function localPath(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
            return null;
        }
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }
        return $value;
    }
}
