<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Central invalidation points for the cross-request content caches
 * (home page dataset, catalog counts/facets, genre tree).
 *
 * Call the matching method from every write path so cached public pages
 * reflect admin changes immediately instead of waiting for the TTL.
 */
final class ContentCache
{
    private static bool $booksInvalidationDeferred = false;

    /**
     * A book (or its availability) changed: invalidate catalog counts/facets,
     * every home entry (home_page_data_v1 and home_api_count_*), and the
     * cached genre tree. O(1) generation bumps — previously cached entries in
     * these namespaces become unreachable immediately, no scan/delete storm.
     */
    public static function booksChanged(): void
    {
        QueryCache::bumpGeneration('catalog_');
        QueryCache::bumpGeneration('home_');
        QueryCache::bumpGeneration('genre_tree_');
    }

    /**
     * Schedule one invalidation at request shutdown. Writers that participate
     * in an outer transaction cannot safely invalidate immediately: another
     * request could rebuild the cache from pre-commit rows. Shutdown runs after
     * the controller has committed (or rolled back), and duplicate calls from a
     * batch collapse into a single invalidation.
     */
    public static function deferBooksChanged(): void
    {
        if (self::$booksInvalidationDeferred) {
            return;
        }

        self::$booksInvalidationDeferred = true;
        register_shutdown_function(static function (): void {
            self::$booksInvalidationDeferred = false;
            self::booksChanged();
        });
    }

    /**
     * Home CMS content or events changed: the cached home dataset is stale.
     */
    public static function homeContentChanged(): void
    {
        QueryCache::bumpGeneration('home_');
    }
}
