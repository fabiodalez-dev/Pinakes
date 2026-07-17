<?php
declare(strict_types=1);

/**
 * Static coverage for issue #257 (Telegram social link).
 *
 * Run:
 *   php tests/issue-257-telegram-social-links.unit.php
 */

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) {
        $passed++;
        echo "  OK   {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n";
};

$read = static function (string $rel) use ($root): string {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$rel}");
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Unreadable file: {$rel}");
    }
    return $content;
};

try {
    $configStore = $read('app/Support/ConfigStore.php');
    $settingsController = $read('app/Controllers/SettingsController.php');
    $settingsView = $read('app/Views/settings/index.php');
    $frontendLayout = $read('app/Views/frontend/layout.php');
    $userLayout = $read('app/Views/user_layout.php');
    $migration = $read('installer/database/migrations/migrate_0.7.35.sql');
    $seedEn = $read('installer/database/data_en_US.sql');
    $seedDe = $read('installer/database/data_de_DE.sql');
    $seedFr = $read('installer/database/data_fr_FR.sql');
    $seedIt = $read('installer/database/data_it_IT.sql');

    $check(str_contains($configStore, "'social_telegram' => ''"), 'ConfigStore default includes social_telegram');
    $check(str_contains($settingsController, "'social_telegram' => trim((string) (\$data['social_telegram'] ?? ''))"), 'SettingsController saves social_telegram');
    $check(str_contains($settingsController, "'social_telegram' => \$repository->get('app', 'social_telegram', \$config['social_telegram'] ?? '')"), 'SettingsController resolves social_telegram');
    $check(str_contains($settingsView, 'id="social_telegram"'), 'Settings view has social_telegram input');
    $check(str_contains($settingsView, 'class="fa-brands fa-telegram mr-1"'), 'Settings view uses Telegram icon');
    $check(str_contains($frontendLayout, "ConfigStore::get('app.social_telegram', '')"), 'Frontend layout loads social_telegram');
    $check(str_contains($frontendLayout, 'class="fa-brands fa-telegram"'), 'Frontend layout renders Telegram icon');
    $check(str_contains($userLayout, "ConfigStore::get('app.social_telegram', '')"), 'User layout loads social_telegram');
    $check(str_contains($userLayout, 'class="fa-brands fa-telegram"'), 'User layout renders Telegram icon');
    $check(str_contains($migration, "'app', 'social_telegram', '', 'Telegram profile URL'"), 'Migration inserts social_telegram');
    $check(str_contains($seedEn, "('app', 'social_telegram', '', 'Telegram profile URL', NOW())"), 'en_US seed includes social_telegram');
    $check(str_contains($seedDe, "('app', 'social_telegram', '', 'Telegram-Profil-URL', NOW())"), 'de_DE seed includes social_telegram');
    $check(str_contains($seedFr, "('app', 'social_telegram', '', 'URL du profil Telegram', NOW())"), 'fr_FR seed includes social_telegram');
    $check(str_contains($seedIt, "('app', 'social_telegram', '', 'Telegram profile URL', NOW())"), 'it_IT seed includes social_telegram');
} catch (Throwable $e) {
    $failed++;
    echo "  FAIL exception: " . $e->getMessage() . "\n";
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
