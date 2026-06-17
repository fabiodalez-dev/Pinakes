<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

/**
 * Coarse outcome of a single PushProvider::send() attempt.
 *
 * Deliberately small: the dispatcher only needs to know whether to bump
 * last_ok_at, increment failure_count, or treat the subscription as gone
 * (404/410 → the device unsubscribed; the dispatcher may prune it). No internal
 * error detail is surfaced to clients — only logged via SecureLogger.
 */
final class PushResult
{
    public const OK      = 'ok';      // Delivered (2xx).
    public const FAILED  = 'failed';  // Transient failure (retry next sweep).
    public const GONE    = 'gone';    // 404/410 — subscription invalid; prune it.
    public const SKIPPED = 'skipped'; // Nothing attempted (no creds / unsupported).

    public string $status;
    public int $httpStatus;

    private function __construct(string $status, int $httpStatus = 0)
    {
        $this->status     = $status;
        $this->httpStatus = $httpStatus;
    }

    public static function ok(int $httpStatus = 200): self
    {
        return new self(self::OK, $httpStatus);
    }

    public static function failed(int $httpStatus = 0): self
    {
        return new self(self::FAILED, $httpStatus);
    }

    public static function gone(int $httpStatus = 410): self
    {
        return new self(self::GONE, $httpStatus);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED, 0);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function isGone(): bool
    {
        return $this->status === self::GONE;
    }
}
