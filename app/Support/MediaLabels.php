<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Returns context-aware field labels based on the book's format.
 * When the format indicates music media, labels change to music terminology.
 */
class MediaLabels
{
    /** @var array<int, string> */
    private static array $musicFormats = [
        'cd_audio', 'vinile', 'cassetta', 'vinyl', 'lp', 'cd', 'cassette', 'audio', 'musik', 'music'
    ];

    /**
     * Check if a format string indicates music media.
     */
    public static function isMusic(?string $formato): bool
    {
        if ($formato === null || $formato === '') {
            return false;
        }
        $lower = strtolower(trim($formato));
        foreach (self::$musicFormats as $musicFormat) {
            if (str_contains($lower, $musicFormat)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map of internal format keys to translatable display names.
     */
    private static array $formatDisplayNames = [
        'cartaceo' => 'Cartaceo',
        'ebook' => 'eBook',
        'audiolibro' => 'Audiolibro',
        'cd_audio' => 'CD Audio',
        'vinile' => 'Vinile',
        'lp' => 'LP',
        'cassetta' => 'Cassetta',
        'dvd' => 'DVD',
        'blu-ray' => 'Blu-ray',
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
        $lower = strtolower(trim($formato));
        if (isset(self::$formatDisplayNames[$lower])) {
            return __(self::$formatDisplayNames[$lower]);
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

        // Remove "Tracklist:" prefix if present
        $text = preg_replace('/^Tracklist\s*:\s*/i', '', $text) ?? $text;

        // Try to split on numbered tracks: "1. Title (3:45)" pattern
        $tracks = preg_split('/(?<=\))\s+(?=\d+\.\s)/', $text);
        if ($tracks === false || count($tracks) < 2) {
            // Fallback: split on "N. " pattern
            $tracks = preg_split('/\s+(?=\d+\.\s)/', $text);
        }

        if ($tracks === false || count($tracks) < 2) {
            return $text; // Not a tracklist, return as-is
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
            return $text;
        }

        $html = '<ol class="tracklist">';
        foreach ($items as $item) {
            $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ol>';
        return $html;
    }

    /**
     * Get the appropriate label for a field based on format.
     * Returns the music label if format is music, otherwise the default.
     */
    public static function label(string $field, ?string $formato = null): string
    {
        $isMusic = self::isMusic($formato);

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
