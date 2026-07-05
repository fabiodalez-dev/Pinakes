<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Governance module (plan §6) — central permission checker for club-level
 * granular permissions.
 *
 * PERMISSION MATRIX (fixed keys, stored as JSON in bookclub_roles.permissions
 * for custom club roles):
 *
 *   proposals.create      propose books from the catalog
 *   proposals.approve     moderate/approve pending proposals
 *   polls.create          open new polls
 *   polls.close           close polls / resolve ties
 *   meetings.create       schedule meetings
 *   meetings.minutes      write meeting minutes
 *   members.invite        send email invitations
 *   members.approve       approve pending join requests
 *   posts.moderate        moderate discussion posts
 *   workflow.transition   move books between workflow states
 *   stats.view            view club statistics
 *   exports.run           run data exports
 *
 * Resolution order of can():
 *   1. Pinakes admin/staff (utenti.tipo_utente) → always true;
 *   2. no active membership in the club → false;
 *   3. system roles: owner/moderator → every key, member → proposals.create
 *      + stats.view, guest → none;
 *   4. custom club roles → decode the permissions JSON (map perm→bool or
 *      plain list) and check the key.
 *
 * Results are cached per request (static caches, one process per request).
 */
final class Permissions
{
    /** @var list<string> The fixed permission matrix. */
    public const KEYS = [
        'proposals.create',
        'proposals.approve',
        'polls.create',
        'polls.close',
        'meetings.create',
        'meetings.minutes',
        'members.invite',
        'members.approve',
        'posts.moderate',
        'workflow.transition',
        'stats.view',
        'exports.run',
    ];

    /** Keys granted to the system 'member' role. */
    private const MEMBER_KEYS = ['proposals.create', 'stats.view'];

    /** @var array<int, bool> userId → is Pinakes admin/staff (per-request cache) */
    private static array $staffCache = [];

    /** @var array<string, array<string, mixed>|null> "clubId:userId" → membership+role row (per-request cache) */
    private static array $memberCache = [];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return self::KEYS;
    }

    /**
     * Translated labels for the admin permission-matrix UI.
     *
     * @return array<string, string> perm key → label
     */
    public static function labels(): array
    {
        return [
            'proposals.create' => __('Proporre libri'),
            'proposals.approve' => __('Approvare le proposte'),
            'polls.create' => __('Aprire votazioni'),
            'polls.close' => __('Chiudere votazioni'),
            'meetings.create' => __('Creare incontri'),
            'meetings.minutes' => __('Redigere i verbali'),
            'members.invite' => __('Invitare lettori'),
            'members.approve' => __('Approvare le richieste di adesione'),
            'posts.moderate' => __('Moderare le discussioni'),
            'workflow.transition' => __('Spostare i libri nel workflow'),
            'stats.view' => __('Vedere le statistiche'),
            'exports.run' => __('Eseguire le esportazioni'),
        ];
    }

    /**
     * Whether $userId holds $perm in $club (hydrated club row).
     *
     * @param array<string, mixed> $club
     */
    public static function can(mysqli $db, array $club, ?int $userId, string $perm): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }
        if (self::isPinakesStaff($db, $userId)) {
            return true;
        }
        $clubId = (int) ($club['id'] ?? 0);
        if ($clubId <= 0) {
            return false;
        }
        $m = self::membership($db, $clubId, $userId);
        if ($m === null || ($m['status'] ?? '') !== 'active') {
            return false;
        }

        if ((int) ($m['is_system'] ?? 0) === 1) {
            return match ((string) ($m['role_slug'] ?? '')) {
                'owner', 'moderator' => true, // every key
                'member' => in_array($perm, self::MEMBER_KEYS, true),
                default => false, // guest & unknown system roles
            };
        }

        // Custom roles must belong to the club they are used in.
        if ($m['role_club_id'] === null || (int) $m['role_club_id'] !== $clubId) {
            return false;
        }
        $decoded = json_decode((string) ($m['permissions'] ?? ''), true);
        if (!is_array($decoded)) {
            return false;
        }
        if (array_is_list($decoded)) {
            return in_array($perm, array_map('strval', $decoded), true);
        }
        return !empty($decoded[$perm]);
    }

    /** Test helper / request-boundary reset. */
    public static function flushCache(): void
    {
        self::$staffCache = [];
        self::$memberCache = [];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function isPinakesStaff(mysqli $db, int $userId): bool
    {
        if (array_key_exists($userId, self::$staffCache)) {
            return self::$staffCache[$userId];
        }
        // Fast path: the current session user.
        $sessionUser = $_SESSION['user'] ?? null;
        if (is_array($sessionUser) && (int) ($sessionUser['id'] ?? 0) === $userId) {
            return self::$staffCache[$userId] = in_array($sessionUser['tipo_utente'] ?? '', ['admin', 'staff'], true);
        }
        $type = '';
        $stmt = $db->prepare('SELECT tipo_utente FROM utenti WHERE id = ?');
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] Permissions staff lookup prepare failed: ' . $db->error);
            return self::$staffCache[$userId] = false;
        }
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $row = $stmt->get_result()?->fetch_assoc();
            $type = (string) ($row['tipo_utente'] ?? '');
        }
        $stmt->close();
        return self::$staffCache[$userId] = in_array($type, ['admin', 'staff'], true);
    }

    /** @return array<string, mixed>|null */
    private static function membership(mysqli $db, int $clubId, int $userId): ?array
    {
        $key = $clubId . ':' . $userId;
        if (array_key_exists($key, self::$memberCache)) {
            return self::$memberCache[$key];
        }
        $stmt = $db->prepare(
            'SELECT m.status, r.slug AS role_slug, r.club_id AS role_club_id, r.permissions, r.is_system
               FROM bookclub_members m
               JOIN bookclub_roles r ON r.id = m.role_id
              WHERE m.club_id = ? AND m.user_id = ?'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] Permissions membership prepare failed: ' . $db->error);
            return self::$memberCache[$key] = null;
        }
        $stmt->bind_param('ii', $clubId, $userId);
        $row = null;
        if ($stmt->execute()) {
            $row = $stmt->get_result()?->fetch_assoc() ?: null;
        }
        $stmt->close();
        return self::$memberCache[$key] = $row;
    }
}
