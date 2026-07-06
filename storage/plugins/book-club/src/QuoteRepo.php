<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the "quotes" module (Citazioni e annotazioni, plan §7.8):
 * bookclub_quotes + bookclub_notes.
 *
 * Quotes reference the core catalog (`libri`) directly, so a quote survives
 * the removal of the club book row; the optional `club_id` scopes it to a
 * club. `visibility` semantics:
 *   - private → only the author sees it,
 *   - club    → active members of the club see it,
 *   - public  → additionally exposable on the core book page (future hook,
 *               see QuotesModule docblock).
 *
 * Notes ("annotazioni") are per club-book (`bookclub_books`), private by
 * default or shared with the club.
 */
class QuoteRepo
{
    public const QUOTE_VISIBILITIES = ['private', 'club', 'public'];
    public const NOTE_VISIBILITIES = ['private', 'club'];

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
            SecureLogger::error('[BookClub:quotes] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:quotes] execute failed: ' . $stmt->error);
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
            SecureLogger::error('[BookClub:quotes] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:quotes] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    // ------------------------------------------------------------------
    // Quotes
    // ------------------------------------------------------------------

    private const QUOTE_SELECT = "SELECT q.*, l.titolo,
                       (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordine_credito SEPARATOR ', ')
                          FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                         WHERE la.libro_id = l.id) AS autori,
                       u.nome AS member_nome, u.cognome AS member_cognome
                  FROM bookclub_quotes q
                  JOIN libri l ON l.id = q.libro_id AND l.deleted_at IS NULL
                  JOIN utenti u ON u.id = q.user_id";

    /** @return array<string, mixed>|null */
    public function quoteById(int $quoteId): ?array
    {
        return $this->row('SELECT * FROM bookclub_quotes WHERE id = ?', 'i', [$quoteId]);
    }

    /**
     * Quotes visible to $viewerId on the club quotes page: everything shared
     * with the club (visibility club/public) plus the viewer's own private
     * ones. Pass viewerId = 0 for "no personal quotes".
     *
     * @return list<array<string, mixed>>
     */
    public function clubQuotes(int $clubId, int $viewerId): array
    {
        return $this->rows(
            self::QUOTE_SELECT . " WHERE q.club_id = ?
                AND (q.visibility IN ('club','public') OR q.user_id = ?)
              ORDER BY q.created_at DESC, q.id DESC",
            'ii',
            [$clubId, $viewerId]
        );
    }

    /**
     * Most recent club-visible quotes (club page panel).
     *
     * @return list<array<string, mixed>>
     */
    public function recentClubQuotes(int $clubId, int $limit = 3): array
    {
        return $this->rows(
            self::QUOTE_SELECT . " WHERE q.club_id = ? AND q.visibility IN ('club','public')
              ORDER BY q.created_at DESC, q.id DESC LIMIT ?",
            'ii',
            [$clubId, max(1, min(10, $limit))]
        );
    }

    /**
     * ALL quotes of one member for a club — the member's own data export.
     *
     * @return list<array<string, mixed>>
     */
    public function myQuotes(int $clubId, int $userId): array
    {
        return $this->rows(
            self::QUOTE_SELECT . ' WHERE q.club_id = ? AND q.user_id = ?
              ORDER BY l.titolo ASC, q.page ASC, q.created_at ASC',
            'ii',
            [$clubId, $userId]
        );
    }

    /**
     * PUBLIC quotes of one catalog book across ALL clubs, newest first —
     * the core book detail page section (hook book.frontend.details).
     * Public visibility is an explicit author choice, so no per-club
     * enablement filter applies; quotes of deleted clubs are dropped by
     * the JOIN.
     *
     * @return list<array<string, mixed>>
     */
    public function publicQuotesForBook(int $libroId, int $limit = 5): array
    {
        return $this->rows(
            "SELECT q.id, q.quote, q.page, q.created_at,
                    u.nome AS member_nome, c.name AS club_name
               FROM bookclub_quotes q
               JOIN utenti u ON u.id = q.user_id
               JOIN bookclub_clubs c ON c.id = q.club_id AND c.deleted_at IS NULL
              WHERE q.libro_id = ? AND q.visibility = 'public'
              ORDER BY q.created_at DESC, q.id DESC
              LIMIT ?",
            'ii',
            [$libroId, max(1, min(10, $limit))]
        );
    }

    public function addQuote(int $userId, int $libroId, ?int $clubId, string $quote, ?int $page, string $note, string $visibility): ?int
    {
        if (!in_array($visibility, self::QUOTE_VISIBILITIES, true)) {
            $visibility = 'club';
        }
        $ok = $this->exec(
            'INSERT INTO bookclub_quotes (user_id, libro_id, club_id, quote, page, note, visibility)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'iiisiss',
            [$userId, $libroId, $clubId, $quote, $page, $note, $visibility]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function setQuoteVisibility(int $quoteId, string $visibility): bool
    {
        if (!in_array($visibility, self::QUOTE_VISIBILITIES, true)) {
            return false;
        }
        return $this->exec('UPDATE bookclub_quotes SET visibility = ? WHERE id = ?', 'si', [$visibility, $quoteId]);
    }

    public function deleteQuote(int $quoteId): bool
    {
        return $this->exec('DELETE FROM bookclub_quotes WHERE id = ?', 'i', [$quoteId]);
    }

    // ------------------------------------------------------------------
    // Notes (annotazioni per club book)
    // ------------------------------------------------------------------

    private const NOTE_SELECT = "SELECT n.*, cb.club_id, l.titolo,
                       u.nome AS member_nome, u.cognome AS member_cognome
                  FROM bookclub_notes n
                  JOIN bookclub_books cb ON cb.id = n.club_book_id
                  JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
                  JOIN utenti u ON u.id = n.user_id";

    /** @return array<string, mixed>|null */
    public function noteById(int $noteId): ?array
    {
        return $this->row(self::NOTE_SELECT . ' WHERE n.id = ?', 'i', [$noteId]);
    }

    /**
     * The member's own annotations across the club's books.
     *
     * @return list<array<string, mixed>>
     */
    public function myNotes(int $clubId, int $userId): array
    {
        return $this->rows(
            self::NOTE_SELECT . ' WHERE cb.club_id = ? AND n.user_id = ?
              ORDER BY l.titolo ASC, n.created_at DESC, n.id DESC',
            'ii',
            [$clubId, $userId]
        );
    }

    /**
     * Club-shared annotations written by OTHER members.
     *
     * @return list<array<string, mixed>>
     */
    public function clubNotesOfOthers(int $clubId, int $userId): array
    {
        return $this->rows(
            self::NOTE_SELECT . " WHERE cb.club_id = ? AND n.visibility = 'club' AND n.user_id <> ?
              ORDER BY n.created_at DESC, n.id DESC",
            'ii',
            [$clubId, $userId]
        );
    }

    public function addNote(int $userId, int $clubBookId, string $body, string $visibility): ?int
    {
        if (!in_array($visibility, self::NOTE_VISIBILITIES, true)) {
            $visibility = 'private';
        }
        $ok = $this->exec(
            'INSERT INTO bookclub_notes (user_id, club_book_id, body, visibility) VALUES (?, ?, ?, ?)',
            'iiss',
            [$userId, $clubBookId, $body, $visibility]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function updateNote(int $noteId, string $body, string $visibility): bool
    {
        if (!in_array($visibility, self::NOTE_VISIBILITIES, true)) {
            $visibility = 'private';
        }
        return $this->exec(
            'UPDATE bookclub_notes SET body = ?, visibility = ? WHERE id = ?',
            'ssi',
            [$body, $visibility, $noteId]
        );
    }

    public function deleteNote(int $noteId): bool
    {
        return $this->exec('DELETE FROM bookclub_notes WHERE id = ?', 'i', [$noteId]);
    }
}
