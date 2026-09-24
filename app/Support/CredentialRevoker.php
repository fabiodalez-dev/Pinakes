<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Ends the access a password was protecting.
 *
 * A password is not the only way into an account. A remember-me cookie and a
 * Mobile API token both outlive the session that created them, by design and
 * for months. Neither the recovery page nor the profile's change-password form
 * touched either of them: they wrote the new hash and stopped. Someone who had
 * obtained one of those credentials therefore kept their access across the
 * recovery of the account — across the exact act the account holder performs
 * in order to take it back, which is the one moment where "the old way in
 * still works" is least acceptable.
 *
 * Two shapes, deliberately distinguished:
 *
 *   - a RESET is performed by someone holding a link to an email address. The
 *     browser doing it is not known to be the owner's, so nothing is kept;
 *   - a CHANGE is performed inside an authenticated session. Signing that
 *     person out of the browser they are typing in would be surprising and
 *     achieves nothing, so that one session is kept and everything else goes.
 *
 * Mobile tokens live in a table owned by the Mobile API plugin, which may not
 * be installed. Its absence is normal and must not turn a password change into
 * an error, so the table is probed and skipped when missing — but it is never
 * skipped silently when present.
 */
final class CredentialRevoker
{
    /**
     * Revoke every persistent credential of $userId.
     *
     * @param bool $keepCurrentDevice keep the remember-me row this request is
     *        bound to (an authenticated change), rather than all of them.
     * @return array{sessions:int,mobile:int} how many rows each store revoked
     */
    public static function revokeAll(mysqli $db, int $userId, bool $keepCurrentDevice = false): array
    {
        if ($userId <= 0) {
            return ['sessions' => 0, 'mobile' => 0];
        }

        $keepRowId = 0;
        if ($keepCurrentDevice && isset($_SESSION[RememberMeService::SESSION_ROW_KEY])) {
            $keepRowId = (int) $_SESSION[RememberMeService::SESSION_ROW_KEY];
        }

        $sessions = self::revokeRows(
            $db,
            'user_sessions',
            $keepRowId > 0
                ? 'UPDATE user_sessions SET is_revoked = 1 WHERE utente_id = ? AND is_revoked = 0 AND id <> ?'
                : 'UPDATE user_sessions SET is_revoked = 1 WHERE utente_id = ? AND is_revoked = 0',
            $userId,
            $keepRowId > 0 ? $keepRowId : null
        );

        $mobile = self::revokeRows(
            $db,
            'mobile_app_tokens',
            'UPDATE mobile_app_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL',
            $userId,
            null
        );

        SecureLogger::info('[CredentialRevoker] Persistent credentials revoked after a password change', [
            'user_id' => $userId,
            'sessions_revoked' => $sessions,
            'mobile_tokens_revoked' => $mobile,
            'kept_current_device' => $keepRowId > 0,
        ]);

        return ['sessions' => $sessions, 'mobile' => $mobile];
    }

    /**
     * Run one revocation statement, tolerating a table the installation does
     * not have. Returns the number of rows it changed.
     */
    private static function revokeRows(mysqli $db, string $table, string $sql, int $userId, ?int $extra): int
    {
        try {
            $exists = $db->query(
                "SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($table) . "' LIMIT 1"
            );
            if (!($exists instanceof \mysqli_result) || $exists->num_rows === 0) {
                // The Mobile API plugin is optional; an installation without it
                // has no tokens to revoke and nothing has gone wrong.
                return 0;
            }
            $exists->free();

            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            if ($extra !== null) {
                $stmt->bind_param('ii', $userId, $extra);
            } else {
                $stmt->bind_param('i', $userId);
            }
            $stmt->execute();
            $affected = $db->affected_rows;
            $stmt->close();

            return max(0, (int) $affected);
        } catch (\Throwable $e) {
            // Never turn a successful password change into an error the user
            // cannot act on — but never lose the fact either, because what
            // failed here is a revocation.
            SecureLogger::error("[CredentialRevoker] Could not revoke {$table} for user {$userId}: " . $e->getMessage());
            return 0;
        }
    }
}
