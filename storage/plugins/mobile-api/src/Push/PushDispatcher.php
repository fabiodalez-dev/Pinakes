<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

use App\Support\DateHelper;
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
 * Idempotency: the loan-due / overdue / reservation-ready events record each
 * (user, event-key) pair in mobile_push_log with a UNIQUE index, claimed atomically
 * with INSERT IGNORE — but only AFTER the pref + quiet-hours gate (shouldNotify), so
 * a quiet-hours pass never burns the claim. The book_available event instead uses
 * its mobile_availability_watchers row as the dedup: the watcher is the durable
 * "wants notification" record and is cleared only once the push is actually
 * delivered, so a NullProvider / quiet-hours user keeps it and still sees the
 * availability in the in-app feed.
 *
 * NEVER hard-fail (spec §Push config): the whole sweep is wrapped and never throws.
 * A real provider ERROR is logged (SecureLogger) and bumps the subscription's
 * failure budget; a NullProvider/unconfigured SKIP is merely counted (no log, no
 * failure bump). Either way a skipped push is never a lost notification — every
 * event type is also derivable by GET /me/notifications.
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
            // Bind the application-domain date explicitly. CURDATE() would follow
            // the DB session timezone (this class used to force UTC), disagreeing
            // with the web/email pipeline around the library's midnight.
            $today = DateHelper::today();

            $counters['loan_due']          = $this->sweepLoanDue($counters, $today);
            $counters['loan_overdue']      = $this->sweepLoanOverdue($counters, $today);
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
    private function sweepLoanDue(array &$counters, string $today): int
    {
        $days = $this->dueSoonDays();
        $events = 0;

        $sql = "SELECT pr.id, pr.utente_id, pr.libro_id, pr.data_scadenza, l.titolo
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                WHERE pr.attivo = 1 AND pr.stato = 'in_corso'
                  AND pr.data_scadenza >= ?
                  AND pr.data_scadenza <= DATE_ADD(?, INTERVAL ? DAY)";
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('ssi', $today, $today, $days);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = ($res instanceof \mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        foreach ($rows as $r) {
            $userId = (int) $r['utente_id'];
            $key    = 'loan_due:' . (int) $r['id'] . ':' . (string) $r['data_scadenza'];
            // Gate BEFORE claim so a quiet-hours/pref-off pass doesn't burn the dedup.
            if (!$this->shouldNotify($userId, 'loan_due')) {
                $counters['skipped']++;
                continue;
            }
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
            $outcome = $this->deliver($userId, $payload, $counters);
            if ($outcome['sent'] === 0 && $outcome['failed'] > 0) {
                // Transient failure only: free the claim so the next sweep retries.
                $this->releaseClaim($userId, $key);
            }
        }

        return $events;
    }

    /**
     * @param array{loan_due:int,loan_overdue:int,reservation_ready:int,book_available:int,sent:int,skipped:int} $counters
     */
    private function sweepLoanOverdue(array &$counters, string $today): int
    {
        $events = 0;

        $sql = "SELECT pr.id, pr.utente_id, pr.libro_id, pr.data_scadenza, l.titolo
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                WHERE pr.attivo = 1
                  AND (pr.stato = 'in_ritardo' OR (pr.stato = 'in_corso' AND pr.data_scadenza < ?))";
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = ($res instanceof \mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        foreach ($rows as $r) {
            $userId = (int) $r['utente_id'];
            // One overdue push per loop per loan (keyed on due date), not per day.
            $key    = 'loan_overdue:' . (int) $r['id'] . ':' . (string) $r['data_scadenza'];
            if (!$this->shouldNotify($userId, 'loan_overdue')) {
                $counters['skipped']++;
                continue;
            }
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
            $outcome = $this->deliver($userId, $payload, $counters);
            if ($outcome['sent'] === 0 && $outcome['failed'] > 0) {
                $this->releaseClaim($userId, $key);
            }
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
                WHERE pr.attivo = 1 AND pr.stato = 'da_ritirare' AND pr.origine = 'prenotazione'";
        $res  = $this->db->query($sql);
        $rows = ($res instanceof \mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];

        foreach ($rows as $r) {
            $userId = (int) $r['utente_id'];
            $key    = 'reservation_ready:' . (int) $r['id'];
            if (!$this->shouldNotify($userId, 'reservation_ready')) {
                $counters['skipped']++;
                continue;
            }
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
            $outcome = $this->deliver($userId, $payload, $counters);
            if ($outcome['sent'] === 0 && $outcome['failed'] > 0) {
                $this->releaseClaim($userId, $key);
            }
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

            // The watcher is BOTH the dedup and the durable "wants notification"
            // record that GET /me/notifications reads — so it must NOT be cleared
            // until the push has actually been delivered. If the user is in quiet
            // hours / has the toggle off, leave the watcher in place: the in-app
            // feed surfaces the availability, and the push re-fires on a later pass.
            if (!$this->shouldNotify($userId, 'book_available')) {
                $counters['skipped']++;
                continue;
            }

            // Serialize concurrent cron passes: claim the (user, watcher) pair in
            // mobile_push_log (INSERT IGNORE on a UNIQUE index) BEFORE delivering, so
            // two overlapping sweeps can't both push for the same watcher. The
            // watcher row remains the durable feed record; this claim only guards
            // the push send. (Keyed on the watcher id, which is unique per wish.)
            if (!$this->claim($userId, 'book_available:' . $watcherId)) {
                continue;
            }

            $payload = new PushPayload(
                'book_available',
                __('Libro di nuovo disponibile'),
                sprintf(__('"%s" è di nuovo disponibile per il prestito.'), (string) $r['titolo']),
                (int) $r['libro_id'],
                ['book_id' => (int) $r['libro_id']]
            );
            $outcome = $this->deliver($userId, $payload, $counters);
            if ($outcome['sent'] <= 0) {
                // Nothing was delivered: keep the watcher so GET /me/notifications
                // keeps surfacing the availability (the durable fallback). On a
                // transient provider/network failure also release the claim so the
                // push retries on the next sweep, matching the FAILED contract; a
                // pure no-subscription / NullProvider skip leaves the claim in place.
                if ($outcome['failed'] > 0) {
                    $this->releaseClaim($userId, 'book_available:' . $watcherId);
                }
                continue;
            }

            // Delivered → clear the one-shot watcher (scoped to its owner).
            $del = $this->db->prepare('DELETE FROM mobile_availability_watchers WHERE id = ? AND user_id = ?');
            if ($del === false) {
                continue;
            }
            $del->bind_param('ii', $watcherId, $userId);
            $del->execute();
            $del->close();
            $events++;
        }

        return $events;
    }

    // ─── Delivery ─────────────────────────────────────────────────────────────

    /**
     * Whether a notification of this type may be delivered to the user RIGHT NOW
     * (per-type toggle on AND outside quiet hours). Callers MUST gate on this
     * BEFORE consuming a dedup claim, so a quiet-hours pass doesn't burn the claim
     * and silently drop the push for the rest of the window — the event re-fires on
     * the next post-quiet-hours pass instead.
     */
    private function shouldNotify(int $userId, string $prefKey): bool
    {
        return $this->prefAllows($userId, $prefKey) && !$this->inQuietHours($userId);
    }

    /**
     * Deliver one event to all of a user's active subscriptions. Pref + quiet-hours
     * gating is the caller's responsibility (shouldNotify, before the dedup claim).
     * Returns ['sent' => N, 'failed' => M]: N is the number of subscriptions that
     * accepted the push, M the number that failed *transiently* (provider/network
     * down — not a permanent 404/410 prune nor an unconfigured NullProvider skip).
     * Callers use a zero `sent` to decide whether a one-shot trigger
     * (book_available watcher) may be cleared, and a transient `failed` to release
     * the dedup claim so the event retries on the next sweep. The in-app feed
     * (GET /me/notifications) reflects the same state, so a NullProvider /
     * no-subscription path still leaves the user able to see the notification.
     *
     * @param array{loan_due:int,loan_overdue:int,reservation_ready:int,book_available:int,sent:int,skipped:int} $counters
     * @return array{sent:int,failed:int}
     */
    private function deliver(int $userId, PushPayload $payload, array &$counters): array
    {
        $subs = $this->subscriptionsFor($userId);
        if ($subs === []) {
            $counters['skipped']++;
            return ['sent' => 0, 'failed' => 0];
        }

        $sent   = 0;
        $failed = 0;
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
                $sent++;
                $this->markSubscriptionOk($subId, $userId);
            } elseif ($result->isGone()) {
                $counters['skipped']++;
                $this->pruneSubscription($subId, $userId);
            } elseif ($result->isSkipped()) {
                // Nothing was attempted (NullProvider / unconfigured push). This is
                // NOT a delivery failure — do NOT bump failure_count, otherwise a
                // push-unconfigured instance would disable every device after a few
                // cron passes (failure_count >= 10 excludes the subscription).
                $counters['skipped']++;
            } else {
                // Transient failure (provider/network). Bump the per-device counter
                // and report it so the caller can release the dedup claim for a retry.
                $counters['skipped']++;
                $failed++;
                $this->bumpSubscriptionFailure($subId, $userId);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
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

    // The subscription mutators scope by BOTH id and user_id (the id always comes
    // from subscriptionsFor($userId), but the extra guard makes a confused-deputy
    // write to another user's subscription impossible — consistent with the
    // watcher DELETE).
    private function markSubscriptionOk(int $subId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mobile_push_subscriptions SET last_ok_at = NOW(), failure_count = 0 WHERE id = ? AND user_id = ?'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ii', $subId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function bumpSubscriptionFailure(int $subId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mobile_push_subscriptions SET failure_count = failure_count + 1 WHERE id = ? AND user_id = ?'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ii', $subId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function pruneSubscription(int $subId, int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM mobile_push_subscriptions WHERE id = ? AND user_id = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ii', $subId, $userId);
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
        $claimed = $stmt->affected_rows > 0;
        $stmt->close();

        return $claimed;
    }

    /**
     * Release a previously-acquired claim so the event can be re-attempted on the
     * next sweep. Used only when delivery failed transiently (provider/network
     * down) and nothing was sent — honouring the PushResult::FAILED "retry next
     * sweep" contract instead of permanently consuming the dedup on a blip.
     */
    private function releaseClaim(int $userId, string $eventKey): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM mobile_push_log WHERE user_id = ? AND event_key = ?'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('is', $userId, $eventKey);
        $stmt->execute();
        $stmt->close();
    }

    private function dueSoonDays(): int
    {
        try {
            // Single source of truth for the horizon (key + fallback + clamp), shared
            // with the web reminders and the mobile-api loan feed.
            return (new \App\Models\SettingsRepository($this->db))->daysBeforeExpiryWarning();
        } catch (\Throwable $e) {
            return 3;
        }
    }
}
