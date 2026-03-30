<?php
declare(strict_types=1);

namespace App\Support;

final class BundledPlugins
{
    public const LIST = [
        'api-book-scraper',
        'dewey-editor',
        'digital-library',
        'discogs',
        'goodlib',
        'open-library',
        'z39-server',
    ];

    private function __construct()
    {
    }
}
