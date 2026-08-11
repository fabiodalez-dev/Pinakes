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
// Lettura "rumorosa": molti check qui sotto sono NEGATIVI (!str_contains) e
// passerebbero in silenzio su una sorgente vuota. Un file mancante o illeggibile
// deve far fallire subito il test, non farlo diventare verde a vuoto.
$src = static function (string $relPath) use ($root): string {
    $path = $root . '/' . $relPath;
    if (!is_file($path)) {
        fwrite(STDERR, "[FATAL] missing source file: {$relPath}" . PHP_EOL);
        exit(1);
    }
    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        fwrite(STDERR, "[FATAL] unreadable/empty source file: {$relPath}" . PHP_EOL);
        exit(1);
    }
    return $content;
};

$loansIndex = $src('app/Views/prestiti/index.php');
$loanDetails = $src('app/Views/prestiti/dettagli_prestito.php');
$userDetails = $src('app/Views/utenti/dettagli_utente.php');
$bookPage = $src('app/Views/libri/scheda_libro.php');
$pendingLoans = $src('app/Views/admin/pending_loans.php');
$integrityReport = $src('app/Views/admin/integrity_report.php');
$controller = $src('app/Controllers/PrestitiController.php');
$badgePartial = $src('app/Views/partials/loan-status-badge.php');
$helpers = $src('app/helpers.php');
$profileReservations = $src('app/Views/profile/reservations.php');
$userDashboard = $src('app/Views/user_dashboard/prenotazioni.php');
$userActions = $src('app/Controllers/UserActionsController.php');
$userDashboardCtrl = $src('app/Controllers/UserDashboardController.php');

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
    str_contains($userActions, 'COALESCE(pr.data_restituzione, pr.updated_at)')
        && str_contains($userDashboardCtrl, 'COALESCE(pr.data_restituzione, pr.updated_at)'),
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
$statsView = $src('app/Views/admin/stats.php');
$dashboardView = $src('app/Views/dashboard/index.php');
$icsGenerator = $src('app/Support/IcsGenerator.php');
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

echo "== #333 API: mobile /me/loans matches the web history and labels via the helper ==\n";
$mobileActions = $src('storage/plugins/mobile-api/src/Controllers/ActionsController.php');
$mobileOpenApi = $src('storage/plugins/mobile-api/src/Controllers/OpenApiController.php');
$mobilePlugin = json_decode($src('storage/plugins/mobile-api/plugin.json'), true);
$check(
    str_contains($mobileActions, "'restituito','perso','danneggiato','annullato','scaduto'")
        && str_contains($mobileActions, 'COALESCE(pr.data_restituzione, pr.updated_at)'),
    'mobile loans history includes cancelled/expired loans with closing-time order'
);
$check(
    str_contains($mobileActions, "'status_label' => translate_loan_status(\$status)"),
    'mobile loan payload carries a server-localized status_label from the canonical helper'
);
$check(
    str_contains($mobileOpenApi, "'status_label'") && str_contains($mobileOpenApi, "'requested_at'"),
    'OpenAPI schema documents the additive status_label and requested_at fields'
);
// Strutturale (CodeRabbit): estrai la RIGA dello schema e verifica insieme la
// forma 3.1 e l'ASSENZA del flag legacy — "type array + nullable=true" non passa.
$requestedAtSchemaLine = '';
foreach (explode("\n", $mobileOpenApi) as $openApiLine) {
    if (str_contains($openApiLine, "'requested_at'")) {
        $requestedAtSchemaLine = $openApiLine;
        break;
    }
}
$check(
    str_contains($requestedAtSchemaLine, "'type' => ['string', 'null']")
        && !str_contains($requestedAtSchemaLine, "'nullable'"),
    'requested_at declares nullability the OpenAPI 3.1 way (type array, no legacy nullable flag)'
);
$check(
    str_contains($mobileActions, "'requested_at'")
        && substr_count($mobileActions, 'pr.created_at') >= 3,
    'every /me/loans payload carries requested_at, selected in all three queries'
);
$check(
    is_array($mobilePlugin) && version_compare((string) ($mobilePlugin['version'] ?? '0'), '1.4.3', '>='),
    'mobile-api plugin version bumped for the additive API change'
);

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
$updateSource = ($updateStart !== false && $closeStart !== false && $closeStart > $updateStart)
    ? substr($controller, $updateStart, $closeStart - $updateStart)
    : '';
// Guardia: il check negativo qui sotto passerebbe a vuoto su una sezione
// vuota — l'estrazione deve aver realmente trovato il corpo di update().
$check($updateSource !== '', 'update() source section extracted (guards the negative checks below)');
$check(
    str_contains($updateSource, '$claimedWindows')
        && str_contains($updateSource, 'excludePrestitoId: $id'),
    'update() checks capacity on the newly-claimed windows through CapacityService'
);
$check(
    str_contains($updateSource, '$copyOverlap->bind_param(\'iiss\', $copyId, $id, $claimEnd, $claimStart)')
        && str_contains($updateSource, '?error=loan_copy_conflict'),
    'update() checks the assigned copy on the same newly-claimed windows with a dedicated error'
);
// Strutturale (CodeRabbit): i boundary helper devono ALIMENTARE il calcolo
// delle finestre, non solo comparire nel testo della funzione.
$check(
    str_contains($updateSource, '$claimedWindows[] = [$newPrestito, min($dayBefore($oldPrestito), $newScadenza)]')
        && str_contains($updateSource, '$claimedWindows[] = [max($dayAfter($oldScadenza), $newPrestito), $newScadenza]'),
    'claimed windows are the exact set difference (boundary helpers feed the window bounds)'
);
$check(
    substr_count($updateSource, 'isStrictIsoDate(') >= 4
        && !str_contains($updateSource, 'strtotime('),
    'update() validates dates strictly (exact Y-m-d, real calendar) with no strtotime'
);
$check(
    !str_contains($updateSource, 'hasFreeCapacity($libroId, $newPrestito, $newScadenza'),
    'update() no longer re-checks the whole loan window (which bounced every edit)'
);
$bulkStart = strpos($controller, 'private function applyBulkLoanExtension(');
$renewStart = strpos($controller, 'public function renew(');
$bulkSource = ($bulkStart !== false && $renewStart !== false && $renewStart > $bulkStart)
    ? substr($controller, $bulkStart, $renewStart - $bulkStart)
    : '';
$check($bulkSource !== '', 'applyBulkLoanExtension() source section extracted');
$check(
    str_contains($bulkSource, 'hasFreeCapacity($bookId, $extensionStart, $newDueDate')
        && str_contains($bulkSource, '$copyOverlap->bind_param(\'iiss\', $copyId, $loanId, $newDueDate, $extensionStart)'),
    'bulk extension gates capacity AND copy overlap on the same added-days interval'
);
$check(
    str_contains($loansIndex, "case 'no_copies_available':")
        && str_contains($loansIndex, "case 'extension_conflicts':")
        && str_contains($loansIndex, "case 'loan_copy_conflict':"),
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
    'Modifica non salvata: la copia assegnata è già impegnata da un altro prestito nel nuovo periodo.',
    'Rinnovo non riuscito. Riprova.',
];
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $bundle = json_decode($src('locale/' . $locale . '.json'), true);
    $ok = is_array($bundle);
    foreach ($newStrings as $key) {
        $ok = $ok && isset($bundle[$key]) && $bundle[$key] !== '';
    }
    $check($ok, "locale {$locale} translates the new loan error strings");
}

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
