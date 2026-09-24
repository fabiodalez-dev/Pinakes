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

    /** Absolute path of the revision counter. */
    private static function revisionPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/sitemap-revision';
    }

    /**
     * How many times the published sitemap has been invalidated.
     *
     * A file, not a cache entry: the cron generator runs under the CLI SAPI and
     * shares no APCu with PHP-FPM, so an in-memory counter would let precisely
     * the two producers that matter disagree. A missing or unreadable file
     * reads as 0, which is the conservative answer — a publisher that cannot
     * read the counter sees "unchanged" and still publishes, exactly as it did
     * before this existed.
     */
    public static function revision(): int
    {
        $raw = @file_get_contents(self::revisionPath());

        return $raw === false ? 0 : (int) trim($raw);
    }

    /**
     * Record that the set of public URLs may have changed.
     *
     * Separate from deleting the file, and deliberately bumped even when there
     * was no file to delete: the counter answers "did anything change while you
     * were generating?", and a generation that started before an invalidation
     * is stale whether or not a published file existed at the time.
     */
    private static function bumpRevision(): void
    {
        $path = self::revisionPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            return;
        }

        // Append-and-count would grow without bound; read-modify-write can lose
        // a concurrent bump. Losing one is harmless — the value is compared for
        // INEQUALITY, so any change at all is enough, and two invalidations
        // that collapse into one still differ from the value a generator read
        // before either of them.
        $current = self::revision();
        @file_put_contents($path, (string) ($current + 1), LOCK_EX);
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
            // Before the unlink, and unconditionally: a generation already in
            // flight has to learn that its snapshot is stale even in the cases
            // this method returns early from.
            self::bumpRevision();

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
