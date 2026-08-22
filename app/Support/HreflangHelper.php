<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Hreflang alternate links for the current page.
 *
 * INTENTIONALLY A NO-OP for now. Earlier versions emitted path-prefixed
 * alternates (`/en/...`, `/de/...`) for every active locale, but no such
 * routes exist: translated route variants are registered at the root
 * (`/catalogo` AND `/catalog`) and the response language follows the
 * SESSION locale, not the URL. Every emitted alternate therefore pointed
 * to a 404, and any servable URL would present the same session-language
 * content regardless of path — both are hreflang errors in Search Console.
 *
 * hreflang is only meaningful when each language variant lives at its own
 * URL and the content follows that URL. If locale path-prefix routing is
 * ever implemented (a middleware consuming `/xx` and fixing the request
 * locale), restore the URL mapping from git history (pre-0.7.65) on top
 * of it.
 */
class HreflangHelper
{
    /**
     * Reset internal caches. For testing and long-running processes.
     */
    public static function clearCache(): void
    {
        // No cached state while the helper is a no-op.
    }

    /**
     * Get hreflang alternate links for the current URL.
     *
     * @return array<int, array{hreflang: string, href: string}>
     */
    public static function getAlternates(): array
    {
        return [];
    }
}
