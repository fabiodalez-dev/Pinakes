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
