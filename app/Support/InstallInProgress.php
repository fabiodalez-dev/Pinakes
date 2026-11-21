<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Tells the command-line entry points (cron, maintenance scripts) that the web
 * installer is in the middle of an install or reinstall.
 *
 * From step 2 the installer has written a .env that points at a new, empty
 * database, and on a reinstall .installed is still there, so a cron run looks
 * like a normal run against an installed site. Anything it creates in that
 * database (EmailOutboxSchema::ensure(), for one) lands before the schema
 * import of step 3, whose plain CREATE TABLE then fails on the stray table and
 * leaves the install stuck. The scripts therefore skip their run while the
 * installer's ownership marker (storage/.install_in_progress, written by
 * Installer::claimInstallOwnership() and removed when the install finishes or
 * is refused) is live.
 *
 * The installer directory is deleted after a successful install, so this reads
 * the marker itself instead of loading installer/classes/Installer.php. The
 * marker is "<sha256 of the owner token>\n<claim time>"; a marker without a
 * claim time falls back to its mtime. A marker older than ABSOLUTE_TTL is one
 * the installer itself would discard, and blocks nothing.
 */
final class InstallInProgress
{
    /** Same value as Installer::INSTALL_OWNER_ABSOLUTE_TTL (a test keeps them equal). */
    public const ABSOLUTE_TTL = 86400;

    public const MARKER = 'storage/.install_in_progress';

    public static function active(string $projectRoot, ?int $now = null): bool
    {
        $marker = rtrim($projectRoot, '/') . '/' . self::MARKER;
        clearstatcache(true, $marker);
        if (is_link($marker) || !is_file($marker)) {
            return false;
        }
        $claimed = null;
        $content = @file_get_contents($marker);
        if (is_string($content)) {
            $lines = preg_split('/\R/', trim($content)) ?: [];
            if (isset($lines[1]) && ctype_digit(trim($lines[1]))) {
                $claimed = (int) trim($lines[1]);
            }
        }
        if ($claimed === null) {
            $mtime = @filemtime($marker);
            if ($mtime === false) {
                // Present but unreadable: an install may be running, stay out.
                return true;
            }
            $claimed = $mtime;
        }
        return (($now ?? time()) - $claimed) <= self::ABSOLUTE_TTL;
    }
}
