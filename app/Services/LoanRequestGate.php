<?php
declare(strict_types=1);

namespace App\Services;

use mysqli;
use App\Support\DateHelper;

/**
 * The single #384 reservation-vs-loan routing gate for EVERY public
 * loan-request entry point (ReservationsController::createReservation for the
 * book-detail modal, UserActionsController::loan for POST /user/loan, and any
 * future client). Extracted so the decision can never diverge between paths:
 * the production bug behind #384 was exactly a second entry point creating a
 * bare `prestiti.pendente` row on a title whose only copy was already held by
 * a preceding commitment — a request no admin could then approve (HTTP 400).
 *
 * Decision contract:
 *  - Physical copies exist AND no in-library copy can serve the requested
 *    window without depending on a preceding borrower returning on time
 *    (I1: a contractual due date is not a guaranteed return), OR no capacity
 *    slot stays free over the whole horizon from today through the requested
 *    end (an unbound FIFO reservation owns book-level capacity even though it
 *    has no copia_id) → create a REAL waitlist reservation in
 *    `prenotazioni.attiva` (FIFO queue_position semantics preserved) and
 *    return OUTCOME_RESERVED.
 *  - Otherwise → OUTCOME_LOAN: the caller creates its pending loan request
 *    (auto-approving where its own setting/flow says so). Legacy books with
 *    NO `copie` rows always take this branch (I6): without a physical copy
 *    the reservation promotion pipeline could never convert the queue entry,
 *    so the bare-pending fallback is deliberate for them.
 *
 * Transaction contract: begin_transaction() does NOT change @@autocommit in
 * mysqli/MySQL, so an in-progress transaction cannot be detected reliably —
 * the caller passes an explicit flag (same reasoning as
 * ReservationManager::beginTransactionIfNeeded, TXN-003). With
 * $inCallerTransaction=true the gate NEVER commits or rolls back: the row
 * locks it takes and the reservation it may insert stay inside the caller's
 * transaction. With false it opens, commits and (on failure) rolls back its
 * own transaction, acquiring the canonical book lock itself. A standalone
 * OUTCOME_LOAN is decision-only: commit releases the copy lock, so its result
 * intentionally carries no assignableCopyId claim.
 */
final class LoanRequestGate
{
    public const OUTCOME_RESERVED = 'reserved';
    public const OUTCOME_LOAN = 'loan';

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Evaluate the routing decision for a loan request over [$startDate,
     * $endDate] and, when the waitlist branch wins, persist the reservation.
     *
     * Preconditions when $inCallerTransaction is true: the caller holds the
     * canonical book row lock (SELECT ... FROM libri ... FOR UPDATE), has
     * already validated the dates, the user's eligibility and the
     * duplicate-request guards. The gate only answers "which durable row may
     * this request become" and creates the reservation row itself so no
     * caller can reimplement (and skew) that write.
     *
     * @throws \RuntimeException when the book does not exist (own-transaction
     *         mode only) or when the reservation insert/recalc fails.
     */
    public function route(
        int $bookId,
        int $userId,
        string $startDate,
        string $endDate,
        bool $inCallerTransaction = false
    ): LoanRequestGateResult {
        $ownTransaction = false;
        if (!$inCallerTransaction) {
            if (!$this->db->begin_transaction()) {
                throw new \RuntimeException('Failed to start transaction for loan-request routing.');
            }
            $ownTransaction = true;
        }

        try {
            if ($ownTransaction) {
                // Serialize against every other canonical circulation writer.
                // Callers in their own transaction have already taken this lock.
                $lockStmt = $this->db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
                $lockStmt->bind_param('i', $bookId);
                $lockStmt->execute();
                $found = $lockStmt->get_result()->fetch_assoc() !== null;
                $lockStmt->close();
                if (!$found) {
                    throw new \RuntimeException('Book not found for loan-request routing.');
                }
            }

            $capacity = new CapacityService($this->db);

            // #384: date-disjoint availability is not enough to promise an out
            // copy — borrowers may return it late. With physical rows but no
            // in-library copy that can serve the window without a preceding
            // return, occupy the requested period through the real FIFO
            // waitlist instead of committing a bare, capacity-free pending
            // loan that an admin cannot safely approve.
            $hasPhysicalCopies = $capacity->hasPhysicalCopies($bookId);
            $assignableCopyId = $hasPhysicalCopies
                ? $this->findAssignableInLibraryCopyThrough($bookId, $endDate)
                : null;

            // Active waitlist reservations are book-level commitments and have
            // no copia_id, so the per-copy query above cannot see them. Require
            // one capacity slot to remain free over the whole horizon from
            // today through the requested end: otherwise the new loan would
            // depend on a preceding reservation being promoted, collected and
            // returned on time.
            $hasIndependentCapacityThroughEnd = $capacity->hasFreeCapacity(
                $bookId,
                DateHelper::today(),
                $endDate,
                excludeUserId: $userId
            );

            if ($hasPhysicalCopies && ($assignableCopyId === null || !$hasIndependentCapacityThroughEnd)) {
                $reservationId = $this->createActiveReservation($bookId, $userId, $startDate, $endDate);
                if ($ownTransaction) {
                    $this->db->commit();
                }
                return new LoanRequestGateResult(self::OUTCOME_RESERVED, $reservationId, null, true);
            }

            if ($ownTransaction) {
                $this->db->commit();
                // The commit releases the selected copy's FOR UPDATE lock. Do
                // not export its id as a safe claim: a standalone caller must
                // reroute inside the transaction that persists its loan.
                $assignableCopyId = null;
            }
            return new LoanRequestGateResult(self::OUTCOME_LOAN, null, $assignableCopyId, $hasPhysicalCopies);
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Return a locked copy physically in the library that can serve the requested
     * period, or null when none exists.
     * `prenotato` is intentionally accepted only when every existing commitment
     * starts after the requested period: the copy can serve the earlier window
     * without depending on anybody returning it first. A preceding commitment,
     * even when date-disjoint, routes to the waitlist for the same reason as a
     * `prestato` copy (#384): its contractual due date is not a guaranteed return.
     *
     * The caller (or the own-transaction branch of route()) holds the book row
     * lock, which serializes all canonical circulation writers for this title;
     * FOR UPDATE also locks the chosen copy before the decision is used.
     */
    private function findAssignableInLibraryCopyThrough(int $bookId, string $endDate): ?int
    {
        $stmt = $this->db->prepare("
            SELECT c.id
            FROM copie c
            WHERE c.libro_id = ?
              AND c.stato IN ('disponibile', 'prenotato')
              AND NOT EXISTS (
                  SELECT 1
                  FROM prestiti p
                  WHERE p.copia_id = c.id
                    AND p.data_prestito <= ?
                    AND (
                        (p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                        OR (p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL)
                    )
              )
            -- #384: among compatible copies, preserve future commitments already
            -- bound to a sibling. Prefer a copy with no later commitment (or whose
            -- next one is furthest away) so binding this loan never makes an
            -- existing scheduled loan depend on this borrower returning on time.
            -- Kept identical to the FIFO-promotion allocator (ReservationManager)
            -- and the approval Step-2d allocator so all three sites agree (I3).
            ORDER BY COALESCE((
                SELECT MIN(future.data_prestito)
                FROM prestiti future
                WHERE future.copia_id = c.id
                  AND future.data_prestito > ?
                  AND (
                      (future.attivo = 1 AND future.stato IN ('in_corso','da_ritirare','prenotato','in_ritardo'))
                      OR (future.stato = 'pendente' AND future.copia_id IS NOT NULL)
                  )
            ), '9999-12-31') DESC,
            c.id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('iss', $bookId, $endDate, $endDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Insert a real active waitlist reservation inside the current transaction.
     * The canonical book lock makes MAX(queue_position)+1 race-safe.
     */
    private function createActiveReservation(int $bookId, int $userId, string $startDate, string $endDate): int
    {
        $posStmt = $this->db->prepare("
            SELECT COALESCE(MAX(queue_position), 0) + 1 AS pos
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
        ");
        $posStmt->bind_param('i', $bookId);
        $posStmt->execute();
        $position = (int) ($posStmt->get_result()->fetch_assoc()['pos'] ?? 1);
        $posStmt->close();

        $startDateTime = $startDate . ' 00:00:00';
        $endDateTime = $endDate . ' 23:59:59';
        $stmt = $this->db->prepare("
            INSERT INTO prenotazioni
                (libro_id, utente_id, queue_position, stato,
                 data_prenotazione, data_scadenza_prenotazione,
                 data_inizio_richiesta, data_fine_richiesta)
            VALUES (?, ?, ?, 'attiva', ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'iiissss',
            $bookId,
            $userId,
            $position,
            $startDateTime,
            $endDateTime,
            $startDate,
            $endDate
        );
        $stmt->execute();
        $reservationId = (int) $this->db->insert_id;
        $stmt->close();
        if ($reservationId <= 0) {
            throw new \RuntimeException('Failed to create the waitlist reservation.');
        }

        \App\Support\ActivityLog::recordReservationEvent(
            $this->db,
            $reservationId,
            'reservation.created',
            action: 'inserimento',
            source: 'request_gate',
            operatorId: $userId
        );

        $integrity = new \App\Support\DataIntegrity($this->db);
        if (!$integrity->recalculateBookAvailability($bookId, insideTransaction: true)) {
            throw new \RuntimeException('Failed to recalculate availability after reservation creation.');
        }

        return $reservationId;
    }
}
