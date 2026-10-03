<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The contacts page accepts a map as an embed snippet pasted from a provider.
 * Only the URL inside it is kept: the iframe is rebuilt from scratch, so the
 * attributes a provider happens to ship — or an attacker happens to add —
 * never reach the page.
 *
 * Extracted from SettingsController so the rules can be exercised directly.
 * They are pure string work, and the only way to reach them before was an
 * authenticated POST, which is why the OpenStreetMap path could go stale
 * unnoticed for as long as it did.
 */
class MapEmbed
{
    public const PROVIDER_NONE = '';
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_OSM = 'openstreetmap';

    /**
     * Exact paths, not prefixes. A prefix test also accepts
     * `/export/embed.html.something`, which is a different resource.
     */
    private const GOOGLE_HOST = 'www.google.com';
    private const GOOGLE_PATHS = ['/maps/embed'];
    private const GOOGLE_PATH_PREFIXES = ['/maps/embed/'];

    private const OSM_HOST = 'www.openstreetmap.org';
    /**
     * OpenStreetMap serves the embed under both spellings. Its own Share panel
     * used to hand out the `.html` one and now hands out the bare one, so a
     * site that accepts only one of them rejects whatever the current site
     * gives the person doing the pasting.
     */
    private const OSM_PATHS = ['/export/embed', '/export/embed.html'];

    /**
     * The URL a pasted snippet points at, or the snippet itself when it is
     * already a bare URL.
     *
     * The entities matter. A snippet copied from a provider carries its query
     * string HTML-encoded (`&amp;layer=mapnik`), because that is what an
     * attribute must contain. Kept as-is and escaped again on the way out it
     * becomes `&amp;amp;`, and the browser then asks for a parameter called
     * `amp;layer` — so the map silently loses every argument after the first.
     */
    public static function extractUrl(string $embedCode): string
    {
        $embedCode = trim($embedCode);
        if ($embedCode === '') {
            return '';
        }

        $url = $embedCode;
        if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $embedCode, $matches) === 1) {
            $url = $matches[1];
        }

        return trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * HTTPS, a host, and no embedded credentials. `user:pass@` in an iframe
     * source is never a map.
     */
    public static function isSafeHttpsUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        return true;
    }

    /**
     * Which provider this URL belongs to, or PROVIDER_NONE for anything else.
     */
    public static function provider(string $url): string
    {
        if (!self::isSafeHttpsUrl($url)) {
            return self::PROVIDER_NONE;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return self::PROVIDER_NONE;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($host === self::GOOGLE_HOST) {
            if (in_array($path, self::GOOGLE_PATHS, true)) {
                return self::PROVIDER_GOOGLE;
            }
            foreach (self::GOOGLE_PATH_PREFIXES as $prefix) {
                if (strpos($path, $prefix) === 0) {
                    return self::PROVIDER_GOOGLE;
                }
            }
        }

        if ($host === self::OSM_HOST && in_array($path, self::OSM_PATHS, true)) {
            return self::PROVIDER_OSM;
        }

        return self::PROVIDER_NONE;
    }

    /**
     * The iframe actually stored: our attributes, the provider's URL, nothing
     * else carried over from what was pasted.
     */
    public static function buildIframe(string $url, string $provider): string
    {
        return sprintf(
            '<iframe src="%s" width="100%%" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" data-map-provider="%s"></iframe>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($provider, ENT_QUOTES, 'UTF-8')
        );
    }
}
