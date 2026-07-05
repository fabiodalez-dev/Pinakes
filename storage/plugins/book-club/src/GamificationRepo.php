<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the Gamification module (plan §7.12).
 *
 * XP MODEL — recomputed, never incremented. The module cannot hook into the
 * other modules' write paths, so every counter is derived from their source
 * tables with cheap per-club GROUP BY COUNT queries:
 *
 *   finished books  × 50  bookclub_progress.finished_at IS NOT NULL per user
 *                         (personal finishes; table guarded — reading module)
 *   proposals       × 15  bookclub_books.proposed_by with state <> 'pending'
 *                         (accepted/processed proposals only)
 *   yes-RSVPs       × 10  bookclub_meeting_rsvps.response = 'yes'
 *   votes cast      ×  5  bookclub_votes rows
 *   posts written   ×  2  bookclub_posts (guarded — discussions module)
 *
 * LEVEL = floor(sqrt(xp / 100)) + 1
 *   → level 1 from 0 XP, level 2 at 100, level 3 at 400, level 4 at 900 …
 *
 * All counters are scoped to ONE club (plan: "nessun punteggio globale
 * forzato tra club diversi"). Snapshots live in bookclub_xp_snapshot
 * (UNIQUE club_id+user_id) and are refreshed at most once per hour per club
 * — isStale() checks MAX(computed_at). Badges are data-driven rows in
 * bookclub_badges whose rule JSON ({"metric":"...","gte":N}) is evaluated
 * against the same counters; awards go into bookclub_user_badges via
 * INSERT IGNORE, so a badge is never granted twice and never revoked.
 *
 * Badge metrics beyond the XP ones:
 *   reviews           approved core reviews (recensioni.stato='approvata')
 *                     of books that belong to the club
 *   meetings_created  bookclub_meetings.created_by rows
 *
 * mysqli prepared statements only, same style as StatsRepo.
 */
class GamificationRepo
{
    public const XP_FINISHED_BOOK = 50;
    public const XP_PROPOSAL = 15;
    public const XP_RSVP_YES = 10;
    public const XP_VOTE = 5;
    public const XP_POST = 2;

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Low-level helpers (same pattern as StatsRepo)
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:gamification] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:gamification] execute failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $out = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $out;
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
            SecureLogger::error('[BookClub:gamification] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:gamification] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function scalarInt(string $sql, string $types = '', array $params = []): int
    {
        $row = $this->row($sql, $types, $params);
        if ($row === null) {
            return 0;
        }
        $first = reset($row);
        return (int) ($first ?? 0);
    }

    public function tableExists(string $table): bool
    {
        $row = $this->row(
            'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            's',
            [$table]
        );
        return (int) ($row['n'] ?? 0) > 0;
    }

    /**
     * Run a "SELECT … AS uid, COUNT(*) AS n … GROUP BY …" query into a
     * user id → count map.
     *
     * @param array<int, mixed> $params
     * @return array<int, int>
     */
    private function countMap(string $sql, string $types, array $params): array
    {
        $out = [];
        foreach ($this->rows($sql, $types, $params) as $row) {
            $out[(int) $row['uid']] = (int) $row['n'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Level math
    // ------------------------------------------------------------------

    /** LEVEL = floor(sqrt(xp / 100)) + 1 (documented in the class header). */
    public static function level(int $xp): int
    {
        return (int) floor(sqrt(max(0, $xp) / 100.0)) + 1;
    }

    /** XP threshold where $level begins: 100 × (level − 1)². */
    public static function levelStartXp(int $level): int
    {
        $l = max(1, $level);
        return 100 * ($l - 1) * ($l - 1);
    }

    // ------------------------------------------------------------------
    // Per-club, per-user activity counters (metric name → user id → count)
    // ------------------------------------------------------------------

    /**
     * All metric maps used by the XP formula and the badge rules.
     *
     * @return array<string, array<int, int>>
     */
    public function metricCounts(int $clubId): array
    {
        return [
            'finished' => $this->finishedPerUser($clubId),
            'proposals' => $this->proposalsPerUser($clubId),
            'rsvp_yes' => $this->yesRsvpsPerUser($clubId),
            'votes' => $this->votesPerUser($clubId),
            'posts' => $this->postsPerUser($clubId),
            'reviews' => $this->reviewsPerUser($clubId),
            'meetings_created' => $this->meetingsCreatedPerUser($clubId),
        ];
    }

    /**
     * XP of one user from the metric maps (see the formula in the header).
     *
     * @param array<string, array<int, int>> $metrics
     */
    public static function xpFromCounts(array $metrics, int $userId): int
    {
        return ($metrics['finished'][$userId] ?? 0) * self::XP_FINISHED_BOOK
            + ($metrics['proposals'][$userId] ?? 0) * self::XP_PROPOSAL
            + ($metrics['rsvp_yes'][$userId] ?? 0) * self::XP_RSVP_YES
            + ($metrics['votes'][$userId] ?? 0) * self::XP_VOTE
            + ($metrics['posts'][$userId] ?? 0) * self::XP_POST;
    }

    /**
     * Personal finishes: progress rows with finished_at set, per user.
     * Empty when the reading module tables are not installed.
     *
     * @return array<int, int>
     */
    public function finishedPerUser(int $clubId): array
    {
        if (!$this->tableExists('bookclub_progress')) {
            return [];
        }
        return $this->countMap(
            'SELECT p.user_id AS uid, COUNT(*) AS n
               FROM bookclub_progress p
               JOIN bookclub_books cb ON cb.id = p.club_book_id
              WHERE cb.club_id = ? AND p.finished_at IS NOT NULL
              GROUP BY p.user_id',
            'i',
            [$clubId]
        );
    }

    /**
     * Accepted proposals: club books with a proposer that moved past the
     * moderation queue (state <> 'pending').
     *
     * @return array<int, int>
     */
    public function proposalsPerUser(int $clubId): array
    {
        return $this->countMap(
            "SELECT cb.proposed_by AS uid, COUNT(*) AS n
               FROM bookclub_books cb
              WHERE cb.club_id = ? AND cb.proposed_by IS NOT NULL AND cb.state <> 'pending'
              GROUP BY cb.proposed_by",
            'i',
            [$clubId]
        );
    }

    /** @return array<int, int> */
    public function yesRsvpsPerUser(int $clubId): array
    {
        return $this->countMap(
            "SELECT r.user_id AS uid, COUNT(*) AS n
               FROM bookclub_meeting_rsvps r
               JOIN bookclub_meetings m ON m.id = r.meeting_id
              WHERE m.club_id = ? AND r.response = 'yes'
              GROUP BY r.user_id",
            'i',
            [$clubId]
        );
    }

    /** @return array<int, int> */
    public function votesPerUser(int $clubId): array
    {
        return $this->countMap(
            'SELECT v.user_id AS uid, COUNT(*) AS n
               FROM bookclub_votes v
               JOIN bookclub_polls p ON p.id = v.poll_id
              WHERE p.club_id = ?
              GROUP BY v.user_id',
            'i',
            [$clubId]
        );
    }

    /**
     * Non-deleted posts per user. Empty when the discussions module tables
     * are not installed.
     *
     * @return array<int, int>
     */
    public function postsPerUser(int $clubId): array
    {
        if (!$this->tableExists('bookclub_posts') || !$this->tableExists('bookclub_threads')) {
            return [];
        }
        return $this->countMap(
            'SELECT po.user_id AS uid, COUNT(*) AS n
               FROM bookclub_posts po
               JOIN bookclub_threads t ON t.id = po.thread_id
              WHERE t.club_id = ? AND po.deleted_at IS NULL
              GROUP BY po.user_id',
            'i',
            [$clubId]
        );
    }

    /**
     * Approved core reviews (recensioni) of books that belong to the club.
     *
     * @return array<int, int>
     */
    public function reviewsPerUser(int $clubId): array
    {
        return $this->countMap(
            "SELECT r.utente_id AS uid, COUNT(*) AS n
               FROM recensioni r
               JOIN bookclub_books cb ON cb.libro_id = r.libro_id
              WHERE cb.club_id = ? AND r.stato = 'approvata'
              GROUP BY r.utente_id",
            'i',
            [$clubId]
        );
    }

    /** @return array<int, int> */
    public function meetingsCreatedPerUser(int $clubId): array
    {
        return $this->countMap(
            'SELECT m.created_by AS uid, COUNT(*) AS n
               FROM bookclub_meetings m
              WHERE m.club_id = ? AND m.created_by IS NOT NULL
              GROUP BY m.created_by',
            'i',
            [$clubId]
        );
    }

    // ------------------------------------------------------------------
    // Snapshot (bookclub_xp_snapshot)
    // ------------------------------------------------------------------

    /**
     * Whether the club snapshot is older than $maxAgeSeconds (or missing).
     * This is the "at most once per hour per club" gate.
     */
    public function isStale(int $clubId, int $maxAgeSeconds = 3600): bool
    {
        $row = $this->row(
            'SELECT MAX(computed_at) AS last FROM bookclub_xp_snapshot WHERE club_id = ?',
            'i',
            [$clubId]
        );
        $last = $row['last'] ?? null;
        if ($last === null) {
            return true;
        }
        $ts = strtotime((string) $last);
        return $ts === false || $ts < time() - $maxAgeSeconds;
    }

    public function upsertXp(int $clubId, int $userId, int $xp): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_xp_snapshot (club_id, user_id, xp, computed_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE xp = VALUES(xp), computed_at = NOW()',
            'iii',
            [$clubId, $userId, $xp]
        );
    }

    public function xpFor(int $clubId, int $userId): int
    {
        return $this->scalarInt(
            'SELECT xp FROM bookclub_xp_snapshot WHERE club_id = ? AND user_id = ?',
            'ii',
            [$clubId, $userId]
        );
    }

    /**
     * XP ranking restricted to CURRENT active members (a member who left
     * keeps the snapshot row until the next refresh prunes it, but never
     * shows up in the ranking).
     *
     * @return list<array<string, mixed>> rows with user_id, xp, nome, cognome
     */
    public function leaderboard(int $clubId, int $limit = 100): array
    {
        return $this->rows(
            "SELECT s.user_id, s.xp, u.nome, u.cognome
               FROM bookclub_xp_snapshot s
               JOIN utenti u ON u.id = s.user_id
               JOIN bookclub_members m
                 ON m.club_id = s.club_id AND m.user_id = s.user_id AND m.status = 'active'
              WHERE s.club_id = ?
              ORDER BY s.xp DESC, u.cognome ASC, u.nome ASC
              LIMIT ?",
            'ii',
            [$clubId, max(1, min(500, $limit))]
        );
    }

    /** Drop snapshot rows of users who are no longer active members. */
    private function pruneInactive(int $clubId): void
    {
        $this->exec(
            "DELETE s FROM bookclub_xp_snapshot s
               LEFT JOIN bookclub_members m
                 ON m.club_id = s.club_id AND m.user_id = s.user_id AND m.status = 'active'
              WHERE s.club_id = ? AND m.id IS NULL",
            'i',
            [$clubId]
        );
    }

    // ------------------------------------------------------------------
    // Badges
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function allBadges(): array
    {
        return $this->rows('SELECT id, slug, name, description, icon, rule FROM bookclub_badges ORDER BY id ASC');
    }

    /**
     * Awarded badges of the club grouped by user.
     *
     * @return array<int, list<array<string, mixed>>> user id → badge rows
     */
    public function badgesByUser(int $clubId): array
    {
        $rows = $this->rows(
            'SELECT ub.user_id, ub.awarded_at, b.slug, b.name, b.description, b.icon
               FROM bookclub_user_badges ub
               JOIN bookclub_badges b ON b.id = ub.badge_id
              WHERE ub.club_id = ?
              ORDER BY ub.awarded_at ASC, b.id ASC',
            'i',
            [$clubId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['user_id']][] = $row;
        }
        return $out;
    }

    /** Idempotent award: the UNIQUE key + INSERT IGNORE make re-awards no-ops. */
    public function awardBadge(int $clubId, int $userId, int $badgeId): void
    {
        $this->exec(
            'INSERT IGNORE INTO bookclub_user_badges (user_id, badge_id, club_id) VALUES (?, ?, ?)',
            'iii',
            [$userId, $badgeId, $clubId]
        );
    }

    /** Idempotent badge seeding, keyed by slug (used by ensureSchema). */
    public function seedBadge(string $slug, string $name, string $description, string $icon, string $ruleJson): void
    {
        $this->exec(
            'INSERT INTO bookclub_badges (slug, name, description, icon, rule)
             SELECT ?, ?, ?, ?, ? FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM bookclub_badges WHERE slug = ?)',
            'ssssss',
            [$slug, $name, $description, $icon, $ruleJson, $slug]
        );
    }

    // ------------------------------------------------------------------
    // Refresh (snapshot + awards) — the whole recompute for one club
    // ------------------------------------------------------------------

    /**
     * Recompute XP snapshots for the given ACTIVE member ids, prune members
     * who left, then evaluate every badge rule and INSERT IGNORE the missing
     * awards. Idempotent; callers gate it with isStale() (max once/hour).
     *
     * @param list<int> $memberIds active member user ids of the club
     */
    public function refreshClub(int $clubId, array $memberIds): void
    {
        $metrics = $this->metricCounts($clubId);
        foreach ($memberIds as $userId) {
            $this->upsertXp($clubId, (int) $userId, self::xpFromCounts($metrics, (int) $userId));
        }
        $this->pruneInactive($clubId);
        $this->awardBadges($clubId, $memberIds, $metrics);
    }

    /**
     * Evaluate rule JSON ({"metric":"...","gte":N}) per badge per member.
     *
     * @param list<int> $memberIds
     * @param array<string, array<int, int>> $metrics
     */
    private function awardBadges(int $clubId, array $memberIds, array $metrics): void
    {
        if ($memberIds === []) {
            return;
        }
        foreach ($this->allBadges() as $badge) {
            $rule = json_decode((string) ($badge['rule'] ?? ''), true);
            if (!is_array($rule)) {
                continue;
            }
            $metric = (string) ($rule['metric'] ?? '');
            $gte = (int) ($rule['gte'] ?? 0);
            if ($metric === '' || $gte < 1 || !array_key_exists($metric, $metrics)) {
                continue;
            }
            foreach ($memberIds as $userId) {
                if (($metrics[$metric][(int) $userId] ?? 0) >= $gte) {
                    $this->awardBadge($clubId, (int) $userId, (int) $badge['id']);
                }
            }
        }
    }
}
