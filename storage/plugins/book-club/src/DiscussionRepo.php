<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the "discussions" feature module (plan §7.7): threads,
 * posts (one reply level), emoji reactions, @mentions and the optional
 * bridge towards the reading module's bookclub_sections table.
 *
 * Same hand-written mysqli prepared-statement style as Repo.
 */
class DiscussionRepo
{
    /** Fixed reaction whitelist rendered as toggle buttons. */
    public const EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '👏'];

    /** Thread kinds; 'announcement' is manager-only at creation time. */
    public const KINDS = ['general', 'chapter', 'character', 'free', 'announcement'];

    private mysqli $db;
    private ?bool $sectionsAvailable = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Low-level helpers (same style as Repo)
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:discussions] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:discussions] execute failed: ' . $stmt->error);
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
            SecureLogger::error('[BookClub:discussions] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:discussions] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    // ------------------------------------------------------------------
    // Optional reading-module bridge (bookclub_sections)
    // ------------------------------------------------------------------

    /**
     * Whether the reading module's bookclub_sections table exists — the
     * discussions module works without it (no section selects, spoiler
     * gate always closed).
     */
    public function sectionsAvailable(): bool
    {
        if ($this->sectionsAvailable !== null) {
            return $this->sectionsAvailable;
        }
        $row = $this->row(
            "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookclub_sections'"
        );
        return $this->sectionsAvailable = ((int) ($row['n'] ?? 0) > 0);
    }

    /**
     * Sections of the club's books, for the "spoiler until" selects.
     *
     * @return list<array<string, mixed>>
     */
    public function clubSections(int $clubId): array
    {
        if (!$this->sectionsAvailable()) {
            return [];
        }
        return $this->rows(
            'SELECT s.id, s.title, l.titolo AS book_title
               FROM bookclub_sections s
               JOIN bookclub_books cb ON cb.id = s.club_book_id
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
              WHERE cb.club_id = ?
              ORDER BY l.titolo ASC, s.id ASC',
            'i',
            [$clubId]
        );
    }

    public function sectionBelongsToClub(int $sectionId, int $clubId): bool
    {
        if (!$this->sectionsAvailable()) {
            return false;
        }
        return $this->row(
            'SELECT s.id FROM bookclub_sections s
               JOIN bookclub_books cb ON cb.id = s.club_book_id
              WHERE s.id = ? AND cb.club_id = ?',
            'ii',
            [$sectionId, $clubId]
        ) !== null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> section_id → title
     */
    public function sectionTitles(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
        if ($ids === [] || !$this->sectionsAvailable()) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->rows(
            "SELECT id, title FROM bookclub_sections WHERE id IN ($placeholders)",
            str_repeat('i', count($ids)),
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) $row['title'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Threads
    // ------------------------------------------------------------------

    private function threadSelect(): string
    {
        $sectionSelect = $this->sectionsAvailable()
            ? ', sec.title AS section_title'
            : ', NULL AS section_title';
        $sectionJoin = $this->sectionsAvailable()
            ? ' LEFT JOIN bookclub_sections sec ON sec.id = t.section_id'
            : '';
        return "SELECT t.*, u.nome AS creator_nome, u.cognome AS creator_cognome,
                       l.titolo AS book_title{$sectionSelect},
                       (SELECT COUNT(*) FROM bookclub_posts p
                         WHERE p.thread_id = t.id AND p.deleted_at IS NULL) AS post_count,
                       COALESCE((SELECT MAX(p2.created_at) FROM bookclub_posts p2
                                  WHERE p2.thread_id = t.id), t.created_at) AS last_activity
                  FROM bookclub_threads t
                  LEFT JOIN utenti u ON u.id = t.created_by
                  LEFT JOIN bookclub_books cb ON cb.id = t.club_book_id
                  LEFT JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL" . $sectionJoin;
    }

    /**
     * Pinned first, then by last activity (latest post, else creation).
     *
     * @return list<array<string, mixed>>
     */
    public function listThreads(int $clubId, int $limit = 100): array
    {
        return $this->rows(
            $this->threadSelect() . ' WHERE t.club_id = ?
              ORDER BY t.is_pinned DESC, last_activity DESC, t.id DESC
              LIMIT ?',
            'ii',
            [$clubId, max(1, min(500, $limit))]
        );
    }

    /** @return array<string, mixed>|null */
    public function thread(int $threadId): ?array
    {
        return $this->row($this->threadSelect() . ' WHERE t.id = ?', 'i', [$threadId]);
    }

    public function createThread(int $clubId, ?int $clubBookId, ?int $sectionId, string $kind, string $title, int $createdBy): ?int
    {
        $ok = $this->exec(
            'INSERT INTO bookclub_threads (club_id, club_book_id, section_id, kind, title, created_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            'iiissi',
            [$clubId, $clubBookId, $sectionId, $kind, $title, $createdBy]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function toggleLock(int $threadId): bool
    {
        return $this->exec('UPDATE bookclub_threads SET is_locked = 1 - is_locked WHERE id = ?', 'i', [$threadId]);
    }

    public function togglePin(int $threadId): bool
    {
        return $this->exec('UPDATE bookclub_threads SET is_pinned = 1 - is_pinned WHERE id = ?', 'i', [$threadId]);
    }

    // ------------------------------------------------------------------
    // Posts
    // ------------------------------------------------------------------

    /**
     * All posts of a thread in chronological order (soft-deleted included:
     * they render as a removal placeholder to keep replies attached).
     *
     * @return list<array<string, mixed>>
     */
    public function posts(int $threadId): array
    {
        return $this->rows(
            'SELECT p.*, u.nome, u.cognome
               FROM bookclub_posts p
               LEFT JOIN utenti u ON u.id = p.user_id
              WHERE p.thread_id = ?
              ORDER BY p.created_at ASC, p.id ASC',
            'i',
            [$threadId]
        );
    }

    /** @return array<string, mixed>|null post + owning thread/club columns */
    public function post(int $postId): ?array
    {
        return $this->row(
            'SELECT p.*, t.club_id AS thread_club_id, t.is_locked AS thread_locked
               FROM bookclub_posts p
               JOIN bookclub_threads t ON t.id = p.thread_id
              WHERE p.id = ?',
            'i',
            [$postId]
        );
    }

    public function createPost(int $threadId, ?int $parentId, int $userId, string $body, string $spoiler, ?int $spoilerSectionId): ?int
    {
        $ok = $this->exec(
            'INSERT INTO bookclub_posts (thread_id, parent_id, user_id, body, spoiler, spoiler_section_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            'iiissi',
            [$threadId, $parentId, $userId, $body, $spoiler, $spoilerSectionId]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function softDeletePost(int $postId): bool
    {
        return $this->exec('UPDATE bookclub_posts SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL', 'i', [$postId]);
    }

    // ------------------------------------------------------------------
    // Reactions
    // ------------------------------------------------------------------

    /** Toggle: remove the reaction when present, add it otherwise. */
    public function toggleReaction(int $postId, int $userId, string $emoji): bool
    {
        $del = $this->db->prepare('DELETE FROM bookclub_reactions WHERE post_id = ? AND user_id = ? AND emoji = ?');
        if ($del === false) {
            SecureLogger::error('[BookClub:discussions] toggleReaction prepare failed: ' . $this->db->error);
            return false;
        }
        $del->bind_param('iis', $postId, $userId, $emoji);
        $ok = $del->execute();
        $removed = $del->affected_rows > 0;
        $del->close();
        if (!$ok) {
            return false;
        }
        if ($removed) {
            return true;
        }
        return $this->exec(
            'INSERT IGNORE INTO bookclub_reactions (post_id, user_id, emoji) VALUES (?, ?, ?)',
            'iis',
            [$postId, $userId, $emoji]
        );
    }

    /**
     * Reaction counts per post of a thread, flagging the viewer's own.
     *
     * @return array<int, list<array{emoji: string, n: int, mine: bool}>> post_id → reactions
     */
    public function reactionsForThread(int $threadId, ?int $userId): array
    {
        $viewer = $userId ?? 0;
        $rows = $this->rows(
            'SELECT r.post_id, r.emoji, COUNT(*) AS n, MAX(r.user_id = ?) AS mine
               FROM bookclub_reactions r
               JOIN bookclub_posts p ON p.id = r.post_id
              WHERE p.thread_id = ?
              GROUP BY r.post_id, r.emoji
              ORDER BY r.post_id ASC, r.emoji ASC',
            'ii',
            [$viewer, $threadId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['post_id']][] = [
                'emoji' => (string) $row['emoji'],
                'n' => (int) $row['n'],
                'mine' => (int) $row['mine'] === 1,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Mentions
    // ------------------------------------------------------------------

    public function addMention(int $postId, int $userId): bool
    {
        return $this->exec(
            'INSERT IGNORE INTO bookclub_mentions (post_id, mentioned_user_id) VALUES (?, ?)',
            'ii',
            [$postId, $userId]
        );
    }

    /**
     * First/last names of the users mentioned in each post of a thread —
     * used by the view to embolden the matched @tokens.
     *
     * @return array<int, list<string>> post_id → names
     */
    public function mentionNamesForThread(int $threadId): array
    {
        $rows = $this->rows(
            'SELECT p.id AS post_id, u.nome, u.cognome
               FROM bookclub_mentions m
               JOIN bookclub_posts p ON p.id = m.post_id
               JOIN utenti u ON u.id = m.mentioned_user_id
              WHERE p.thread_id = ?',
            'i',
            [$threadId]
        );
        $out = [];
        foreach ($rows as $row) {
            $postId = (int) $row['post_id'];
            foreach (['nome', 'cognome'] as $field) {
                $name = trim((string) ($row[$field] ?? ''));
                if ($name !== '' && !in_array($name, $out[$postId] ?? [], true)) {
                    $out[$postId][] = $name;
                }
            }
        }
        return $out;
    }

    /**
     * Active members with names, for @mention matching.
     *
     * @return list<array<string, mixed>>
     */
    public function activeMembers(int $clubId): array
    {
        return $this->rows(
            "SELECT u.id, u.nome, u.cognome
               FROM bookclub_members m
               JOIN utenti u ON u.id = m.user_id
              WHERE m.club_id = ? AND m.status = 'active'",
            'i',
            [$clubId]
        );
    }
}
