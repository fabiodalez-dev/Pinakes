<?php
declare(strict_types=1);

namespace App\Support;

final class BundledPlugins
{
    public const LIST = [
        'api-book-scraper',
        'archives',
        'deezer',
        'dewey-editor',
        'digital-library',
        'discogs',
        'goodlib',
        'musicbrainz',
        'open-library',
        'z39-server',
    ];

    private function __construct()
    {
    }
}
