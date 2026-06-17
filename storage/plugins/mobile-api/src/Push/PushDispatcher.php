<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

use App\Support\SecureLogger;
use mysqli;

/**
 * Push dispatch sweep — hooks into the existing loan/notification scheduler
 * (spec §Push architecture).
 *
 * The core cron (MaintenanceService::runAll → NotificationService) already emails
 * loan-due / overdue / pickup-ready / wishlist-available reminders. We DO NOT
 * reimplement those rules: this dispatcher runs right after them, on the SAME
 * cron pass (fired via the `mobile_api.dispatch_push` action), and derives the
 * exact same event taxonomy from current DB state, then pushes each event to the
 * subscribed devices whose mobile_push_prefs allow it and that are outside quiet
 * hours.
 *
 * Idempotency: every (user, event-key) pair is recorded in mobile_push_log with a
 * UNIQUE index, claimed atomically with INSERT IGNORE BEFORE sending — so a push
 * is delivered at most once even across overlapping cron runs (same mark-then-send
 * pattern the email reminders use).
 *
 * NEVER hard-fail (spec §Push config): the whole sweep is wrapped; a provider that
 * is NullProvider, or any per-row error, degrades to the in-app feed and is logged,
 * never thrown.
 *
 * Data isolation: every query is scoped by the OWNING user_id; a push for user A is
 * only ever sent to user A's own subscriptions. Every `libri` read carries
 * `AND deleted_at IS NULL`.
 */
final class PushDispatcher
{
    private mysqli $db;
    private PushProvider $provider;

    public function __construct(mysqli $db, PushProvider $provider)
    {
        $this->db       = $db;
        $this->provider = $provider;
    }

    /**
     * Run the full sweep. Returns coarse counters (for logging/tests); never throws.
     *
     * @return array{loan_due:int, loan_overdue:int, reservation_ready:int, book_available:int, sent:int, skipped:int}
     */
    public function dispatch(): array
    {
        $counters = [
            'loan_due'          => 0,
            'loan_overdue'      => 0,
            'reservation_ready' => 0,
            'book_available'    => 0,
            'sent'              => 0,
            'skipped'           => 0,
        ];

        try {
            // UTC so quiet-hours and "today" comparisons are deterministic.
            $this->db->query("SET SESSION time_zone = '+00:00'");

            $counters['loan_due']          = $this->sweepLoanDue($counters);
            $counters['loan_overdue']      = $this->sweepLoanOverdue($counters);
            $counters['reservation_ready'] = $this->sweepReservationReady($counters);
            $counters['book_available']    = $this->sweepBookAvailable($counters);
        } catch (\Throwable $e) {
            // The cron must continue; push is best-effort.
            SecureLogger::error('[MobileApi] push dispatch failed: ' . $e->getMessage());
        }

        return $counters;
    }

    // ─── Event sweeps ─────────────────────────────────────────────────────────

    /**
     * Loans due within the reminder window (active, in_corso, not yet overdue).
     * Event key is per-loan so a renewal (new due date) re-notifies.
     *
     * @param array{loan_due:int,loan_overdue:int,reservation_ready:int,book_available:int,sent:int,skipped:int} $counters
     */
    private function sweepLoanDue(array &$counters): int
    {
        $days = $this->dueSoonDays();
        $events = 0;

        $sql = "SELECT pr.id, pr.utente_id, pr.libro_id, pr.data_scadenza, l.titolo
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                WHERE pr.attivo = 1 AND pr.stato = 'in_corso'
                  AND pr.data_scadenza >= CURDATE()
                  AND pr.data_scadenza <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = ($res instanceof \mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        foreach ($rows as $r) {
            $userId = (int) $r['utente_id'];
            $key    = 'loan_due:' . (int) $r['id'] . ':' . (string) $r['data_scadenza'];
            if (!$this->claim($userId, $key)) {
                continue;
            }
            $events++;
            $payload = new PushPayload(
                'loan_due',
                __('Prestito in scadenza'),
                sprintf(__('Il prestito di "%s" scade il %s.'), (string) $r['titolo'], (string) $r['data_scadenza']),
                (int) $r['libro_id'],
                ['loan_id' => (int) $r['id'], 'due_at' => (string) $r['data_scadenza']]
            );
            $this->deliver($userId, 'loan_due', $payload, $counters);
        }

        return $events;
    }

    /**
     * @param array{loan_due:int,loan_overdue:int,reservation_ready:int,book_available:int,sent:int,skipped:int} $counters
     */
    private function sweepLoanOverdue(array &$counters): int
    {
        $events = 0;

        $sql = "SELECT pr.id, pr.utente_id, pr.libro_id, pr.data_scadenza, l.titolo
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                WHERE pr.attivo = 1
                  AND (pr.stato = 'in_ritardo' OR (pr.stato = 'in_corso' AND pr.data_scadenza < CURDATE()))";
        $res  = $this->db->query($sql);
        $rows = ($res instanceof \mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];

        foreach ($rows as $r) {
            $userId = (int) $r['utente_id'];
            // One overdue push per loop per loan (keyed on due date), not per day.
            $key    = 'loan_overdue:' . (int) $r['id'] . ':' . (string) $r['data_scadenza'];
            if (!$this->claim($userId, $key)) {
                continue;
            }
            $events++;
            $payload = new PushPayload(
                'loan_overdue',
                __('Prestito scaduto'),
                sprintf(__('Il prestito di "%s" è scaduto il %s.'), (string) $r['titolo'], (string) $r['data_scadenza']),
                (int) $r['libro_id'],
                ['loan_id' => (int) $r['id'], 'due_at' => (string) $r['data_scadenza']]
            );
            $this->deliver($userId, 'loan_overdue', $payload, $counters);
        }

        return $events;
    }

    /**
     * Reservations promoted to a ready-for-pickup loan.
     *
     * @param array{loan_due:int,loan_overdue:int,reservation_ready:int,book_available:int,sent:int,skipped:int} $counters
     */
    private function sweepReservationReady(array &$counters): int
    {
        $events = 0;

        $sql = "SELECT pr.id, pr.utente_id, pr.libro_id, l.titolo
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                WHERE pr.stato = 'da_ritirare' AND pr.origine = 'prenotazione'";
        $res  = $this->db->query($sql);
        $rows = ($res instanceof \mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];

        foreach ($rows as $r) {
            $userId = (int) $r['utente_id'];
            $key    = 'reservation_ready:' . (int) $r['id'];
            if (!$this->claim($userId, $key)) {
                continue;
            }
            $events++;
            $payload = new PushPayload(
                'reservation_ready',
                __('Prenotazione pronta'),
                sprintf(__('"%s" è pronto per il ritiro.'), (string) $r['titolo']),
                (int) $r['libro_id'],
                ['loan_id' => (int) $r['id']]
            );
            $this->deliver($userId, 'reservation_ready', $payload, $counters);
        }

        return $events;
    }

    /**
     * book back available — when a watched title is loanable again, notify the
     * watcher(s) and CLEAR the watcher (spec §Push triggers / data model
     * mobile_availability_watchers). A title is "loanable again" when it has at
     * least one available copy.
     *
     * @param array{loan_due:int,loan_overdue:int,reservation_ready:int,book_available:int,sent:int,skipped:int} $counters
     */
    private function sweepBookAvailable(array &$counters): int
    {
        $events = 0;

        // Join watchers to currently-loanable, non-deleted titles. copie_disponibili
        // is the canonical availability counter the rest of the app maintains.
        $sql = "SELECT w.id AS watcher_id, w.user_id, w.libro_id, l.titolo
                FROM mobile_availability_watchers w
                JOIN libri l ON l.id = w.libro_id AND l.deleted_at IS NULL
                WHERE l.copie_disponibili > 0";
        $res  = $this->db->query($sql);
        $rows = ($res instanceof \mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];

        foreach ($rows as $r) {
            $userId    = (int) $r['user_id'];
            $watcherId = (int) $r['watcher_id'];

            // Atomically claim+clear the watcher first: deleting it is the dedup —
            // a watcher fires exactly once and is gone, so no mobile_push_log row
            // is needed for this event type. If the delete matches nothing, another
            // pass already handled it.
            $del = $this->db->prepare('DELETE FROM mobile_availability_watchers WHERE id = ? AND user_id = ?');
            if ($del === false) {
                continue;
            }
            $del->bind_param('ii', $watcherId, $userId);
            $del->execute();
            $claimed = $this->db->affected_rows > 0;
            $del->close();
            if (!$claimed) {
                continue;
            }

            $events++;
            $payload = new PushPayload(
                'book_available',
                __('Libro di nuovo disponibile'),
                sprintf(__('"%s" è di nuovo disponibile per il prestito.'), (string) $r['titolo']),
                (int) $r['libro_id'],
                ['book_id' => (int) $r['libro_id']]
            );
            $this->deliver($userId, 'book_available', $payload, $counters);
        }

        return $events;
    }

    // ─── Delivery ─────────────────────────────────────────────────────────────

    /**
     * Deliver one event to all of a user's active subscriptions, gated by their
     * per-type pref and quiet hours. The in-app feed (GET /me/notifications)
     * always reflects the same state, so a SKIPPED/NullProvider path still leaves
     * the user able to see the notification by polling.
     *
     * @param array{loan_due:int,loan_overdue:int,reservation_ready:int,book_available:int,sent:int,skipped:int} $counters
     */
    private function deliver(int $userId, string $prefKey, PushPayload $payload, array &$counters): void
    {
        if (!$this->prefAllows($userId, $prefKey)) {
            $counters['skipped']++;
            return;
        }
        if ($this->inQuietHours($userId)) {
            $counters['skipped']++;
            return;
        }

        $subs = $this->subscriptionsFor($userId);
        if ($subs === []) {
            $counters['skipped']++;
            return;
        }

        foreach ($subs as $sub) {
            try {
                $result = $this->provider->send($sub, $payload);
            } catch (\Throwable $e) {
                // Provider contract forbids throwing, but stay defensive.
                SecureLogger::warning('[MobileApi] push provider threw: ' . $e->getMessage());
                $result = PushResult::failed();
            }

            $subId = (int) $sub['id'];
            if ($result->isOk()) {
                $counters['sent']++;
                $this->markSubscriptionOk($subId);
            } elseif ($result->isGone()) {
                $counters['skipped']++;
                $this->pruneSubscription($subId);
            } else {
                $counters['skipped']++;
                $this->bumpSubscriptionFailure($subId);
            }
        }
    }

    // ─── Preferences & quiet hours ────────────────────────────────────────────

    /**
     * Whether the user's per-type toggle allows this event. Missing prefs row =
     * defaults (all on), matching the table defaults.
     */
    private function prefAllows(int $userId, string $prefKey): bool
    {
        $allowed = ['loan_due', 'loan_overdue', 'reservation_ready', 'new_message', 'book_available'];
        if (!in_array($prefKey, $allowed, true)) {
            return false;
        }

        // Column name == pref key (validated against the allow-list above, so it is
        // never attacker-controlled and safe to interpolate).
        $sql = "SELECT {$prefKey} AS v FROM mobile_push_prefs WHERE user_id = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return true; // fail-open to defaults
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return true; // no prefs row → defaults (all enabled)
        }

        return (int) $row['v'] === 1;
    }

    /**
     * True when "now" (UTC) falls inside the user's quiet-hours window. Supports a
     * window that wraps midnight (start > end). No window set → never quiet.
     */
    private function inQuietHours(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT quiet_start, quiet_end FROM mobile_push_prefs WHERE user_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null || $row['quiet_start'] === null || $row['quiet_end'] === null) {
            return false;
        }

        $start = substr((string) $row['quiet_start'], 0, 5);
        $end   = substr((string) $row['quiet_end'], 0, 5);
        if ($start === $end) {
            return false; // zero-length window
        }
        $now = gmdate('H:i');

        if ($start < $end) {
            // Same-day window, e.g. 22:00–23:30 (rare) or 08:00–20:00.
            return $now >= $start && $now < $end;
        }

        // Wrapping window, e.g. 22:00–07:00.
        return $now >= $start || $now < $end;
    }

    // ─── Subscriptions ────────────────────────────────────────────────────────

    /**
     * Active push subscriptions for a user (its token, if any, must not be
     * revoked). Strictly user-scoped.
     *
     * @return list<array<string,mixed>>
     */
    private function subscriptionsFor(int $userId): array
    {
        $sql = "SELECT s.id, s.user_id, s.token_id, s.provider, s.endpoint, s.registration_id,
                       s.public_key, s.auth, s.failure_count
                FROM mobile_push_subscriptions s
                LEFT JOIN mobile_app_tokens t ON t.id = s.token_id
                WHERE s.user_id = ?
                  AND (s.token_id IS NULL OR t.revoked_at IS NULL)
                  AND s.failure_count < 10";
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = ($res instanceof \mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        /** @var list<array<string,mixed>> $out */
        return $out;
    }

    private function markSubscriptionOk(int $subId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mobile_push_subscriptions SET last_ok_at = NOW(), failure_count = 0 WHERE id = ?'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $subId);
        $stmt->execute();
        $stmt->close();
    }

    private function bumpSubscriptionFailure(int $subId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mobile_push_subscriptions SET failure_count = failure_count + 1 WHERE id = ?'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $subId);
        $stmt->execute();
        $stmt->close();
    }

    private function pruneSubscription(int $subId): void
    {
        $stmt = $this->db->prepare('DELETE FROM mobile_push_subscriptions WHERE id = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $subId);
        $stmt->execute();
        $stmt->close();
    }

    // ─── Dedup log ────────────────────────────────────────────────────────────

    /**
     * Atomically claim a (user, event-key) pair. Returns true exactly once per
     * pair (UNIQUE index + INSERT IGNORE). A claimed-but-undelivered event simply
     * shows up in the in-app feed, so a lost push is never a lost notification.
     */
    private function claim(int $userId, string $eventKey): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO mobile_push_log (user_id, event_key, created_at) VALUES (?, ?, NOW())'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('is', $userId, $eventKey);
        $stmt->execute();
        $claimed = $this->db->affected_rows > 0;
        $stmt->close();

        return $claimed;
    }

    private function dueSoonDays(): int
    {
        try {
            $days = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'reminder_days_before', '3') ?? 3);
        } catch (\Throwable $e) {
            $days = 3;
        }

        return $days >= 1 ? $days : 3;
    }
}
