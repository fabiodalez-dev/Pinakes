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

        $content = strtr($content, [
            'http://fonts.googleapis.com' => 'https://fonts.googleapis.com',
            'http://fonts.gstatic.com' => 'https://fonts.gstatic.com',
            '//fonts.googleapis.com' => 'https://fonts.googleapis.com',
            '//fonts.gstatic.com' => 'https://fonts.gstatic.com',
        ]);

        // Rimuove completamente qualsiasi inclusione esterna ai Google Fonts.
        $patterns = [
            '#<link[^>]+fonts\.googleapis\.com[^>]*>#i',
            '#<link[^>]+fonts\.gstatic\.com[^>]*>#i',
            '#@import\s+url\((?:"|\'|)(?:https?:)?//fonts\.googleapis\.com[^)]*\)\s*;?#i',
            '#https?://fonts\.googleapis\.com/[^\"\'\s>]+#i',
            '#https?://fonts\.gstatic\.com/[^\"\'\s>]+#i',
        ];

        return preg_replace($patterns, '', $content) ?? $content;
    }
}
