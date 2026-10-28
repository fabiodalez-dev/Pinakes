<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pure late-fine arithmetic: no database, no clock.
 *
 * Rules (plan §7, decision 5):
 * - days late = OPEN days strictly after the due date up to and including the
 *   return date. The "is open day" callable decides which days count; the
 *   default counts every day. Stack hook: the closures calendar passes
 *   ClosureCalendar::isOpen here.
 * - the grace period is a THRESHOLD, as in SLiMS: up to graceDays late there is
 *   no fine; once a loan is later than that, every late day is charged from
 *   day one.
 * - the optional cap per loan limits the total.
 */
final class FineCalculator
{
    /** Upper bound on the day walk, so a corrupt date cannot spin for years. */
    private const MAX_DAYS = 3660;

    /** @var (callable(string): bool)|null */
    private $isOpenDay;

    /**
     * @param (callable(string): bool)|null $isOpenDay receives a Y-m-d date and
     *        returns false for a day the library is closed.
     */
    public function __construct(?callable $isOpenDay = null)
    {
        $this->isOpenDay = $isOpenDay;
    }

    /** Open days the item was kept after $dueDate, up to $returnDate. */
    public function daysLate(string $dueDate, string $returnDate): int
    {
        $due = self::parse($dueDate);
        $returned = self::parse($returnDate);
        if ($due === null || $returned === null || $returned <= $due) {
            return 0;
        }

        $span = (int) $due->diff($returned)->days;
        if ($this->isOpenDay === null) {
            return $span;
        }

        $open = 0;
        $day = $due;
        $steps = min($span, self::MAX_DAYS);
        for ($i = 0; $i < $steps; $i++) {
            $day = $day->modify('+1 day');
            if (($this->isOpenDay)($day->format('Y-m-d'))) {
                $open++;
            }
        }
        return $open;
    }

    /** Late fine in cents for a loan due on $dueDate and returned on $returnDate. */
    public function lateFineCents(FineParameters $params, string $dueDate, string $returnDate): int
    {
        if (!$params->finesEnabled()) {
            return 0;
        }
        $days = $this->daysLate($dueDate, $returnDate);
        if ($days <= $params->graceDays) {
            return 0;
        }
        $amount = $days * $params->dailyFineCents;
        if ($params->maxFinePerLoanCents !== null) {
            $amount = min($amount, $params->maxFinePerLoanCents);
        }
        return $amount;
    }

    private static function parse(string $date): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
