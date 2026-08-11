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

echo "== #333: stato 'annullato' rendered everywhere ==\n";
$check(
    substr_count($loansIndex, "case 'annullato':") >= 2,
    'loans list renders the annullato badge in both the SSR and DataTables paths'
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
    str_contains($loanDetails, "'annullato' => __('Annullato')")
        && str_contains($loanDetails, "'scaduto' => __('Scaduto')"),
    'loan details page labels annullato/scaduto instead of Sconosciuto'
);
$check(
    str_contains($loanDetails, "['annullato', 'scaduto']"),
    'loan details page does not claim "not yet returned" for cancelled/expired loans'
);
$check(
    str_contains($userDetails, "case 'annullato':") && str_contains($userDetails, "case 'da_ritirare':"),
    'user details page labels annullato (and da_ritirare) loans'
);
$check(
    str_contains($bookPage, "case 'annullato':"),
    'admin book page loan history labels annullato loans'
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
