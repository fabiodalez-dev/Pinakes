<?php
declare(strict_types=1);

namespace App\Support;

final class BundledPlugins
{
    public const LIST = [
        'api-book-scraper',
        'archives',
        'bibframe-linked-data',
        'book-club',
        'deezer',
        'dewey-editor',
        'digital-library',
        'discogs',
        'frbr-lrm',
        'goodlib',
        'mobile-api',
        'musicbrainz',
        'ncip-server',
        'oai-pmh-server',
        'open-library',
        'openurl-resolver',
        'resource-sync',
        'viaf-authority',
        'z39-server',
    ];

    private function __construct()
    {
    }
}
