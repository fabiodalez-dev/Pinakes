<?php
declare(strict_types=1);

namespace App\Support;

/** Detects container runtimes for features that are unsupported in Docker. */
final class ContainerRuntime
{
    private const OFFICIAL_IMAGE_MARKER = '/opt/pinakes/.official-docker-image';

    private function __construct()
    {
    }

    /**
     * True inside Docker, Podman or Kubernetes, including the official image.
     * Detection deliberately cannot be disabled with an environment override:
     * container-only restrictions must survive a stale or hand-edited .env.
     */
    public static function detected(): bool
    {
        if (is_file(self::OFFICIAL_IMAGE_MARKER)
            || is_file('/.dockerenv')
            || is_file('/run/.containerenv')) {
            return true;
        }

        $flag = $_ENV['PINAKES_DOCKER'] ?? (getenv('PINAKES_DOCKER') ?: '');
        if (is_string($flag) && self::isTruthyFlag($flag)) {
            return true;
        }

        $cgroup = @file_get_contents('/proc/1/cgroup');
        return is_string($cgroup)
            && preg_match('/\b(docker|containerd|kubepods|libpod)\b/', $cgroup) === 1;
    }

    /**
     * True only on the official Pinakes image, not on containers in general.
     *
     * The distinction is load-bearing. detected() is true for any container,
     * including community images that keep the code in a writable volume where
     * an in-app update legitimately works. Policy that belongs to OUR image must
     * key on the marker that image writes, so it cannot reach anyone else's.
     */
    public static function officialImage(): bool
    {
        return is_file(self::OFFICIAL_IMAGE_MARKER);
    }

    /** Recognize only explicit truthy values; "false" must not mean Docker. */
    private static function isTruthyFlag(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
