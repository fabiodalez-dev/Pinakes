<?php
declare(strict_types=1);

/** Regression guards for the final user requests in Discussion #238. */
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}\n";
    }
};

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/LibriController.php');
$settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$settingsView = (string) file_get_contents($root . '/app/Views/settings/index.php');
$bookView = (string) file_get_contents($root . '/app/Views/libri/scheda_libro.php');
$languages = (string) file_get_contents($root . '/app/Controllers/Admin/LanguagesController.php');
$updater = (string) file_get_contents($root . '/app/Support/Updater.php');

$check(str_contains($controller, "getQueryParams()['copy_id']"), 'single-copy PDF accepts a copy id');
$check(str_contains($controller, 'AND c.id = ?'), 'single-copy PDF scopes the copy to its book');
$check(str_contains($bookView, "copy-labels-pdf?copy_id="), 'each physical-copy row exposes a print action');
$check(str_contains($settingsView, 'name="custom_width"') && str_contains($settingsView, 'name="custom_height"'), 'label settings expose custom width and height');
$check(str_contains($settings, "'show_subtitle'") && str_contains($settings, "'show_dewey'"), 'label content choices are persisted');
$check(str_contains($controller, 'applyLabelContentSettings'), 'book and copy labels apply content choices');
$check(str_contains($languages, 'loadCanonicalTranslations'), 'language exports and stats use the current canonical key set');
$check(str_contains($languages, "\$translations[\$key] ?? ''"), 'missing translations export as empty strings');
$check(str_contains($updater, 'isCustomLocalePath'), 'updater recognizes custom locale catalogs');
$check(substr_count($updater, 'isCustomLocalePath(') >= 4, 'custom locales are preserved in copy, preflight and orphan cleanup');

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
