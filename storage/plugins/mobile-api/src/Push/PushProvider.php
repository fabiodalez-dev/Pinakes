<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

/**
 * Pluggable push-delivery abstraction (spec §Push transport / §Push architecture).
 *
 * A provider takes ONE resolved subscription row + an already-built payload and
 * tries to deliver it to the device, returning a coarse outcome. Implementations:
 *   - UnifiedPushProvider  — WebPush POST to the user-supplied endpoint (primary).
 *   - FcmProvider          — Firebase HTTP v1 (optional / stub).
 *   - NullProvider         — no credentials configured → no-op (graceful fallback).
 *
 * Contract (NEVER hard-fail — spec §Push config "Never hard-fail"):
 *   - send() MUST NOT throw. Any transport/credential problem is caught inside
 *     and reported via the returned PushResult so the dispatcher can degrade to
 *     the in-app feed and (optionally) bump failure_count without aborting the
 *     whole sweep.
 *   - A provider that is structurally unable to deliver (e.g. missing endpoint,
 *     unsupported provider for the subscription) returns a skipped result, not
 *     an exception.
 */
interface PushProvider
{
    /**
     * Machine identifier of the provider ('unifiedpush' | 'fcm' | 'null'),
     * matching the `provider` column on mobile_push_subscriptions where relevant.
     */
    public function name(): string;

    /**
     * Attempt delivery of a single notification to a single subscription.
     *
     * @param array<string,mixed> $subscription One mobile_push_subscriptions row
     *                                           (endpoint/registration_id/public_key/auth/provider/...).
     * @param PushPayload         $payload      Already-localized, app-formatted payload.
     */
    public function send(array $subscription, PushPayload $payload): PushResult;
}
