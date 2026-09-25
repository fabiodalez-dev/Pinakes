<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Removes a Content-Security-Policy header left in .htaccess by an older
 * install, so the application's own policy is the one that reaches the browser.
 *
 * Until 0.7.x the policy was a static line in `public/.htaccess`. It moved into
 * the application, which emits a per-response nonce-based policy instead, and
 * the line was deleted from the shipped file. But `public/.htaccess` is on the
 * updater's preserved list — it has to be, it holds the operator's own rules —
 * so on every install that predates the change the old line is still there, and
 * a header set by the web server is the one the browser obeys.
 *
 * Two things follow, and the second is the reason this exists:
 *
 *  - Anything the application has since allowed to be framed is refused. That
 *    is how it was found: a library added a Google Maps embed through a form
 *    that accepts Google Maps, and the browser reported
 *    "Framing 'https://www.google.com/' violates ... frame-src 'self' data:
 *    blob: about: https://www.openstreetmap.org" — a policy naming a provider
 *    that had been the only supported one years earlier.
 *
 *  - The old line carries `script-src 'unsafe-inline'` and no nonce. So every
 *    upgraded install has quietly been running the weaker policy the move was
 *    made to replace, with no sign of it anywhere in the admin interface.
 *
 * The removal is deliberately narrow: one directive, matched on its own line,
 * with the comment that introduces it. Nothing else in the file is touched,
 * because everything else in it belongs to whoever runs the server.
 */
class StaleCspHeader
{
    /**
     * Matches `Header always set Content-Security-Policy "…"` on one line, in
     * any of the spellings Apache accepts (`Header set`, `Header always set`,
     * optional `Header unset` companions are left alone), including a value
     * broken over continuation lines.
     */
    private const DIRECTIVE = '/^[ \t]*Header[ \t]+(?:always[ \t]+)?set[ \t]+(?:"Content-Security-Policy"|Content-Security-Policy)[ \t]+.*(?:\\\\\r?\n.*)*\r?\n?/mi';

    /**
     * Comment lines introducing that directive, immediately above it.
     */
    private const LEAD_COMMENT = '/(?:^[ \t]*#[^\r\n]*Content Security Policy[^\r\n]*\r?\n(?:[ \t]*#[^\r\n]*\r?\n)*)/mi';

    /**
     * True when the file still carries a server-level policy.
     */
    public static function isPresent(string $content): bool
    {
        return preg_match(self::DIRECTIVE, $content) === 1;
    }

    /**
     * The file content with the stale directive removed. Returns the input
     * unchanged when there is nothing to remove, so callers can compare.
     */
    public static function strip(string $content): string
    {
        if (!self::isPresent($content)) {
            return $content;
        }

        // Drop the introducing comment only when it really introduces this
        // directive: a comment followed by the header, nothing else between.
        $cleaned = preg_replace_callback(
            '/(' . trim(self::LEAD_COMMENT, '/mi') . ')(' . trim(self::DIRECTIVE, '/mi') . ')/mi',
            static fn (): string => '',
            $content
        );
        if (!is_string($cleaned)) {
            $cleaned = $content;
        }

        // Then any remaining occurrence that had no comment above it.
        $cleaned = preg_replace(self::DIRECTIVE, '', $cleaned);
        if (!is_string($cleaned)) {
            return $content;
        }

        // Collapse the blank run the removal leaves behind, without reflowing
        // the rest of a file this code does not own.
        $cleaned = preg_replace("/\r?\n[ \t]*\r?\n[ \t]*\r?\n+/", "\n\n", $cleaned);

        return is_string($cleaned) ? $cleaned : $content;
    }

    /**
     * Heal the file in place. Returns true when nothing needed doing or the
     * rewrite succeeded, false when a stale directive is present and could not
     * be removed — the caller decides whether that is fatal or a warning.
     */
    public static function heal(?string $path = null): bool
    {
        $path ??= dirname(__DIR__, 2) . '/public/.htaccess';

        $content = @file_get_contents($path);
        if (!is_string($content)) {
            // Absent is fine: there is no stale header to remove. Present but
            // unreadable is not, and must not be reported as healed.
            return !file_exists($path);
        }

        if (!self::isPresent($content)) {
            return true;
        }

        $cleaned = self::strip($content);
        if ($cleaned === $content || self::isPresent($cleaned)) {
            return false;
        }

        // Keep a copy: this edits a file the operator may have customised.
        @copy($path, $path . '.bak-csp-' . date('YmdHis'));

        $temporary = @tempnam(dirname($path), '.pinakes-csp-');
        if (!is_string($temporary)) {
            return false;
        }
        try {
            if (@file_put_contents($temporary, $cleaned, LOCK_EX) === false) {
                return false;
            }
            @chmod($temporary, 0644);
            if (!@rename($temporary, $path)) {
                return false;
            }
            return true;
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
