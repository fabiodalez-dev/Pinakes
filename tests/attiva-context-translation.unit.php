<?php
declare(strict_types=1);

/**
 * Regression guard for the state/action ambiguity of the Italian key "Attiva".
 *
 * Run: php tests/attiva-context-translation.unit.php
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$check = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;
        echo "  OK  {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$label}\n";
};

$locales = [];
foreach (['it_IT', 'en_US', 'fr_FR', 'de_DE'] as $code) {
    $decoded = json_decode((string) file_get_contents("{$root}/locale/{$code}.json"), true, 512, JSON_THROW_ON_ERROR);
    $locales[$code] = $decoded;
}

$check($locales['en_US']['Attiva'] === 'Active', 'generic Attiva remains the English state label Active');
$check($locales['fr_FR']['Attiva'] === 'Actif', 'generic Attiva remains a French state label');
$check($locales['de_DE']['Attiva'] === 'Aktiv', 'generic Attiva remains a German state label');

$actionKeys = ['Attiva API key', 'Attiva lingua', 'Attiva plugin', 'Attiva tema', 'Attiva utente'];
foreach ($actionKeys as $key) {
    foreach ($locales as $code => $translations) {
        $check(isset($translations[$key]) && trim((string) $translations[$key]) !== '', "{$code} defines contextual action: {$key}");
    }
}

$actionViews = [
    'app/Views/settings/advanced-tab.php' => 'Attiva API key',
    'app/Views/admin/languages/index.php' => 'Attiva lingua',
    'app/Views/admin/plugins.php' => 'Attiva plugin',
    'app/Views/admin/themes.php' => 'Attiva tema',
    'app/Views/utenti/index.php' => 'Attiva utente',
];
foreach ($actionViews as $path => $key) {
    $source = (string) file_get_contents("{$root}/{$path}");
    $check(str_contains($source, "__(\"{$key}\")") || str_contains($source, "__('{$key}')"), "{$path} uses {$key}");
}

$statusViews = [
    'app/Support/IcsGenerator.php',
    'app/Views/dashboard/index.php',
    'app/Views/prenotazioni/index.php',
    'app/Views/prenotazioni/modifica_prenotazione.php',
    'storage/plugins/book-club/views/partials/buddy_panel.php',
];
foreach ($statusViews as $path) {
    $source = (string) file_get_contents("{$root}/{$path}");
    $check(str_contains($source, "__('Attiva')") || str_contains($source, '__("Attiva")'), "{$path} keeps the generic state key");
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
