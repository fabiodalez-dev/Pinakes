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
$loanForm = (string) file_get_contents($root . '/app/Views/prestiti/crea_prestito.php');
$scanner = (string) file_get_contents($root . '/frontend/js/copy-scanner.js');
$copyRepository = (string) file_get_contents($root . '/app/Models/CopyRepository.php');

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

// 2026-08-11 follow-up: the next-loan form must not retain stale success UI,
// scanner teardown must return interaction focus, and copies/labels use C1..C20.
$check(str_contains($loanForm, 'id="loan_created_alert"'), 'loan success notice has a stable dismiss target');
$check(str_contains($loanForm, "loanForm.addEventListener('input', clearCreatedAlert)")
    && str_contains($loanForm, "currentUrl.searchParams.delete('created')")
    && str_contains($loanForm, "currentUrl.searchParams.delete('pdf')"),
    'editing the next loan clears the stale notice and URL flags');
$check(str_contains($scanner, 'video.pause()') && str_contains($scanner, "video.removeAttribute('src')"),
    'scanner fully tears down the mobile video element');
$check(str_contains($scanner, 'previouslyFocused.focus({ preventScroll: true })')
    && str_contains($scanner, 'btn.focus({ preventScroll: true })')
    && !preg_match('/function fillTarget\([^}]+input\.focus\(/s', $scanner),
    'scanner restores its trigger without reopening the mobile keyboard');
$check(str_contains($copyRepository, 'strnatcasecmp($leftCode, $rightCode)'),
    'copy repository defines natural inventory sorting');
$check(str_contains($copyRepository, '$copie = self::sortByInventoryNumber($copie);'),
    'physical-copy table data applies the natural inventory order');

require_once $root . '/vendor/autoload.php';
$natural = \App\Models\CopyRepository::sortByInventoryNumber([
    ['id' => 10, 'numero_inventario' => 'LIB-6-C10'],
    ['id' => 2, 'numero_inventario' => 'LIB-6-C2'],
    ['id' => 20, 'numero_inventario' => 'LIB-6-C20'],
    ['id' => 1, 'numero_inventario' => 'LIB-6-C1'],
    ['id' => 3, 'numero_inventario' => 'LIB-6-C3'],
]);
$check(array_column($natural, 'numero_inventario') === [
    'LIB-6-C1', 'LIB-6-C2', 'LIB-6-C3', 'LIB-6-C10', 'LIB-6-C20',
], 'natural sorter orders physical copies numerically');
$check(str_contains($controller, 'CopyRepository::sortByInventoryNumber($copie)'),
    'multi-label PDF reuses the natural copy order');

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
