<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access shared by the Fase-4 extension modules (plan §7.17):
 * reading sprints (bookclub_sprints + bookclub_sprint_participants,
 * owned by SprintsModule) and buddy reading (bookclub_buddies, owned
 * by BuddyModule). mysqli prepared statements only, same query-helper
 * style as Repo/ReadingRepo.
 */
class ExtensionsRepo
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Internal query helpers (same style as Repo)
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:extensions] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:extensions] execute failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    private function row(string $sql, string $types = '', array $params = []): ?array
    {
        $rows = $this->rows($sql, $types, $params);
        return $rows[0] ?? null;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function exec(string $sql, string $types = '', array $params = []): bool
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:extensions] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:extensions] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    // ------------------------------------------------------------------
    // Sprints
    // ------------------------------------------------------------------

    private const SPRINT_SELECT = "SELECT s.*, l.titolo AS book_title,
                    TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS creator_name,
                    (SELECT COUNT(*) FROM bookclub_sprint_participants p WHERE p.sprint_id = s.id) AS participant_count
               FROM bookclub_sprints s
               LEFT JOIN bookclub_books cb ON cb.id = s.club_book_id
               LEFT JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
               LEFT JOIN utenti u ON u.id = s.created_by";

    /**
     * Sprints of a club, most recent start first.
     *
     * @return list<array<string, mixed>>
     */
    public function sprintsForClub(int $clubId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->rows(
            self::SPRINT_SELECT . ' WHERE s.club_id = ? ORDER BY s.starts_at DESC, s.id DESC LIMIT ' . $limit,
            'i',
            [$clubId]
        );
    }

    /** @return array<string, mixed>|null */
    public function sprintById(int $sprintId): ?array
    {
        return $this->row(self::SPRINT_SELECT . ' WHERE s.id = ?', 'i', [$sprintId]);
    }

    /**
     * The next relevant sprint of a club: not cancelled/done and not yet
     * over (scheduled or currently running), earliest start first.
     *
     * @return array<string, mixed>|null
     */
    public function nextSprint(int $clubId): ?array
    {
        return $this->row(
            self::SPRINT_SELECT . " WHERE s.club_id = ?
                AND s.status = 'scheduled'
                AND DATE_ADD(s.starts_at, INTERVAL s.duration_min MINUTE) > NOW()
              ORDER BY s.starts_at ASC LIMIT 1",
            'i',
            [$clubId]
        );
    }

    public function createSprint(int $clubId, ?int $clubBookId, string $title, string $startsAt, int $durationMin, ?int $createdBy): ?int
    {
        $ok = $this->exec(
            'INSERT INTO bookclub_sprints (club_id, club_book_id, title, starts_at, duration_min, created_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            'iissii',
            [$clubId, $clubBookId, $title, $startsAt, $durationMin, $createdBy]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function cancelSprint(int $sprintId): bool
    {
        return $this->exec("UPDATE bookclub_sprints SET status = 'cancelled' WHERE id = ?", 'i', [$sprintId]);
    }

    /**
     * Maintenance helper: persist the derived 'done' status on scheduled
     * sprints whose window is over (purely cosmetic — effectiveStatus()
     * derives it at read time anyway).
     */
    public function markEndedSprintsDone(): void
    {
        $this->exec(
            "UPDATE bookclub_sprints SET status = 'done'
              WHERE status = 'scheduled'
                AND DATE_ADD(starts_at, INTERVAL duration_min MINUTE) <= NOW()"
        );
    }

    /**
     * Time-derived status (plan §7.17): the DB row only ever stores
     * 'scheduled' / 'cancelled' / 'done' (the latter persisted lazily by the
     * maintenance tick); 'running' and 'done' are computed from
     * starts_at + duration_min at read time.
     *
     * @param array<string, mixed> $sprint
     */
    public static function effectiveStatus(array $sprint, ?int $now = null): string
    {
        $status = (string) ($sprint['status'] ?? 'scheduled');
        if ($status === 'cancelled' || $status === 'done') {
            return $status;
        }
        $now = $now ?? time();
        $start = strtotime((string) ($sprint['starts_at'] ?? ''));
        if ($start === false) {
            return $status;
        }
        $end = $start + max(0, (int) ($sprint['duration_min'] ?? 0)) * 60;
        if ($now < $start) {
            return 'scheduled';
        }
        return $now < $end ? 'running' : 'done';
    }

    // ------------------------------------------------------------------
    // Sprint participants
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function participantsForSprint(int $sprintId): array
    {
        return $this->rows(
            "SELECT p.*, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name
               FROM bookclub_sprint_participants p
               JOIN utenti u ON u.id = p.user_id
              WHERE p.sprint_id = ?
              ORDER BY p.pages_read IS NULL, p.pages_read DESC, p.joined_at ASC",
            'i',
            [$sprintId]
        );
    }

    /**
     * All participants of all sprints of a club, grouped by sprint id
     * (one query instead of one per sprint on the list page).
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function participantsByClubSprint(int $clubId): array
    {
        $rows = $this->rows(
            "SELECT p.*, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name
               FROM bookclub_sprint_participants p
               JOIN bookclub_sprints s ON s.id = p.sprint_id
               JOIN utenti u ON u.id = p.user_id
              WHERE s.club_id = ?
              ORDER BY p.pages_read IS NULL, p.pages_read DESC, p.joined_at ASC",
            'i',
            [$clubId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['sprint_id']][] = $row;
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function participantRow(int $sprintId, int $userId): ?array
    {
        return $this->row(
            'SELECT * FROM bookclub_sprint_participants WHERE sprint_id = ? AND user_id = ?',
            'ii',
            [$sprintId, $userId]
        );
    }

    /** Idempotent join (UNIQUE(sprint_id, user_id) absorbs double posts). */
    public function joinSprint(int $sprintId, int $userId): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_sprint_participants (sprint_id, user_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE sprint_id = sprint_id',
            'ii',
            [$sprintId, $userId]
        );
    }

    public function leaveSprint(int $sprintId, int $userId): bool
    {
        return $this->exec(
            'DELETE FROM bookclub_sprint_participants WHERE sprint_id = ? AND user_id = ?',
            'ii',
            [$sprintId, $userId]
        );
    }

    /** Result logging after the sprint ended: pages read by a participant. */
    public function logPages(int $sprintId, int $userId, int $pages): bool
    {
        return $this->exec(
            'UPDATE bookclub_sprint_participants SET pages_read = ? WHERE sprint_id = ? AND user_id = ?',
            'iii',
            [$pages, $sprintId, $userId]
        );
    }

    // ------------------------------------------------------------------
    // Buddy reading
    // ------------------------------------------------------------------

    private const BUDDY_SELECT = "SELECT b.*, l.titolo AS book_title,
                    TRIM(CONCAT(COALESCE(ua.nome, ''), ' ', COALESCE(ua.cognome, ''))) AS name_a,
                    TRIM(CONCAT(COALESCE(ub.nome, ''), ' ', COALESCE(ub.cognome, ''))) AS name_b
               FROM bookclub_buddies b
               JOIN bookclub_books cb ON cb.id = b.club_book_id
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
               LEFT JOIN utenti ua ON ua.id = b.user_a
               LEFT JOIN utenti ub ON ub.id = b.user_b";

    /**
     * All pairings involving $userId in $clubId (both sides see them).
     *
     * @return list<array<string, mixed>>
     */
    public function buddiesForUser(int $clubId, int $userId): array
    {
        return $this->rows(
            self::BUDDY_SELECT . " WHERE b.club_id = ? AND (b.user_a = ? OR b.user_b = ?)
              ORDER BY FIELD(b.status, 'proposed', 'active', 'done'), b.created_at DESC",
            'iii',
            [$clubId, $userId, $userId]
        );
    }

    /** @return array<string, mixed>|null */
    public function buddyById(int $buddyId): ?array
    {
        return $this->row(self::BUDDY_SELECT . ' WHERE b.id = ?', 'i', [$buddyId]);
    }

    public function buddyExists(int $clubId, int $clubBookId, int $userA, int $userB): bool
    {
        if ($userA > $userB) {
            [$userA, $userB] = [$userB, $userA];
        }
        return $this->row(
            'SELECT id FROM bookclub_buddies WHERE club_id = ? AND club_book_id = ? AND user_a = ? AND user_b = ?',
            'iiii',
            [$clubId, $clubBookId, $userA, $userB]
        ) !== null;
    }

    /**
     * Create a 'proposed' pairing. The user_a < user_b storage invariant is
     * enforced here so the UNIQUE key catches (x, y) and (y, x) duplicates.
     */
    public function createBuddy(int $clubId, int $clubBookId, int $userA, int $userB, int $createdBy): ?int
    {
        if ($userA === $userB) {
            return null;
        }
        if ($userA > $userB) {
            [$userA, $userB] = [$userB, $userA];
        }
        $ok = $this->exec(
            'INSERT INTO bookclub_buddies (club_id, club_book_id, user_a, user_b, created_by)
             VALUES (?, ?, ?, ?, ?)',
            'iiiii',
            [$clubId, $clubBookId, $userA, $userB, $createdBy]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function setBuddyStatus(int $buddyId, string $status): bool
    {
        if (!in_array($status, ['proposed', 'active', 'done'], true)) {
            return false;
        }
        return $this->exec('UPDATE bookclub_buddies SET status = ? WHERE id = ?', 'si', [$status, $buddyId]);
    }

    public function deleteBuddy(int $buddyId): bool
    {
        return $this->exec('DELETE FROM bookclub_buddies WHERE id = ?', 'i', [$buddyId]);
    }

    // ------------------------------------------------------------------
    // Shared lookups
    // ------------------------------------------------------------------

    /**
     * Club books sitting in a workflow state flagged `current`, for the
     * book selects of both modules.
     *
     * @param list<string> $stateKeys
     * @return list<array<string, mixed>>
     */
    public function currentBooks(int $clubId, array $stateKeys): array
    {
        if ($stateKeys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($stateKeys), '?'));
        $types = 'i' . str_repeat('s', count($stateKeys));
        return $this->rows(
            "SELECT cb.id, cb.state, l.titolo
               FROM bookclub_books cb
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
              WHERE cb.club_id = ? AND cb.state IN ($placeholders)
              ORDER BY cb.position ASC, cb.updated_at DESC",
            $types,
            array_merge([$clubId], $stateKeys)
        );
    }

    /**
     * Keys of the workflow states flagged `current`.
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     * @return list<string>
     */
    public static function currentStateKeys(array $states): array
    {
        $keys = [];
        foreach ($states as $state) {
            if (!empty($state['flags']['current'])) {
                $keys[] = (string) $state['key'];
            }
        }
        return $keys;
    }
}
