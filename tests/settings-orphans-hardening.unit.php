<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\HtmlHelper;
use App\Support\SettingsMailTemplates;

$checks = [];
$checks['https URL accepted'] = HtmlHelper::sanitizePublicHttpUrl('https://example.org/cookies') === 'https://example.org/cookies';
$checks['http URL accepted'] = HtmlHelper::sanitizePublicHttpUrl('http://example.org/cookies') === 'http://example.org/cookies';
$checks['javascript URL rejected'] = HtmlHelper::sanitizePublicHttpUrl('javascript:alert(1)') === '';
$checks['data URL rejected'] = HtmlHelper::sanitizePublicHttpUrl('data:text/html,test') === '';
$checks['credential-bearing URL rejected'] = HtmlHelper::sanitizePublicHttpUrl('https://user:pass@example.org/cookies') === '';
$checks['control characters rejected'] = HtmlHelper::sanitizePublicHttpUrl("https://example.org/\ncookies") === '';
$checks['relative URL rejected for admin-provided public link'] = HtmlHelper::sanitizePublicHttpUrl('/cookies') === '';

foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR'] as $locale) {
    $allTemplates = SettingsMailTemplates::all($locale);
    $checks["all 22 templates exist for {$locale}"] = count($allTemplates) === 22;
    $template = SettingsMailTemplates::get('user_registration_verification', $locale);
    $checks["verification template exists for {$locale}"] = is_array($template)
        && str_contains((string) ($template['body'] ?? ''), '{{sezione_verifica}}')
        && !str_contains(strtolower((string) ($template['subject'] ?? '')), 'pending approval');
    foreach ($allTemplates as $name => $definition) {
        preg_match_all('/\{\{([^}]+)\}\}/', (string) $definition['subject'] . (string) $definition['body'], $matches);
        $actual = array_values(array_unique($matches[1]));
        $declared = $definition['placeholders'] ?? [];
        sort($actual);
        sort($declared);
        $checks["placeholder parity {$locale}/{$name}"] = $actual === $declared;
    }
}

$banner = file_get_contents($root . '/app/Views/partials/cookie-banner.php');
$controller = file_get_contents($root . '/app/Controllers/SettingsController.php');
$notifications = file_get_contents($root . '/app/Support/NotificationService.php');
$checks['unsupported statementUrl config removed'] = !str_contains($banner, 'statementUrl:');
$checks['cookie links revalidated when rendered'] = substr_count($banner, 'sanitizePublicHttpUrl(') >= 2;
$checks['built-in cookie page remains the fallback'] = str_contains($banner, "route_path('cookies')");
$checks['privacy save validates both links'] = substr_count($controller, 'HtmlHelper::sanitizePublicHttpUrl(') >= 2;
$checks['registration notification selects the flag-specific template'] = str_contains($notifications, "'user_registration_verification'")
    && str_contains($notifications, "'user_registration_pending'")
    && str_contains($notifications, "registration.require_admin_approval");

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);
