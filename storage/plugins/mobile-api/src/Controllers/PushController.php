<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Push subscription + preferences for the Mobile API (slice: Push).
 *
 * Endpoints (all bearer-authenticated, strictly scoped to the token-resolved
 * user — identity comes ONLY from AppAuthMiddleware, never a client user_id):
 *   POST   /me/push/subscribe — register a UnifiedPush/FCM endpoint for the
 *                               CURRENT device (token). Idempotent per (token,provider).
 *   DELETE /me/push/subscribe — remove this device's subscription(s).
 *   GET    /me/push/prefs      — per-type toggles + quiet hours.
 *   PUT    /me/push/prefs      — set them.
 *
 * NEVER hard-fail when push is unconfigured: subscribing is always accepted and
 * stored; if the instance has no provider credentials the dispatcher simply uses
 * NullProvider and the user falls back to the in-app feed (GET /me/notifications).
 */
final class PushController
{
    private const PREF_KEYS = ['loan_due', 'loan_overdue', 'reservation_ready', 'new_message', 'book_available'];

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ─── POST /me/push/subscribe ──────────────────────────────────────────────

    public function subscribe(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }
        $tokenId = $this->tokenId($request);

        $body     = JsonBody::parse($request);
        $provider = strtolower(trim((string) ($body['provider'] ?? 'unifiedpush')));
        if (!in_array($provider, ['unifiedpush', 'fcm'], true)) {
            return ResponseEnvelope::error($response, 'invalid_provider', __('Provider push non supportato.'), 422);
        }

        $endpoint = isset($body['endpoint']) ? trim((string) $body['endpoint']) : '';
        $regId    = isset($body['registration_id']) ? trim((string) $body['registration_id']) : '';
        $pubKey   = isset($body['public_key']) ? trim((string) $body['public_key']) : '';
        $auth     = isset($body['auth']) ? trim((string) $body['auth']) : '';

        if ($provider === 'unifiedpush') {
            // UnifiedPush delivers by POSTing to an HTTPS endpoint URL.
            if ($endpoint === '' || !$this->isSafePushEndpoint($endpoint)) {
                return ResponseEnvelope::error($response, 'invalid_endpoint', __('Endpoint UnifiedPush non valido (richiesto HTTPS verso un host pubblico).'), 422);
            }
            $regId = ''; // not used for UnifiedPush
        } else {
            if ($regId === '') {
                return ResponseEnvelope::error($response, 'invalid_registration', __('Token di registrazione FCM mancante.'), 422);
            }
            $endpoint = ''; // not used for FCM
        }

        // Clip to column widths.
        $endpoint = $this->clip($endpoint, 500);
        $regId    = $this->clip($regId, 500);
        $pubKey   = $this->clip($pubKey, 255);
        $auth     = $this->clip($auth, 255);

        try {
            // DELETE+INSERT must be atomic: if the INSERT failed after a
            // successful DELETE the device would lose its subscription with no
            // replacement. A transaction keeps the prior row on any failure.
            $this->db->begin_transaction();

            // Idempotent per device: replace any prior subscription for this token
            // (a device re-registers when its endpoint rotates). Scoped by user_id
            // AND token_id so a user can never touch another user's subscription.
            if ($tokenId !== null) {
                $del = $this->db->prepare(
                    'DELETE FROM mobile_push_subscriptions WHERE user_id = ? AND token_id = ?'
                );
                if ($del !== false) {
                    $del->bind_param('ii', $userId, $tokenId);
                    $del->execute();
                    $del->close();
                }
            }

            $ins = $this->db->prepare(
                'INSERT INTO mobile_push_subscriptions
                    (user_id, token_id, provider, endpoint, registration_id, public_key, auth, created_at, failure_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0)'
            );
            if ($ins === false) {
                $this->db->rollback();
                return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
            }
            $endpointParam = $endpoint !== '' ? $endpoint : null;
            $regParam      = $regId !== '' ? $regId : null;
            $pubParam      = $pubKey !== '' ? $pubKey : null;
            $authParam     = $auth !== '' ? $auth : null;
            $ins->bind_param('iisssss', $userId, $tokenId, $provider, $endpointParam, $regParam, $pubParam, $authParam);
            $ins->execute();
            $subId = (int) $ins->insert_id;
            $ins->close();

            $this->db->commit();

            // Ensure a prefs row exists (defaults) so GET /me/push/prefs is stable.
            $this->ensurePrefsRow($userId);

            return ResponseEnvelope::success(
                $response,
                ['id' => $subId, 'provider' => $provider],
                ['message' => __('Dispositivo registrato per le notifiche push.')],
                201
            );
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $ignored) {
                // already rolled back / no active transaction
            }
            SecureLogger::error('[MobileApi] push subscribe failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
        }
    }

    // ─── DELETE /me/push/subscribe ────────────────────────────────────────────

    public function unsubscribe(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }
        $tokenId = $this->tokenId($request);

        try {
            // Remove this device's subscriptions only (scoped by user_id + token_id).
            // If the request has no resolvable token id, remove the user's rows that
            // have no token binding, never another user's.
            if ($tokenId !== null) {
                $stmt = $this->db->prepare(
                    'DELETE FROM mobile_push_subscriptions WHERE user_id = ? AND token_id = ?'
                );
                if ($stmt === false) {
                    return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
                }
                $stmt->bind_param('ii', $userId, $tokenId);
            } else {
                $stmt = $this->db->prepare(
                    'DELETE FROM mobile_push_subscriptions WHERE user_id = ? AND token_id IS NULL'
                );
                if ($stmt === false) {
                    return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
                }
                $stmt->bind_param('i', $userId);
            }
            $stmt->execute();
            $stmt->close();

            return ResponseEnvelope::success(
                $response,
                null,
                ['message' => __('Notifiche push disattivate per questo dispositivo.')],
                200
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] push unsubscribe failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
        }
    }

    // ─── GET /me/push/prefs ───────────────────────────────────────────────────

    public function getPrefs(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        try {
            $row = $this->loadPrefs($userId);

            return ResponseEnvelope::success($response, $row, [], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] get push prefs failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Preferenze non disponibili.'), 500);
        }
    }

    // ─── PUT /me/push/prefs ───────────────────────────────────────────────────

    public function putPrefs(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        $body = JsonBody::parse($request);

        // Start from current values so a partial PUT only changes what it sends.
        $current = $this->loadPrefs($userId);

        $toggles = [];
        foreach (self::PREF_KEYS as $key) {
            if (array_key_exists($key, $body)) {
                $toggles[$key] = $this->toBool($body[$key]) ? 1 : 0;
            } else {
                $toggles[$key] = (int) $current[$key];
            }
        }

        // Quiet hours: accept "HH:MM" (or "HH:MM:SS"); empty string / null clears.
        $quietStart = $this->normalizeTime($body, 'quiet_start', $current['quiet_start']);
        $quietEnd   = $this->normalizeTime($body, 'quiet_end', $current['quiet_end']);
        if ($quietStart === false || $quietEnd === false) {
            return ResponseEnvelope::error($response, 'invalid_time', __('Orario silenzioso non valido (formato HH:MM).'), 422);
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO mobile_push_prefs
                    (user_id, loan_due, loan_overdue, reservation_ready, new_message, book_available, quiet_start, quiet_end)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    loan_due = VALUES(loan_due),
                    loan_overdue = VALUES(loan_overdue),
                    reservation_ready = VALUES(reservation_ready),
                    new_message = VALUES(new_message),
                    book_available = VALUES(book_available),
                    quiet_start = VALUES(quiet_start),
                    quiet_end = VALUES(quiet_end)'
            );
            if ($stmt === false) {
                return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
            }
            $stmt->bind_param(
                'iiiiiiss',
                $userId,
                $toggles['loan_due'],
                $toggles['loan_overdue'],
                $toggles['reservation_ready'],
                $toggles['new_message'],
                $toggles['book_available'],
                $quietStart,
                $quietEnd
            );
            $stmt->execute();
            $stmt->close();

            return ResponseEnvelope::success(
                $response,
                $this->loadPrefs($userId),
                ['message' => __('Preferenze aggiornate.')],
                200
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] put push prefs failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Load (or default) the user's prefs as a normalized array.
     *
     * @return array{loan_due:bool, loan_overdue:bool, reservation_ready:bool, new_message:bool, book_available:bool, quiet_start:?string, quiet_end:?string}
     */
    private function loadPrefs(int $userId): array
    {
        $defaults = [
            'loan_due'          => true,
            'loan_overdue'      => true,
            'reservation_ready' => true,
            'new_message'       => true,
            'book_available'    => true,
            'quiet_start'       => null,
            'quiet_end'         => null,
        ];

        $stmt = $this->db->prepare(
            'SELECT loan_due, loan_overdue, reservation_ready, new_message, book_available, quiet_start, quiet_end
               FROM mobile_push_prefs WHERE user_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return $defaults;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return $defaults;
        }

        return [
            'loan_due'          => (int) $row['loan_due'] === 1,
            'loan_overdue'      => (int) $row['loan_overdue'] === 1,
            'reservation_ready' => (int) $row['reservation_ready'] === 1,
            'new_message'       => (int) $row['new_message'] === 1,
            'book_available'    => (int) $row['book_available'] === 1,
            'quiet_start'       => $row['quiet_start'] !== null ? substr((string) $row['quiet_start'], 0, 5) : null,
            'quiet_end'         => $row['quiet_end'] !== null ? substr((string) $row['quiet_end'], 0, 5) : null,
        ];
    }

    private function ensurePrefsRow(int $userId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO mobile_push_prefs (user_id) VALUES (?)'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Validate + normalize an optional time field to "HH:MM:SS" or null.
     * Returns false when present but malformed.
     *
     * @param array<string,mixed> $body
     * @return string|null|false
     */
    private function normalizeTime(array $body, string $key, ?string $fallback)
    {
        if (!array_key_exists($key, $body)) {
            return $fallback !== null ? $fallback . ':00' : null;
        }
        $raw = $body[$key];
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null; // explicit clear
        }
        $val = trim((string) $raw);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $val) !== 1) {
            return false;
        }

        return strlen($val) === 5 ? $val . ':00' : $val;
    }

    private function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * SSRF-safe validation of a user-supplied UnifiedPush endpoint. The server
     * POSTs to this URL, so it must be HTTPS toward a genuinely PUBLIC host and
     * must not be coaxable into hitting internal infrastructure:
     *   - https only;
     *   - no userinfo (`user:pass@host`) — a common parser-confusion SSRF vector;
     *   - no bare-IP literal host (push services use real hostnames);
     *   - port must be 443 or 8443 (block odd internal ports; allow the common
     *     alt HTTPS port self-hosted UnifiedPush distributors use);
     *   - the host must resolve, and EVERY A/AAAA record must be public —
     *     SsrfGuard::resolvePinnedIp() returns null if any is private/reserved/
     *     loopback/link-local/NAT64/IPv4-mapped (one bad record blocks the host).
     * The send path re-checks and pins the IP (TOCTOU/DNS-rebind defense).
     */
    private function isSafePushEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false; // no userinfo
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [443, 8443], true)) {
            return false; // standard or common alt HTTPS port (self-hosted UnifiedPush distributors often use 8443)
        }
        $host = strtolower((string) $parts['host']);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false; // reject bare-IP literals (incl. [v6])
        }

        return \App\Support\SsrfGuard::resolvePinnedIp($host) !== null;
    }

    private function clip(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    private function userId(Request $request): int
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);

        return (is_array($user) && isset($user['id'])) ? (int) $user['id'] : 0;
    }

    private function tokenId(Request $request): ?int
    {
        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);

        return is_int($tokenId) ? $tokenId : null;
    }

    private function unauth(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'unauthorized', __('Non autenticato.'), 401);
    }
}
