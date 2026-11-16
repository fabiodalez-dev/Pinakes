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
    private const LIBRARY_SECRET_KEY = 'library_ics_secret';

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
        return hash_hmac('sha256', 'loan-ics:' . $loanId, $this->secret(self::SECRET_KEY));
    }

    public function isValidToken(int $loanId, string $token): bool
    {
        return $token !== '' && hash_equals($this->token($loanId), $token);
    }

    /** Days a per-loan .ics link keeps working after the loan's due date. */
    public const LINK_GRACE_DAYS = 30;

    /**
     * Whether the emailed .ics link of $loanId is still live: while the loan
     * is open (an overdue copy keeps its calendar event current), and up to
     * LINK_GRACE_DAYS after the due date once it is closed. A forwarded
     * confirmation email therefore stops disclosing the loan a month after
     * it ended, instead of forever. An unknown loan is not live.
     */
    public function isLinkLive(int $loanId): bool
    {
        $stmt = $this->db->prepare('SELECT data_scadenza, attivo FROM prestiti WHERE id = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return false;
        }

        return self::linkLiveOn(
            $row['data_scadenza'] !== null ? (string) $row['data_scadenza'] : null,
            (int) ($row['attivo'] ?? 0) === 1,
            new \DateTimeImmutable('today')
        );
    }

    /** Pure rule behind isLinkLive(), on the library-local date $today. */
    public static function linkLiveOn(?string $dueDate, bool $open, \DateTimeImmutable $today): bool
    {
        if ($open || $dueDate === null || $dueDate === '') {
            return true;
        }
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($dueDate, 0, 10));
        if ($due === false) {
            return true;
        }

        return $due->modify('+' . self::LINK_GRACE_DAYS . ' days') >= $today->setTime(0, 0);
    }

    /**
     * Operator subscription URL of the whole library calendar (dashboard
     * "copy link"). Only this link unlocks borrower names and emails in the
     * event descriptions; the bare /calendar/events.ics is guessable, so it
     * serves the same events without personal data.
     *
     * The token is bound to ONE operator and checked against that account on
     * every fetch ({@see libraryFeedOperator()}): demoting, suspending or
     * deleting the operator kills their link. It is derived from a secret of
     * its own, so rotating it (deleting the `library_ics_secret` setting)
     * revokes every operator link without touching the per-loan links already
     * sent by email.
     */
    public function libraryFeedUrl(int $operatorId): string
    {
        return url('/calendar/events.ics') . '?op=' . $operatorId . '&token=' . urlencode($this->libraryFeedToken($operatorId));
    }

    public function libraryFeedToken(int $operatorId): string
    {
        return hash_hmac('sha256', 'library-ics:' . $operatorId, $this->secret(self::LIBRARY_SECRET_KEY));
    }

    /**
     * Withdraw every operator calendar link at once (dashboard "regenerate").
     * The next libraryFeedToken() call creates a fresh secret; the per-loan
     * links emailed to borrowers use their own secret and keep working.
     */
    public function rotateLibraryFeedSecret(): void
    {
        $stmt = $this->db->prepare('DELETE FROM system_settings WHERE category = ? AND setting_key = ?');
        if ($stmt === false) {
            throw new \RuntimeException('library feed secret rotation failed: ' . $this->db->error);
        }
        $category = self::SECRET_CATEGORY;
        $key = self::LIBRARY_SECRET_KEY;
        $stmt->bind_param('ss', $category, $key);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('library feed secret rotation failed: ' . $error);
        }
        $stmt->close();
    }

    /**
     * The operator a library-feed link belongs to, or null when the token is
     * wrong or the account is no longer an active admin/staff member.
     */
    public function libraryFeedOperator(string $operatorId, string $token): ?int
    {
        if ($token === '' || !ctype_digit($operatorId)) {
            return null;
        }
        $id = (int) $operatorId;
        if ($id <= 0 || !hash_equals($this->libraryFeedToken($id), $token)) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM utenti WHERE id = ? AND stato = 'attivo' AND tipo_utente IN ('admin', 'staff') LIMIT 1");
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ok = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();

        return $ok ? $id : null;
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
    private function secret(string $key): string
    {
        $repo = new \App\Models\SettingsRepository($this->db);
        $secret = (string) ($repo->get(self::SECRET_CATEGORY, $key, '') ?? '');
        if ($secret !== '') {
            return $secret;
        }

        $candidate = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('INSERT IGNORE INTO system_settings (category, setting_key, setting_value) VALUES (?, ?, ?)');
        $category = self::SECRET_CATEGORY;
        $stmt->bind_param('sss', $category, $key, $candidate);
        $stmt->execute();
        $stmt->close();

        $stored = (string) ($repo->get(self::SECRET_CATEGORY, $key, '') ?? '');
        return $stored !== '' ? $stored : $candidate;
    }
}
