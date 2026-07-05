<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the Challenges module (plan §7.16 — Reading Challenge):
 * annual reading goals, personal (user_id set) or club-wide (user_id NULL),
 * with progress snapshots recomputed from the reading tracker.
 *
 * PROGRESS FORMULAS (per club, per user, per year_ref) — the normative
 * source is bookclub_progress.finished_at set by the Reading module:
 *
 *  - metric 'books':   COUNT of bookclub_progress rows of the user whose
 *                      club_book belongs to the club and whose finished_at
 *                      falls in year_ref.
 *  - metric 'pages':   SUM of libri.numero_pagine over those same finished
 *                      books; books without a page count fall back to a
 *                      flat 300-page estimate (documented approximation).
 *  - metric 'authors': COUNT DISTINCT autori (via libri_autori) of those
 *                      same finished books.
 *
 * Club-wide challenges keep one bookclub_challenge_progress row PER active
 * member (each member's own metric value); the club total shown against the
 * target is SUM(current) over all rows. Personal challenges keep a single
 * row for the owner, so the same SUM works for both kinds.
 *
 * mysqli prepared statements only, same style as App\Plugins\BookClub\Repo.
 */
class ChallengeRepo
{
    public const METRICS = ['books', 'pages', 'authors'];

    /** Flat page estimate for finished books whose libri.numero_pagine is NULL. */
    public const PAGES_FALLBACK = 300;

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Low-level helpers (same pattern as Repo / StatsRepo)
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:challenges] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:challenges] execute failed: ' . $stmt->error);
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
            SecureLogger::error('[BookClub:challenges] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:challenges] execute failed: ' . $stmt->error);
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
     * Whether the Reading module's tracker table exists — the single data
     * source of every metric. Without it progress cannot be computed (the
     * plan declares 'challenges' depends on 'reading').
     */
    public function readingSchemaReady(): bool
    {
        return $this->tableExists('bookclub_progress');
    }

    // ------------------------------------------------------------------
    // Challenge CRUD
    // ------------------------------------------------------------------

    /**
     * All challenges of a club for one year, club-wide first, each with:
     *  - owner_name / creator_name (empty for club-wide / unknown),
     *  - total_current = SUM of all participants' snapshots (club total for
     *    club-wide, the owner's value for personal challenges),
     *  - participant_count.
     *
     * @return list<array<string, mixed>>
     */
    public function challengesForYear(int $clubId, int $year): array
    {
        return $this->rows(
            "SELECT c.*,
                    TRIM(CONCAT(COALESCE(uo.nome, ''), ' ', COALESCE(uo.cognome, ''))) AS owner_name,
                    TRIM(CONCAT(COALESCE(uc.nome, ''), ' ', COALESCE(uc.cognome, ''))) AS creator_name,
                    (SELECT COALESCE(SUM(cp.`current`), 0)
                       FROM bookclub_challenge_progress cp WHERE cp.challenge_id = c.id) AS total_current,
                    (SELECT COUNT(*)
                       FROM bookclub_challenge_progress cp2 WHERE cp2.challenge_id = c.id) AS participant_count
               FROM bookclub_challenges c
               LEFT JOIN utenti uo ON uo.id = c.user_id
               LEFT JOIN utenti uc ON uc.id = c.created_by
              WHERE c.club_id = ? AND c.year_ref = ?
              ORDER BY (c.user_id IS NULL) DESC, c.created_at ASC, c.id ASC",
            'ii',
            [$clubId, $year]
        );
    }

    /** @return array<string, mixed>|null */
    public function challengeById(int $challengeId): ?array
    {
        return $this->row('SELECT * FROM bookclub_challenges WHERE id = ?', 'i', [$challengeId]);
    }

    /** $userId NULL = club-wide challenge. Returns the new id or null. */
    public function createChallenge(int $clubId, ?int $userId, string $title, string $metric, int $target, int $year, ?int $createdBy): ?int
    {
        $ok = $this->exec(
            'INSERT INTO bookclub_challenges (club_id, user_id, title, metric, target, year_ref, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'iissiii',
            [$clubId, $userId, $title, $metric, $target, $year, $createdBy]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    /** Progress rows follow via ON DELETE CASCADE. */
    public function deleteChallenge(int $challengeId): bool
    {
        return $this->exec('DELETE FROM bookclub_challenges WHERE id = ?', 'i', [$challengeId]);
    }

    // ------------------------------------------------------------------
    // Progress snapshots
    // ------------------------------------------------------------------

    /** Idempotent per (challenge, user): recomputes only refresh the value. */
    public function upsertProgress(int $challengeId, int $userId, int $current): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_challenge_progress (challenge_id, user_id, `current`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `current` = VALUES(`current`)',
            'iii',
            [$challengeId, $userId, $current]
        );
    }

    /**
     * The viewing user's snapshot for every challenge of the club/year.
     *
     * @return array<int, int> challenge_id → current
     */
    public function userProgressMap(int $clubId, int $year, int $userId): array
    {
        $rows = $this->rows(
            'SELECT cp.challenge_id, cp.`current`
               FROM bookclub_challenge_progress cp
               JOIN bookclub_challenges c ON c.id = cp.challenge_id
              WHERE c.club_id = ? AND c.year_ref = ? AND cp.user_id = ?',
            'iii',
            [$clubId, $year, $userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['challenge_id']] = (int) $row['current'];
        }
        return $out;
    }

    /** @return list<int> user ids of the club's active members */
    public function activeMemberIds(int $clubId): array
    {
        $rows = $this->rows(
            "SELECT user_id FROM bookclub_members WHERE club_id = ? AND status = 'active'",
            'i',
            [$clubId]
        );
        return array_map(static fn(array $r): int => (int) $r['user_id'], $rows);
    }

    // ------------------------------------------------------------------
    // Metric computation (see class docblock for the formulas)
    // ------------------------------------------------------------------

    public function computeMetric(int $clubId, int $userId, string $metric, int $year): int
    {
        switch ($metric) {
            case 'pages':
                // SUM of real page counts; NULL numero_pagine → 300-page estimate.
                return $this->scalarInt(
                    'SELECT COALESCE(SUM(COALESCE(l.numero_pagine, ' . self::PAGES_FALLBACK . ')), 0) AS n
                       FROM bookclub_progress p
                       JOIN bookclub_books cb ON cb.id = p.club_book_id
                       JOIN libri l ON l.id = cb.libro_id
                      WHERE cb.club_id = ? AND p.user_id = ?
                        AND p.finished_at IS NOT NULL AND YEAR(p.finished_at) = ?',
                    'iii',
                    [$clubId, $userId, $year]
                );
            case 'authors':
                return $this->scalarInt(
                    'SELECT COUNT(DISTINCT la.autore_id) AS n
                       FROM bookclub_progress p
                       JOIN bookclub_books cb ON cb.id = p.club_book_id
                       JOIN libri_autori la ON la.libro_id = cb.libro_id
                      WHERE cb.club_id = ? AND p.user_id = ?
                        AND p.finished_at IS NOT NULL AND YEAR(p.finished_at) = ?',
                    'iii',
                    [$clubId, $userId, $year]
                );
            case 'books':
            default:
                return $this->scalarInt(
                    'SELECT COUNT(*) AS n
                       FROM bookclub_progress p
                       JOIN bookclub_books cb ON cb.id = p.club_book_id
                      WHERE cb.club_id = ? AND p.user_id = ?
                        AND p.finished_at IS NOT NULL AND YEAR(p.finished_at) = ?',
                    'iii',
                    [$clubId, $userId, $year]
                );
        }
    }

    /**
     * Refresh the snapshot(s) of one challenge: the owner's row for a
     * personal challenge, one row per active member for a club-wide one
     * (rows of members who later left are kept — their past contribution
     * remains part of the club total).
     *
     * @param array<string, mixed> $challenge bookclub_challenges row
     * @param list<int>|null $memberIds active member ids (fetched when null)
     */
    public function recomputeChallenge(array $challenge, ?array $memberIds = null): void
    {
        if (!$this->readingSchemaReady()) {
            return;
        }
        $challengeId = (int) $challenge['id'];
        $clubId = (int) $challenge['club_id'];
        $metric = (string) $challenge['metric'];
        $year = (int) $challenge['year_ref'];

        if ($challenge['user_id'] !== null) {
            $userId = (int) $challenge['user_id'];
            $this->upsertProgress($challengeId, $userId, $this->computeMetric($clubId, $userId, $metric, $year));
            return;
        }
        foreach ($memberIds ?? $this->activeMemberIds($clubId) as $userId) {
            $this->upsertProgress($challengeId, $userId, $this->computeMetric($clubId, $userId, $metric, $year));
        }
    }

    /**
     * Recompute every challenge of a club for one year. Called lazily on
     * page view and from the maintenance tick; safe to re-run at any time.
     */
    public function recomputeClub(int $clubId, int $year): void
    {
        if (!$this->readingSchemaReady()) {
            return;
        }
        $memberIds = $this->activeMemberIds($clubId);
        foreach ($this->challengesForYear($clubId, $year) as $challenge) {
            $this->recomputeChallenge($challenge, $memberIds);
        }
    }
}
