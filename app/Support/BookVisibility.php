<?php
declare(strict_types=1);
namespace App\Support;

/** Keeps requests out of the public holdings catalogue, even after plugin deactivation. */
final class BookVisibility
{
    /**
     * The PUBLIC-catalogue predicate. It belongs on reads that answer to a
     * visitor or a member — the front end, the public and mobile APIs, the
     * interop protocols, sitemaps, feeds, patron dashboards and favourites.
     *
     * It must NOT be used on operator surfaces. An admin has to be able to
     * find, list, count and edit a wanted title; /admin/books, the DataTables
     * totals, the admin dashboard card and the header quick-stat therefore all
     * stay unfiltered, and filtering only some of them makes two numbers on the
     * same screen disagree with nothing in the UI to account for it.
     *
     * Where one method serves both audiences, take a default-permissive boolean
     * and pass true from the public entry point only — see
     * OpereRepository::editionsForOpera() and IcsGenerator::generate().
     *
     * Returns the literal 1=1 when the column is absent, so every caller can
     * concatenate it unconditionally.
     */
    public static function catalogue(\mysqli $db, string $alias = 'libri'): string
    {
        return self::hasDesiderata($db) ? self::alias($alias) . '.is_desiderata = 0' : '1=1';
    }

    /**
     * The SEARCH-and-DETAIL predicate: what a visitor may find by asking for
     * it by name and open by link. Identical to catalogue() unless a plugin
     * widens it through the `book.visibility.discoverable` filter — the
     * desiderata plugin answers '1=1' while active, so a wanted title shows
     * up (badged) in search results and on its own page. Browse, feeds,
     * sitemap, the mobile API and the interop protocols keep catalogue().
     *
     * The '1=1' allow-list below is a SECURITY control, not a style choice:
     * the return value is concatenated straight into WHERE clauses, so a
     * handler must be able to widen the predicate to "everything" and to
     * nothing else. Any other string — including a plausible-looking
     * "1=1 OR l.id > 0" — is discarded and the catalogue predicate stands,
     * which is why a filter can never smuggle SQL through here.
     */
    public static function discoverable(\mysqli $db, string $alias = 'libri'): string
    {
        $default = self::catalogue($db, $alias);
        $filtered = \App\Support\Hooks::apply('book.visibility.discoverable', $default, [$db, $alias]);

        return $filtered === '1=1' ? '1=1' : $default;
    }

    /**
     * The mirror image of catalogue(): "this row is a request, not a holding".
     *
     * It exists for the two harvesting protocols, which owe their subscribers a
     * DELETION when a record they already took stops being published — flagging
     * a harvested book as wanted is a withdrawal, and silence would leave a
     * stale copy in every remote catalogue forever. Expressing it here keeps the
     * column probe in one place instead of each protocol re-deriving it.
     *
     * Returns the literal 0=1 when the column is absent, so the extra UNION arm
     * an installation without the plugin carries is inert and costs nothing.
     */
    public static function delisted(\mysqli $db, string $alias = 'libri'): string
    {
        return self::hasDesiderata($db) ? self::alias($alias) . '.is_desiderata = 1' : '0=1';
    }

    /**
     * "This row was once in the public catalogue" — the other half delisted()
     * cannot supply on its own.
     *
     * A harvester is owed a deletion for a record it actually received. But
     * is_desiderata = 1 is reached two ways — a catalogued book WITHDRAWN, and
     * a book BORN as a request — and the flag keeps no memory of which, because
     * the copies are hard-deleted and nothing else records the transition. Used
     * alone it publishes tombstones for wishes no harvester ever saw, and with
     * them the ids and timestamps of the library's wish list.
     *
     * libri.catalogued_at is stamped once, the first time a row is written with
     * is_desiderata = 0, and never cleared. Pair this with delisted() to get
     * "withdrawn" rather than "wanted".
     *
     * Returns 0=1 when the column is absent, so an installation that has not
     * run the plugin's ensureSchema() emits no tombstones at all. That is the
     * deliberate direction: a missing deletion leaves one stale record in a
     * remote catalogue, while a wrong one publishes a wish that was never
     * public.
     */
    public static function everCatalogued(\mysqli $db, string $alias = 'libri'): string
    {
        return self::hasCataloguedAt($db) ? self::alias($alias) . '.catalogued_at IS NOT NULL' : '0=1';
    }

    /**
     * The write-once stamp, as a SET fragment: empty when the column is absent,
     * so a caller can concatenate it onto an UPDATE unconditionally.
     *
     * COALESCE is what makes it write-once. It is spliced into the same
     * statement that clears the flag rather than issued as a second query, so
     * a row can never be un-flagged without being stamped — the two facts stay
     * in step even if the caller is interrupted between statements.
     */
    public static function catalogueStamp(\mysqli $db, string $column = 'catalogued_at'): string
    {
        if (!self::hasCataloguedAt($db)) {
            return '';
        }
        $column = self::alias($column);

        return ', ' . $column . ' = COALESCE(' . $column . ', NOW())';
    }

    /**
     * The same stamp for a row being CREATED, as a matched column/value pair to
     * splice into an INSERT: [', catalogued_at', ', NOW()'] for a row born in
     * the catalogue, or ['', ''] for a row born as a request or on an
     * installation without the column.
     *
     * The rule it enforces is not a new one — the plugin's activation backfill
     * (`SET catalogued_at = NOW() WHERE is_desiderata = 0 AND catalogued_at IS
     * NULL`) and BookRepository::create() already state it independently: a row
     * that is born visible in the catalogue carries the stamp. The backfill is
     * also what hides a violation, because it runs once and repairs the whole
     * history, so only rows created AFTER activation stay unstamped — and an
     * unstamped row that is later withdrawn produces no OAI-PMH tombstone at
     * all, leaving harvesters holding a record the library has retired.
     *
     * Returned as a pair rather than two calls so the column and its value
     * cannot drift apart: there is no way to add one without the other.
     *
     * NOW() is a literal, not a bound parameter, so the value comes from the
     * same MySQL clock as created_at/updated_at — see the note in
     * BookRepository::create().
     *
     * @return array{0: string, 1: string}
     */
    public static function catalogueBirth(\mysqli $db, bool $wanted = false): array
    {
        if ($wanted || !self::hasCataloguedAt($db)) {
            return ['', ''];
        }

        return [', catalogued_at', ', NOW()'];
    }

    /** Same memoised probe as hasDesiderata(); see the note on its docblock. */
    public static function hasCataloguedAt(\mysqli $db): bool
    {
        static $columns;
        static $seenColumn = false;
        $columns ??= new \WeakMap();
        if (!isset($columns[$db])) {
            // The underscore is escaped because LIKE reads a bare _ as "any one
            // character"; the plugin spells the same probe this way too.
            $reason = '';
            try {
                $result = $db->query("SHOW COLUMNS FROM libri LIKE 'catalogued\\_at'");
                if ($result === false) {
                    $reason = $db->error;
                }
            } catch (\Throwable $e) {
                // Off the exception, not the connection: see hasDesiderata().
                $result = false;
                $reason = $e->getMessage();
            }
            if ($result === false) {
                // Not silent: when this answers "absent", everCatalogued()
                // becomes 0=1 and every de-listing tombstone on OAI-PMH and
                // ResourceSync disappears, so harvesters stop receiving
                // deletions. The log line is the only trace of why.
                \App\Support\SecureLogger::error(
                    $seenColumn
                        ? 'BookVisibility: cannot probe libri.catalogued_at; keeping the tombstones on, the column was seen earlier in this worker'
                        : 'BookVisibility: cannot probe libri.catalogued_at; de-listing tombstones are degraded for this call',
                    ['error' => $reason]
                );
                return $seenColumn;
            }
            $columns[$db] = $result->num_rows > 0;
            if ($columns[$db]) {
                $seenColumn = true;
            }
        }

        return $columns[$db];
    }

    private static function alias(string $alias): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid SQL alias');
        }
        return $alias;
    }

    /**
     * Is the desiderata column present on this connection?
     *
     * The answer is memoised per connection and never invalidated, which looks
     * unsafe next to a plugin whose activation runs the ALTER TABLE mid-request:
     * a caller that asked BEFORE the column existed would keep being told "no"
     * afterwards, and the visibility filter would be off for the rest of that
     * request. It was checked rather than assumed, and the window does not
     * exist. Both memos are function statics, which PHP-FPM discards at the end
     * of every request, and the WeakMap is keyed on a connection that dies with
     * it — so the blast radius is one request at most. That request is the
     * activation endpoint, which answers bare JSON and consults nothing here
     * before the ALTER. Recorded so the next reader does not have to re-derive
     * it; an invalidation hook would couple the plugin to this class to defend
     * a case that cannot arise, and dropping the memo would put a SHOW COLUMNS
     * on every catalogue query, which is the hottest path in the application.
     */
    public static function hasDesiderata(\mysqli $db): bool
    {
        static $columns;
        // Whether any probe in this worker ever SUCCEEDED in finding the column.
        // Separate from the per-connection memo below on purpose: it survives a
        // later failure, which is what lets a failed probe fall back to what we
        // already know instead of to a guess.
        static $seenColumn = false;
        $columns ??= new \WeakMap();
        if (!isset($columns[$db])) {
            // Both failure shapes have to be caught here. The app runs under
            // mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) —
            // ConfigStore:291 sets it, and it is also the PHP 8.2 default — so a
            // failing probe THROWS rather than returning false, and an uncaught
            // exception out of a visibility helper is a 500 on every page that
            // lists books. The false branch is still reachable: BackupManager
            // turns reporting off for the duration of an import (see its comment
            // at :1217) and restores it afterwards.
            $reason = '';
            try {
                $result = $db->query("SHOW COLUMNS FROM libri LIKE 'is\\_desiderata'");
                if ($result === false) {
                    // Reporting is off (an import is running): the message lives
                    // on the connection, not on an exception.
                    $reason = $db->error;
                }
            } catch (\Throwable $e) {
                // Read the reason off the exception, never off the connection:
                // $db may be closed by now, and mysqli throws again on property
                // access to a closed handle — which would replace a logged
                // degradation with an unlogged fatal.
                $result = false;
                $reason = $e->getMessage();
            }
            if ($result === false) {
                // A FAILED probe is not an answer, and memoising it as "the
                // column is absent" would poison the rest of the request: every
                // later catalogue() would degrade to 1=1 and publish the whole
                // wish list to the catalogue, the feeds, the sitemap and all six
                // interop protocols. Log it and answer for THIS call only, so
                // the next one probes again.
                //
                // Which answer is safe depends on something we often already
                // know. Guessing "absent" is only defensible on an installation
                // where the column genuinely may not exist: emitting a predicate
                // on a missing column would take the public catalogue down
                // everywhere the plugin was never installed, which is worse than
                // the leak it prevents. But once any probe in this worker has
                // SEEN the column, that reasoning no longer applies — the column
                // exists, a transient query failure does not un-create it, and
                // answering "absent" would publish the wish list for no reason.
                // So: fall back to what we learned, and only guess when we never
                // learned anything.
                //
                // "This worker" is too narrow on its own. $seenColumn is a
                // static, so a PHP-FPM worker that has just been spawned knows
                // nothing, and a transient failure on its very first probe would
                // answer "absent" and publish the wish list for that call even
                // though every other worker has seen the column. The shared
                // cache (APCu, or the file backend) carries the same fact across
                // workers and restarts. Trusting it is safe for the same reason
                // trusting $seenColumn is: the plugin never drops the column —
                // onUninstall() clears the flag on every row and leaves the
                // column in place — so "seen once" stays true.
                $known = $seenColumn || self::columnSeenElsewhere();
                \App\Support\SecureLogger::error(
                    $known
                        ? 'BookVisibility: cannot probe libri.is_desiderata; keeping the filter on, the column is known to exist'
                        : 'BookVisibility: cannot probe libri.is_desiderata; visibility filtering is degraded for this call',
                    ['error' => $reason]
                );
                return $known;
            }
            $columns[$db] = $result->num_rows > 0;
            if ($columns[$db]) {
                if (!$seenColumn) {
                    self::rememberColumnSeen();
                }
                $seenColumn = true;
            }
        }
        return $columns[$db];
    }

    /**
     * Shared-cache key for "the desiderata column exists on this installation".
     * Thirty days, because the column is never dropped and a long-lived worker
     * pool only rewrites the key when a new worker makes its first probe.
     */
    private const COLUMN_SEEN_KEY = 'visibility_desiderata_column_seen';

    /**
     * Record, once per worker, that a probe found the column. A cache failure
     * is ignored: this is a tie-breaker for a failed probe, never a reason to
     * fail the successful one that is running now.
     */
    private static function rememberColumnSeen(): void
    {
        try {
            \App\Support\QueryCache::set(self::COLUMN_SEEN_KEY, true, 2592000);
        } catch (\Throwable) {
            // Best effort by design; see above.
        }
    }

    /** Has any worker on this installation seen the column? */
    private static function columnSeenElsewhere(): bool
    {
        try {
            return \App\Support\QueryCache::get(self::COLUMN_SEEN_KEY) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}