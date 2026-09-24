<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * The one place a password-setup or recovery token is minted and stored.
 *
 * There is exactly one contract, and every path that invites someone to choose
 * a password has to follow all three parts of it:
 *
 *   1. the database holds the SHA-256 of the token, never the token;
 *   2. the email carries the original, which the database therefore cannot
 *      reveal — not to someone reading the users table, and not to someone
 *      holding an archived backup of it;
 *   3. `data_token_reset` is the moment the link STOPS working, so it is a time
 *      in the future.
 *
 * PasswordController::forgot() already did all three. The invitation paths —
 * admin-created user, admin-created administrator, promotion to administrator —
 * each did neither (1) nor (3): they wrote the token in clear, and wrote the
 * current instant where an expiry belongs. Either mistake alone is enough to
 * make the link dead on arrival, because the page looks the token up by its
 * hash AND requires `data_token_reset > NOW()`. No invitation had ever worked.
 *
 * Both templates promise the reader 24 hours, so that is the window; changing
 * it means changing what they say.
 */
final class PasswordSetupToken
{
    /** What the invitation emails tell the reader they have. */
    public const TTL_HOURS = 24;

    /**
     * Mint a token for $userId, store its hash and expiry, return the original.
     *
     * The original is returned and never stored: it exists in this process and
     * in the email, and nowhere else. Returns null when the row could not be
     * updated, so the caller can decline to send a link that cannot work.
     */
    public static function issue(mysqli $db, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        // UTC, like the recovery page, which compares against a session whose
        // time_zone it sets to +00:00 before looking the token up.
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600);

        try {
            $stmt = $db->prepare(
                'UPDATE utenti SET token_reset_password = ?, data_token_reset = ? WHERE id = ?'
            );
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('ssi', $hash, $expiresAt, $userId);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::error('[PasswordSetupToken] Could not store the setup token', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $raw;
    }
}
