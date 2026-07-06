<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access shared by the Stats and Seasons modules (plan §7.11, §7.16,
 * §7.17): per-member activity counters, per-club aggregates, the daily
 * metric rollup (bookclub_stats_daily), season CRUD/assignment and the
 * flat exports used by /export.json / /export.csv.
 *
 * mysqli prepared statements only, same style as App\Plugins\BookClub\Repo.
 */
class StatsRepo
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Low-level helpers (same pattern as Repo)
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:stats] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:stats] execute failed: ' . $stmt->error);
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
            SecureLogger::error('[BookClub:stats] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:stats] execute failed: ' . $stmt->error);
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

    public function columnExists(string $table, string $column): bool
    {
        $row = $this->row(
            'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            'ss',
            [$table, $column]
        );
        return (int) ($row['n'] ?? 0) > 0;
    }

    /** Whether the seasons schema (table + bookclub_books.season_id) is in place. */
    public function seasonsSchemaReady(): bool
    {
        return $this->tableExists('bookclub_seasons')
            && $this->columnExists('bookclub_books', 'season_id');
    }

    // ------------------------------------------------------------------
    // Workflow state selectors
    // ------------------------------------------------------------------

    /**
     * Keys of "finished" states: everything flagged `archived`, plus the
     * literal 'finished' key when the workflow still has one (same rule as
     * the Library module's reviews bridge).
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     * @return list<string>
     */
    public static function finishedStateKeys(array $states): array
    {
        $keys = [];
        foreach ($states as $state) {
            if (!empty($state['flags']['archived']) || $state['key'] === 'finished') {
                $keys[] = (string) $state['key'];
            }
        }
        return $keys;
    }

    /**
     * Keys of states flagged `current` (the club is reading/discussing).
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

    /**
     * Keys of states flagged `archived` (the historical shelf).
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     * @return list<string>
     */
    public static function archivedStateKeys(array $states): array
    {
        $keys = [];
        foreach ($states as $state) {
            if (!empty($state['flags']['archived'])) {
                $keys[] = (string) $state['key'];
            }
        }
        return $keys;
    }

    /**
     * @param list<string> $keys
     * @return array{0: string, 1: string} [placeholders, type string]
     */
    private static function inClause(array $keys): array
    {
        return [implode(',', array_fill(0, count($keys), '?')), str_repeat('s', count($keys))];
    }

    // ------------------------------------------------------------------
    // Per-member activity (club page panel)
    // ------------------------------------------------------------------

    public function votesCastBy(int $clubId, int $userId): int
    {
        return $this->scalarInt(
            'SELECT COUNT(*) AS n
               FROM bookclub_votes v
               JOIN bookclub_polls p ON p.id = v.poll_id
              WHERE p.club_id = ? AND v.user_id = ?',
            'ii',
            [$clubId, $userId]
        );
    }

    public function yesRsvpsBy(int $clubId, int $userId): int
    {
        return $this->scalarInt(
            "SELECT COUNT(*) AS n
               FROM bookclub_meeting_rsvps r
               JOIN bookclub_meetings m ON m.id = r.meeting_id
              WHERE m.club_id = ? AND r.user_id = ? AND r.response = 'yes'",
            'ii',
            [$clubId, $userId]
        );
    }

    /** Null when the discussions module tables are not installed. */
    public function postsWrittenBy(int $clubId, int $userId): ?int
    {
        if (!$this->tableExists('bookclub_posts')) {
            return null;
        }
        return $this->scalarInt(
            'SELECT COUNT(*) AS n
               FROM bookclub_posts po
               JOIN bookclub_threads t ON t.id = po.thread_id
              WHERE t.club_id = ? AND po.user_id = ? AND po.deleted_at IS NULL',
            'ii',
            [$clubId, $userId]
        );
    }

    // ------------------------------------------------------------------
    // Per-club aggregates (stats page + rollup)
    // ------------------------------------------------------------------

    /** @return array<string, int> state key → book count */
    public function booksPerState(int $clubId): array
    {
        $rows = $this->rows(
            'SELECT state, COUNT(*) AS n FROM bookclub_books WHERE club_id = ? GROUP BY state',
            'i',
            [$clubId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['state']] = (int) $row['n'];
        }
        return $out;
    }

    public function totalBookCount(int $clubId): int
    {
        return $this->scalarInt('SELECT COUNT(*) AS n FROM bookclub_books WHERE club_id = ?', 'i', [$clubId]);
    }

    /**
     * Books the club finished: state-log rows whose to_state is a finished
     * state, deduplicated per club_book (a book bouncing in and out of the
     * archive still counts once).
     *
     * @param list<string> $finishedKeys
     */
    public function finishedBookCount(int $clubId, array $finishedKeys): int
    {
        if ($finishedKeys === []) {
            return 0;
        }
        [$ph, $types] = self::inClause($finishedKeys);
        return $this->scalarInt(
            "SELECT COUNT(DISTINCT sl.club_book_id) AS n
               FROM bookclub_book_state_log sl
               JOIN bookclub_books cb ON cb.id = sl.club_book_id
              WHERE cb.club_id = ? AND sl.to_state IN ($ph)",
            'i' . $types,
            array_merge([$clubId], $finishedKeys)
        );
    }

    public function meetingsHeld(int $clubId): int
    {
        return $this->scalarInt(
            "SELECT COUNT(*) AS n FROM bookclub_meetings WHERE club_id = ? AND status = 'done'",
            'i',
            [$clubId]
        );
    }

    public function openPollCount(int $clubId): int
    {
        return $this->scalarInt(
            "SELECT COUNT(*) AS n FROM bookclub_polls WHERE club_id = ? AND status = 'open'",
            'i',
            [$clubId]
        );
    }

    /** Null when the discussions module tables are not installed. */
    public function totalPostCount(int $clubId): ?int
    {
        if (!$this->tableExists('bookclub_posts')) {
            return null;
        }
        return $this->scalarInt(
            'SELECT COUNT(*) AS n
               FROM bookclub_posts po
               JOIN bookclub_threads t ON t.id = po.thread_id
              WHERE t.club_id = ? AND po.deleted_at IS NULL',
            'i',
            [$clubId]
        );
    }

    /** Average stars of APPROVED core reviews of the club's books, or null. */
    public function avgApprovedStars(int $clubId): ?float
    {
        $row = $this->row(
            "SELECT AVG(r.stelle) AS avg_stars
               FROM recensioni r
               JOIN bookclub_books cb ON cb.libro_id = r.libro_id
              WHERE cb.club_id = ? AND r.stato = 'approvata'",
            'i',
            [$clubId]
        );
        if ($row === null || $row['avg_stars'] === null) {
            return null;
        }
        return (float) $row['avg_stars'];
    }

    /** @return list<array{name: string, n: int}> */
    public function topProposers(int $clubId, int $limit = 5): array
    {
        $rows = $this->rows(
            'SELECT u.nome, u.cognome, COUNT(*) AS n
               FROM bookclub_books cb
               JOIN utenti u ON u.id = cb.proposed_by
              WHERE cb.club_id = ? AND cb.proposed_by IS NOT NULL
              GROUP BY cb.proposed_by, u.nome, u.cognome
              ORDER BY n DESC, u.cognome ASC, u.nome ASC
              LIMIT ?',
            'ii',
            [$clubId, max(1, min(25, $limit))]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name' => trim((string) $row['nome'] . ' ' . (string) $row['cognome']),
                'n' => (int) $row['n'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Daily rollup (bookclub_stats_daily)
    // ------------------------------------------------------------------

    /** Idempotent per (club, day, metric): re-running a tick only refreshes the value. */
    public function upsertDailyMetric(int $clubId, string $metric, int $value): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_stats_daily (club_id, stat_date, metric, value)
             VALUES (?, CURDATE(), ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            'isi',
            [$clubId, $metric, $value]
        );
    }

    // ------------------------------------------------------------------
    // Seasons
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> seasons with book_count, current first */
    public function seasons(int $clubId): array
    {
        return $this->rows(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM bookclub_books b WHERE b.season_id = s.id) AS book_count
               FROM bookclub_seasons s
              WHERE s.club_id = ?
              ORDER BY s.is_current DESC, s.starts_on DESC, s.id DESC',
            'i',
            [$clubId]
        );
    }

    /** @return array<string, mixed>|null */
    public function seasonById(int $seasonId): ?array
    {
        return $this->row('SELECT * FROM bookclub_seasons WHERE id = ?', 'i', [$seasonId]);
    }

    /** @return array<string, mixed>|null */
    public function currentSeason(int $clubId): ?array
    {
        return $this->row(
            'SELECT * FROM bookclub_seasons WHERE club_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1',
            'i',
            [$clubId]
        );
    }

    public function createSeason(int $clubId, string $name, ?string $startsOn, ?string $endsOn, ?int $booksTarget): ?int
    {
        $ok = $this->exec(
            'INSERT INTO bookclub_seasons (club_id, name, starts_on, ends_on, books_target)
             VALUES (?, ?, ?, ?, ?)',
            'isssi',
            [$clubId, $name, $startsOn, $endsOn, $booksTarget]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function updateSeason(int $seasonId, string $name, ?string $startsOn, ?string $endsOn, ?int $booksTarget): bool
    {
        return $this->exec(
            'UPDATE bookclub_seasons SET name = ?, starts_on = ?, ends_on = ?, books_target = ? WHERE id = ?',
            'sssii',
            [$name, $startsOn, $endsOn, $booksTarget, $seasonId]
        );
    }

    /** Enforces the single-is_current-per-club invariant. */
    public function setCurrentSeason(int $clubId, int $seasonId): bool
    {
        $cleared = $this->exec('UPDATE bookclub_seasons SET is_current = 0 WHERE club_id = ?', 'i', [$clubId]);
        $set = $this->exec(
            'UPDATE bookclub_seasons SET is_current = 1 WHERE id = ? AND club_id = ?',
            'ii',
            [$seasonId, $clubId]
        );
        return $cleared && $set;
    }

    /**
     * Manually assign (or clear, with NULL) the season of one club book —
     * the "seasons/assign" form in the club-page panel.
     */
    public function setBookSeason(int $clubBookId, ?int $seasonId): bool
    {
        if (!$this->seasonsSchemaReady()) {
            return false;
        }
        return $this->exec(
            'UPDATE bookclub_books SET season_id = ? WHERE id = ?',
            'ii',
            [$seasonId, $clubBookId]
        );
    }

    /**
     * Club books eligible for manual season assignment: every book of the
     * club except those in $excludeState (the moderation-pending state),
     * with title/authors and the current season_id for the select.
     *
     * @return list<array<string, mixed>>
     */
    public function assignableBooks(int $clubId, string $excludeState): array
    {
        if (!$this->seasonsSchemaReady()) {
            return [];
        }
        return $this->rows(
            "SELECT cb.id, cb.season_id, cb.state, l.titolo,
                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordine_credito SEPARATOR ', ')
                       FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id) AS autori
               FROM bookclub_books cb
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
              WHERE cb.club_id = ? AND cb.state <> ?
              ORDER BY cb.position ASC, cb.created_at DESC",
            'is',
            [$clubId, $excludeState]
        );
    }

    public function seasonBookCount(int $seasonId): int
    {
        return $this->scalarInt('SELECT COUNT(*) AS n FROM bookclub_books WHERE season_id = ?', 'i', [$seasonId]);
    }

    public function deleteSeason(int $seasonId): bool
    {
        return $this->exec('DELETE FROM bookclub_seasons WHERE id = ?', 'i', [$seasonId]);
    }

    /**
     * Opportunistic assignment: every club book sitting in a `current`-flagged
     * state and still without a season gets the current season. Idempotent.
     *
     * @param list<string> $currentKeys
     */
    public function assignSeasonToCurrentBooks(int $clubId, int $seasonId, array $currentKeys): bool
    {
        if ($currentKeys === [] || !$this->seasonsSchemaReady()) {
            return false;
        }
        [$ph, $types] = self::inClause($currentKeys);
        return $this->exec(
            "UPDATE bookclub_books
                SET season_id = ?
              WHERE club_id = ? AND season_id IS NULL AND state IN ($ph)",
            'ii' . $types,
            array_merge([$seasonId, $clubId], $currentKeys)
        );
    }

    /**
     * Archived-flag books with their season name for the "storico" section.
     *
     * @param list<string> $archivedKeys
     * @return list<array<string, mixed>>
     */
    public function archivedBooksBySeason(int $clubId, array $archivedKeys): array
    {
        if ($archivedKeys === []) {
            return [];
        }
        [$ph, $types] = self::inClause($archivedKeys);
        $seasonSelect = 'NULL AS season_name';
        $seasonJoin = '';
        $seasonOrder = '';
        if ($this->seasonsSchemaReady()) {
            $seasonSelect = 's.name AS season_name';
            $seasonJoin = 'LEFT JOIN bookclub_seasons s ON s.id = cb.season_id';
            $seasonOrder = 's.starts_on DESC, s.id DESC,';
        }
        return $this->rows(
            "SELECT cb.id, cb.state, cb.reading_starts, cb.reading_ends, cb.updated_at,
                    l.titolo,
                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordine_credito SEPARATOR ', ')
                       FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id) AS autori,
                    $seasonSelect
               FROM bookclub_books cb
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
               $seasonJoin
              WHERE cb.club_id = ? AND cb.state IN ($ph)
              ORDER BY $seasonOrder cb.updated_at DESC",
            'i' . $types,
            array_merge([$clubId], $archivedKeys)
        );
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> full book history with season + proposer */
    public function exportBooks(int $clubId): array
    {
        $seasonSelect = 'NULL AS season_name';
        $seasonJoin = '';
        if ($this->seasonsSchemaReady()) {
            $seasonSelect = 's.name AS season_name';
            $seasonJoin = 'LEFT JOIN bookclub_seasons s ON s.id = cb.season_id';
        }
        return $this->rows(
            "SELECT l.titolo,
                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordine_credito SEPARATOR ', ')
                       FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id) AS autori,
                    cb.state, $seasonSelect,
                    TRIM(CONCAT(COALESCE(up.nome, ''), ' ', COALESCE(up.cognome, ''))) AS proposer,
                    cb.reading_starts, cb.reading_ends, cb.created_at
               FROM bookclub_books cb
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
               LEFT JOIN utenti up ON up.id = cb.proposed_by
               $seasonJoin
              WHERE cb.club_id = ?
              ORDER BY cb.created_at ASC, cb.id ASC",
            'i',
            [$clubId]
        );
    }

    /** @return list<array<string, mixed>> chronological state transitions */
    public function exportStateLog(int $clubId): array
    {
        return $this->rows(
            "SELECT l.titolo, sl.from_state, sl.to_state, sl.changed_at,
                    TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS changed_by_name
               FROM bookclub_book_state_log sl
               JOIN bookclub_books cb ON cb.id = sl.club_book_id
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
               LEFT JOIN utenti u ON u.id = sl.changed_by
              WHERE cb.club_id = ?
              ORDER BY sl.changed_at ASC, sl.id ASC",
            'i',
            [$clubId]
        );
    }

    /** @return list<array<string, mixed>> polls with the winner title resolved */
    public function exportPolls(int $clubId): array
    {
        return $this->rows(
            'SELECT p.id, p.title, p.mode, p.votes_per_member, p.anonymity, p.status,
                    p.created_at, p.closes_at, p.closed_at, lw.titolo AS winner_title
               FROM bookclub_polls p
               LEFT JOIN bookclub_books wb ON wb.id = p.winner_club_book_id
               LEFT JOIN libri lw ON lw.id = wb.libro_id AND lw.deleted_at IS NULL
              WHERE p.club_id = ?
              ORDER BY p.created_at ASC, p.id ASC',
            'i',
            [$clubId]
        );
    }
}
