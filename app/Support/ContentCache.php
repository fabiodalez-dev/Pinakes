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
    /**
     * A book (or its availability) changed: clear catalog counts/facets,
     * the home dataset and the cached genre tree.
     */
    public static function booksChanged(): void
    {
        QueryCache::clearByPrefix('catalog_');
        QueryCache::clearByPrefix('home_');
        QueryCache::clearByPrefix('genre_tree_');
    }

    /**
     * Home CMS content or events changed: the cached home dataset is stale.
     */
    public static function homeContentChanged(): void
    {
        QueryCache::clearByPrefix('home_');
    }
}
