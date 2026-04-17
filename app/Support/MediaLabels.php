<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Returns context-aware field labels based on the book's format.
 * When the format indicates music media, labels change to music terminology.
 */
class MediaLabels
{
    /**
     * Build normalized lookup candidates for format/media values.
     *
     * @return array<int, string>
     */
    private static function normalizedCandidates(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $lower = strtolower($trimmed);
        $underscore = preg_replace('/[\s-]+/u', '_', $lower) ?? $lower;
        $collapsed = preg_replace('/[\s\-_]+/u', '', $lower) ?? $lower;

        return array_values(array_unique([$lower, $underscore, $collapsed]));
    }

    /**
     * Normalize explicit tipo_media or common aliases to the canonical enum.
     */
    public static function normalizeTipoMedia(?string $tipoMedia): ?string
    {
        foreach (self::normalizedCandidates($tipoMedia) as $candidate) {
            if (isset(self::allTypes()[$candidate])) {
                return $candidate;
            }

            if (in_array($candidate, ['book', 'books', 'paperback', 'hardcover', 'hardback', 'cartaceo', 'print', 'printed'], true)) {
                return 'libro';
            }

            if (in_array($candidate, ['disc', 'record', 'album', 'cd', 'cdaudio', 'compactdisc', 'vinyl', 'vinile', 'lp', 'cassette', 'cassetta', 'audiocassetta'], true)) {
                return 'disco';
            }

            if (in_array($candidate, ['audiobook', 'audiobooks', 'audiolibro'], true)) {
                return 'audiolibro';
            }

            if (in_array($candidate, ['dvd', 'bluray', 'blu_ray', 'movie', 'film'], true)) {
                return 'dvd';
            }

            if (in_array($candidate, ['altro', 'other'], true)) {
                return 'altro';
            }
        }

        return null;
    }

    /**
     * Resolve the effective tipo_media, preferring an explicit valid value.
     */
    public static function resolveTipoMedia(?string $formato, ?string $tipoMedia = null): string
    {
        $normalized = self::normalizeTipoMedia($tipoMedia);
        if ($normalized !== null) {
            return $normalized;
        }

        return self::inferTipoMedia($formato);
    }

    /**
     * Check if a format string indicates music media.
     */
    public static function isMusic(?string $formato, ?string $tipoMedia = null): bool
    {
        $normalizedTipoMedia = self::normalizeTipoMedia($tipoMedia);
        if ($normalizedTipoMedia !== null) {
            return $normalizedTipoMedia === 'disco';
        }

        return self::inferTipoMedia($formato) === 'disco';
    }

    /**
     * Map of internal format keys to translatable display names.
     */
    private static array $formatDisplayNames = [
        'cartaceo' => 'Cartaceo',
        'ebook' => 'eBook',
        'audiolibro' => 'Audiolibro',
        'audiobook' => 'Audiolibro',
        'cd_audio' => 'CD Audio',
        'cd' => 'CD',
        'vinile' => 'Vinile',
        'vinyl' => 'Vinile',
        'lp' => 'LP',
        'cassetta' => 'Cassetta',
        'cassette' => 'Cassetta',
        'audiocassetta' => 'Audiocassetta',
        'dvd' => 'DVD',
        'blu-ray' => 'Blu-ray',
        'blu_ray' => 'Blu-ray',
        'digitale' => 'Digitale',
        'altro' => 'Altro',
    ];

    /**
     * Get human-readable display name for a format value.
     * Returns the translated display name, or the raw value titlecased if unknown.
     */
    public static function formatDisplayName(?string $formato): string
    {
        if ($formato === null || $formato === '') {
            return '';
        }

        foreach (self::normalizedCandidates($formato) as $candidate) {
            if (isset(self::$formatDisplayNames[$candidate])) {
                return __(self::$formatDisplayNames[$candidate]);
            }
        }

        // Return the original value with first letter uppercase
        return ucfirst($formato);
    }

    /**
     * Format a tracklist string into an HTML ordered list.
     * Detects "1. Track (3:45) 2. Track (2:30)" patterns and converts to <ol>.
     */
    public static function formatTracklist(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // If already formatted as HTML ordered list, rebuild with escaped content.
        // strip_tags() preserves attributes on allowed tags (onclick, onerror, etc.)
        // so we extract <li> text content and rebuild a clean <ol> instead.
        if (str_contains($text, '<ol') && str_contains($text, '</ol>')) {
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $text, $matches)) {
                $items = array_map(static function (string $item): string {
                    return '<li>' . htmlspecialchars(strip_tags($item), ENT_QUOTES, 'UTF-8') . '</li>';
                }, $matches[1]);
                return '<ol class="tracklist">' . implode('', $items) . '</ol>';
            }
            // Fallback: strip all HTML and return plain text
            return htmlspecialchars(strip_tags($text), ENT_QUOTES, 'UTF-8');
        }

        // Remove "Tracklist:" prefix if present
        $text = preg_replace('/^Tracklist\s*:\s*/i', '', $text) ?? $text;

        // Try to split on numbered tracks: "1. Title (3:45)" pattern
        $tracks = preg_split('/(?<=\))\s+(?=\d+\.\s)/', $text);
        if ($tracks === false || count($tracks) < 2) {
            // Fallback: split on "N. " pattern
            $tracks = preg_split('/\s+(?=\d+\.\s)/', $text);
        }

        if ($tracks === false || count($tracks) < 2) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
        }

        $items = [];
        foreach ($tracks as $track) {
            $track = trim($track);
            // Remove leading "N. " numbering
            $track = preg_replace('/^\d+\.\s*/', '', $track) ?? $track;
            if ($track !== '') {
                $items[] = $track;
            }
        }

        if (empty($items)) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
        }

        $html = '<ol class="tracklist">';
        foreach ($items as $item) {
            $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ol>';
        return $html;
    }

    /**
     * All valid tipo_media values with their metadata.
     * @return array<string, array{icon: string, schema: string, label: string}>
     */
    public static function allTypes(): array
    {
        return [
            'libro' => ['icon' => 'fa-book', 'schema' => 'Book', 'label' => 'Libro'],
            'disco' => ['icon' => 'fa-compact-disc', 'schema' => 'MusicAlbum', 'label' => 'Disco'],
            'audiolibro' => ['icon' => 'fa-headphones', 'schema' => 'Audiobook', 'label' => 'Audiolibro'],
            'dvd' => ['icon' => 'fa-film', 'schema' => 'Movie', 'label' => 'DVD'],
            'altro' => ['icon' => 'fa-box', 'schema' => 'CreativeWork', 'label' => 'Altro'],
        ];
    }

    public static function icon(?string $tipoMedia): string
    {
        $types = self::allTypes();
        $resolved = self::normalizeTipoMedia($tipoMedia) ?? 'libro';
        return $types[$resolved]['icon'] ?? 'fa-book';
    }

    public static function schemaOrgType(?string $tipoMedia): string
    {
        $types = self::allTypes();
        $resolved = self::normalizeTipoMedia($tipoMedia) ?? 'libro';
        return $types[$resolved]['schema'] ?? 'Book';
    }

    public static function tipoMediaDisplayName(?string $tipoMedia): string
    {
        $types = self::allTypes();
        $resolved = self::normalizeTipoMedia($tipoMedia) ?? 'libro';
        $label = $types[$resolved]['label'] ?? 'Libro';
        return __($label);
    }

    /**
     * Infer tipo_media from formato field (for backward compat / migration).
     */
    public static function inferTipoMedia(?string $formato): string
    {
        if ($formato === null || $formato === '') {
            return 'libro';
        }

        $normalized = self::normalizeTipoMedia($formato);
        if ($normalized !== null) {
            return $normalized;
        }

        foreach (self::normalizedCandidates($formato) as $candidate) {
            // Check audiobook BEFORE music tokens to prevent "Audiobook CD" matching as disco
            if (str_contains($candidate, 'audiolibro') || str_contains($candidate, 'audiobook')) {
                return 'audiolibro';
            }

            // Long tokens: safe for substring match (unique enough)
            foreach (['cdaudio', 'compactdisc', 'vinile', 'vinyl', 'cassetta', 'cassette', 'audiocassetta'] as $musicToken) {
                if (str_contains($candidate, $musicToken)) {
                    return 'disco';
                }
            }
            // Short tokens: exact match only to avoid false positives
            // ('cd' would match 'cdrom', 'lp' would match 'help')
            if ($candidate === 'cd' || $candidate === 'lp') {
                return 'disco';
            }

            if (str_contains($candidate, 'dvd') || str_contains($candidate, 'bluray') || str_contains($candidate, 'blu_ray')) {
                return 'dvd';
            }

            if (str_contains($candidate, 'other') || str_contains($candidate, 'altro')) {
                return 'altro';
            }

            if (str_contains($candidate, 'book') || str_contains($candidate, 'paperback') || str_contains($candidate, 'hardcover') || str_contains($candidate, 'hardback')) {
                return 'libro';
            }
        }

        foreach (self::normalizedCandidates($formato) as $candidate) {
            if (preg_match('/\b(?:music|musik)\b/i', $candidate) === 1) {
                return 'disco';
            }
        }

        return 'libro';
    }

    /**
     * Get the appropriate label for a field based on format.
     * Returns the music label if format is music, otherwise the default.
     */
    public static function label(string $field, ?string $formato = null, ?string $tipoMedia = null): string
    {
        $isMusic = self::isMusic($formato, $tipoMedia);

        return match ($field) {
            'autore', 'author' => $isMusic ? __('Artista') : __('Autore'),
            'autori', 'authors' => $isMusic ? __('Artisti') : __('Autori'),
            'editore', 'publisher' => $isMusic ? __('Etichetta') : __('Editore'),
            'anno_pubblicazione', 'year' => $isMusic ? __('Anno di Uscita') : __('Anno di Pubblicazione'),
            'numero_pagine', 'pages' => $isMusic ? __('Tracce') : __('Numero di Pagine'),
            'isbn13' => $isMusic ? __('Barcode') : 'ISBN-13',
            'ean' => $isMusic ? __('Barcode') : 'EAN',
            'descrizione', 'description' => $isMusic ? __('Tracklist') : __('Descrizione'),
            'collana', 'series' => $isMusic ? __('Discografia') : __('Collana'),
            'formato', 'format' => __('Formato'),
            default => __($field),
        };
    }
}
