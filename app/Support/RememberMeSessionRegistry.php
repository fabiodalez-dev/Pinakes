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
 * session instead of starting another. The note is published in one step and
 * only where there is none already, so the whole burst agrees on one session
 * even when two of its requests reach this at the same instant: the loser is
 * told who won and joins that one rather than keeping its own.
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

        // Two passes at most. The first can lose to a note that turns out to
        // be past its window, and clearing that earns exactly one more try;
        // losing to a live one needs no retry, because the next read returns
        // it and that is the answer.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $published = self::lookup($tokenPlain);
            if ($published !== null) {
                return $published;
            }

            if (self::publish($path, $sessionId)) {
                if (random_int(1, self::SWEEP_ONE_WRITE_IN) === 1) {
                    self::sweep(dirname($path));
                }

                return $sessionId;
            }

            // The publish failed, so something is in the way. Read it again
            // before doing anything to it: the read at the top of this pass
            // happened before it existed, so "nothing there" and "a sibling
            // published a moment ago" arrived as the same answer, and acting
            // on the first reading would delete the note that just won.
            $published = self::lookup($tokenPlain);
            if ($published !== null) {
                return $published;
            }

            // Still nothing a reader will accept, and yet something occupies
            // the name: stale, malformed, or truncated by a process that died
            // mid-write. It belongs to no session, so it goes.
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        return self::lookup($tokenPlain);
    }

    /**
     * Put a complete note in place, and only where there is no note already.
     *
     * Writing straight to the destination is what makes a reader able to see a
     * half-written one: `file_put_contents()` opens with `wb`, which truncates
     * at open — before `LOCK_EX` is taken — so the window between the empty
     * file and the finished note is visible to anybody reading in it. The note
     * is therefore built off to the side and only then linked into place.
     */
    private static function publish(string $path, string $sessionId): bool
    {
        $directory = dirname($path);
        $temporary = @tempnam($directory, '.note-');
        if ($temporary === false) {
            return false;
        }

        if (@file_put_contents($temporary, $sessionId . '|' . time()) === false) {
            @unlink($temporary);

            return false;
        }
        @chmod($temporary, 0600);

        // link() publishes something already complete and refuses to replace
        // what is there: both halves of what this needs. Where it is missing —
        // some shared hosts disable it — rename() still publishes in one step,
        // so no reader ever sees a partial note, but it overwrites: on those
        // installations the last request of a burst wins instead of the first.
        if (function_exists('link')) {
            $linked = @link($temporary, $path);
            @unlink($temporary);

            return $linked;
        }

        if (@rename($temporary, $path)) {
            return true;
        }
        @unlink($temporary);

        return false;
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
