<?php

namespace App\Support;

class GenreHelper
{
    /**
     * Parse a long genre name into its components
     * Format: "Romanzo - storico - fantastoria" -> [Prosa, Narrativa, Romanzo - storico - fantastoria]
     * Or extract the path components: [0] = complete, [1] = last part
     *
     * @param string $genreName Long genre name with separators
     * @return array ['path' => string, 'shortName' => string, 'parts' => array]
     */
    public static function parseGenreName(string $genreName): array
    {
        if (empty($genreName)) {
            return [
                'path' => '',
                'shortName' => '',
                'parts' => []
            ];
        }

        // Split by " - " separator
        $parts = array_map('trim', explode(' - ', $genreName));

        // The last part is the "short name" (most specific genre)
        $shortName = end($parts);

        // The first part is usually the broad category
        $firstPart = reset($parts);

        return [
            'path' => $genreName,
            'shortName' => $shortName,
            'parts' => $parts,
            'count' => count($parts)
        ];
    }

    /**
     * Format genre path for display
     * Shows full path but with smart line breaks
     *
     * @param string $radice Root genre name
     * @param string $genere Main genre name
     * @param string $sottogenere Sub-genre name (can be very long)
     * @return string HTML formatted path
     */
    public static function formatGenrePath(
        ?string $radice = null,
        ?string $genere = null,
        ?string $sottogenere = null
    ): string {
        $parts = array_filter([
            !empty($radice) ? $radice : null,
            !empty($genere) ? $genere : null,
            !empty($sottogenere) ? $sottogenere : null
        ]);

        if (empty($parts)) {
            return 'Non specificato';
        }

        // Create HTML with proper formatting
        $html = '<span class="genre-path">';

        foreach ($parts as $i => $part) {
            if ($i > 0) {
                // Add separator with optional line break after second level
                $html .= '<span class="genre-separator"> → </span>';
                if ($i === 2) {
                    $html .= '<br class="d-none d-md-inline"><span class="ms-2"></span>';
                }
            }
            $html .= '<span class="genre-part">' . HtmlHelper::e($part) . '</span>';
        }

        $html .= '</span>';
        return $html;
    }

    /**
     * Extract readable subgenre name from long genre string
     * "Romanzo - fantascienza - fantascienza tecnologica - cyberpunk - steampunk"
     * -> "Romanzo / fantascienza / steampunk"
     *
     * @param string $genreName
     * @return string Readable format
     */
    public static function getReadableSubgenrePath(string $genreName): string
    {
        $parts = array_map('trim', explode(' - ', $genreName));

        if (count($parts) <= 1) {
            return $genreName;
        }

        // Keep first and last part, skip middle if too many
        if (count($parts) <= 3) {
            return implode(' / ', $parts);
        }

        // For very long paths: "First / ... / Last"
        return $parts[0] . ' / ... / ' . end($parts);
    }

    /**
     * Get genre level (based on parent chain depth)
     * Used to assign colors/styling
     *
     * @param ?int $parentId
     * @return int 1=root, 2=main, 3=sub
     */
    public static function getGenreLevel(?int $parentId): int
    {
        if ($parentId === null) return 1; // Root
        if ($parentId <= 3) return 2;     // Main (under root)
        return 3;                         // Sub-genre
    }

    /**
     * Get CSS class for genre badge based on level
     *
     * @param int $level
     * @return string CSS class
     */
    public static function getGenreBadgeClass(int $level): string
    {
        return match($level) {
            1 => 'bg-primary text-white',
            2 => 'bg-info text-white',
            3 => 'bg-success text-white',
            default => 'bg-secondary text-white'
        };
    }
}
