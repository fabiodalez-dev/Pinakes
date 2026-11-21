<?php
/** Staff uses the same active theme and contrast pairs as the public catalogue. */
$interfacePalette = [];
try {
    $interfaceDb = $db ?? \App\Support\ConfigStore::sharedConnection();
    if ($interfaceDb instanceof \mysqli) {
        $interfaceThemes = new \App\Support\ThemeManager($interfaceDb);
        $interfacePalette = (new \App\Support\ThemeColorizer())->generateColorPalette(
            $interfaceThemes->getThemeColors($interfaceThemes->getActiveTheme())
        );
    }
} catch (\Throwable $e) {
    // Theme storage can be unavailable during setup; CSS carries the defaults.
}
$interfaceProperties = [
    'primary' => 'primary-color', 'primary_text' => 'primary-text',
    'primary_ink' => 'primary-ink', 'primary_on_dark' => 'primary-on-dark',
    'secondary' => 'secondary-color', 'secondary_surface' => 'secondary-surface',
    'button' => 'button-color', 'button_surface' => 'button-surface',
    'button_text' => 'button-text-color', 'button_hover' => 'button-hover',
];
?>
<style<?= csp_nonce_attr() ?>>:root {
<?php foreach ($interfaceProperties as $interfaceKey => $interfaceProperty):
    $interfaceValue = $interfacePalette[$interfaceKey] ?? null;
    // Only colour values belong in this inline stylesheet.
    if (!is_string($interfaceValue) || !preg_match('/^#[a-f0-9]{3,8}$/i', $interfaceValue)) { continue; }
?>
    --<?= $interfaceProperty ?>: <?= htmlspecialchars($interfaceValue, ENT_QUOTES, 'UTF-8') ?>;
<?php endforeach; ?>
}</style>
