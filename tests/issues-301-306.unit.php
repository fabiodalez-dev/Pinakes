<?php
declare(strict_types=1);

/** Regression guards for GitHub issues #301 and #306. */
$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$authors = (string) file_get_contents($root . '/app/Controllers/AutoriApiController.php');
$frontend = (string) file_get_contents($root . '/app/Controllers/FrontendController.php');
$publishers = (string) file_get_contents($root . '/app/Controllers/EditoriApiController.php');
$settingsRepo = (string) file_get_contents($root . '/app/Models/SettingsRepository.php');
$settingsController = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$settingsView = (string) file_get_contents($root . '/app/Views/settings/loans-tab.php');
$actions = (string) file_get_contents($root . '/app/Controllers/UserActionsController.php');
$approval = (string) file_get_contents($root . '/app/Controllers/LoanApprovalController.php');
$mobileActions = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/ActionsController.php');
$mobileHealth = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/HealthController.php');
$mobileDocs = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/OpenApiController.php');
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.46.sql');

$check(
    substr_count($authors, 'JOIN libri l ON l.id = la.libro_id AND l.deleted_at IS NULL') === 2,
    'author list and bulk export count only non-deleted books'
);
$check(
    str_contains($frontend, 'SELECT COUNT(DISTINCT l.id)')
        && str_contains($frontend, 'WHERE l.deleted_at IS NULL')
        && str_contains($frontend, 'OR l.sottogenere_id = g.id'),
    'genre facet count excludes soft-deleted books'
);
$check(
    substr_count($publishers, 'AND l.deleted_at IS NULL') >= 3,
    'publisher list/filter/export counts exclude soft-deleted books'
);

$check(str_contains($settingsRepo, 'function autoApproveLoanRequests()'), 'loan setting has a shared typed accessor');
$check(
    str_contains($settingsController, "set('loans', 'auto_approve_requests'")
        && str_contains($settingsView, 'name="auto_approve_requests"')
        && str_contains($settingsView, 'class="toggle-checkbox sr-only"'),
    'loan settings persist an accessible toggle using the existing switch component'
);
// Param-agnostic marker: the call gained a pre-read $autoApproveEnabled arg when
// /user/loan started pre-binding the copy before commit. The invariant this
// guards is the ORDER (auto-approve → guard → admin notify), not the exact args.
$autoApproveCall = strpos($actions, 'autoApproveLoanRequest($request, $db, $newLoanId');
$autoApprovedGuard = strpos($actions, 'if (!$autoApproved)');
$notifyCall = strpos($actions, 'notifyLoanRequest($newLoanId)');
$check(
    // Guard the markers before ordering: strpos() returns false when a marker is
    // gone, and false < int is true in PHP, so a rename/removal would otherwise
    // pass this silently.
    $autoApproveCall !== false && $autoApprovedGuard !== false && $notifyCall !== false
        && $autoApproveCall < $autoApprovedGuard
        && $autoApprovedGuard < $notifyCall,
    'automatic approval runs before email I/O and suppresses the now-stale administrator action notification'
);
$check(
    str_contains($approval, "getAttribute('automatic_loan_approval'")
        && str_contains($approval, 'sendLoanApprovedNotification($loanId)'),
    'automatic approval sends the patron approval email'
);
$check(
    str_contains($mobileActions, "'auto_approved' => \$autoApproved")
        && str_contains($mobileActions, "'status' => \$autoApproved ? 'da_ritirare' : 'pendente'")
        && str_contains($mobileHealth, "'loan_approval_required' => \$loanApprovalRequired")
        && str_contains($mobileDocs, 'data.auto_approved'),
    'Mobile API advertises the setting and returns the actual request outcome'
);
$check(
    str_contains($migration, "'auto_approve_requests', '0'")
        && str_contains($migration, 'ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`'),
    'upgrade migration is idempotent and preserves manual approval by default'
);

foreach (['it_IT', 'en_US', 'fr_FR', 'de_DE', 'da_DK'] as $locale) {
    $catalog = json_decode((string) file_get_contents($root . "/locale/{$locale}.json"), true, 512, JSON_THROW_ON_ERROR);
    $check(isset($catalog['Approvazione automatica']), "{$locale} includes auto-approval translations");
}

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
