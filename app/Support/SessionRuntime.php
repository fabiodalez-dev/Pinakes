<?php
declare(strict_types=1);

namespace App\Support;

/** Starts and periodically rotates the already-configured PHP session. */
final class SessionRuntime
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - (int) $_SESSION['last_regeneration'] > 1800) {
            // Keep the old file until normal GC so concurrent requests carrying
            // the previous id can finish without logging the user out.
            session_regenerate_id(false);
            $_SESSION['last_regeneration'] = time();
        }
    }
}
