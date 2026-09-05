<?php
declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * CapacityService — the single authority for book-level capacity (issue
 * fix/loan-state-bugs). Every reader that asks "is this book free for a period?"
 * routes through here so the occupancy predicate cannot drift across controllers.
 *
 * ── CANONICAL OCCUPANCY RULE (two enforcement layers, never mixed) ────────────
 *
 * HOLDING set (per-copy, Layer 1 — what occupies a *specific* copy):
 *     HOLDING(p) :=
 *         ( p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo') )
 *      OR ( p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL )
 * Enforced by DB triggers + per-copy allocators. The waitlist (prenotazioni)
 * never participates here — it has no copia_id.
 *
 * OCC (book-level capacity, Layer 2 — how many simultaneous commitments):
 *     OCC(b,[s,e]) := per-day MAX over [s,e] of (
 *           COUNT(HOLDING loans of b overlapping the day)
 *         + COUNT(active prenotazioni of b overlapping the day) )
 *     where the reservation interval bounds are
 *       R_START(r) := COALESCE(r.data_inizio_richiesta, DATE(r.data_scadenza_prenotazione))
 *       R_END(r) := COALESCE(r.data_fine_richiesta, DATE(r.data_scadenza_prenotazione), r.data_inizio_richiesta)
 * Inclusive overlap: start_a <= end_b AND end_a >= start_b.
 *
 * Free capacity for [s,e] iff OCC(b,[s,e]) < copie_totali(b) for EVERY day in [s,e].
 * copie_totali(b) = COUNT of copie rows whose stato is lendable
 *                   (NOT IN perso, danneggiato, manutenzione, in_restauro, in_trasferimento).
 *
 * THE DECISION: a prenotazioni row (stato='attiva') with period
 * [R_START, R_END] occupies exactly one capacity unit for that
 * period, counted in OCC up to copie_totali. It is *soft* (gates capacity,
 * blocks new commitments) but does not pin a physical copy. The bare prestiti
 * request (stato='pendente', copia_id IS NULL) is unbounded and does NOT occupy.
 */
final class CapacityService
{
    private ?int $maxActiveLoansPerUser = null;

    public function __construct(private mysqli $db) {}

    /**
     * Lendable physical copies of a book (the capacity ceiling). If the book has
     * per-copy rows, count the lendable ones; otherwise fall back to the legacy
     * libri.copie_totali (NULL → 1, explicit 0 → 0), matching
     * ReservationsController::getBookTotalCopies and the overbooked auditor so a
     * legacy book without copie rows is not spuriously blocked by every gate.
     */
    public function totalCopies(int $libroId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM copie WHERE libro_id = ?");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $copyRows = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        if ($copyRows > 0) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS total FROM copie
                 WHERE libro_id = ?
                   AND stato NOT IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento')"
            );
            $stmt->bind_param('i', $libroId);
            $stmt->execute();
            $lendable = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            return $lendable;
        }

        // No per-copy rows: legacy fallback to libri.copie_totali (NULL → 1, 0 → 0).
        $stmt = $this->db->prepare("SELECT IFNULL(copie_totali, 1) AS total FROM libri WHERE id = ? AND deleted_at IS NULL");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? (int) $row['total'] : 0;
    }

    /**
     * True se il libro ha almeno una riga in `copie`. Un libro legacy senza
     * copie fisiche registrate supera il gate di capacità tramite il fallback
     * copie_totali, ma la promozione della coda seleziona SOLO da `copie`:
     * una prenotazione accettata non convertirebbe mai (fallirebbe in silenzio
     * a ogni run fino alla scadenza). I gate di CREAZIONE prenotazione usano
     * questo check per rifiutare a monte; i prestiti legacy copyless esistenti
     * non passano di qui e restano gestiti dal fallback.
     */
    public function hasPhysicalCopies(int $libroId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM copie WHERE libro_id = ? LIMIT 1");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $hasRows = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $hasRows;
    }

    /**
     * OCC(b,[s,e]) — the peak simultaneous occupancy over the inclusive interval
     * [$start,$end], counting HOLDING loans + active reservations. Excludes the
     * row/user being decided (so a gate can ignore the very commitment it is about
     * to create or move).
     *
     * @param string $start Y-m-d inclusive
     * @param string $end   Y-m-d inclusive
     */
    public function occupiedCount(
        int $libroId,
        string $start,
        string $end,
        ?int $excludePrestitoId = null,
        ?int $excludeReservationId = null,
        ?int $excludeUserId = null,
        ?int $excludeReservationsAfterQueuePos = null
    ): int {
        $intervals = array_merge(
            $this->holdingLoanIntervals($libroId, $start, $end, $excludePrestitoId, $excludeUserId),
            $this->activeReservationIntervals($libroId, $start, $end, $excludeReservationId, $excludeUserId, $excludeReservationsAfterQueuePos)
        );
        return $this->sweepPeak($intervals);
    }

    /**
     * True iff OCC(b,[s,e]) < totalCopies(b) for every day in [s,e].
     *
     * @phpstan-impure Reads live prestiti/prenotazioni/copie state: two identical
     * calls can legitimately differ (e.g. a pre-lock check vs a post-lock recheck
     * inside a transaction), so the result must never be treated as memoisable.
     */
    public function hasFreeCapacity(
        int $libroId,
        string $start,
        string $end,
        ?int $excludePrestitoId = null,
        ?int $excludeReservationId = null,
        ?int $excludeUserId = null,
        ?int $excludeReservationsAfterQueuePos = null
    ): bool {
        $total = $this->totalCopies($libroId);
        if ($total <= 0) {
            return false;
        }
        $occ = $this->occupiedCount($libroId, $start, $end, $excludePrestitoId, $excludeReservationId, $excludeUserId, $excludeReservationsAfterQueuePos);
        return $occ < $total;
    }

    /**
     * Earliest day on or after $from with at least one free capacity unit.
     *
     * Uses the same canonical HOLDING/reservation intervals as every write
     * gate, then sweeps only their boundary events. Open-ended overdue loans
     * are clamped to the horizon without a release event, so no guessed return
     * date is exposed while a physical copy is still out.
     */
    public function firstAvailableDate(int $libroId, string $from): ?string
    {
        try {
            $fromDate = new \DateTimeImmutable($from);
        } catch (\Throwable) {
            return null;
        }
        if ($fromDate->format('Y-m-d') !== $from) {
            return null;
        }

        $total = $this->totalCopies($libroId);
        if ($total <= 0) {
            return null;
        }

        // Keep one day available for finite end+1 events. Any interval clamped
        // to this horizon is effectively open-ended for application purposes.
        $horizon = '9999-12-30';
        $intervals = array_merge(
            $this->holdingLoanIntervals($libroId, $from, $horizon, null, null),
            $this->activeReservationIntervals($libroId, $from, $horizon, null, null)
        );
        if ($intervals === []) {
            return $from;
        }

        /** @var array<string, int> $events */
        $events = [];
        foreach ($intervals as [$start, $end]) {
            $events[$start] = ($events[$start] ?? 0) + 1;
            if ($end < $horizon) {
                $release = (new \DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
                $events[$release] = ($events[$release] ?? 0) - 1;
            }
        }
        ksort($events, SORT_STRING);

        $occupied = 0;
        $cursor = $from;
        foreach ($events as $date => $delta) {
            // No boundary changes between cursor and date: if capacity was
            // already free, cursor is the earliest possible answer.
            if ($date > $cursor && $occupied < $total) {
                return $cursor;
            }
            $occupied += $delta;
            if ($date >= $from && $occupied < $total) {
                return $date;
            }
            $cursor = $date;
        }

        // Remaining occupancy is made exclusively of horizon-clamped,
        // open-ended commitments; their physical return date is unknown.
        return null;
    }

    /**
     * Every day in [$start,$end] that has NO free capacity, computed from a
     * SINGLE load of the loan/reservation intervals plus an in-memory per-day
     * count — instead of one hasFreeCapacity() call (and its queries) per day.
     * Used to enrich a rejection payload after a whole-range check already
     * failed, so a 90-day request no longer fires ~360 extra queries.
     *
     * @return list<string> Y-m-d dates lacking capacity, ascending
     */
    public function unavailableDatesInRange(int $libroId, string $start, string $end, ?int $excludeUserId = null): array
    {
        $days = $this->enumerateDays($start, $end);
        if ($days === []) {
            return [];
        }
        $total = $this->totalCopies($libroId);
        if ($total <= 0) {
            return $days; // nothing lendable → every requested day conflicts
        }

        $intervals = array_merge(
            $this->holdingLoanIntervals($libroId, $start, $end, null, $excludeUserId),
            $this->activeReservationIntervals($libroId, $start, $end, null, $excludeUserId)
        );

        $conflicts = [];
        foreach ($days as $day) {
            $count = 0;
            foreach ($intervals as [$s, $e]) {
                if ($s <= $day && $day <= $e) {
                    $count++;
                }
            }
            if ($count >= $total) {
                $conflicts[] = $day;
            }
        }
        return $conflicts;
    }

    /**
     * @return list<string> Y-m-d days from $start to $end inclusive.
     */
    private function enumerateDays(string $start, string $end): array
    {
        try {
            $cur = new \DateTimeImmutable($start);
            $stop = new \DateTimeImmutable($end);
        } catch (\Throwable) {
            return [];
        }
        if ($cur > $stop) {
            return [];
        }
        $days = [];
        $oneDay = new \DateInterval('P1D');
        while ($cur <= $stop) {
            $days[] = $cur->format('Y-m-d');
            $cur = $cur->add($oneDay);
        }
        return $days;
    }

    /**
     * HOLDING loan intervals overlapping [$start,$end], clamped to the window.
     * @return list<array{0:string,1:string}>
     */
    private function holdingLoanIntervals(int $libroId, string $start, string $end, ?int $excludePrestitoId, ?int $excludeUserId): array
    {
        // An unreturned overdue loan has no known end yet. Its contractual due
        // date is in the past, but the physical copy remains out of the library:
        // clamp it to the requested window end instead of freeing capacity after
        // data_scadenza. This mirrors the DB trigger and the public calendar.
        // #366 residual: a loan overdue BY DATE but not yet flipped by the
        // maintenance sweep ('in_corso' with data_scadenza < today) is the very
        // same unreturned copy — treat it exactly like 'in_ritardo' (one branch,
        // one clamp, never both on the same row: the OR selects a single case).
        $today = \App\Support\DateHelper::today();
        $openEnded = "(p.attivo = 1 AND (p.stato = 'in_ritardo' OR (p.stato = 'in_corso' AND p.data_scadenza < ?)))";
        $sql = "SELECT GREATEST(p.data_prestito, ?) AS s,
                       LEAST(CASE
                           WHEN {$openEnded} THEN ?
                           ELSE p.data_scadenza
                       END, ?) AS e
                FROM prestiti p
                WHERE p.libro_id = ?
                  AND p.data_prestito <= ?
                  AND ({$openEnded} OR p.data_scadenza >= ?)
                  AND ( (p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                        OR (p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL) )";
        $types = 'ssssisss';
        $params = [$start, $today, $end, $end, $libroId, $end, $today, $start];
        if ($excludePrestitoId !== null) {
            $sql .= ' AND p.id <> ?';
            $types .= 'i';
            $params[] = $excludePrestitoId;
        }
        if ($excludeUserId !== null) {
            $sql .= ' AND p.utente_id <> ?';
            $types .= 'i';
            $params[] = $excludeUserId;
        }
        return $this->fetchIntervals($sql, $types, $params);
    }

    /**
     * Active reservation intervals overlapping [$start,$end], clamped to the window.
     * @return list<array{0:string,1:string}>
     */
    private function activeReservationIntervals(int $libroId, string $start, string $end, ?int $excludeReservationId, ?int $excludeUserId, ?int $excludeReservationsAfterQueuePos = null): array
    {
        // Canonical 3-step coalesce chain for the reservation end (no 2-step variants).
        $rEnd = 'COALESCE(r.data_fine_richiesta, DATE(r.data_scadenza_prenotazione), r.data_inizio_richiesta)';
        // Start falls back to the reservation deadline for legacy 'attiva' rows whose
        // data_inizio_richiesta is NULL (nullable column). For normal rows the COALESCE
        // returns data_inizio_richiesta unchanged, so this is behaviour-preserving; it
        // only stops such legacy holds from silently vanishing from the occupancy peak
        // (they never promote either, so they'd otherwise allow overbooking their copy).
        $rStart = 'COALESCE(r.data_inizio_richiesta, DATE(r.data_scadenza_prenotazione))';
        // A reservation belonging to a currently ineligible patron is paused,
        // not allowed to consume scarce capacity and starve eligible readers.
        // The same applies while the patron is already at the configured active
        // loan limit. The reservation stays active and regains its FIFO priority
        // automatically once the account becomes eligible again.
        $today = \App\Support\DateHelper::today();
        $maxLoans = $this->maxActiveLoansPerUser();
        $sql = "SELECT GREATEST($rStart, ?) AS s, LEAST($rEnd, ?) AS e
                FROM prenotazioni r
                JOIN utenti u ON u.id = r.utente_id
                WHERE r.libro_id = ?
                  AND r.stato = 'attiva'
                  AND " . \App\Support\LoanEligibility::eligibleUserWhere('u') . "
                  AND $rStart IS NOT NULL
                  AND $rStart <= ?
                  AND $rEnd >= ?";
        $types = 'ssisss';
        $params = [$start, $end, $libroId, $today, $end, $start];
        if ($maxLoans > 0) {
            // F3: il cap va valutato sulla FINESTRA della prenotazione, non su
            // "adesso". Un utente oggi al limite con prestiti che scadono prima
            // di [R_START, R_END] sarà di nuovo sotto-cap quando la finestra
            // inizia: la sua prenotazione deve OCCUPARE capacità, altrimenti il
            // gate lascia passare un nuovo impegno che si sovrappone alla sua
            // promessa (overbooking). Contiamo quindi solo gli impegni che si
            // sovrappongono alla finestra prenotata; in_ritardo e in_corso già
            // scaduti per data sono open-ended (la copia è ancora fuori) e
            // sovrappongono qualunque finestra futura. Il gate di PROMOZIONE
            // (ReservationManager) resta NOW-based: lì la finestra è già iniziata.
            $sql .= " AND (
                        SELECT COUNT(*)
                        FROM prestiti cap
                        WHERE cap.utente_id = r.utente_id
                          AND cap.attivo = 1
                          AND cap.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')
                          AND cap.data_prestito <= $rEnd
                          AND (cap.stato = 'in_ritardo'
                               OR (cap.stato = 'in_corso' AND cap.data_scadenza < ?)
                               OR cap.data_scadenza >= $rStart)
                      ) < ?";
            $types .= 'si';
            $params[] = $today;
            $params[] = $maxLoans;
        }
        if ($excludeReservationId !== null) {
            $sql .= ' AND r.id <> ?';
            $types .= 'i';
            $params[] = $excludeReservationId;
        }
        if ($excludeUserId !== null) {
            $sql .= ' AND r.utente_id <> ?';
            $types .= 'i';
            $params[] = $excludeUserId;
        }
        // Promotion gate (#157): when promoting the queue head, the waitlist
        // entries BEHIND it must not occupy capacity — they are lower-priority
        // and are promoted in later runs as copies free up. Exclude reservations
        // with a known queue_position strictly greater than the promoted one.
        // NULL queue_position rows still count (conservative — never overbook).
        if ($excludeReservationsAfterQueuePos !== null) {
            $sql .= ' AND NOT (r.queue_position IS NOT NULL AND r.queue_position > ?)';
            $types .= 'i';
            $params[] = $excludeReservationsAfterQueuePos;
        }
        return $this->fetchIntervals($sql, $types, $params);
    }

    private function maxActiveLoansPerUser(): int
    {
        if ($this->maxActiveLoansPerUser === null) {
            $value = (new \App\Models\SettingsRepository($this->db))
                ->get('loans', 'max_active_loans_per_user', '0');
            $this->maxActiveLoansPerUser = max(0, (int) ($value ?? 0));
        }
        return $this->maxActiveLoansPerUser;
    }

    /**
     * @param string $types
     * @param list<int|string> $params
     * @return list<array{0:string,1:string}>
     */
    private function fetchIntervals(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $refs = [$types];
        foreach ($params as $k => $v) {
            $refs[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $s = (string) $row['s'];
            $e = (string) $row['e'];
            if ($s !== '' && $e !== '' && $s <= $e) {
                $out[] = [$s, $e];
            }
        }
        $stmt->close();
        return $out;
    }

    /**
     * Sweep-line peak: the maximum number of intervals overlapping on any single
     * day. Inclusive bounds → the end event fires on (end + 1 day). Returns 0 for
     * an empty set.
     *
     * @param list<array{0:string,1:string}> $intervals each [startYmd, endYmd]
     */
    private function sweepPeak(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }
        /** @var list<array{0:string,1:int}> $events */
        $events = [];
        foreach ($intervals as [$s, $e]) {
            $events[] = [$s, 1];
            $events[] = [$this->nextDay($e), -1];
        }
        // Sort by day. At the same coordinate, process the end event (-1) BEFORE
        // the start event (+1): with half-open [start, end+1) intervals, an
        // interval ending and another starting on the same day do NOT overlap
        // (adjacent ranges [1..10] and [11..20] peak at 1, not 2).
        usort($events, static function (array $a, array $b): int {
            if ($a[0] === $b[0]) {
                return $a[1] <=> $b[1]; // -1 (end) before +1 (start)
            }
            return $a[0] <=> $b[0];
        });
        $running = 0;
        $peak = 0;
        foreach ($events as [, $delta]) {
            $running += $delta;
            if ($running > $peak) {
                $peak = $running;
            }
        }
        return $peak;
    }

    /** Y-m-d of the day after $ymd (string math via DateTime, no TZ ambiguity). */
    private function nextDay(string $ymd): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $ymd);
        if ($dt === false) {
            return $ymd;
        }
        $dt->modify('+1 day');
        return $dt->format('Y-m-d');
    }
}
