<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the "lending" module (Prestito tra membri, plan §7.17):
 * bookclub_member_loans.
 *
 * Members lend their PERSONAL copies of the club's books to each other —
 * this is deliberately separate from the core `prestiti` table (the
 * library's own copies, surfaced by the library module).
 *
 * Lifecycle of a row:
 *   offered   → a lender published a personal copy (borrower NULL);
 *   requested → one borrower asked for it (first wins, lender may decline);
 *   active    → the lender handed the copy over (lent_at set, due_on opt.);
 *   returned  → closed with returned_at;
 *   cancelled → the lender withdrew an offered/requested row.
 *
 * "Open" = offered/requested/active: at most ONE open row per
 * (club_book_id, lender_id) — enforced in code via hasOpenOffer().
 */
class LendingRepo
{
    /** Statuses that count as an open offer/loan. */
    public const OPEN_STATUSES = ['offered', 'requested', 'active'];

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
            SecureLogger::error('[BookClub:lending] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:lending] execute failed: ' . $stmt->error);
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
     * Execute a write statement and return the number of affected rows
     * (-1 on failure). Conditional UPDATEs use this to implement
     * "first requester wins" and state-machine guards atomically.
     *
     * @param array<int, mixed> $params
     */
    private function execAffected(string $sql, string $types = '', array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:lending] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return -1;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:lending] execute failed: ' . $stmt->error);
            $stmt->close();
            return -1;
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        return (int) $affected;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private const LOAN_SELECT = "SELECT ml.*, cb.libro_id, l.titolo, l.copertina_url,
                       (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordine_credito SEPARATOR ', ')
                          FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                         WHERE la.libro_id = l.id) AS autori,
                       ul.nome AS lender_nome, ul.cognome AS lender_cognome,
                       ub.nome AS borrower_nome, ub.cognome AS borrower_cognome
                  FROM bookclub_member_loans ml
                  JOIN bookclub_books cb ON cb.id = ml.club_book_id
                  JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
                  JOIN utenti ul ON ul.id = ml.lender_id
                  LEFT JOIN utenti ub ON ub.id = ml.borrower_id";

    /** @return array<string, mixed>|null */
    public function loanById(int $loanId): ?array
    {
        return $this->row(self::LOAN_SELECT . ' WHERE ml.id = ?', 'i', [$loanId]);
    }

    /**
     * Copies currently offered and still up for grabs (status 'offered').
     *
     * @return list<array<string, mixed>>
     */
    public function openOffers(int $clubId): array
    {
        return $this->rows(
            self::LOAN_SELECT . " WHERE ml.club_id = ? AND ml.status = 'offered'
              ORDER BY ml.offered_at DESC, ml.id DESC",
            'i',
            [$clubId]
        );
    }

    /**
     * Every offer the user made in the club (open ones first, then history).
     *
     * @return list<array<string, mixed>>
     */
    public function myOffers(int $clubId, int $userId): array
    {
        return $this->rows(
            self::LOAN_SELECT . " WHERE ml.club_id = ? AND ml.lender_id = ?
              ORDER BY FIELD(ml.status,'requested','active','offered','returned','cancelled'),
                       ml.offered_at DESC, ml.id DESC",
            'ii',
            [$clubId, $userId]
        );
    }

    /**
     * Loans the user requested or holds as borrower (open first).
     *
     * @return list<array<string, mixed>>
     */
    public function myBorrowings(int $clubId, int $userId): array
    {
        return $this->rows(
            self::LOAN_SELECT . " WHERE ml.club_id = ? AND ml.borrower_id = ?
              ORDER BY FIELD(ml.status,'active','requested','returned','cancelled','offered'),
                       ml.offered_at DESC, ml.id DESC",
            'ii',
            [$clubId, $userId]
        );
    }

    /** One open row (offered/requested/active) per (club_book, lender). */
    public function hasOpenOffer(int $clubBookId, int $lenderId): bool
    {
        return $this->row(
            "SELECT id FROM bookclub_member_loans
              WHERE club_book_id = ? AND lender_id = ? AND status IN ('offered','requested','active')
              LIMIT 1",
            'ii',
            [$clubBookId, $lenderId]
        ) !== null;
    }

    public function countOpenOffers(int $clubId): int
    {
        $row = $this->row(
            "SELECT COUNT(*) AS n FROM bookclub_member_loans WHERE club_id = ? AND status = 'offered'",
            'i',
            [$clubId]
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * The user's ACTIVE loans in the club, on either side of the deal
     * (club page panel).
     *
     * @return list<array<string, mixed>>
     */
    public function myActiveLoans(int $clubId, int $userId): array
    {
        return $this->rows(
            self::LOAN_SELECT . " WHERE ml.club_id = ? AND ml.status = 'active'
                AND (ml.lender_id = ? OR ml.borrower_id = ?)
              ORDER BY ml.due_on IS NULL, ml.due_on ASC, ml.lent_at DESC",
            'iii',
            [$clubId, $userId, $userId]
        );
    }

    // ------------------------------------------------------------------
    // Writes (conditional UPDATEs guard the state machine)
    // ------------------------------------------------------------------

    public function createOffer(int $clubId, int $clubBookId, int $lenderId, string $notes): ?int
    {
        $notesOrNull = $notes !== '' ? $notes : null;
        // Atomic check-and-insert: the controller's hasOpenOffer() pre-check
        // is only UX — two concurrent submits must not both insert, so the
        // one-open-offer invariant is re-enforced inside the INSERT itself.
        $ok = $this->execAffected(
            "INSERT INTO bookclub_member_loans (club_id, club_book_id, lender_id, status, notes)
             SELECT ?, ?, ?, 'offered', ? FROM DUAL
              WHERE NOT EXISTS (
                    SELECT 1 FROM bookclub_member_loans
                     WHERE club_book_id = ? AND lender_id = ?
                       AND status IN ('offered','requested','active')
              )",
            'iiisii',
            [$clubId, $clubBookId, $lenderId, $notesOrNull, $clubBookId, $lenderId]
        );
        return $ok > 0 ? (int) $this->db->insert_id : null;
    }

    /** First requester wins: the row must still be 'offered' and unclaimed. */
    public function requestLoan(int $loanId, int $borrowerId): bool
    {
        return $this->execAffected(
            "UPDATE bookclub_member_loans
                SET status = 'requested', borrower_id = ?
              WHERE id = ? AND status = 'offered' AND borrower_id IS NULL",
            'ii',
            [$borrowerId, $loanId]
        ) === 1;
    }

    /** Lender declines the pending request: back to 'offered', borrower cleared. */
    public function declineRequest(int $loanId): bool
    {
        return $this->execAffected(
            "UPDATE bookclub_member_loans
                SET status = 'offered', borrower_id = NULL
              WHERE id = ? AND status = 'requested'",
            'i',
            [$loanId]
        ) === 1;
    }

    /** Lender hands the copy over: 'requested' → 'active', lent_at NOW. */
    public function handOver(int $loanId, ?string $dueOn): bool
    {
        return $this->execAffected(
            "UPDATE bookclub_member_loans
                SET status = 'active', lent_at = NOW(), due_on = ?
              WHERE id = ? AND status = 'requested' AND borrower_id IS NOT NULL",
            'si',
            [$dueOn, $loanId]
        ) === 1;
    }

    /** Lender or borrower closes the loan: 'active' → 'returned'. */
    public function markReturned(int $loanId): bool
    {
        return $this->execAffected(
            "UPDATE bookclub_member_loans
                SET status = 'returned', returned_at = NOW()
              WHERE id = ? AND status = 'active'",
            'i',
            [$loanId]
        ) === 1;
    }

    /** Lender withdraws an offered/requested row. */
    public function cancel(int $loanId): bool
    {
        return $this->execAffected(
            "UPDATE bookclub_member_loans
                SET status = 'cancelled', borrower_id = NULL
              WHERE id = ? AND status IN ('offered','requested')",
            'i',
            [$loanId]
        ) === 1;
    }
}
