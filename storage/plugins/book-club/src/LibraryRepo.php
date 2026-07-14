<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the "library" module (Integrazione biblioteca + ponte
 * recensioni, plan §7.9/§7.10): copy availability + member loans + waitlist
 * read from the core tables (libri, prestiti, prenotazioni), and the review
 * bridge over the core `recensioni` table with per-club extensions in
 * `bookclub_review_meta` (spoiler flag, strengths/weaknesses).
 */
class LibraryRepo
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
            SecureLogger::error('[BookClub:library] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:library] execute failed: ' . $stmt->error);
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
            SecureLogger::error('[BookClub:library] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:library] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    // ------------------------------------------------------------------
    // Availability (plan §7.10)
    // ------------------------------------------------------------------

    /**
     * Club books in the given workflow states, joined to the core catalog
     * availability counters plus the length of the active reservation queue
     * (whole-library `waitlist` and `club_waitlist`, the subset held by
     * ACTIVE club members). With no loans/reservations data the counters
     * simply come back as zeros.
     *
     * @param list<string> $stateKeys
     * @return list<array<string, mixed>>
     */
    public function booksInStates(int $clubId, array $stateKeys): array
    {
        if ($stateKeys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($stateKeys), '?'));
        $types = 'i' . str_repeat('s', count($stateKeys));
        return $this->rows(
            "SELECT cb.id, cb.libro_id, cb.state, cb.reading_starts, cb.reading_ends,
                    l.titolo, l.copertina_url,
                    COALESCE(l.copie_totali, 0) AS copie_totali,
                    COALESCE(l.copie_disponibili, 0) AS copie_disponibili,
                    (SELECT GROUP_CONCAT(CASE WHEN TRIM(COALESCE(a.pseudonimo, '')) <> ''
                                              THEN CONCAT(TRIM(a.pseudonimo), ' (', TRIM(a.nome), ')')
                                              ELSE TRIM(a.nome) END
                                         ORDER BY la.ordine_credito SEPARATOR ', ')
                       FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id
                        AND la.ruolo IN ('principale', 'co-autore')) AS autori,
                    (SELECT COUNT(*) FROM prenotazioni pr
                      WHERE pr.libro_id = l.id AND pr.stato = 'attiva') AS waitlist,
                    (SELECT COUNT(*) FROM prenotazioni pr2
                       JOIN bookclub_members bm ON bm.user_id = pr2.utente_id
                            AND bm.club_id = cb.club_id AND bm.status = 'active'
                      WHERE pr2.libro_id = l.id AND pr2.stato = 'attiva') AS club_waitlist
               FROM bookclub_books cb
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
              WHERE cb.club_id = ? AND cb.state IN ($placeholders)
              ORDER BY cb.position ASC, cb.created_at DESC",
            $types,
            array_merge([$clubId], $stateKeys)
        );
    }

    /**
     * Active club members who currently hold a copy of $libroId on loan
     * (prestiti stato in_corso/in_ritardo). Names are shown to managers
     * only; regular members just get count($result).
     *
     * @return list<array<string, mixed>> rows with user_id, nome, cognome
     */
    public function memberLoans(int $clubId, int $libroId): array
    {
        return $this->rows(
            "SELECT DISTINCT u.id AS user_id, u.nome, u.cognome
               FROM prestiti p
               JOIN bookclub_members m ON m.user_id = p.utente_id
                    AND m.club_id = ? AND m.status = 'active'
               JOIN utenti u ON u.id = p.utente_id
              WHERE p.libro_id = ? AND p.stato IN ('in_corso','in_ritardo')
              ORDER BY u.cognome, u.nome",
            'ii',
            [$clubId, $libroId]
        );
    }

    /**
     * Highest number of yes-RSVPs among the scheduled meetings linked to the
     * given club book — used for the "N partecipanti, M copie" warning.
     */
    public function maxYesRsvpsForBook(int $clubBookId): int
    {
        $row = $this->row(
            "SELECT COALESCE(MAX(t.cnt), 0) AS n FROM (
                SELECT COUNT(*) AS cnt
                  FROM bookclub_meeting_rsvps r
                  JOIN bookclub_meetings mt ON mt.id = r.meeting_id
                 WHERE mt.club_book_id = ? AND mt.status = 'scheduled' AND r.response = 'yes'
                 GROUP BY mt.id
             ) t",
            'i',
            [$clubBookId]
        );
        return (int) ($row['n'] ?? 0);
    }

    // ------------------------------------------------------------------
    // Review bridge (plan §7.9)
    // ------------------------------------------------------------------

    /**
     * APPROVED core reviews of the given libri written by ACTIVE members of
     * the club, with the book-club meta (spoiler flag, strengths/weaknesses)
     * when present.
     *
     * @param list<int> $libroIds
     * @return list<array<string, mixed>>
     */
    public function clubReviews(int $clubId, array $libroIds): array
    {
        if ($libroIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($libroIds), '?'));
        $types = 'i' . str_repeat('i', count($libroIds));
        return $this->rows(
            "SELECT r.id, r.libro_id, r.utente_id, r.stelle, r.titolo, r.descrizione,
                    r.data_recensione, u.nome, u.cognome, l.titolo AS libro_titolo,
                    COALESCE(rm.has_spoiler, 0) AS has_spoiler,
                    rm.strengths, rm.weaknesses
               FROM recensioni r
               JOIN bookclub_members m ON m.user_id = r.utente_id
                    AND m.club_id = ? AND m.status = 'active'
               JOIN utenti u ON u.id = r.utente_id
               JOIN libri l ON l.id = r.libro_id AND l.deleted_at IS NULL
               LEFT JOIN bookclub_review_meta rm ON rm.recensione_id = r.id
              WHERE r.stato = 'approvata' AND r.libro_id IN ($placeholders)
              ORDER BY r.data_recensione DESC, r.id DESC",
            $types,
            array_merge([$clubId], $libroIds)
        );
    }

    /**
     * Whether $userId already has a review row for $libroId in ANY state —
     * the core UNIQUE(libro_id, utente_id) would reject a second insert.
     */
    public function hasReviewed(int $userId, int $libroId): bool
    {
        return $this->row(
            'SELECT id FROM recensioni WHERE utente_id = ? AND libro_id = ?',
            'ii',
            [$userId, $libroId]
        ) !== null;
    }

    /**
     * Libro ids among $libroIds that $userId has already reviewed (used to
     * hide them from the submission form).
     *
     * @param list<int> $libroIds
     * @return list<int>
     */
    public function reviewedLibroIds(int $userId, array $libroIds): array
    {
        if ($libroIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($libroIds), '?'));
        $types = 'i' . str_repeat('i', count($libroIds));
        $rows = $this->rows(
            "SELECT libro_id FROM recensioni WHERE utente_id = ? AND libro_id IN ($placeholders)",
            $types,
            array_merge([$userId], $libroIds)
        );
        return array_map(static fn(array $r): int => (int) $r['libro_id'], $rows);
    }

    /**
     * Insert a pending core review — same shape as the core
     * RecensioniRepository::createReview (stato 'pendente', moderated by the
     * admin queue).
     */
    public function createReview(int $libroId, int $userId, int $stelle, string $titolo, string $descrizione): ?int
    {
        $ok = $this->exec(
            "INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione, stato, data_recensione)
             VALUES (?, ?, ?, ?, ?, 'pendente', NOW())",
            'iiiss',
            [$libroId, $userId, $stelle, $titolo, $descrizione]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    /**
     * Attach the book-club meta to a core review. Idempotent thanks to
     * UNIQUE(recensione_id).
     */
    public function insertMeta(int $recensioneId, int $clubId, bool $hasSpoiler, ?string $strengths, ?string $weaknesses): bool
    {
        $spoiler = $hasSpoiler ? 1 : 0;
        return $this->exec(
            'INSERT INTO bookclub_review_meta (recensione_id, club_id, has_spoiler, strengths, weaknesses)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE has_spoiler = VALUES(has_spoiler),
                                     strengths = VALUES(strengths),
                                     weaknesses = VALUES(weaknesses)',
            'iiiss',
            [$recensioneId, $clubId, $spoiler, $strengths, $weaknesses]
        );
    }
}
