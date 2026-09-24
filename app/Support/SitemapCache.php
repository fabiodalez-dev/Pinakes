<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The published sitemap file, and the one rule about it: it is a cache.
 *
 * /sitemap.xml has two producers. `SitemapGenerator` can write a static file
 * into public/, which Apache then serves directly, and the same generator also
 * answers the /sitemap.xml route when that file is absent. The file exists for
 * speed: it is written by the admin button and by scripts/generate-sitemap.php,
 * never on the fly.
 *
 * Being a file, it survives everything — including the moment its content stops
 * being true. Activating or removing a plugin changes which public URLs exist
 * (an emeroteca, an archive), and until somebody regenerates it the published
 * file keeps advertising addresses that now answer 404, to crawlers that have
 * no way of knowing better. Deleting it is not a loss: the route regenerates
 * the correct document on the next request, a little slower, and the admin's
 * "Rigenera adesso" (or the cron) republishes the fast path.
 */
final class SitemapCache
{
    /** Absolute path of the published file, the single definition in the app. */
    public static function publishedPath(): string
    {
        return dirname(__DIR__, 2) . '/public/sitemap.xml';
    }

    /**
     * Drop the published file because the set of public URLs may have changed.
     *
     * Never throws and never propagates a failure: this runs inside plugin
     * lifecycle operations, and a read-only public/ directory must cost a stale
     * sitemap, not a failed activation. A missing file is the normal case on an
     * installation that never generated one, and is not an error.
     *
     * @param string $reason short cause, recorded in the log so an operator can
     *        tell this deletion from a manual one
     * @return bool true when a file was actually removed
     */
    public static function invalidate(string $reason, ?string $path = null): bool
    {
        $target = $path ?? self::publishedPath();

        try {
            if (!is_file($target)) {
                return false;
            }
            if (@unlink($target)) {
                SecureLogger::info('[SitemapCache] Published sitemap removed: ' . $reason . '. It will be regenerated on the next request to /sitemap.xml, and republished as a file by "Rigenera adesso" or scripts/generate-sitemap.php.');
                return true;
            }
            SecureLogger::warning('[SitemapCache] Could not remove the published sitemap (' . $reason . '): it may now advertise URLs that no longer exist. Regenerate it from Settings → Advanced.');
        } catch (\Throwable $exception) {
            SecureLogger::warning('[SitemapCache] Sitemap invalidation failed (' . $reason . '): ' . $exception->getMessage());
        }

        return false;
    }
}
