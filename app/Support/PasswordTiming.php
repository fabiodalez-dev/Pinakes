<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Keeps "no such account" as slow as "wrong password" on the password gates
 * that look an account up before verifying it (CWE-208).
 *
 * The dummy hash must have the algorithm and cost of the stored hashes
 * (PASSWORD_DEFAULT), and it is a precomputed constant: hashing it on the
 * request path would make the first unknown-account attempt of every worker
 * pay password_hash() on top of the verify, a timing signal of its own.
 * PHP 8.4 raised the default bcrypt cost from 10 to 12, so there is one
 * constant per default. tests/security-hardening-protocols.unit.php fails
 * when the selected constant no longer matches PASSWORD_DEFAULT.
 */
final class PasswordTiming
{
    /** bcrypt, cost 10: PASSWORD_DEFAULT before PHP 8.4. Matches no password. */
    private const DUMMY_HASH_COST_10 = '$2y$10$8wWrqSDrdbHZS7kLbegUq.fYOpfhlBkS8rFV.4rgvubR9ZSS1WakK';

    /** bcrypt, cost 12: PASSWORD_DEFAULT from PHP 8.4. Matches no password. */
    private const DUMMY_HASH_COST_12 = '$2y$12$t05c/ayXLr.juXzOsB7hJeuOOAAURL78AEgrLlGe2oH7J8yVTzYTO';

    /** How many dummy verifies this process ran (observability for the tests). */
    private static int $equalized = 0;

    /** The precomputed hash matching this runtime's PASSWORD_DEFAULT. */
    public static function dummyHash(): string
    {
        return PHP_VERSION_ID >= 80400 ? self::DUMMY_HASH_COST_12 : self::DUMMY_HASH_COST_10;
    }

    /** Spend one password verify on a hash that never matches. */
    public static function equalize(string $password): void
    {
        password_verify($password, self::dummyHash());
        self::$equalized++;
    }

    public static function equalizedCount(): int
    {
        return self::$equalized;
    }
}
