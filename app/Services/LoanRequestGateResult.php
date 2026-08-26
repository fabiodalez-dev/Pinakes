<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Structured outcome of the shared #384 loan-request routing decision
 * (LoanRequestGate::route). Callers translate it into their own response
 * style: the book-detail modal endpoint returns JSON, POST /user/loan
 * redirects back — but the DECISION (and the persisted waitlist row, when
 * that branch wins) is identical for every public entry point.
 */
final class LoanRequestGateResult
{
    public function __construct(
        /** LoanRequestGate::OUTCOME_RESERVED or LoanRequestGate::OUTCOME_LOAN */
        public readonly string $outcome,
        /** The prenotazioni.attiva row created when outcome is RESERVED, else null. */
        public readonly ?int $reservationId,
        /**
         * The in-library copy (already locked FOR UPDATE) that can serve the
         * requested window without depending on a preceding return, or null.
         * Only meaningful when outcome is LOAN; callers that auto-approve may
         * pre-bind it to the pending row to close the post-commit race.
         */
        public readonly ?int $assignableCopyId,
        /** False only for legacy aggregate-only books with no `copie` rows (I6). */
        public readonly bool $hasPhysicalCopies,
    ) {
    }

    public function isReservation(): bool
    {
        return $this->outcome === LoanRequestGate::OUTCOME_RESERVED;
    }
}
