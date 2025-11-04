<?php
declare(strict_types=1);

namespace App\Support;

final class ContentSanitizer
{
    /**
     * Normalizza URL esterni noti per rispettare HTTPS e la CSP.
     */
    public static function normalizeExternalAssets(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        return strtr($content, [
            'http://fonts.googleapis.com' => 'https://fonts.googleapis.com',
            'http://fonts.gstatic.com' => 'https://fonts.gstatic.com',
            '//fonts.googleapis.com' => 'https://fonts.googleapis.com',
            '//fonts.gstatic.com' => 'https://fonts.gstatic.com',
        ]);
    }
}
