<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Where Digital Library files live, and the move of the old ones (issue #480).
 *
 * Files are kept in storage/digital/, outside the web root; their URL stays
 * /uploads/digital/<name> and ProtectedUploadController serves them after asking
 * DigitalAccess. Before 0.8.6 they were in public/uploads/digital/: this class moves
 * them across, safely and idempotently. That folder needs no rule of its own: public/.htaccess
 * sends every /uploads/digital/ request to Pinakes, whether or not the file is there.
 * Paths are parameters so the move can be tested on scratch folders.
 */
final class DigitalStorage
{
    /** Marker written once the old folder holds no file left to move (its content: that folder's mtime). */
    public const MIGRATED_MARKER = '.migrated-from-public';

    /** A copy on its way in: never served (the name starts with a dot) and finished atomically. */
    private const PARTIAL_PREFIX = '.partial-';

    /**
     * Files a run could not move (name => "size:mtime"). A page request skips them while
     * they are unchanged, so an old folder the web server cannot write to, or a clash,
     * costs one failed attempt instead of a re-hash on every page; the maintenance cron,
     * the activation of the plugin and "Sposta ora" try them again.
     */
    public const FAILED_REGISTRY = '.migration-failed.json';

    /** Across disks a file is copied: during a page request only this many bytes in total. */
    public const REQUEST_COPY_BUDGET = 64 * 1024 * 1024;

    /**
     * Tests only: replaces the first rename() of a move, so the cross-disk copy path (two
     * Docker volumes) can be exercised on one disk. Null in production.
     *
     * @var (\Closure(string, string): bool)|null
     */
    public static ?\Closure $renameForTests = null;

    /** storage/digital under the installation root. */
    public static function targetDir(?string $root = null): string
    {
        return ($root ?? dirname(__DIR__, 2)) . '/storage/digital';
    }

    /** public/uploads/digital under the installation root: where files lived before 0.8.6. */
    public static function legacyDir(?string $root = null): string
    {
        return ($root ?? dirname(__DIR__, 2)) . '/public/uploads/digital';
    }

    /**
     * Create $dir with the mode of the other writable folders (0775, as Installer and
     * Updater do) and shut it to direct requests.
     */
    public static function ensureDir(string $dir): string
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create ' . $dir);
            }
            // mkdir() applies the umask (0755 under the usual 022): set the mode the
            // installer and the updater give every writable folder.
            @chmod($dir, 0775);
        } else {
            // An upgrade through an older updater creates the folder by copying
            // storage/digital/.gitkeep from the package, with mkdir()'s 0755: add the
            // group bits the other writable folders have (ug+rwX, as setup-permissions.sh
            // does), never the "other" bits, so a stricter choice of the administrator stays.
            // Fresh mode, not the stat cache: PHP 8.2's chmod() does not clear it.
            clearstatcache(true, $dir);
            $perms = @fileperms($dir);
            if ($perms !== false && ($perms & 0770) !== 0770) {
                @chmod($dir, ($perms & 0777) | 0770);
            }
        }
        // storage/.htaccess already denies the whole tree; this one keeps the folder
        // shut even when it is copied elsewhere or storage/.htaccess goes missing.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', self::denyAllHtaccess());
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }

    /**
     * No deny rule in the old folder: Apache and LiteSpeed apply a folder's .htaccess to
     * every URL that maps into it BEFORE public/.htaccess rewrites the request to Pinakes,
     * so "Require all denied" there refused every Digital Library download, the allowed
     * ones too. Pre-release builds wrote exactly that file; it is removed, and an .htaccess
     * with any other content (the administrator's own) is left alone.
     */
    public static function releaseLegacyDir(string $legacy): void
    {
        $htaccess = $legacy . '/.htaccess';
        if (is_file($htaccess) && (string) @file_get_contents($htaccess) === self::denyAllHtaccess()) {
            @unlink($htaccess);
        }
    }

    public static function denyAllHtaccess(): string
    {
        return implode("\n", [
            '# Digital Library files are served by Pinakes, which checks who may open them.',
            'Options -Indexes',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Order Deny,Allow',
            '    Deny from all',
            '</IfModule>',
            '',
        ]);
    }

    /**
     * Done while the old folder is unchanged since the last complete move. A file put back
     * there later (a backup from before 0.8.6 restored, a copy by hand) changes the folder's
     * mtime and the move runs again; a stat and a tiny read per request decide.
     */
    public static function migrationDone(string $legacy, string $target): bool
    {
        $marker = $target . '/' . self::MIGRATED_MARKER;
        if (!is_file($marker)) {
            return false;
        }
        if (!is_dir($legacy)) {
            return true;
        }
        clearstatcache(true, $legacy);
        if (trim((string) @file_get_contents($marker)) === (string) @filemtime($legacy)) {
            return true;
        }
        // The folder changed (or the stamp was too fresh to trust): look for real. A file
        // remembered as failed, and unchanged since, is the cron's: it does not reopen the move.
        if (self::hasMovableFile($legacy, self::failedRegistry($target))) {
            return false;
        }
        self::writeMarker($legacy, $target);
        return true;
    }

    /**
     * The marker holds the old folder's mtime, but only once that mtime is two seconds old:
     * mtime has one-second resolution, so a file arriving in the same second as the last
     * change would otherwise leave it unchanged and never be moved. A fresh folder gets
     * "recheck", and the next request looks at the folder itself.
     */
    private static function writeMarker(string $legacy, string $target): void
    {
        clearstatcache(true, $legacy);
        $mtime = is_dir($legacy) ? (int) @filemtime($legacy) : 0;
        $stamp = !is_dir($legacy) ? 'absent' : (time() - $mtime >= 2 ? (string) $mtime : 'recheck');
        @file_put_contents($target . '/' . self::MIGRATED_MARKER, $stamp . "\n");
    }

    /** @param array<string, string> $knownFailures name => "size:mtime" */
    private static function hasMovableFile(string $dir, array $knownFailures = []): bool
    {
        foreach (new \DirectoryIterator($dir) as $entry) {
            $name = $entry->getFilename();
            if (!$entry->isDot() && !$entry->isLink() && $entry->isFile() && !str_starts_with($name, '.') && $name !== 'index.html'
                && ($knownFailures[$name] ?? null) !== $entry->getSize() . ':' . $entry->getMTime()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Move the old files across:
     *   - rename() when both folders are on the same disk (instant, atomic);
     *   - otherwise copy to a hidden partial name in the target folder, compare size and
     *     SHA-256 with the original, rename the copy into place, then delete the original;
     *   - a target that already holds the same bytes (an interrupted earlier run) only
     *     costs the original; a target with DIFFERENT bytes is never overwritten: the file
     *     stays where it is, still served, and the conflict is logged;
     *   - a copy larger than $copyBudget waits for a run with room for it (the cron);
     *     comparing an identical leftover reads both files, so it counts against it too;
     *   - a file that fails is remembered: with $retryFailed false (page requests) it is
     *     skipped while unchanged, and the marker no longer waits for it;
     *   - one run at a time (lock file), and the marker only once nothing is left.
     *
     * @return array{moved:int, remaining:int, failed:int}
     */
    public static function migrate(string $legacy, string $target, int $maxFiles, float $maxSeconds, int $copyBudget = PHP_INT_MAX, bool $retryFailed = true): array
    {
        self::ensureDir($target);
        $result = ['moved' => 0, 'remaining' => 0, 'failed' => 0];
        if (!is_dir($legacy)) {
            self::writeMarker($legacy, $target);
            return $result;
        }
        self::releaseLegacyDir($legacy);

        $lock = @fopen($target . '/.migration.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            $result['remaining'] = 1; // another run is moving them right now
            return $result;
        }

        try {
            $started = microtime(true);
            $copied = 0;
            $known = self::failedRegistry($target);
            $registry = [];
            foreach (new \DirectoryIterator($legacy) as $entry) {
                $name = $entry->getFilename();
                if ($entry->isDot() || $entry->isLink() || !$entry->isFile() || str_starts_with($name, '.') || $name === 'index.html') {
                    continue;
                }
                $stamp = $entry->getSize() . ':' . $entry->getMTime();
                if (!$retryFailed && ($known[$name] ?? null) === $stamp) {
                    $registry[$name] = $stamp; // still served, checked, from the old folder
                    continue;
                }
                if ($result['moved'] >= $maxFiles || microtime(true) - $started > $maxSeconds) {
                    $result['remaining']++;
                    if (isset($known[$name])) {
                        $registry[$name] = $known[$name];
                    }
                    continue;
                }
                $outcome = self::moveOne($entry->getPathname(), $target . '/' . $name, $copyBudget - $copied);
                if (is_int($outcome)) {
                    $result['moved']++;
                    $copied += $outcome;
                } elseif ($outcome === 'deferred') {
                    $result['remaining']++;
                } else {
                    $result['failed']++;
                    clearstatcache(true, $entry->getPathname());
                    $registry[$name] = @filesize($entry->getPathname()) . ':' . @filemtime($entry->getPathname());
                }
            }
            if ($registry !== $known) {
                self::writeFailedRegistry($target, $registry);
            }
            if ($result['remaining'] === 0 && $result['failed'] === 0) {
                self::writeMarker($legacy, $target);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        if ($result['moved'] > 0 || $result['failed'] > 0) {
            SecureLogger::info('[Digital Library] moved files to storage/digital', $result);
        }
        return $result;
    }

    /** How many files the last runs could not move (they wait for the cron or "Sposta ora"). */
    public static function knownFailures(string $target): int
    {
        return count(self::failedRegistry($target));
    }

    /** @return array<string, string> */
    private static function failedRegistry(string $target): array
    {
        $raw = @file_get_contents($target . '/' . self::FAILED_REGISTRY);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        return array_filter($data, static fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<string, string> $registry */
    private static function writeFailedRegistry(string $target, array $registry): void
    {
        $file = $target . '/' . self::FAILED_REGISTRY;
        if ($registry === []) {
            @unlink($file);
            return;
        }
        ksort($registry);
        @file_put_contents($file, (string) json_encode($registry, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** @return int files in $dir that a reader could be served (dotfiles and index.html aside) */
    public static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isFile() && !str_starts_with($entry->getFilename(), '.') && $entry->getFilename() !== 'index.html') {
                $n++;
            }
        }
        return $n;
    }

    /**
     * One file. Returns the bytes copied or compared (0 for a rename) when it was moved,
     * 'deferred' when a cross-disk copy or a comparison does not fit the budget, 'failed'
     * otherwise.
     *
     * @return int|'deferred'|'failed'
     */
    private static function moveOne(string $from, string $to, int $copyBudget): int|string
    {
        $size = @filesize($from);
        if ($size === false) {
            return 'failed';
        }
        if (is_file($to)) {
            if (@filesize($to) === $size) {
                // Comparing reads both files: a page request leaves a large one to the cron.
                if ($size > $copyBudget) {
                    return 'deferred';
                }
                if (@hash_file('sha256', $to) === @hash_file('sha256', $from)) {
                    if (@unlink($from)) {
                        return $size;
                    }
                    SecureLogger::error('[Digital Library] moved to storage/digital, but the original cannot be removed: check the permissions of public/uploads/digital', [
                        'file' => basename($from),
                    ]);
                    return 'failed';
                }
            }
            SecureLogger::error('[Digital Library] storage/digital already holds a different file with this name; left in place', [
                'file' => basename($from),
            ]);
            return 'failed';
        }
        $renamed = self::$renameForTests !== null ? (self::$renameForTests)($from, $to) : @rename($from, $to);
        if ($renamed) {
            @chmod($to, 0644);
            return 0;
        }
        // Different disk (EXDEV, e.g. two Docker volumes): copy, verify, then swap in.
        if ($size > $copyBudget) {
            return 'deferred';
        }
        $partial = dirname($to) . '/' . self::PARTIAL_PREFIX . basename($to);
        @unlink($partial);
        if (!@copy($from, $partial)) {
            @unlink($partial);
            return 'failed';
        }
        if (@filesize($partial) !== $size || @hash_file('sha256', $partial) !== @hash_file('sha256', $from)) {
            @unlink($partial);
            SecureLogger::error('[Digital Library] copy to storage/digital did not match the original; left in place', [
                'file' => basename($from),
            ]);
            return 'failed';
        }
        @chmod($partial, 0644);
        if (!@rename($partial, $to)) {
            @unlink($partial);
            return 'failed';
        }
        // The copy is in place and verified: removing the original is the last step. If it
        // fails (an old folder the web server cannot write to) this is a failure like any
        // other, remembered: the next run finds identical bytes on both sides and finishes it.
        if (@unlink($from)) {
            return $size;
        }
        SecureLogger::error('[Digital Library] copied to storage/digital, but the original cannot be removed: check the permissions of public/uploads/digital', [
            'file' => basename($from),
        ]);
        return 'failed';
    }
}
