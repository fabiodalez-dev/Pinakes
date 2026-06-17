<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

use App\Support\SecureLogger;

/**
 * Firebase Cloud Messaging provider — OPTIONAL / STUB (spec §Push transport:
 * "FCM impl optional/stub").
 *
 * FCM HTTP v1 requires a Google service-account JSON, an OAuth2 access-token
 * exchange (JWT-bearer grant) and per-project endpoints. Implementing that
 * end-to-end is out of scope for this slice and is intentionally NOT done here:
 * the maintainer's locked default is UnifiedPush, which needs no central
 * credential. This class exists so the abstraction is complete and a future slice
 * can drop in the real HTTP v1 sender without touching the dispatcher.
 *
 * Behaviour now (NEVER hard-fail):
 *   - With no credentials configured → SKIPPED (the plugin selects this provider
 *     only when an FCM credential is present; defensively it still skips if the
 *     subscription lacks a registration_id).
 *   - It NEVER attempts a real network call, so it cannot throw or block the sweep.
 */
final class FcmProvider implements PushProvider
{
    /** Raw service-account JSON (or project credential blob) from settings; unused by the stub. */
    private ?string $credentialsJson;

    public function __construct(?string $credentialsJson = null)
    {
        $this->credentialsJson = ($credentialsJson !== null && trim($credentialsJson) !== '')
            ? trim($credentialsJson)
            : null;
    }

    public function name(): string
    {
        return 'fcm';
    }

    public function send(array $subscription, PushPayload $payload): PushResult
    {
        $registrationId = isset($subscription['registration_id'])
            ? trim((string) $subscription['registration_id'])
            : '';

        if ($registrationId === '' || $this->credentialsJson === null) {
            return PushResult::skipped();
        }

        // TODO (future slice): exchange the service-account JSON for an OAuth2
        // access token and POST to
        // https://fcm.googleapis.com/v1/projects/{project_id}/messages:send
        // with { message: { token, data, notification } }. Until then this is a
        // no-op that degrades gracefully to the in-app feed.
        SecureLogger::debug('[MobileApi] FcmProvider is a stub; skipping real FCM delivery.');

        return PushResult::skipped();
    }
}
