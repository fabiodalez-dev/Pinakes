<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the "reading" module (Lettura condivisa + Reading Tracker,
 * plan §7.6): bookclub_sections + bookclub_progress.
 *
 * PUBLIC API for other Book Club modules
 * --------------------------------------
 * `bookclub_progress.section_id` stores the LAST COMPLETED section of the
 * user for that club book (nullable: "not started" / "no section picked").
 *
 * - {@see ReadingRepo::userPassedSection()} — static; true when the user's
 *   progress row for the section's club_book has `finished_at` NOT NULL, or
 *   its `section_id` points to a section whose `sort` is >= the target
 *   section's `sort`. Intended for SpoilerGate-style checks (plan §7.7).
 * - {@see ReadingRepo::sectionsForBook()} — static; ordered section rows for
 *   a club book.
 *
 * Both statics only need a mysqli handle, so other modules can call them
 * without instantiating this repo:
 *
 *     if (\App\Plugins\BookClub\ReadingRepo::userPassedSection($db, $userId, $sectionId)) { … }
 */
class ReadingRepo
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Static public API (usable by other modules)
    // ------------------------------------------------------------------

    /**
     * Ordered sections of a club book (sort ASC, id ASC).
     *
     * @return list<array<string, mixed>>
     */
    public static function sectionsForBook(mysqli $db, int $clubBookId): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM bookclub_sections WHERE club_book_id = ? ORDER BY sort ASC, id ASC'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:reading] sectionsForBook prepare failed: ' . $db->error);
            return [];
        }
        $stmt->bind_param('i', $clubBookId);
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:reading] sectionsForBook execute failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Whether $userId has read past section $sectionId (definition in the
     * class docblock: finished the book, or last-completed section's sort is
     * >= the target section's sort).
     */
    public static function userPassedSection(mysqli $db, int $userId, int $sectionId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1
               FROM bookclub_sections s
               JOIN bookclub_progress p
                 ON p.club_book_id = s.club_book_id AND p.user_id = ?
               LEFT JOIN bookclub_sections ls
                 ON ls.id = p.section_id AND ls.club_book_id = p.club_book_id
              WHERE s.id = ?
                AND (p.finished_at IS NOT NULL OR (ls.id IS NOT NULL AND ls.sort >= s.sort))
              LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:reading] userPassedSection prepare failed: ' . $db->error);
            return false;
        }
        $stmt->bind_param('ii', $userId, $sectionId);
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:reading] userPassedSection execute failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        $found = $result !== false && $result->fetch_row() !== null;
        $stmt->close();
        return $found;
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
            SecureLogger::error('[BookClub:reading] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:reading] execute failed: ' . $stmt->error);
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
            SecureLogger::error('[BookClub:reading] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:reading] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function sections(int $clubBookId): array
    {
        return self::sectionsForBook($this->db, $clubBookId);
    }

    /** @return array<string, mixed>|null */
    public function sectionById(int $sectionId): ?array
    {
        return $this->row('SELECT * FROM bookclub_sections WHERE id = ?', 'i', [$sectionId]);
    }

    public function addSection(int $clubBookId, string $title, string $unit, ?int $rangeFrom, ?int $rangeTo, ?string $discussFrom, ?int $sort = null): ?int
    {
        if ($sort === null) {
            $row = $this->row('SELECT COALESCE(MAX(sort), 0) AS mx FROM bookclub_sections WHERE club_book_id = ?', 'i', [$clubBookId]);
            $sort = (int) ($row['mx'] ?? 0) + 1;
        }
        $ok = $this->exec(
            'INSERT INTO bookclub_sections (club_book_id, title, sort, unit, range_from, range_to, discuss_from)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'isisiis',
            [$clubBookId, $title, $sort, $unit, $rangeFrom, $rangeTo, $discussFrom]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function updateSection(int $sectionId, string $title, int $sort, ?string $discussFrom): bool
    {
        return $this->exec(
            'UPDATE bookclub_sections SET title = ?, sort = ?, discuss_from = ? WHERE id = ?',
            'sisi',
            [$title, $sort, $discussFrom, $sectionId]
        );
    }

    public function deleteSection(int $sectionId): bool
    {
        return $this->exec('DELETE FROM bookclub_sections WHERE id = ?', 'i', [$sectionId]);
    }

    // ------------------------------------------------------------------
    // Progress
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function progressRow(int $clubBookId, int $userId): ?array
    {
        return $this->row(
            'SELECT * FROM bookclub_progress WHERE club_book_id = ? AND user_id = ?',
            'ii',
            [$clubBookId, $userId]
        );
    }

    /**
     * Insert-or-update the single progress row per (club_book, user).
     * $finishedAt is the FINAL value ("once" semantics computed by the
     * caller against the existing row).
     */
    public function upsertProgress(int $clubBookId, int $userId, int $percent, ?int $sectionId, ?string $finishedAt): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_progress (club_book_id, user_id, section_id, percent, finished_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE section_id = VALUES(section_id), percent = VALUES(percent), finished_at = VALUES(finished_at)',
            'iiiis',
            [$clubBookId, $userId, $sectionId, $percent, $finishedAt]
        );
    }

    /**
     * Club-level aggregate for a book.
     *
     * - avg_percent:     legacy average over the users who logged progress;
     * - avg_percent_all: average over ALL active club members — members who
     *   never logged any progress count as 0% (SUM of the active members'
     *   percents divided by the active member count);
     * - active_readers / members: participation ratio ("N lettori su M
     *   membri");
     * - finished / readers: finishers and loggers across ALL progress rows
     *   (unchanged semantics).
     *
     * @return array{avg_percent: float, avg_percent_all: float, finished: int, readers: int, active_readers: int, members: int}
     */
    public function aggregate(int $clubBookId, int $clubId): array
    {
        $row = $this->row(
            'SELECT COALESCE(AVG(percent), 0) AS avg_percent,
                    COALESCE(SUM(finished_at IS NOT NULL), 0) AS finished,
                    COUNT(*) AS readers
               FROM bookclub_progress
              WHERE club_book_id = ?',
            'i',
            [$clubBookId]
        );
        $active = $this->row(
            "SELECT COALESCE(SUM(p.percent), 0) AS sum_percent,
                    COUNT(p.id) AS active_readers
               FROM bookclub_progress p
               JOIN bookclub_members m
                 ON m.user_id = p.user_id AND m.club_id = ? AND m.status = 'active'
              WHERE p.club_book_id = ?",
            'ii',
            [$clubId, $clubBookId]
        );
        $membersRow = $this->row(
            "SELECT COUNT(*) AS n FROM bookclub_members WHERE club_id = ? AND status = 'active'",
            'i',
            [$clubId]
        );
        $members = (int) ($membersRow['n'] ?? 0);
        $sumPercent = (float) ($active['sum_percent'] ?? 0);
        return [
            'avg_percent' => (float) ($row['avg_percent'] ?? 0),
            'avg_percent_all' => $members > 0 ? $sumPercent / $members : 0.0,
            'finished' => (int) ($row['finished'] ?? 0),
            'readers' => (int) ($row['readers'] ?? 0),
            'active_readers' => (int) ($active['active_readers'] ?? 0),
            'members' => $members,
        ];
    }

    /**
     * How many distinct users passed each section of the book (same
     * definition as userPassedSection).
     *
     * @return array<int, int> section_id → count
     */
    public function sectionPassedCounts(int $clubBookId): array
    {
        $rows = $this->rows(
            'SELECT s.id AS section_id, COUNT(DISTINCT p.user_id) AS passed
               FROM bookclub_sections s
               JOIN bookclub_progress p ON p.club_book_id = s.club_book_id
               LEFT JOIN bookclub_sections ls
                 ON ls.id = p.section_id AND ls.club_book_id = p.club_book_id
              WHERE s.club_book_id = ?
                AND (p.finished_at IS NOT NULL OR (ls.id IS NOT NULL AND ls.sort >= s.sort))
              GROUP BY s.id',
            'i',
            [$clubBookId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['section_id']] = (int) $row['passed'];
        }
        return $out;
    }

    /**
     * Books of $clubId sitting in one of the workflow states flagged
     * `current`, with catalog title/cover (club panel).
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
            "SELECT cb.id, cb.state, cb.reading_starts, cb.reading_ends,
                    l.titolo, l.copertina_url,
                    (SELECT GROUP_CONCAT(CASE WHEN TRIM(COALESCE(a.pseudonimo, '')) <> ''
                                              THEN CONCAT(TRIM(a.pseudonimo), ' (', TRIM(a.nome), ')')
                                              ELSE TRIM(a.nome) END
                                         ORDER BY la.ordine_credito SEPARATOR ', ')
                       FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id
                        AND la.ruolo IN ('principale', 'co-autore')) AS autori
               FROM bookclub_books cb
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
              WHERE cb.club_id = ? AND cb.state IN ($placeholders)
              ORDER BY cb.position ASC, cb.updated_at DESC",
            $types,
            array_merge([$clubId], $stateKeys)
        );
    }
}
