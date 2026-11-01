<?php

declare(strict_types=1);

namespace App\Services\Circulation;

/**
 * Circulation limits that depend on the patron only (not on the material).
 *
 * Built by CirculationPolicy::forUser(); never constructed by callers. Every
 * numeric limit follows the historical convention of the loans settings:
 * 0 means "no limit".
 */
final readonly class UserPolicy
{
    public function __construct(
        /** Patron type the rules were resolved for; null when none applies. */
        public ?int $patronTypeId,
        public ?string $patronTypeCode,
        public ?string $patronTypeName,
        /** Total open loans (scheduled, to collect, in progress, overdue); 0 = unlimited. */
        public int $maxActiveLoans,
        public bool $reservationsEnabled,
        /** Active queue reservations + loan requests awaiting approval; 0 = unlimited. */
        public int $maxReservations,
        /** Card validity applied on creation/approval/renewal; null = not automatic. */
        public ?int $cardDurationDays,
        /** Outstanding balance that blocks circulation (fines branch); null = disabled. */
        public ?float $debtBlockThreshold,
    ) {
    }
}
