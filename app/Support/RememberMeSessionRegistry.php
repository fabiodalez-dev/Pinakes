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
 * session instead of starting another. Reading a note, judging it and writing
 * one happen under a single lock, so two requests reaching this at the same
 * instant cannot both decide the note is theirs to write: one publishes, the
 * other is told which session won and joins that one.
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

    /**
     * The file every read and write of a note is serialised on.
     *
     * One lock for the registry rather than one per token: it is held for the
     * length of opening a 43-byte file, reading it and possibly rewriting it,
     * and the only requests that ever contend for it are simultaneous
     * remembered sign-ins. A per-token lock file would be finer-grained and
     * worse — it would need sweeping like any other file, and sweeping a lock
     * somebody is holding hands the next request a different inode, which is
     * no lock at all. The leading dot keeps it out of the sweep's glob.
     */
    private const LOCK_FILE = '.lock';

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

        $lock = self::lock(LOCK_SH);
        try {
            $raw = @file_get_contents($path);

            return is_string($raw) ? self::readNote($raw) : null;
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Publish the session this request created, and say which one siblings
     * should join from here on.
     *
     * Returns this request's own id when it got there first, the id already
     * published when it did not, and null when nothing could be written. The
     * caller compares the answer against the session it is sitting in and
     * joins the winner, so a burst that loses the race by microseconds still
     * ends in one session rather than two.
     *
     * The first writer wins rather than the last. Overwriting a live note
     * would let two requests publish different ids in turn, and a third
     * sibling would then join whichever happened to be on disk when it looked.
     */
    public static function claim(string $tokenPlain, string $sessionId): ?string
    {
        if (!self::isWellFormedSessionId($sessionId)) {
            return null;
        }
        $path = self::pathFor($tokenPlain);
        if ($path === null) {
            return null;
        }

        $lock = self::lock(LOCK_EX);
        try {
            $raw = @file_get_contents($path);
            $published = is_string($raw) ? self::readNote($raw) : null;
            if ($published !== null) {
                return $published;
            }

            // Nothing worth joining: no note at all, or one past its window,
            // malformed, or truncated by a process that died mid-write. Any
            // of those belongs to no session, so this request takes the name.
            if (@file_put_contents($path, $sessionId . '|' . time()) === false) {
                return null;
            }
            @chmod($path, 0600);
            $published = $sessionId;
        } finally {
            self::unlock($lock);
        }

        // A note is worth keeping for seconds, so without a sweep the
        // directory would grow by one file per remembered sign-in and never
        // shrink. Done on a fraction of writes, the way PHP collects its own
        // sessions: the cost is spread out and no request pays for a scan it
        // did not cause. Outside the lock, because it is nobody's critical
        // section — a note it removes is already being treated as absent.
        if (random_int(1, self::SWEEP_ONE_WRITE_IN) === 1) {
            self::sweep(dirname($path));
        }

        return $published;
    }

    /**
     * The session id a note names, when it is still worth joining.
     *
     * Everything this refuses — a malformed id, a timestamp that is not one, a
     * note past its window, a half-written file — comes back as null, which
     * the callers read as "nothing to join". That is deliberate: the value
     * decides which session a request is put into, so anything not recognised
     * is treated as absent rather than repaired or guessed at.
     */
    private static function readNote(string $raw): ?string
    {
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
     * Take the registry lock, or carry on without it.
     *
     * A host where flock() does not work is a host where PHP's own file
     * session handler does not lock either, so refusing to work at all here
     * would disable joining on an installation whose sessions are already
     * racing. Best effort is the right answer: without the lock this is what
     * it was before the lock existed, which is a narrower race than the one
     * the whole class exists to close.
     *
     * @return resource|null
     */
    private static function lock(int $mode)
    {
        $directory = self::directory();
        if ($directory === null) {
            return null;
        }

        $handle = @fopen($directory . '/' . self::LOCK_FILE, 'c');
        if ($handle === false) {
            return null;
        }
        if (!@flock($handle, $mode)) {
            @fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource|null $handle */
    private static function unlock($handle): void
    {
        if ($handle === null) {
            return;
        }
        @flock($handle, LOCK_UN);
        @fclose($handle);
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

        $lock = self::lock(LOCK_EX);
        try {
            if (!file_exists($path)) {
                return true;
            }

            return @unlink($path);
        } finally {
            self::unlock($lock);
        }
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
