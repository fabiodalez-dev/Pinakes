<?php
declare(strict_types=1);

/**
 * Guards for the "add to calendar" links in the loan confirmation emails:
 * Google Calendar URL building (behavioral) plus static wiring checks —
 * the {{sezione_calendario}} placeholder must stay raw-HTML, be produced by
 * NotificationService for loan_approved AND loan_pickup_ready, exist in the
 * default templates (all shipped locales), and the tokenized per-loan .ics
 * route must stay registered and gated by LoanCalendarLinks::isValidToken.
 */
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

// --- Behavioral: Google Calendar URL (pure static, no DB) -------------------
require $root . '/app/Support/LoanCalendarLinks.php';

$url = \App\Support\LoanCalendarLinks::googleCalendarUrl('📖 Prestito: Il nome della rosa', '2026-08-11', '2026-08-20', "Libro: Il nome della rosa\nBiblioteca");
$check(str_starts_with($url, 'https://calendar.google.com/calendar/render?'), 'Google URL points at the calendar render endpoint');
$check(str_contains($url, 'action=TEMPLATE'), 'Google URL uses the TEMPLATE action');
$check(str_contains($url, 'dates=20260811%2F20260821'), 'all-day event: DTEND is exclusive (due date + 1 day)');
$check(str_contains($url, rawurlencode('Il nome della rosa')), 'event title is URL-encoded into the text parameter');
$check(str_contains($url, 'details='), 'details parameter is included when provided');

$noDetails = \App\Support\LoanCalendarLinks::googleCalendarUrl('T', '2026-08-11', '2026-08-20');
$check(!str_contains($noDetails, 'details='), 'details parameter is omitted when empty');

$inverted = \App\Support\LoanCalendarLinks::googleCalendarUrl('T', '2026-08-20', '2026-08-11');
$check(str_contains($inverted, 'dates=20260820%2F20260820'), 'an end date before the start never produces a negative range');

// --- Static wiring ----------------------------------------------------------
$emailService = (string) file_get_contents($root . '/app/Support/EmailService.php');
$check(
    preg_match("/RAW_HTML_VARIABLES\s*=\s*\[[^\]]*'sezione_calendario'/", $emailService) === 1,
    'sezione_calendario is a raw-HTML variable (not escaped at substitution)'
);
$check(
    str_contains($emailService, "'calendar_links' => 'sezione_calendario'"),
    'the English alias calendar_links maps to sezione_calendario'
);

$notifications = (string) file_get_contents($root . '/app/Support/NotificationService.php');
$check(
    substr_count($notifications, "'sezione_calendario' => \$this->buildCalendarSection(") === 2,
    'both loan_approved and loan_pickup_ready sends build the calendar section'
);
$check(
    str_contains($notifications, 'private function buildCalendarSection('),
    'NotificationService owns the locale-aware section builder'
);

$templates = (string) file_get_contents($root . '/app/Support/SettingsMailTemplates.php');
$check(
    substr_count($templates, '{{sezione_calendario}}') === 2,
    'Italian base templates include the placeholder in loan_approved and loan_pickup_ready'
);
$check(
    substr_count($templates, "'sezione_calendario'") >= 3,
    'placeholder is declared in both placeholders lists and described for the settings UI'
);

foreach (['en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $override = (string) file_get_contents($root . "/app/Support/mail_templates/{$locale}.php");
    $check(
        substr_count($override, '{{sezione_calendario}}') === 2,
        "{$locale} template overrides include the placeholder in both loan templates"
    );
}

$routes = (string) file_get_contents($root . '/app/Routes/web.php');
$check(
    str_contains($routes, "'/calendar/loan/{id:[0-9]+}.ics'"),
    'per-loan ICS route is registered'
);
$check(
    str_contains($routes, 'isValidToken($loanId, $token)') && str_contains($routes, 'generateForLoan($loanId)'),
    'per-loan ICS route validates the HMAC token before generating'
);

$ics = (string) file_get_contents($root . '/app/Support/IcsGenerator.php');
$check(
    str_contains($ics, 'public function generateForLoan(int $loanId): ?string')
        && str_contains($ics, "p.stato <> 'pendente'"),
    'IcsGenerator can render a single non-pending loan'
);

$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.65-rc.1.sql');
$check(
    str_contains($migration, "'loan_approved', 'loan_pickup_ready'")
        && str_contains($migration, "NOT LIKE '%sezione_calendario%'"),
    'migration appends the placeholder to existing template rows, idempotently'
);

foreach (['it_IT', 'en_US', 'fr_FR', 'de_DE', 'da_DK'] as $locale) {
    $catalogue = json_decode((string) file_get_contents($root . "/locale/{$locale}.json"), true);
    $check(
        is_array($catalogue)
            && isset($catalogue['Aggiungi il prestito al tuo calendario'])
            && isset($catalogue['Altri calendari (.ics)']),
        "{$locale} translates the calendar section labels"
    );
}

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
