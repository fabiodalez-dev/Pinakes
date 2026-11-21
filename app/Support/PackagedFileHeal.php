<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Files the release ships inside a directory the updater preserves, that are
 * code and not data, so they must follow the release.
 *
 * copyDirectory() keeps an existing copy of everything under a preserved path
 * (storage/calendar holds the operator's own .ics files), so a changed
 * storage/calendar/.htaccess never reaches an upgraded install by itself. Two
 * places write it:
 *
 *  - Updater::healPreservedPackagedFiles(), right after the copy, from the
 *    package. That is the NEW Updater: an in-app upgrade runs the OLD one,
 *    already in memory, which knows nothing of it;
 *  - PluginManager's once-per-version maintenance block, from FILES below: the
 *    first request under the new code, for every way of upgrading.
 *
 * FILES carries the shipped content so the runtime heal has something to
 * write once the package is gone. tests/packaged-file-heal-runtime.unit.php
 * pins every entry to the file in the tree, so the two cannot drift.
 */
final class PackagedFileHeal
{
    public const WRITTEN = 'written';
    public const UNCHANGED = 'unchanged';
    public const DIR_MISSING = 'dir_missing';
    public const SYMLINK = 'symlink';
    public const WRITE_FAILED = 'write_failed';

    /** storage/calendar/.htaccess stopped granting every .ics file after 0.9.5. */
    private const CALENDAR_HTACCESS = <<<'HTACCESS_EOF'
# Calendar files (.ics) under storage/calendar/
#
# The legacy subscription URL /storage/calendar/library-calendar.ics is NOT
# served from this directory: on installs whose document root is the project
# root, the root .htaccess rewrites it to public/, where the application
# answers it (like /calendar/events.ics) so private mode applies to it. On
# docroot=public installs storage/ is outside the web root.
#
# The grant below only lets that request pass the parent storage/.htaccess
# deny long enough for the root rewrite to run (Apache checks access before
# per-directory rewrites). Without mod_rewrite there is no such rewrite, so the
# file is denied instead of being served statically.

# Apache 2.4
<IfModule mod_authz_core.c>
    <IfModule mod_rewrite.c>
        <FilesMatch "\.ics$">
            Require all granted
        </FilesMatch>
    </IfModule>
</IfModule>

# Apache 2.2 (fallback)
<IfModule !mod_authz_core.c>
    <IfModule mod_rewrite.c>
        <FilesMatch "\.ics$">
            Order Allow,Deny
            Allow from all
        </FilesMatch>
    </IfModule>
</IfModule>

# Set correct MIME type for iCalendar files
<IfModule mod_mime.c>
    AddType text/calendar .ics
</IfModule>

# Prevent directory listing
Options -Indexes

HTACCESS_EOF;

    /** @var array<string, string> relative path => the content the release ships */
    public const FILES = [
        'storage/calendar/.htaccess' => self::CALENDAR_HTACCESS,
    ];

    /**
     * Write $content over $target when it is missing or differs. A symlink in
     * its place is replaced, never written through; the write is a temp file
     * and a rename, so a reader never sees half a file.
     */
    public static function write(string $target, string $content): string
    {
        if (!is_link($target) && is_file($target) && @file_get_contents($target) === $content) {
            return self::UNCHANGED;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return self::DIR_MISSING;
        }
        if (is_link($target) && !@unlink($target)) {
            return self::SYMLINK;
        }
        $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            return self::WRITE_FAILED;
        }
        @chmod($target, 0644);

        return self::WRITTEN;
    }

    /**
     * Bring every file of FILES under $root to the shipped content.
     *
     * @return array<string, string> relative path => status, only for the files that could not be healed
     */
    public static function healAll(?string $root = null): array
    {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/\\');
        $failed = [];
        foreach (self::FILES as $relative => $content) {
            $status = self::write($root . '/' . $relative, $content);
            if ($status !== self::WRITTEN && $status !== self::UNCHANGED) {
                $failed[$relative] = $status;
            }
        }

        return $failed;
    }
}
