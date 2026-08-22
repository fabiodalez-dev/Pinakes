<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * "Add to calendar" links for a single loan, embedded in the loan
 * confirmation emails ({{sezione_calendario}} placeholder): a Google
 * Calendar prefill URL plus a per-loan .ics download served by
 * GET /calendar/loan/{id}.ics.
 *
 * The .ics link is opened from a mail/calendar client without a session, so
 * it is protected by an HMAC token derived from a per-installation secret
 * lazily generated into system_settings (same idea as the book-club
 * ics_token): the numeric loan id alone can never fetch someone else's loan.
 */
final class LoanCalendarLinks
{
    private const SECRET_CATEGORY = 'calendar';
    private const SECRET_KEY = 'loan_ics_secret';

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Google Calendar "render?action=TEMPLATE" prefill URL for an all-day
     * event spanning the loan period. Pure function (no DB), used directly
     * by the email section and unit-testable in isolation.
     *
     * @param string $startDate Loan start (Y-m-d or any strtotime-parseable date)
     * @param string $endDate   Loan due date (inclusive; the exclusive end is derived here)
     */
    public static function googleCalendarUrl(string $title, string $startDate, string $endDate, string $details = ''): string
    {
        $startTs = strtotime($startDate) ?: time();
        $endTs = strtotime($endDate . ' +1 day') ?: $startTs;
        // All-day events: the end date is exclusive, hence the +1 day above.
        $params = [
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => date('Ymd', $startTs) . '/' . date('Ymd', max($startTs, $endTs)),
        ];
        if ($details !== '') {
            $params['details'] = $details;
        }
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Absolute tokenized URL of the per-loan .ics download. */
    public function icsUrl(int $loanId): string
    {
        return absoluteUrl('/calendar/loan/' . $loanId . '.ics?token=' . urlencode($this->token($loanId)));
    }

    public function token(int $loanId): string
    {
        return hash_hmac('sha256', 'loan-ics:' . $loanId, $this->secret());
    }

    public function isValidToken(int $loanId, string $token): bool
    {
        return $token !== '' && hash_equals($this->token($loanId), $token);
    }

    /**
     * The email block with the two "add to calendar" buttons, styled with the
     * same inline idiom as the other template sections: EmailLayout's
     * normalizeContent() restyles the background/buttons to the app accent at
     * send time. Labels are resolved via __() — the caller is responsible for
     * having the right locale active (NotificationService switches to the
     * installation locale, matching how the templates themselves render).
     */
    public function emailSection(int $loanId, string $bookTitle, string $startDate, string $endDate): string
    {
        $appName = (string) ConfigStore::get('app.name', 'Biblioteca');
        $eventTitle = '📖 ' . __('Prestito') . ': ' . $bookTitle;
        $details = __('Libro') . ': ' . $bookTitle . "\n" . $appName;
        $googleUrl = self::googleCalendarUrl($eventTitle, $startDate, $endDate, $details);
        $icsUrl = $this->icsUrl($loanId);

        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<div style="background-color: #f0f9ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #3b82f6;">'
            . '<p><strong>📅 ' . $esc(__('Aggiungi il prestito al tuo calendario')) . '</strong></p>'
            . '<p>' . $esc(__('Salva le date del prestito nel tuo calendario per non dimenticare la restituzione.')) . '</p>'
            . '<p style="text-align: center;">'
            . '<a href="' . $esc($googleUrl) . '" style="background-color: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">Google Calendar</a>'
            . '<a href="' . $esc($icsUrl) . '" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">' . $esc(__('Altri calendari (.ics)')) . '</a>'
            . '</p></div>';
    }

    /**
     * Per-installation HMAC secret, generated on first use. INSERT IGNORE +
     * re-read (instead of upsert) so concurrent first sends converge on ONE
     * secret — a last-writer-wins update would invalidate tokens already
     * emailed with the losing value.
     */
    private function secret(): string
    {
        $repo = new \App\Models\SettingsRepository($this->db);
        $secret = (string) ($repo->get(self::SECRET_CATEGORY, self::SECRET_KEY, '') ?? '');
        if ($secret !== '') {
            return $secret;
        }

        $candidate = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('INSERT IGNORE INTO system_settings (category, setting_key, setting_value) VALUES (?, ?, ?)');
        $category = self::SECRET_CATEGORY;
        $key = self::SECRET_KEY;
        $stmt->bind_param('sss', $category, $key, $candidate);
        $stmt->execute();
        $stmt->close();

        $stored = (string) ($repo->get(self::SECRET_CATEGORY, self::SECRET_KEY, '') ?? '');
        return $stored !== '' ? $stored : $candidate;
    }
}
