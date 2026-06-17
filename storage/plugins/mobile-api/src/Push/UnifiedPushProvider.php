<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

use App\Support\HttpClient;
use App\Support\SecureLogger;

/**
 * Primary push provider: UnifiedPush (spec §Push transport).
 *
 * UnifiedPush is a vendor-neutral protocol: the app registers with a *distributor*
 * on the device, which hands it an HTTPS *endpoint* URL. To deliver a push, the
 * server simply does an HTTPS POST of the message body to that endpoint
 * (RFC 8030 Web Push delivery). No central Google/Apple credential is required —
 * which is exactly why the spec picks it as the "minimal setup" default once the
 * manager enables app access.
 *
 * Delivery contract (NEVER hard-fail):
 *   - Requires a non-empty `endpoint`; otherwise SKIPPED.
 *   - HTTPS-only (https_only) so a redirect can never downgrade the POST.
 *   - 201/202/200 → OK; 404/410 → GONE (device unsubscribed, prune it);
 *     anything else / transport error → FAILED (retry next sweep).
 *   - Never throws — HttpClient already returns a result array on transport error.
 *
 * Payload encryption (RFC 8291 / VAPID):
 *   Full Web Push *encryption* needs ECDH + HKDF + a VAPID signing key. This repo
 *   ships no Web Push crypto library, so this implementation sends the JSON
 *   payload to the endpoint as-is (TTL + urgency headers set). UnifiedPush
 *   distributors that accept unencrypted application payloads (the common
 *   self-hosted case, e.g. ntfy/NextPush) deliver it directly; the app reads the
 *   `mobile_push_subscriptions.public_key`/`auth` pair only if a future encrypted
 *   transport is added. See STATUS.md / the TODO in MobileApiPlugin::makeProvider.
 */
final class UnifiedPushProvider implements PushProvider
{
    /** Seconds the push endpoint should retain an undelivered message. */
    private const TTL_SECONDS = 86400;

    /** Optional VAPID subject (mailto:/https:) advertised in settings, for distributors that require it. */
    private ?string $vapidSubject;

    public function __construct(?string $vapidSubject = null)
    {
        $this->vapidSubject = ($vapidSubject !== null && trim($vapidSubject) !== '') ? trim($vapidSubject) : null;
    }

    public function name(): string
    {
        return 'unifiedpush';
    }

    public function send(array $subscription, PushPayload $payload): PushResult
    {
        $endpoint = isset($subscription['endpoint']) ? trim((string) $subscription['endpoint']) : '';
        if ($endpoint === '') {
            return PushResult::skipped();
        }

        // SSRF re-validation at send time (the endpoint was vetted at registration,
        // but DNS can rebind in the meantime). Resolve the host to a vetted PUBLIC
        // IP and pin the connection to it; abort if it no longer resolves publicly.
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $pinnedIp = $host !== '' ? \App\Support\SsrfGuard::resolvePinnedIp($host) : null;
        if ($pinnedIp === null) {
            SecureLogger::warning('[MobileApi] UnifiedPush endpoint host did not resolve to a public IP; refusing to send', ['host' => $host]);
            return PushResult::failed();
        }

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'TTL'          => (string) self::TTL_SECONDS,
            'Urgency'      => $payload->type === 'loan_overdue' ? 'high' : 'normal',
        ];
        if ($this->vapidSubject !== null) {
            // Advisory only; real VAPID JWT signing is out of scope (see class doc).
            $headers['X-Push-Subject'] = $this->vapidSubject;
        }

        try {
            $res = HttpClient::post(
                $endpoint,
                $payload->toJson(),
                $headers,
                ['https_only' => true, 'timeout' => 10, 'connect_timeout' => 5, 'max_redirects' => 0, 'pin_ip' => $pinnedIp]
            );
        } catch (\Throwable $e) {
            // Defensive: HttpClient should never throw, but the provider contract
            // forbids propagating any failure to the dispatcher.
            SecureLogger::warning('[MobileApi] UnifiedPush send threw: ' . $e->getMessage());
            return PushResult::failed();
        }

        if (!$res['ok']) {
            return PushResult::failed();
        }

        $status = (int) $res['status'];
        if ($status >= 200 && $status < 300) {
            return PushResult::ok($status);
        }
        if ($status === 404 || $status === 410) {
            return PushResult::gone($status);
        }

        return PushResult::failed($status);
    }
}
