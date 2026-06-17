<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

/**
 * Fallback provider selected when no push credentials are configured
 * (spec §Push config: "if absent → graceful fallback to polling / in-app
 * notifications. Never hard-fail").
 *
 * It delivers nothing and reports SKIPPED for every subscription. The dispatcher
 * still records the in-app feed (GET /me/notifications derives it), so the user
 * experience degrades to polling rather than breaking.
 */
final class NullProvider implements PushProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function send(array $subscription, PushPayload $payload): PushResult
    {
        // No-op by design: no transport, no credentials, no failure.
        return PushResult::skipped();
    }
}
