<?php
declare(strict_types=1);

/**
 * The accent as a text colour (ThemeColorizer::readableOnTint, exposed as
 * --primary-text). Every theme preset must read at WCAG AA (4.5:1) on its own
 * soft tint and on white, keep its hue, and stay untouched when the accent is
 * already dark enough.
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

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
