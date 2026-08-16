<?php
declare(strict_types=1);

/**
 * Regression guard for discussion #238: user rows must inherit the language
 * selected for the installation, never the historical it_IT schema default or
 * a client-controlled form value.
 *
 * Run: php tests/installation-locale-users-238.unit.php
 */

$root = dirname(__DIR__);
$registration = (string) file_get_contents($root . '/app/Controllers/RegistrationController.php');
$users = (string) file_get_contents($root . '/app/Controllers/UsersController.php');
$mobileRegistration = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/AuthController.php');
$editView = (string) file_get_contents($root . '/app/Views/utenti/modifica_utente.php');

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        echo "PASS: {$label}\n";
        return;
    }

    ++$failed;
    echo "FAIL: {$label}\n";
};

$check(
    str_contains($registration, 'I18n::getInstallationLocale()')
        && str_contains($registration, "tipo_utente, locale, email_verificata"),
    'public registration explicitly persists the installation locale'
);
$check(
    !str_contains($registration, "\$locale = 'it_IT';\n\n        // Ensure timezone"),
    'public registration does not hard-code Italian as the normal path'
);
$check(
    substr_count($users, '$locale = $this->installationLocale();') === 2,
    'admin create and update both use the installation locale'
);
$check(
    !str_contains($users, "\$data['locale']"),
    'admin endpoints ignore client-controlled locale values'
);
$check(
    str_contains($mobileRegistration, 'I18n::getInstallationLocale()')
        && str_contains($mobileRegistration, 'tipo_utente, locale, email_verificata'),
    'Android/API registration explicitly persists the installation locale'
);
$check(
    str_contains($editView, 'id="installation_locale"')
        && str_contains($editView, 'readonly')
        && !str_contains($editView, 'name="locale"'),
    'admin edit exposes installation language as read-only information'
);

$keys = [
    "Lingua dell'applicazione",
    "È una configurazione globale: viene scelta durante l'installazione e può essere modificata dall'amministratore.",
];
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $catalogue = json_decode(
        (string) file_get_contents($root . '/locale/' . $locale . '.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $check(
        isset($catalogue[$keys[0]], $catalogue[$keys[1]])
            && trim((string) $catalogue[$keys[0]]) !== ''
            && trim((string) $catalogue[$keys[1]]) !== '',
        "{$locale} contains the installation-language labels"
    );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
