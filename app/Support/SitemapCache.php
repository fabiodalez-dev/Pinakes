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

    /** Absolute path of the lock that serialises publication and withdrawal. */
    private static function lockPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/sitemap-publish.lock';
    }

    /** @var resource|null the lock handle this process holds, if any */
    private static $lockHandle = null;

    private static int $lockDepth = 0;

    /**
     * Take the exclusive lock shared by every sitemap publisher and by every
     * change that has to withdraw the published file (enabling private mode,
     * a plugin lifecycle change).
     *
     * SitemapGenerator::saveTo() decides whether to publish and renames its
     * file into place while holding it; the private-mode save writes the flag
     * and withdraws the copy while holding it. The two can therefore not
     * interleave: either the file is published first and then withdrawn, or
     * the publisher sees the flag and does not publish.
     *
     * Reentrant within a process (flock() on a second handle of the same file
     * would wait for the first one forever). A file that cannot be opened for
     * writing is opened read-only: flock() does not need write access, and the
     * cron and PHP-FPM may run as different users.
     *
     * @return bool false when the lock could not be taken; a publisher must
     *         then not publish
     */
    public static function acquirePublishLock(): bool
    {
        if (self::$lockDepth > 0) {
            self::$lockDepth++;
            return true;
        }

        $path = self::lockPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            SecureLogger::error('[SitemapCache] Publish lock unavailable: storage/cache is missing and cannot be created.');
            return false;
        }

        $created = !is_file($path);
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            $handle = @fopen($path, 'r');
        }
        if ($handle === false) {
            SecureLogger::error('[SitemapCache] Publish lock unavailable: storage/cache/sitemap-publish.lock cannot be opened.');
            return false;
        }
        if ($created) {
            // The cron and the web server may run as different users; both
            // must be able to open it. Its content is never read.
            @chmod($path, 0666);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            SecureLogger::error('[SitemapCache] Publish lock unavailable: flock() failed on storage/cache/sitemap-publish.lock.');
            return false;
        }

        self::$lockHandle = $handle;
        self::$lockDepth = 1;
        return true;
    }

    /** Release one acquirePublishLock(); a no-op when this process holds none. */
    public static function releasePublishLock(): void
    {
        if (self::$lockDepth === 0) {
            return;
        }
        self::$lockDepth--;
        if (self::$lockDepth === 0 && self::$lockHandle !== null) {
            flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
            self::$lockHandle = null;
        }
    }

    /**
     * How many times the published sitemap has been invalidated, or null when
     * that cannot be known.
     *
     * A file, not a cache entry: the cron generator runs under the CLI SAPI and
     * shares no APCu with PHP-FPM, so an in-memory counter would let precisely
     * the two producers that matter disagree. A missing file reads as 0 (no
     * invalidation yet). Null means the counter cannot be trusted to move: the
     * file is unreadable or holds something else, or its directory is missing
     * or read-only so the next bump would fail. A publisher treats null as
     * "changed" and does not publish.
     */
    public static function revision(): ?int
    {
        $path = self::revisionPath();
        $directory = dirname($path);
        clearstatcache(true, $path);
        if (!is_dir($directory) || !is_writable($directory)) {
            return null;
        }
        if (!file_exists($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        return preg_match('/^\d{1,18}$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * Record that the set of public URLs may have changed.
     *
     * Separate from deleting the file, and deliberately bumped even when there
     * was no file to delete: the counter answers "did anything change while you
     * were generating?", and a generation that started before an invalidation
     * is stale whether or not a published file existed at the time.
     *
     * Written to a temporary file and renamed over the counter: that needs
     * write access to the directory only, so a counter created by the cron
     * user can still be bumped by PHP-FPM. Callers hold the publish lock, so
     * two bumps cannot interleave.
     */
    private static function bumpRevision(): bool
    {
        $path = self::revisionPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            return false;
        }

        $current = self::revision();
        // An unreadable counter is replaced by a value no publisher can have
        // read; any change at all is enough, since it is compared for inequality.
        $next = $current === null ? random_int(1, 999_999_999_999) : $current + 1;
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, (string) $next) === false) {
            @unlink($temporary); // nosemgrep: php.lang.security.unlink-use.unlink-use -- fixed internal path
            return false;
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary); // nosemgrep: php.lang.security.unlink-use.unlink-use -- fixed internal path
            return false;
        }
        clearstatcache(true, $path);
        return true;
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

        // Under the publish lock, so a publisher cannot rename its file into
        // place between the bump and the unlink. Not taking it (storage/cache
        // unusable) does not stop the withdrawal: publishers cannot take it
        // either, and do not publish without it.
        $locked = self::acquirePublishLock();
        try {
            // Before the unlink, and unconditionally: a generation already in
            // flight has to learn that its snapshot is stale even in the cases
            // this method returns early from.
            if (!self::bumpRevision()) {
                SecureLogger::error('[SitemapCache] Could not record the sitemap invalidation in storage/cache/sitemap-revision (' . $reason . '): publishers refuse to publish until storage/cache is writable again.');
            }

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
        } finally {
            if ($locked) {
                self::releasePublishLock();
            }
        }

        return false;
    }

    /**
     * Withdraw the published file and report whether a copy is still there.
     *
     * invalidate() answers "did I remove a file?", which is false both when
     * there was nothing to remove and when the unlink failed. Enforcing private
     * mode needs the other question, because a file that survives keeps being
     * served by the web server without PHP: the caller has to tell the admin
     * (or exit non-zero) instead of reporting success.
     *
     * @return bool true when no file remains at the path (absent or removed)
     */
    public static function withdraw(string $reason, ?string $path = null): bool
    {
        $target = $path ?? self::publishedPath();
        self::invalidate($reason, $target);
        clearstatcache(true, $target);

        return !is_file($target);
    }
}
