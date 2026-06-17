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
 * VAPID signing (RFC 8292):
 *   When a VAPID keypair is configured (auto-generated per instance), each POST
 *   carries a signed ES256 `Authorization: vapid …` header bound to the endpoint
 *   origin — accepted by standard Web Push endpoints and VAPID-enforcing
 *   distributors. Signing is best-effort: a failure never blocks delivery.
 *
 * Payload encryption (RFC 8291):
 *   The JSON payload is sent as-is (not ECDH/HKDF-encrypted). UnifiedPush
 *   distributors that accept unencrypted application payloads (the common
 *   self-hosted case, e.g. ntfy/NextPush) deliver it directly; the stored
 *   `mobile_push_subscriptions.public_key`/`auth` pair is reserved for a future
 *   encrypted transport. See STATUS.md.
 */
final class UnifiedPushProvider implements PushProvider
{
    /** Seconds the push endpoint should retain an undelivered message. */
    private const TTL_SECONDS = 86400;

    /** Optional VAPID subject (mailto:/https:) — the JWT `sub` claim. */
    private ?string $vapidSubject;

    /** VAPID public key (base64url uncompressed EC point) — the `k=` value. */
    private ?string $vapidPublicKey;

    /** VAPID private key (PEM) used to sign the per-request ES256 JWT. */
    private ?string $vapidPrivateKey;

    public function __construct(
        ?string $vapidSubject = null,
        ?string $vapidPublicKey = null,
        ?string $vapidPrivateKey = null
    ) {
        $this->vapidSubject    = ($vapidSubject !== null && trim($vapidSubject) !== '') ? trim($vapidSubject) : null;
        $this->vapidPublicKey  = ($vapidPublicKey !== null && trim($vapidPublicKey) !== '') ? trim($vapidPublicKey) : null;
        $this->vapidPrivateKey = ($vapidPrivateKey !== null && trim($vapidPrivateKey) !== '') ? $vapidPrivateKey : null;
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

        // VAPID (RFC 8292): sign a per-request ES256 JWT bound to this endpoint's
        // origin when a keypair is configured. Required by standard Web Push
        // endpoints / VAPID-enforcing distributors; advisory otherwise. A signing
        // failure must never block delivery — fall through and POST unsigned.
        if ($this->vapidPublicKey !== null && $this->vapidPrivateKey !== null) {
            $auth = VapidSigner::authorizationHeader(
                $endpoint,
                $this->vapidSubject ?? '',
                $this->vapidPublicKey,
                $this->vapidPrivateKey
            );
            if ($auth !== null) {
                $headers['Authorization'] = $auth;
                // Crypto-Key for older servers that read the key from there.
                $headers['Crypto-Key'] = 'p256ecdsa=' . $this->vapidPublicKey;
            }
        } elseif ($this->vapidSubject !== null) {
            // No keypair available: keep the advisory subject hint.
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
