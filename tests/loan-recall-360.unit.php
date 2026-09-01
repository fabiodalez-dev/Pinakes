<?php
declare(strict_types=1);

/**
 * DB-free contract test for issue #360 — overdue-loan recalls (solleciti) and
 * emailing the loan receipt PDF.
 *
 * Locks:
 *  - the two new mail templates exist for every shipped locale, with the
 *    placeholders the senders actually bind;
 *  - the English alias map covers the new {{numero_sollecito}} placeholder;
 *  - the mailers expose the attachment path the receipt email relies on;
 *  - NotificationService / PrestitiController expose the recall + receipt API
 *    and the routes/UI wire them up.
 *
 * Run:  php tests/loan-recall-360.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\EmailService;
use App\Support\NotificationService;
use App\Support\SettingsMailTemplates;

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

// --- Templates -------------------------------------------------------------

$recallPlaceholders = ['utente_nome', 'libro_titolo', 'data_scadenza', 'giorni_ritardo', 'numero_sollecito'];
$receiptPlaceholders = ['utente_nome', 'libro_titolo', 'data_prestito', 'data_scadenza'];

foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $recall = SettingsMailTemplates::get('loan_recall_notification', $locale);
    $ok = $recall !== null;
    foreach ($recallPlaceholders as $token) {
        $ok = $ok && str_contains((string) ($recall['body'] ?? ''), '{{' . $token . '}}');
    }
    $check($ok, "{$locale}: loan_recall_notification exists and binds every recall placeholder");

    $receipt = SettingsMailTemplates::get('loan_receipt_email', $locale);
    $ok = $receipt !== null;
    foreach ($receiptPlaceholders as $token) {
        $ok = $ok && str_contains((string) ($receipt['body'] ?? ''), '{{' . $token . '}}');
    }
    $ok = $ok && str_contains((string) ($receipt['subject'] ?? ''), '{{prestito_id}}');
    $check($ok, "{$locale}: loan_receipt_email exists and binds every receipt placeholder");
}

$check(
    array_key_exists('numero_sollecito', SettingsMailTemplates::placeholderDescriptions()),
    'placeholderDescriptions documents numero_sollecito for the template editor'
);

// --- Placeholder alias -----------------------------------------------------

$aliases = (new ReflectionClassConstant(EmailService::class, 'PLACEHOLDER_ALIASES'))->getValue();
$check(
    ($aliases['recall_number'] ?? null) === 'numero_sollecito',
    'EmailService maps the recall_number alias to numero_sollecito'
);

// --- Attachment support ----------------------------------------------------

foreach (['sendTemplate', 'sendEmail'] as $method) {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(EmailService::class, $method))->getParameters()
    );
    $check(in_array('attachments', $params, true), "EmailService::{$method} accepts attachments");
}

// --- NotificationService API ------------------------------------------------

foreach (['sendLoanRecalls', 'sendManualRecall', 'sendLoanReceiptEmail'] as $method) {
    $check(
        method_exists(NotificationService::class, $method)
            && (new ReflectionMethod(NotificationService::class, $method))->isPublic(),
        "NotificationService::{$method} is part of the public API"
    );
}

$runSource = file_get_contents(__DIR__ . '/../app/Support/NotificationService.php');
$check(
    str_contains((string) $runSource, "'loan_recalls'")
        && str_contains((string) $runSource, 'sendLoanRecalls()'),
    'runAutomaticNotifications reports and runs the recall pass'
);
$check(
    str_contains((string) $runSource, "'recall_auto_enabled'")
        && str_contains((string) $runSource, "'recall_interval_days'")
        && str_contains((string) $runSource, "'recall_max_count'")
        && str_contains((string) $runSource, 'SettingsRepository')
        // Regression guard (#360 P1): the recall schedule MUST be read via
        // SettingsRepository, not ConfigStore::get('loans.*'). ConfigStore's
        // loadDatabaseSettings() has no 'loans' category mapping, so those
        // reads always return the code default — recall_auto_enabled resolves
        // to '0' regardless of the admin toggle and the scheduler never fires.
        // Regex so both quote spellings are caught, not just the single-quoted one.
        && preg_match('/ConfigStore::get\(\s*[\'"]loans\./', (string) $runSource) !== 1,
    'automatic recalls read the loans.recall_* settings via SettingsRepository (not the inert ConfigStore loans.* path)'
);
// The atomic claim must re-assert due date + counter so a concurrent
// renew()/recall claims at most once (#252/M3 pattern). Assert the full
// UPDATE guard so removing EITHER the due-date predicate or the counter
// predicate fails the test.
$check(
    str_contains(
        (string) $runSource,
        'SET recall_count = recall_count + 1, last_recall_at = ? WHERE id = ? AND data_scadenza = ? AND recall_count = ?'
    ),
    'recall claim is atomic (due date AND counter re-asserted in the UPDATE guard)'
);

// --- Recipient-language emails ----------------------------------------------

// Review finding (#361): installs seed email_templates only for the
// installation language, so the DB fallback chain (recipient -> en_US ->
// it_IT) used to land on the installation row and per-recipient localization
// never materialized. getEmailTemplate must prefer the SHIPPED default for
// the requested locale over another locale's stored row — gated on
// hasShippedLocale so custom languages keep winning cross-locale
// customizations, and same-language variants (it_CH -> it_IT) keep the row.
$check(
    SettingsMailTemplates::hasShippedLocale('en_US')
        && SettingsMailTemplates::hasShippedLocale('de_DE')
        && SettingsMailTemplates::hasShippedLocale('it_CH')
        && !SettingsMailTemplates::hasShippedLocale('xx_XX')
        && !SettingsMailTemplates::hasShippedLocale(''),
    'hasShippedLocale recognizes shipped translations (and only those)'
);
$emailServiceSrc = file_get_contents(__DIR__ . '/../app/Support/EmailService.php');
$check(
    str_contains((string) $emailServiceSrc, 'hasShippedLocale')
        && str_contains((string) $emailServiceSrc, "substr(\$candidateLocale, 0, 2) !== substr(\$locale, 0, 2)"),
    'getEmailTemplate prefers the shipped requested-locale default over another locale\'s stored row'
);

$formatParams = array_map(
    static fn (ReflectionParameter $p): string => $p->getName(),
    (new ReflectionMethod(NotificationService::class, 'formatEmailDate'))->getParameters()
);
$check(in_array('locale', $formatParams, true), 'formatEmailDate accepts a per-recipient locale');

$check(
    method_exists(NotificationService::class, 'resolveRecipientLocale')
        && str_contains((string) $runSource, 'SELECT locale FROM utenti WHERE email = ?'),
    'recipient locale resolves from utenti.locale'
);
$registrationPendingSrc = substr(
    (string) $runSource,
    (int) strpos((string) $runSource, 'public function sendUserRegistrationPending'),
    4000
);
$check(
    str_contains($registrationPendingSrc, 'formatEmailDate($user[\'created_at\'], true, $locale)'),
    'registration email formats its date in the recipient locale'
);
// The retry sender must use the recipient's language, not the installation's.
$sendWithRetrySrc = substr(
    (string) $runSource,
    (int) strpos((string) $runSource, 'private function sendWithRetry'),
    1200
);
$check(
    str_contains($sendWithRetrySrc, 'resolveRecipientLocale')
        && !str_contains($sendWithRetrySrc, 'getInstallationLocale'),
    'sendWithRetry renders templates in the recipient locale'
);

$settingsControllerSrc = file_get_contents(__DIR__ . '/../app/Controllers/SettingsController.php');
$settingsViewSrc = file_get_contents(__DIR__ . '/../app/Views/settings/index.php');
$check(
    str_contains((string) $settingsControllerSrc, 'resolveTemplateEditorLocale')
        && str_contains((string) $settingsControllerSrc, 'saveEmailTemplate($template, $subject, $body, $localizedDefinition')
        && str_contains((string) $settingsViewSrc, 'name="template_locale"')
        && str_contains((string) $settingsViewSrc, 'Lingua dei template'),
    'administrators can edit and persist an independent template for each recipient locale'
);

// The registrant can pick their language on the form; the controller
// validates it against the shipped locales (installation locale fallback).
$registerView = file_get_contents(__DIR__ . '/../app/Views/auth/register.php');
$registerCtrl = file_get_contents(__DIR__ . '/../app/Controllers/RegistrationController.php');
$check(
    str_contains((string) $registerView, 'name="locale"')
        && str_contains((string) $registerView, 'getAvailableLocales'),
    'registration form offers the language choice on multi-language installs'
);
$check(
    str_contains((string) $registerCtrl, "\$data['locale']")
        && str_contains((string) $registerCtrl, 'getAvailableLocales'),
    'registration stores a validated user-chosen locale'
);

// --- Controller + routes ----------------------------------------------------

foreach (['sendRecall', 'bulkRecall', 'emailPdf'] as $method) {
    $check(
        method_exists(\App\Controllers\PrestitiController::class, $method),
        "PrestitiController::{$method} exists"
    );
}

$controllerSource = file_get_contents(__DIR__ . '/../app/Controllers/PrestitiController.php');
$check(
    substr_count((string) $controllerSource, 'recall_count = 0') >= 4
        && substr_count((string) $controllerSource, 'last_recall_at = NULL') >= 4
        && str_contains((string) $controllerSource, "if (\$newUserId !== (int) \$locked['utente_id'])"),
    'due-date changes, renewals, bulk extensions, and user reassignment reset the recall cycle'
);
$check(
    str_contains((string) $controllerSource, 'BULK_RECALL_TIME_BUDGET_SECONDS')
        && str_contains((string) $controllerSource, 'sendManualRecall($loanId, 1, 0)'),
    'bulk recall has a server-side time budget and uses one SMTP attempt per recipient'
);

$loansViewSource = file_get_contents(__DIR__ . '/../app/Views/prestiti/index.php');
$check(
    str_contains((string) $loansViewSource, 'selected.size > 50')
        && str_contains((string) $loansViewSource, 'È possibile inviare al massimo 50 solleciti alla volta.'),
    'bulk recall rejects oversized selections before submitting the form'
);

$emailServiceSrc = file_get_contents(__DIR__ . '/../app/Support/EmailService.php');
$check(
    str_contains((string) $emailServiceSrc, 'mail.smtp.timeout')
        && str_contains((string) $emailServiceSrc, '->Timeout = $smtpTimeout'),
    'SMTP connect and command time are explicitly bounded'
);

$receiptStart = strpos((string) $runSource, 'public function sendLoanReceiptEmail');
$receiptEnd = strpos((string) $runSource, 'public function notifyAdminsOverdue', $receiptStart === false ? 0 : $receiptStart);
$receiptSource = ($receiptStart !== false && $receiptEnd !== false)
    ? substr((string) $runSource, $receiptStart, $receiptEnd - $receiptStart)
    : '';
$check(
    str_contains($receiptSource, 'I18n::setLocale($recipientLocale)')
        && str_contains($receiptSource, 'new LoanPdfGenerator')
        && str_contains($receiptSource, 'finally')
        && str_contains($receiptSource, 'I18n::setLocale($requestLocale)'),
    'receipt PDFs are generated in the recipient locale and always restore the request locale'
);

$routes = file_get_contents(__DIR__ . '/../app/Routes/web.php');
$check(
    str_contains((string) $routes, "/admin/loans/{id:\\d+}/recall")
        && str_contains((string) $routes, "/admin/loans/bulk-recall")
        && str_contains((string) $routes, "/admin/loans/{id:\\d+}/email-pdf"),
    'recall + email-pdf routes are registered'
);

// --- Schema artifacts -------------------------------------------------------

$schema = file_get_contents(__DIR__ . '/../installer/database/schema.sql');
$check(
    str_contains((string) $schema, '`recall_count`') && str_contains((string) $schema, '`last_recall_at`'),
    'fresh-install schema ships the recall tracking columns'
);
$migration = file_get_contents(__DIR__ . '/../installer/database/migrations/migrate_0.7.62-rc.1.sql');
$check(
    $migration !== false
        && str_contains((string) $migration, 'recall_count')
        && str_contains((string) $migration, 'last_recall_at')
        && str_contains((string) $migration, 'INFORMATION_SCHEMA.COLUMNS'),
    'upgrade migration adds the recall columns idempotently'
);

foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $seed = file_get_contents(__DIR__ . '/../installer/database/data_' . $locale . '.sql');
    $check(
        $seed !== false
            && str_contains((string) $seed, "'loan_recall_notification'")
            && str_contains((string) $seed, "'loan_receipt_email'")
            && str_contains((string) $seed, "'recall_auto_enabled'")
            && str_contains((string) $seed, "'recall_interval_days'")
            && str_contains((string) $seed, "'recall_max_count'"),
        "installer data_{$locale}.sql seeds the recall templates and settings"
    );
}

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
