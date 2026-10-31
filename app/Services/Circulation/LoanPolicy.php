<?php

declare(strict_types=1);

namespace App\Services\Circulation;

/**
 * Circulation rules for one patron borrowing one title (optionally one copy).
 *
 * Built by CirculationPolicy::forLoan() through the pure PolicyResolver.
 * Durations are in days. Quantity limits use 0 for "no limit". The fine
 * fields are carried for the fines feature and are null until configured.
 */
final readonly class LoanPolicy
{
    public function __construct(
        public UserPolicy $user,
        /** libri.tipo_media of the title, '*' when unknown. */
        public string $mediaType,
        /** False = consultation only (material rule or the single copy). */
        public bool $loanable,
        /** Standard loan duration (staff loans, NCIP checkout, default end). */
        public int $durationDays,
        /** Longest span a reader request may cover (explicit end date included). */
        public int $maxRequestDays,
        public int $maxRenewals,
        /** Days a renewal adds to the current due date. */
        public int $renewalDays,
        /** Total open loans allowed for the patron; 0 = unlimited. */
        public int $maxActiveLoans,
        /** Open loans allowed for this material type, on top of the total; 0 = unlimited. */
        public int $maxActiveForMedia,
        public ?float $dailyFine,
        public ?int $graceDays,
        public ?float $maxFinePerLoan,
        public ?float $debtBlockThreshold,
    ) {
    }

    /** Default for reader requests and their calendars: the duration, capped by the request maximum. */
    public function requestDurationDays(): int
    {
        return min($this->durationDays, $this->maxRequestDays);
    }

    /** Whether any quantity limit has to be counted for this loan. */
    public function hasQuantityLimits(): bool
    {
        return $this->maxActiveLoans > 0 || $this->maxActiveForMedia > 0;
    }
}
