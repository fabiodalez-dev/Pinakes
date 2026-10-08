<?php
declare(strict_types=1);

namespace App\Support;

/**
 * ThemeColorizer
 *
 * Provides color manipulation and WCAG contrast checking utilities.
 * Used for generating color variants (hover states) and validating accessibility.
 */
class ThemeColorizer
{
    /**
     * Darken a HEX color by a percentage
     *
     * @param string $hex HEX color (e.g. '#d70161' or 'd70161')
     * @param int $percent Percentage to darken (0-100)
     * @return string Darkened color in #rrggbb format
     */
    public function darken(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');

        // Convert 3-char hex to 6-char
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        // Convert to RGB
        $rgb = array_map('hexdec', str_split($hex, 2));

        // Darken each component
        foreach ($rgb as &$value) {
            $value = max(0, min(255, $value - ($value * $percent / 100)));
        }

        // Convert back to HEX
        return '#' . implode('', array_map(function ($v) {
            return str_pad(dechex((int)round($v)), 2, '0', STR_PAD_LEFT);
        }, $rgb));
    }

    /**
     * Lighten a HEX color by a percentage
     *
     * @param string $hex HEX color
     * @param int $percent Percentage to lighten (0-100)
     * @return string Lightened color in #rrggbb format
     */
    public function lighten(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $rgb = array_map('hexdec', str_split($hex, 2));

        foreach ($rgb as &$value) {
            $value = max(0, min(255, $value + ((255 - $value) * $percent / 100)));
        }

        return '#' . implode('', array_map(function ($v) {
            return str_pad(dechex((int)round($v)), 2, '0', STR_PAD_LEFT);
        }, $rgb));
    }

    /**
     * Calculate WCAG contrast ratio between two colors
     *
     * @param string $foreground Foreground color (HEX)
     * @param string $background Background color (HEX)
     * @return float Contrast ratio (1-21)
     */
    public function getContrastRatio(string $foreground, string $background): float
    {
        $l1 = $this->getLuminance($foreground);
        $l2 = $this->getLuminance($background);

        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Check if contrast meets WCAG AA standard (4.5:1 for normal text)
     *
     * @param string $foreground Foreground color
     * @param string $background Background color
     * @return bool True if passes WCAG AA
     */
    public function isAccessibleAA(string $foreground, string $background): bool
    {
        return $this->getContrastRatio($foreground, $background) >= 4.5;
    }

    /**
     * Check if contrast meets WCAG AAA standard (7:1 for normal text)
     *
     * @param string $foreground Foreground color
     * @param string $background Background color
     * @return bool True if passes WCAG AAA
     */
    public function isAccessibleAAA(string $foreground, string $background): bool
    {
        return $this->getContrastRatio($foreground, $background) >= 7.0;
    }

    /**
     * Check if contrast meets WCAG AA Large Text standard (3:1)
     *
     * @param string $foreground Foreground color
     * @param string $background Background color
     * @return bool True if passes WCAG AA Large Text
     */
    public function isAccessibleAALarge(string $foreground, string $background): bool
    {
        return $this->getContrastRatio($foreground, $background) >= 3.0;
    }

    /**
     * Get optimal text color (black or white) for a given background
     * Uses luminance to determine if light or dark text is more readable
     *
     * @param string $backgroundColor Background color (HEX)
     * @return string '#000000' or '#ffffff'
     */
    public function getOptimalTextColor(string $backgroundColor): string
    {
        $luminance = $this->getLuminance($backgroundColor);

        // If background is light (luminance > 0.5), use dark text
        // If background is dark (luminance <= 0.5), use light text
        return $luminance > 0.5 ? '#000000' : '#ffffff';
    }

    /**
     * Calculate relative luminance of a color (WCAG formula)
     *
     * @param string $hex HEX color
     * @return float Luminance value (0-1)
     */
    private function getLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $rgb = array_map('hexdec', str_split($hex, 2));

        // Normalize and apply gamma correction
        $rgb = array_map(function ($val) {
            $val = $val / 255;
            return $val <= 0.03928
                ? $val / 12.92
                : pow(($val + 0.055) / 1.055, 2.4);
        }, $rgb);

        // Calculate luminance using WCAG formula
        return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
    }

    /**
     * Validate if a string is a valid HEX color
     *
     * @param string $hex Color string to validate
     * @return bool True if valid HEX color
     */
    public function isValidHex(string $hex): bool
    {
        return (bool) preg_match('/^#?([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $hex);
    }

    /**
     * Normalize a HEX color to #rrggbb format
     *
     * @param string $hex HEX color
     * @return string Normalized HEX color (#rrggbb)
     */
    public function normalizeHex(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . strtolower($hex);
    }

    /**
     * Convert HEX to RGB array
     *
     * @param string $hex HEX color
     * @return array ['r' => int, 'g' => int, 'b' => int]
     */
    public function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }

    /**
     * Convert RGB to HEX
     *
     * @param int $r Red (0-255)
     * @param int $g Green (0-255)
     * @param int $b Blue (0-255)
     * @return string HEX color (#rrggbb)
     */
    public function rgbToHex(int $r, int $g, int $b): string
    {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Generate all color variants needed for theme
     * Returns primary, secondary, button colors with their hover/focus variants
     *
     * @param array $colors Base colors ['primary' => '#xxx', 'secondary' => '#xxx', ...]
     * @return array Complete color palette with variants
     */
    public function generateColorPalette(array $colors): array
    {
        $primary = $colors['primary'] ?? '#d70161';
        $secondary = $colors['secondary'] ?? '#111827';
        $button = $colors['button'] ?? '#d70262';
        $buttonText = $colors['button_text'] ?? '#ffffff';
        $buttonSurface = $this->readableSurface($button, $buttonText);

        return [
            // Base colors
            'primary' => $this->normalizeHex($primary),
            'secondary' => $this->normalizeHex($secondary),
            'button' => $this->normalizeHex($button),
            'button_text' => $this->normalizeHex($buttonText),

            // Variants
            'primary_hover' => $this->darken($primary, 10),
            'primary_focus' => $this->darken($primary, 15),
            'primary_dark' => $this->darken($primary, 15),
            'secondary_hover' => $this->darken($secondary, 10),
            'button_hover' => $this->readableSurface($this->darken($buttonSurface, 15), $buttonText),
            'primary_text' => $this->readableOnTint($primary),
            // The accent as text on the page itself (paper, white cards): the
            // brand colour untouched whenever it already reads, darkened only
            // for a light accent.
            'primary_ink' => $this->readableOnPaper($primary),
            // 2026 design: filled surfaces that carry text, kept at AA with
            // that text (see readableSurface()), and the accent as text on
            // the dark surface.
            'button_surface' => $buttonSurface,
            'secondary_surface' => $this->readableSurface($secondary, '#ffffff'),
            'primary_on_dark' => $this->readableOnDark($primary, $this->readableSurface($secondary, '#ffffff')),
        ];
    }

    /**
     * A filled surface that carries $text, moved only as far as WCAG AA
     * (4.5:1) for normal-size text needs: darkened under light text,
     * lightened under dark text. A pair that already reads is returned
     * unchanged, so a theme keeps its colour wherever it can.
     */
    public function readableSurface(string $surface, string $text): string
    {
        $surface = $this->normalizeHex($surface);
        $text = $this->normalizeHex($text);
        $dark = $this->darken($surface, 100);
        $light = $this->lighten($surface, 100);
        $preferDark = $this->getContrastRatio($text, $dark) >= $this->getContrastRatio($text, $light);
        for ($percent = 0; $percent <= 100; $percent += 2) {
            $candidates = [$this->darken($surface, $percent), $this->lighten($surface, $percent)];
            if (!$preferDark) {
                $candidates = array_reverse($candidates);
            }
            foreach ($candidates as $candidate) {
                if ($this->getContrastRatio($text, $candidate) >= 4.6) {
                    return $candidate;
                }
            }
        }
        // Some intermediate text colours cannot reach the 4.6 safety margin.
        // The better extreme still guarantees WCAG AA (at least sqrt(21):1).
        return $preferDark ? $dark : $light;
    }

    /**
     * The accent as text on the dark surface (the home's story band): the
     * accent lightened only as far as AA on that surface needs.
     */
    public function readableOnDark(string $accent, string $dark): string
    {
        $accent = $this->normalizeHex($accent);
        $dark = $this->normalizeHex($dark);
        for ($percent = 0; $percent <= 100; $percent += 2) {
            $candidate = $this->lighten($accent, $percent);
            if ($this->getContrastRatio($candidate, $dark) >= 4.6) {
                return $candidate;
            }
        }
        return '#ffffff';
    }

    /**
     * The accent as a text colour: the same hue, darkened only as far as it
     * takes to read at WCAG AA (4.5:1) on the accent's own soft tint, the
     * lightest surface the public site sets accent text on
     * (--pk-accent-soft: 9% accent over white). A dark accent comes back
     * unchanged; a light one (orange, teal, coral) gets just deep enough.
     */
    /**
     * The accent as text on the page background (--pk-bg, #fbfaf9) and the
     * white cards: darkened only as far as WCAG AA needs on the page paper,
     * so the default fuchsia stays the brand colour (4.9:1) while a pale
     * accent gets deep enough to read.
     */
    public function readableOnPaper(string $hex): string
    {
        $hex = $this->normalizeHex($hex);
        for ($percent = 0; $percent <= 60; $percent += 2) {
            $candidate = $this->darken($hex, $percent);
            if ($this->getContrastRatio($candidate, '#fbfaf9') >= 4.6) {
                return $candidate;
            }
        }
        return $this->darken($hex, 60);
    }

    public function readableOnTint(string $hex): string
    {
        $hex = $this->normalizeHex($hex);
        $rgb = $this->hexToRgb($hex);
        $tint = $this->rgbToHex(
            (int) round($rgb['r'] * 0.09 + 255 * 0.91),
            (int) round($rgb['g'] * 0.09 + 255 * 0.91),
            (int) round($rgb['b'] * 0.09 + 255 * 0.91)
        );
        for ($percent = 0; $percent <= 60; $percent += 2) {
            $candidate = $this->darken($hex, $percent);
            // A little over 4.5 so rounding in the browser cannot tip it under.
            if ($this->getContrastRatio($candidate, $tint) >= 4.6) {
                return $candidate;
            }
        }
        return $this->darken($hex, 60);
    }
}
