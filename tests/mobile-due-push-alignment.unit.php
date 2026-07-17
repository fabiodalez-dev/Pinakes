<?php
declare(strict_types=1);

/** Regression guards for Android due-date parity and hourly mobile push dispatch. */
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n";
};

$root = dirname(__DIR__);
$actions = (string)file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/ActionsController.php');
$openApi = (string)file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/OpenApiController.php');
$dispatcher = (string)file_get_contents($root . '/storage/plugins/mobile-api/src/Push/PushDispatcher.php');
$settingsRepo = (string)file_get_contents($root . '/app/Models/SettingsRepository.php');
$hourlyCron = (string)file_get_contents($root . '/cron/automatic-notifications.php');

$check(
    // The horizon (key + fallback + clamp) lives in one shared method; both mobile
    // callers delegate to it and neither reads the setting inline or via the old key.
    str_contains($settingsRepo, 'public function daysBeforeExpiryWarning(): int')
        && str_contains($settingsRepo, "get('advanced', 'days_before_expiry_warning', '3')")
        && str_contains($settingsRepo, 'max(0,')
        && substr_count($actions . $dispatcher, '->daysBeforeExpiryWarning()') === 2
        && !str_contains($actions . $dispatcher, "get('advanced', 'days_before_expiry_warning'")
        && !str_contains($actions . $dispatcher, "get('loans', 'reminder_days_before'"),
    'mobile feed and push share the core expiration-warning horizon helper'
);
$check(
    str_contains($dispatcher, '$today = DateHelper::today()')
        && !str_contains($dispatcher, '>= CURDATE()')
        && !str_contains($dispatcher, '< CURDATE()')
        && !str_contains($dispatcher, "SET SESSION time_zone = '+00:00'"),
    'push date boundaries use the application timezone'
);
$check(
    str_contains($dispatcher, "bind_param('ssi', \$today, \$today, \$days)")
        && str_contains($dispatcher, "bind_param('s', \$today)"),
    'due and overdue push queries bind the authoritative application date'
);
$check(
    str_contains($actions, "'due_attention'") && str_contains($openApi, "'due_attention'"),
    'loan API and OpenAPI expose the server-authoritative due-date cue'
);
$check(
    str_contains($hourlyCron, "doAction('mobile_api.dispatch_push')"),
    'hourly notification cron dispatches native mobile push'
);

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
