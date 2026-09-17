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

    private static function alias(string $alias): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException('Invalid SQL alias');
        }
        return $alias;
    }

    public static function hasDesiderata(\mysqli $db): bool
    {
        static $columns;
        $columns ??= new \WeakMap();
        if (!isset($columns[$db])) {
            $result = $db->query("SHOW COLUMNS FROM libri LIKE 'is_desiderata'");
            if ($result === false) {
                // A FAILED probe is not an answer, and memoising it as "the
                // column is absent" would poison the rest of the request: every
                // later catalogue() would degrade to 1=1 and publish the whole
                // wish list to the catalogue, the feeds, the sitemap and all six
                // interop protocols. Log it and answer false for THIS call only,
                // so the next one probes again.
                //
                // False — not true — is the only safe answer here: emitting a
                // predicate on a column that may genuinely not exist would take
                // the public catalogue down on every installation without the
                // plugin, which is worse than the leak it would prevent.
                \App\Support\SecureLogger::error(
                    'BookVisibility: cannot probe libri.is_desiderata; visibility filtering is degraded for this call',
                    ['error' => $db->error]
                );
                return false;
            }
            $columns[$db] = $result->num_rows > 0;
        }
        return $columns[$db];
    }
}
