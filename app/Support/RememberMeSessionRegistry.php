<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Keeps concurrent remember-me sign-ins inside one session.
 *
 * A browser that comes back carrying only the remember-me cookie asks for
 * several things at once: the page, and whatever the page fetches for itself.
 * Every one of those requests arrives without a session, every one of them
 * authenticates from the same cookie, and — before this existed — every one of
 * them minted its own session with its own CSRF token. The browser keeps the
 * last `Set-Cookie` it receives, so the token printed into the HTML could
 * belong to a session that no longer has anything to do with it, and the
 * visitor's first form submission was refused with "Errore di Sicurezza".
 *
 * Measured on a real page load: about half the time the dashboard's own
 * `/api/stats/active-loans-count` request raced the document and created a
 * second session; the token in the page and the token in the surviving session
 * then disagreed, and the next POST returned 403.
 *
 * So the first request to authenticate a given token writes down the session
 * it created, and any sibling that arrives while that note is fresh joins that
 * session instead of starting another.
 *
 * Why a file and not APCu: the note has to be visible to whichever worker
 * handles the sibling request, and to CLI too. This project already reached
 * the same conclusion for the sitemap revision counter, for the same reason.
 *
 * What is written is a session id, never the remember-me token: the file is
 * named after a hash of the token, and holds only the id and a timestamp.
 */
class RememberMeSessionRegistry
{
    /**
     * How long a note is worth joining. It only has to span the burst of
     * requests one page load makes — seconds, not minutes. Past that the note
     * is treated as absent, so a later visit starts cleanly rather than being
     * pulled into a session from an earlier one.
     */
    private const FRESH_FOR_SECONDS = 30;

    /** How often a write also tidies up. */
    private const SWEEP_ONE_WRITE_IN = 50;

    /**
     * PHP session ids as the default handler produces them. Anything else is
     * refused rather than handed to session_id(): this value decides which
     * session a request joins, so it is never taken on trust.
     */
    private const SESSION_ID_PATTERN = '/^[A-Za-z0-9,\-]{16,128}$/';

    private static ?string $directoryOverride = null;

    /** Point the registry somewhere else. For tests. */
    public static function useDirectory(?string $directory): void
    {
        self::$directoryOverride = $directory;
    }

    /**
     * The session a sibling request has already created for this token, or
     * null when there is none worth joining.
     */
    public static function lookup(string $tokenPlain): ?string
    {
        $path = self::pathFor($tokenPlain);
        if ($path === null) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $parts = explode('|', trim($raw), 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$sessionId, $writtenAt] = $parts;

        if (!self::isWellFormedSessionId($sessionId)) {
            return null;
        }
        if (!ctype_digit($writtenAt) || (time() - (int) $writtenAt) > self::FRESH_FOR_SECONDS) {
            return null;
        }

        return $sessionId;
    }

    /**
     * Write down the session this request created. Overwrites a note that is
     * already there: the newest sign-in is the one siblings should join.
     */
    public static function remember(string $tokenPlain, string $sessionId): bool
    {
        if (!self::isWellFormedSessionId($sessionId)) {
            return false;
        }
        $path = self::pathFor($tokenPlain);
        if ($path === null) {
            return false;
        }

        $line = $sessionId . '|' . time();
        $written = @file_put_contents($path, $line, LOCK_EX) !== false;

        // A note is worth keeping for seconds, so without a sweep the
        // directory would grow by one file per remembered sign-in and never
        // shrink. Done on a fraction of writes, the way PHP collects its own
        // sessions: the cost is spread out and no request pays for a scan it
        // did not cause.
        if ($written && random_int(1, self::SWEEP_ONE_WRITE_IN) === 1) {
            self::sweep(dirname($path));
        }

        return $written;
    }

    /**
     * Delete notes that are past the window. Failures are ignored on purpose:
     * a note that cannot be removed is already being treated as absent, and a
     * housekeeping error must not affect the request that triggered it.
     */
    private static function sweep(string $directory): void
    {
        $cutoff = time() - self::FRESH_FOR_SECONDS;
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $modified = @filemtime($file);
            if (is_int($modified) && $modified < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Drop the note — on sign-out, or when the token is revoked. A missing
     * note is success: there is nothing left to join.
     */
    public static function forget(string $tokenPlain): bool
    {
        $path = self::pathFor($tokenPlain);
        if ($path === null) {
            return false;
        }
        if (!file_exists($path)) {
            return true;
        }
        return @unlink($path);
    }

    public static function isWellFormedSessionId(string $sessionId): bool
    {
        return preg_match(self::SESSION_ID_PATTERN, $sessionId) === 1;
    }

    /**
     * Named after a hash of the token so the directory never holds anything
     * that could be replayed as a credential.
     */
    private static function pathFor(string $tokenPlain): ?string
    {
        if ($tokenPlain === '') {
            return null;
        }
        $directory = self::directory();
        if ($directory === null) {
            return null;
        }
        return $directory . '/' . hash('sha256', $tokenPlain);
    }

    private static function directory(): ?string
    {
        $directory = self::$directoryOverride
            ?? dirname(__DIR__, 2) . '/storage/tmp/remember-session';

        if (is_dir($directory)) {
            return is_writable($directory) ? $directory : null;
        }
        if (!@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return null;
        }
        return is_writable($directory) ? $directory : null;
    }
}
