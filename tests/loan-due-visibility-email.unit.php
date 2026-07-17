<?php
declare(strict_types=1);

/** Static regression guards for due-date visibility and warning selection. */
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
$notifications = (string)file_get_contents($root . '/app/Support/NotificationService.php');
$api = (string)file_get_contents($root . '/app/Controllers/PrestitiApiController.php');
$loansView = (string)file_get_contents($root . '/app/Views/prestiti/index.php');
$dashboardView = (string)file_get_contents($root . '/app/Views/dashboard/index.php');

$check(
    str_contains($notifications, 'p.data_scadenza BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)')
        && str_contains($notifications, "bind_param('sssi', \$today, \$today, \$today, \$daysBeforeWarning)"),
    'expiration warnings include due-today loans through the configured horizon'
);
$check(
    str_contains($notifications, 'scade oggi') && str_contains($notifications, '$daysRemaining === 0'),
    'due-today in-app notification has accurate wording'
);
$check(
    preg_match("/3\s*=>\s*'p\\.data_scadenza'/", $api) === 1
        && preg_match("/4\s*=>\s*'p\\.stato'/", $api) === 1,
    'DataTables due-date and status columns map to their real SQL fields'
);
$check(
    str_contains($loansView, 'DateHelper::today()')
        && str_contains($loansView, 'data <= applicationToday')
        && !str_contains($loansView, "strtotime(date('Y-m-d'))"),
    'loan list highlighting uses the application date in PHP and JavaScript'
);
$check(
    str_contains($dashboardView, 'DateHelper::today()')
        && !str_contains($dashboardView, "strtotime(date('Y-m-d'))"),
    'dashboard due-date highlighting uses the application timezone'
);

foreach (['it_IT', 'en_US', 'fr_FR', 'de_DE'] as $locale) {
    $catalogue = json_decode((string)file_get_contents($root . "/locale/{$locale}.json"), true);
    $check(
        is_array($catalogue) && isset($catalogue['"%s" prestato a %s scade oggi']),
        "{$locale} translates the due-today notification"
    );
}

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
