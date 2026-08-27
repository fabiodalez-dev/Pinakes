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
    private static bool $availabilityInvalidationDeferred = false;

    /**
     * Book metadata or taxonomy changed: invalidate catalog counts/facets,
     * every home entry (home_page_data_v1 and home_api_count_*), the cached
     * genre tree and static detail DTOs. Availability-only writes use the
     * narrower availabilityChanged() path below.
     */
    public static function booksChanged(): void
    {
        QueryCache::bumpGeneration('catalog_');
        QueryCache::bumpGeneration('home_');
        QueryCache::bumpGeneration('genre_tree_');
        QueryCache::bumpGeneration('book_detail_');
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
     * Only circulation-derived availability changed. Catalog membership,
     * counts and the home dataset must be refreshed, while the static
     * book-detail DTO deliberately remains valid because its availability is
     * always merged from a live query.
     */
    public static function availabilityChanged(): void
    {
        QueryCache::bumpGeneration('catalog_');
        QueryCache::bumpGeneration('home_');
    }

    /**
     * Defer and coalesce availability invalidation for caller-owned
     * transactions. A broader deferred booksChanged() subsumes this work.
     */
    public static function deferAvailabilityChanged(): void
    {
        if (self::$booksInvalidationDeferred || self::$availabilityInvalidationDeferred) {
            return;
        }

        self::$availabilityInvalidationDeferred = true;
        register_shutdown_function(static function (): void {
            self::$availabilityInvalidationDeferred = false;
            if (!self::$booksInvalidationDeferred) {
                self::availabilityChanged();
            }
        });
    }

    /**
     * Home CMS content or events changed: the cached home dataset is stale.
     */
    public static function homeContentChanged(): void
    {
        QueryCache::bumpGeneration('home_');
    }

    /**
     * A review was approved, rejected or deleted: the cached public reviews
     * block ('book_reviews_' namespace, book-detail page) is stale. New
     * reviews are created as 'pendente' (never publicly visible), so only
     * moderation transitions need to invalidate.
     */
    public static function reviewsChanged(): void
    {
        QueryCache::bumpGeneration('book_reviews_');
    }
}
