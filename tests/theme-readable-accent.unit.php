<?php
declare(strict_types=1);

/**
 * The accent as a text colour (ThemeColorizer::readableOnTint, exposed as
 * --primary-text). Every theme preset must read at WCAG AA (4.5:1) on its own
 * soft tint and on white, keep its hue, and stay untouched when the accent is
 * already dark enough. Also covers the 2026 surfaces (readableSurface,
 * readableOnDark and the palette's button_surface / secondary_surface /
 * primary_on_dark): AA with their text for every preset.
 *
 * Run: php tests/theme-readable-accent.unit.php
 */

use App\Support\ThemeColorizer;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$c = new ThemeColorizer();
$tint = static function (string $hex) use ($c): string {
    $rgb = $c->hexToRgb($hex);
    return $c->rgbToHex(
        (int) round($rgb['r'] * 0.09 + 255 * 0.91),
        (int) round($rgb['g'] * 0.09 + 255 * 0.91),
        (int) round($rgb['b'] * 0.09 + 255 * 0.91)
    );
};

// The ten presets shipped in installer/database/data_*.sql, plus a near-white.
$presets = ['#d70161', '#404040', '#0284c7', '#059669', '#ea580c', '#be123c', '#0d9488', '#475569', '#f43f5e', '#1e40af', '#fde68a'];
foreach ($presets as $primary) {
    $text = $c->readableOnTint($primary);
    $check($c->getContrastRatio($text, $tint($primary)) >= 4.5, "{$primary} → {$text} reads at AA on its soft tint");
    $check($c->getContrastRatio($text, '#ffffff') >= 4.5, "{$primary} → {$text} reads at AA on white");
}

// Already-dark accents come back unchanged.
foreach (['#404040', '#be123c', '#475569', '#1e40af'] as $dark) {
    $check($c->readableOnTint($dark) === $dark, "{$dark} is dark enough and is left as it is");
}

// Same hue: darkening scales every channel, so the channel order holds.
$orange = $c->hexToRgb($c->readableOnTint('#ea580c'));
$check($orange['r'] > $orange['g'] && $orange['g'] > $orange['b'], 'an orange accent stays orange');

$palette = $c->generateColorPalette(['primary' => '#0d9488']);
$check(($palette['primary_text'] ?? '') === $c->readableOnTint('#0d9488'), 'generateColorPalette() carries primary_text');

// 2026 design: filled surfaces that carry text (readableSurface) and the
// accent as text on the dark surface (readableOnDark), exposed by
// generateColorPalette() as button_surface, secondary_surface, primary_on_dark.
// The ten bundled presets, as seeded by installer/database/data_en_US.sql.
$themes = [
    ['primary' => '#d70161', 'secondary' => '#1b1720', 'button' => '#d70262', 'button_text' => '#ffffff'],
    ['primary' => '#404040', 'secondary' => '#000000', 'button' => '#808080', 'button_text' => '#ffffff'],
    ['primary' => '#0284c7', 'secondary' => '#0c4a6e', 'button' => '#0ea5e9', 'button_text' => '#ffffff'],
    ['primary' => '#059669', 'secondary' => '#064e3b', 'button' => '#10b981', 'button_text' => '#ffffff'],
    ['primary' => '#ea580c', 'secondary' => '#7c2d12', 'button' => '#f97316', 'button_text' => '#ffffff'],
    ['primary' => '#be123c', 'secondary' => '#881337', 'button' => '#e11d48', 'button_text' => '#ffffff'],
    ['primary' => '#0d9488', 'secondary' => '#134e4a', 'button' => '#14b8a6', 'button_text' => '#ffffff'],
    ['primary' => '#475569', 'secondary' => '#1e293b', 'button' => '#64748b', 'button_text' => '#ffffff'],
    ['primary' => '#f43f5e', 'secondary' => '#9f1239', 'button' => '#fb7185', 'button_text' => '#ffffff'],
    ['primary' => '#1e40af', 'secondary' => '#1e3a8a', 'button' => '#3b82f6', 'button_text' => '#ffffff'],
];
foreach ($themes as $theme) {
    $p = $c->generateColorPalette($theme);
    $name = $theme['primary'];
    $check(
        ($p['button_surface'] ?? '') === $c->readableSurface($theme['button'], $theme['button_text'])
            && $c->getContrastRatio($theme['button_text'], $p['button_surface']) >= 4.5,
        "{$name}: button_surface {$p['button_surface']} reads at AA under {$theme['button_text']}"
    );
    $check(
        ($p['secondary_surface'] ?? '') === $c->readableSurface($theme['secondary'], '#ffffff')
            && $c->getContrastRatio('#ffffff', $p['secondary_surface']) >= 4.5,
        "{$name}: secondary_surface {$p['secondary_surface']} reads at AA under white"
    );
    $check(
        ($p['primary_on_dark'] ?? '') === $c->readableOnDark($theme['primary'], $p['secondary_surface'])
            && $c->getContrastRatio($p['primary_on_dark'], $p['secondary_surface']) >= 4.5,
        "{$name}: primary_on_dark {$p['primary_on_dark']} reads at AA on secondary_surface"
    );
}

// A pair that already reads comes back unchanged.
$check($c->readableSurface('#111827', '#ffffff') === '#111827', '#111827 under white already reads and is left as it is');
$check($c->readableSurface('#ffffff', '#111827') === '#ffffff', 'white under #111827 already reads and is left as it is');
$check($c->readableOnDark('#ffffff', '#111827') === '#ffffff', 'white on #111827 already reads and is left as it is');

// Dark text on a mid surface: the surface is lightened, not darkened.
$mid = '#808080';
$lifted = $c->readableSurface($mid, '#111827');
$check($c->getContrastRatio('#111827', $lifted) >= 4.5, "dark text: {$mid} → {$lifted} reads at AA under #111827");
$check($c->getContrastRatio($lifted, '#000000') > $c->getContrastRatio($mid, '#000000'), "dark text: {$mid} is lightened, not darkened");
$tooDark = $c->readableSurface('#1e3a8a', '#111827');
$check($c->getContrastRatio('#111827', $tooDark) >= 4.5, "dark text: #1e3a8a → {$tooDark} reads at AA under #111827");

// Intermediate text must choose whichever direction can reach AA, even
// when a surface starts at an extreme or the 4.6 safety margin is unreachable.
foreach (['#aaaaaa', '#777777', '#767676', '#808080', '#111827', '#ffffff'] as $text) {
    foreach (['#ffffff', '#000000', '#909090', '#d70161'] as $surface) {
        $adjusted = $c->readableSurface($surface, $text);
        $check($c->getContrastRatio($text, $adjusted) >= 4.5, "{$text} on {$surface} adjusts to AA ({$adjusted})");
        $hoverPalette = $c->generateColorPalette(['button' => $surface, 'button_text' => $text]);
        $check($c->getContrastRatio($text, $hoverPalette['button_hover']) >= 4.5, "{$text} on {$surface} also reads at AA in hover");
    }
}

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
