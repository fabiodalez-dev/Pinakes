<?php
declare(strict_types=1);

/**
 * Regression guard for discussion #238: new users inherit the installation
 * language, while each user can subsequently keep a validated preference.
 *
 * Run: php tests/installation-locale-users-238.unit.php
 */

$root = dirname(__DIR__);
$registration = (string) file_get_contents($root . '/app/Controllers/RegistrationController.php');
$users = (string) file_get_contents($root . '/app/Controllers/UsersController.php');
$mobileRegistration = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/AuthController.php');
$editView = (string) file_get_contents($root . '/app/Views/utenti/modifica_utente.php');
$createView = (string) file_get_contents($root . '/app/Views/utenti/crea_utente.php');
$auth = (string) file_get_contents($root . '/app/Controllers/AuthController.php');
$rememberMe = (string) file_get_contents($root . '/app/Middleware/RememberMeMiddleware.php');
$languageController = (string) file_get_contents($root . '/app/Controllers/LanguageController.php');
$profileController = (string) file_get_contents($root . '/app/Controllers/ProfileController.php');

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
    str_contains($users, "\$locale = \$this->localeFromInput(\$data['locale'] ?? null, \$this->installationLocale());"),
    'admin create accepts an active per-user locale with installation fallback'
);
$check(
    str_contains($users, "\$this->localeFromInput(\$data['locale'] ?? null, (string) (\$original['locale'] ?? ''))"),
    'admin update preserves the current user locale for omitted or invalid input'
);
$check(
    str_contains($users, 'isset($availableLocales[$requested])')
        && str_contains($users, 'isset($availableLocales[$current])'),
    'admin locale input and fallback are restricted to active languages'
);
$check(
    str_contains($users, "\$_SESSION['locale'] = \$locale;")
        && str_contains($users, "\$_SESSION['user']['locale'] = \$locale;"),
    'admin self-edit applies the selected locale immediately'
);
$check(
    str_contains($mobileRegistration, 'I18n::getInstallationLocale()')
        && str_contains($mobileRegistration, 'tipo_utente, locale, email_verificata'),
    'Android/API registration explicitly persists the installation locale'
);
$check(
    str_contains($editView, '<select id="locale" name="locale"')
        && str_contains($editView, '$code === $selectedLocale')
        && !str_contains($editView, 'id="installation_locale"'),
    'admin edit exposes an editable per-user locale selector'
);
$check(
    str_contains($createView, '<select id="locale" name="locale"')
        && str_contains($createView, '$code === $selectedLocale'),
    'admin create can choose the new user initial locale'
);
$check(
    str_contains($auth, 'I18n::resolveUserLocale($row[\'locale\'] ?? null)')
        && str_contains($rememberMe, 'I18n::resolveUserLocale($row[\'locale\'] ?? null)'),
    'password and remember-me login both restore the per-user locale'
);
$check(
    str_contains($languageController, "\$_SESSION['user']['locale'] = \$locale;"),
    'runtime language switch keeps the authenticated session coherent'
);
$check(
    str_contains($profileController, "elseif (!isset(\$availableLocales[\$locale]))")
        && str_contains($profileController, '$localeProvided = false;'),
    'profile update ignores unknown locale values instead of resetting the preference'
);

$keys = [
    "Lingua dell'interfaccia",
    "L'utente potrà cambiarla in qualsiasi momento.",
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
        "{$locale} contains the per-user interface-language labels"
    );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
