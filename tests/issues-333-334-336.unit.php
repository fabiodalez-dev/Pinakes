<?php
declare(strict_types=1);

/**
 * Regression guards for GitHub issues #333, #334 and #336 (loans).
 *
 * #333 — a loan cancelled by the user (stato='annullato') rendered as
 *        "Sconosciuto/Unknown" in every admin view: the stato enum contains
 *        'annullato' but no badge/label switch handled it.
 * #334 — the loans-overview page header (sticky top-0 z-30, later in the DOM
 *        than the layout header at the same z-index) painted OVER the layout
 *        header and its notifications dropdown.
 * #336 — editing a loan's dates re-checked capacity on the WHOLE new window,
 *        so commitments already coexisting with the current period (e.g. a
 *        queued reservation) bounced ANY date edit with no_copies_available;
 *        renew()'s 'extension_conflicts' error key was also unknown to the
 *        book page banner, which showed a generic message.
 *
 * Run: php tests/issues-333-334-336.unit.php
 */
$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$loansIndex = (string) file_get_contents($root . '/app/Views/prestiti/index.php');
$loanDetails = (string) file_get_contents($root . '/app/Views/prestiti/dettagli_prestito.php');
$userDetails = (string) file_get_contents($root . '/app/Views/utenti/dettagli_utente.php');
$bookPage = (string) file_get_contents($root . '/app/Views/libri/scheda_libro.php');
$pendingLoans = (string) file_get_contents($root . '/app/Views/admin/pending_loans.php');
$integrityReport = (string) file_get_contents($root . '/app/Views/admin/integrity_report.php');
$controller = (string) file_get_contents($root . '/app/Controllers/PrestitiController.php');
$badgePartial = (string) file_get_contents($root . '/app/Views/partials/loan-status-badge.php');
$helpers = (string) file_get_contents($root . '/app/helpers.php');
$profileReservations = (string) file_get_contents($root . '/app/Views/profile/reservations.php');
$userDashboard = (string) file_get_contents($root . '/app/Views/user_dashboard/prenotazioni.php');
$userActions = (string) file_get_contents($root . '/app/Controllers/UserActionsController.php');
$userDashboardCtrl = (string) file_get_contents($root . '/app/Controllers/UserDashboardController.php');

echo "== #333: canonical badge covers the whole stato enum, used by every admin view ==\n";
// The shared partial is the ONLY badge map: every enum value must be there,
// and the labels must come from the same helper the PDF/CSV already use.
foreach (['pendente', 'prenotato', 'da_ritirare', 'in_corso', 'in_ritardo', 'restituito', 'perso', 'danneggiato', 'scaduto', 'annullato'] as $stato) {
    $check(
        str_contains($badgePartial, "'{$stato}'"),
        "canonical badge map covers '{$stato}'"
    );
}
$check(
    str_contains($badgePartial, 'translate_loan_status(')
        && str_contains($helpers, "'annullato' => __('Annullato')"),
    'badge labels come from translate_loan_status(), which maps annullato'
);
foreach ([
    'loans list' => $loansIndex,
    'loan details page' => $loanDetails,
    'user details page' => $userDetails,
    'admin book page' => $bookPage,
] as $surface => $source) {
    $check(
        str_contains($source, 'loan-status-badge.php') && str_contains($source, 'loan_status_badge('),
        "{$surface} renders states through the shared badge partial"
    );
}
$check(
    str_contains($loansIndex, 'loan_status_badge_map()')
        && !str_contains($loansIndex, "case 'in_corso':"),
    'DataTables status column uses the PHP-generated map, no duplicated JS switch'
);
$check(
    str_contains($loansIndex, 'data-status="annullato"'),
    'loans list offers an Annullato status filter button'
);
$check(
    str_contains($loansIndex, 'value="annullato"'),
    'CSV export dialog includes the annullato state'
);
$check(
    str_contains($loanDetails, "['annullato', 'scaduto']"),
    'loan details page does not claim "not yet returned" for cancelled/expired loans'
);

echo "== #333 follow-up: cancelled loans appear in the user-facing history ==\n";
$check(
    substr_count($userActions, "'restituito','perso','danneggiato','annullato','scaduto'") === 1,
    'profile history query includes cancelled/expired loans'
);
$check(
    substr_count($userDashboardCtrl, "'restituito','perso','danneggiato','annullato','scaduto'") === 2,
    'user dashboard history query AND its counter include cancelled/expired loans'
);
$check(
    str_contains($userActions, 'COALESCE(pr.data_restituzione, DATE(pr.updated_at))')
        && str_contains($userDashboardCtrl, 'COALESCE(pr.data_restituzione, DATE(pr.updated_at))'),
    'history sorts cancelled loans by closing time instead of sinking NULL return dates'
);
$check(
    str_contains($profileReservations, "'annullato' => 'fa-ban'")
        && str_contains($userDashboard, "'annullato' => 'fa-ban'"),
    'both user history views give cancelled loans a dedicated icon'
);
$check(
    str_contains($profileReservations, '$canReview') && str_contains($userDashboard, '$canReview'),
    'history hides the review button for loans that never went out (annullato/scaduto)'
);

echo "== #333 sweep: every stato-label consumer routes through the canonical helpers ==\n";
$statsView = (string) file_get_contents($root . '/app/Views/admin/stats.php');
$dashboardView = (string) file_get_contents($root . '/app/Views/dashboard/index.php');
$icsGenerator = (string) file_get_contents($root . '/app/Support/IcsGenerator.php');
$check(
    str_contains($helpers, 'function loan_status_label_map()')
        && str_contains($helpers, "translate_loan_status(\$stato)"),
    'helpers.php exposes the enum-wide label map built on translate_loan_status()'
);
$check(
    str_contains($statsView, 'loan_status_label_map()'),
    'stats chart labels come from the canonical label map'
);
$check(
    str_contains($dashboardView, 'loan_status_label_map()'),
    'dashboard calendar labels come from the canonical label map'
);
$check(
    str_contains($icsGenerator, 'translate_loan_status($status)'),
    'ICS feed status labels delegate to translate_loan_status()'
);
$check(
    str_contains($bookPage, 'translate_loan_status((string) $stato)'),
    'book page occupancy calendar labels delegate to translate_loan_status()'
);
$check(
    str_contains($profileReservations, 'translate_loan_status(')
        && str_contains($userDashboard, 'translate_loan_status('),
    'user history views delegate labels to translate_loan_status()'
);
// No hand-maintained stato→label literals left outside the two helpers: the
// old maps always spelled a quoted state key next to a __() label call.
foreach ([
    'admin stats view' => $statsView,
    'admin dashboard view' => $dashboardView,
] as $surface => $source) {
    $check(
        !preg_match('/[\'"]in_corso[\'"]\s*(=>|:)\s*(<\?=\s*)?(json_encode\()?__\(/', $source),
        "{$surface} keeps no local stato→label literal map"
    );
}

echo "== #334: page header no longer covers the notifications dropdown ==\n";
// Match class attributes only — the explanatory comments in those views cite
// the removed utility string verbatim.
$check(
    !preg_match('/class="[^"]*sticky top-0 z-30/', $pendingLoans),
    'loans overview page header is not sticky at the layout header z-index'
);
$check(
    !preg_match('/class="[^"]*sticky top-0 z-30/', $integrityReport),
    'integrity report page header is not sticky at the layout header z-index'
);

echo "== #336: date edits check only newly-claimed days; clear error messages ==\n";
$updateStart = strpos($controller, 'public function update(');
$closeStart = strpos($controller, 'public function close(');
$updateSource = ($updateStart !== false && $closeStart !== false)
    ? substr($controller, $updateStart, $closeStart - $updateStart)
    : '';
$check(
    str_contains($updateSource, '$claimedWindows')
        && str_contains($updateSource, 'excludePrestitoId: $id'),
    'update() checks capacity on the newly-claimed windows through CapacityService'
);
$check(
    !str_contains($updateSource, 'hasFreeCapacity($libroId, $newPrestito, $newScadenza'),
    'update() no longer re-checks the whole loan window (which bounced every edit)'
);
$bulkStart = strpos($controller, 'private function applyBulkLoanExtension(');
$renewStart = strpos($controller, 'public function renew(');
$bulkSource = ($bulkStart !== false && $renewStart !== false)
    ? substr($controller, $bulkStart, $renewStart - $bulkStart)
    : '';
$check(
    str_contains($bulkSource, "hasFreeCapacity(\$bookId, (string) \$loan['data_scadenza'], \$newDueDate"),
    'bulk extension checks the extension window only, like renew()'
);
$check(
    str_contains($loansIndex, "case 'no_copies_available':")
        && str_contains($loansIndex, "case 'extension_conflicts':"),
    'loans list banner explains capacity conflicts instead of a generic error'
);
$check(
    str_contains($bookPage, "case 'extension_conflicts':")
        && str_contains($bookPage, "case 'renewal_failed':"),
    "book page banner recognizes renew()'s actual error keys"
);

// The new user-facing strings must be translated in every bundled locale.
$newStrings = [
    'Modifica non salvata: nel nuovo periodo tutte le copie sono già impegnate da altri prestiti o prenotazioni.',
    'Rinnovo non riuscito. Riprova.',
];
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $bundle = json_decode((string) file_get_contents($root . '/locale/' . $locale . '.json'), true);
    $ok = is_array($bundle);
    foreach ($newStrings as $key) {
        $ok = $ok && isset($bundle[$key]) && $bundle[$key] !== '';
    }
    $check($ok, "locale {$locale} translates the new loan error strings");
}

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
