<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Keeps "no such account" as slow as "wrong password" on the password gates
 * that look an account up before verifying it (CWE-208).
 *
 * The dummy hash is made with PASSWORD_DEFAULT, the algorithm and cost of the
 * stored hashes, once per process: a hard-coded hash with another cost makes
 * the unknown-account branch measurably faster or slower than a real verify,
 * which is the oracle it is meant to remove.
 */
final class PasswordTiming
{
    private static ?string $dummyHash = null;

    /** How many dummy verifies this process ran (observability for the tests). */
    private static int $equalized = 0;

    /** Spend one password verify on a hash that never matches. */
    public static function equalize(string $password): void
    {
        self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        password_verify($password, self::$dummyHash);
        self::$equalized++;
    }

    public static function equalizedCount(): int
    {
        return self::$equalized;
    }
}
