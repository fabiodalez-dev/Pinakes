<?php
declare(strict_types=1);

/** Regression coverage for the follow-up review fixes on PR #376. */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
function auditOk(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $passed++;
    fwrite(STDOUT, "PASS: {$message}\n");
}
function auditRead(string $path): string
{
    $content = file_get_contents($path);
    auditOk($content !== false, 'read ' . basename($path));
    return (string) $content;
}

// SSRF guard: private literals must be rejected before any connection attempt.
$loopback = \App\Support\HttpClient::get('http://127.0.0.1:9/test', [], ['ssrf_guard' => true]);
auditOk($loopback === ['ok' => false, 'status' => 0, 'body' => ''], 'HttpClient blocks IPv4 loopback');
$private = \App\Support\HttpClient::get('https://10.0.0.1/test', [], ['ssrf_guard' => true]);
auditOk($private === ['ok' => false, 'status' => 0, 'body' => ''], 'HttpClient blocks RFC1918 targets');

// #382 / PR #383 review follow-up: author-photo downloads must be bounded
// during transfer, not merely checked after the entire body is in memory.
$authors = auditRead($root . '/app/Controllers/AutoriController.php');
$httpClient = auditRead($root . '/app/Support/HttpClient.php');
auditOk(
    str_contains($authors, "'max_bytes'    => 5 * 1024 * 1024"),
    'author URL download passes the 5 MB transfer cap'
);
auditOk(
    str_contains($httpClient, 'RequestOptions::PROGRESS')
        && str_contains($httpClient, 'downloaded body exceeds max_bytes'),
    'HttpClient aborts an in-progress response after max_bytes'
);

// #381 / PR #383 review follow-up: every caller, including an API request that
// omits `reason`, must receive neutral cancellation wording.
$loanSwal = auditRead($root . '/app/Views/partials/loan-actions-swal.php');
$notifications = auditRead($root . '/app/Support/NotificationService.php');
auditOk(
    str_contains($loanSwal, "'cancelPickupReason' => __('Ritiro annullato')"),
    'pickup cancellation dialog submits a neutral reason'
);
auditOk(
    str_contains($notifications, "\$reason !== ''")
        && str_contains($notifications, "\$this->translateInLocale('Ritiro annullato', \$recipientLocale)"),
    'pickup cancellation email fallback is neutral, recipient-localized and preserves "0"'
);
auditOk(
    str_contains($notifications, "\$terminalState === 'scaduto'")
        && str_contains($notifications, "'loan_pickup_expired'"),
    'an already-expired pickup uses the expired notification template'
);

// #384 concurrency follow-up: an automatic request must hold its selected copy
// before the creation transaction releases the canonical book lock.
$reservationsController = auditRead($root . '/app/Controllers/ReservationsController.php');
auditOk(
    str_contains($reservationsController, '$preassignedCopyId = $autoApproveEnabled ? $assignableCopyId : null')
        && str_contains($reservationsController, '(libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)'),
    'auto-approved requests persist a copy-bound hold before commit'
);

$ai = auditRead($root . '/storage/plugins/book-club/src/AiService.php');
$sru = auditRead($root . '/storage/plugins/z39-server/classes/SruClient.php');
auditOk(substr_count($ai, "'ssrf_guard'      => true") === 1, 'Book Club enables the SSRF guard');
auditOk(substr_count($sru, "'ssrf_guard'      => true") === 1, 'SRU enables the SSRF guard');

// Dewey readers and writers must agree on the full-locale canonical path.
auditOk(
    basename(\App\Support\DeweyDataFiles::canonicalPath('it_IT')) === 'dewey_completo_it_IT.json',
    'Dewey canonical path retains the full locale'
);
auditOk(
    basename(\App\Support\DeweyDataFiles::resolveReadPath('it_IT')) === 'dewey_completo_it.json',
    'Dewey reads fall back to the legacy file before migration'
);
$deweyApi = auditRead($root . '/app/Controllers/DeweyApiController.php');
$deweyAuto = auditRead($root . '/app/Support/DeweyAutoPopulator.php');
$deweyPlugin = auditRead($root . '/storage/plugins/dewey-editor/DeweyEditorPlugin.php');
auditOk(str_contains($deweyApi, 'DeweyDataFiles::resolveReadPath'), 'Dewey API uses the shared resolver');
auditOk(str_contains($deweyAuto, 'DeweyDataFiles::canonicalPath'), 'Dewey auto-populator writes the canonical file');
auditOk(str_contains($deweyPlugin, 'DeweyDataFiles::canonicalPath'), 'Dewey Editor writes the canonical file');

// ResourceSync must advertise the representation actually returned by <loc>.
$resourceSync = auditRead($root . '/storage/plugins/resource-sync/ResourceSyncPlugin.php');
auditOk(
    substr_count($resourceSync, "writeAttribute('type', \$this->resourceType())") === 2,
    'ResourceSync derives MIME type in both resource and change lists'
);
auditOk(
    str_contains($resourceSync, "return \$this->bibframeActive() ? 'application/ld+json' : 'text/html';"),
    'ResourceSync HTML fallback declares text/html'
);

// Editing an Expression must retain role authors beyond the 1,000-row picker cap.
$frbr = auditRead($root . '/storage/plugins/frbr-lrm/FrbrLrmPlugin.php');
auditOk(str_contains($frbr, "'autori' => \$this->autoriForSelect(\$currentAuthorIds)"), 'Expression edit includes current role authors');
auditOk(str_contains($frbr, 'WHERE a.id IN ({$placeholders})'), 'FRBR fetches required authors beyond the cap');

fwrite(STDOUT, "\nAll {$passed} assertions passed.\n");
