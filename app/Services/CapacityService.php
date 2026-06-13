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
 *     where the reservation interval end is
 *       R_END(r) := COALESCE(r.data_fine_richiesta, DATE(r.data_scadenza_prenotazione), r.data_inizio_richiesta)
 * Inclusive overlap: start_a <= end_b AND end_a >= start_b.
 *
 * Free capacity for [s,e] iff OCC(b,[s,e]) < copie_totali(b) for EVERY day in [s,e].
 * copie_totali(b) = COUNT of copie rows whose stato is lendable
 *                   (NOT IN perso, danneggiato, manutenzione, in_restauro, in_trasferimento).
 *
 * THE DECISION: a prenotazioni row (stato='attiva') with period
 * [data_inizio_richiesta, R_END] occupies exactly one capacity unit for that
 * period, counted in OCC up to copie_totali. It is *soft* (gates capacity,
 * blocks new commitments) but does not pin a physical copy. The bare prestiti
 * request (stato='pendente', copia_id IS NULL) is unbounded and does NOT occupy.
 */
final class CapacityService
{
    public function __construct(private mysqli $db) {}

    /** Lendable physical copies of a book (the capacity ceiling). */
    public function totalCopies(int $libroId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM copie
             WHERE libro_id = ?
               AND stato NOT IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento')"
        );
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return (int) $n;
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
        ?int $excludeUserId = null
    ): int {
        $intervals = array_merge(
            $this->holdingLoanIntervals($libroId, $start, $end, $excludePrestitoId, $excludeUserId),
            $this->activeReservationIntervals($libroId, $start, $end, $excludeReservationId, $excludeUserId)
        );
        return $this->sweepPeak($intervals);
    }

    /** True iff OCC(b,[s,e]) < totalCopies(b) for every day in [s,e]. */
    public function hasFreeCapacity(
        int $libroId,
        string $start,
        string $end,
        ?int $excludePrestitoId = null,
        ?int $excludeReservationId = null,
        ?int $excludeUserId = null
    ): bool {
        $total = $this->totalCopies($libroId);
        if ($total <= 0) {
            return false;
        }
        $occ = $this->occupiedCount($libroId, $start, $end, $excludePrestitoId, $excludeReservationId, $excludeUserId);
        return $occ < $total;
    }

    /**
     * HOLDING loan intervals overlapping [$start,$end], clamped to the window.
     * @return list<array{0:string,1:string}>
     */
    private function holdingLoanIntervals(int $libroId, string $start, string $end, ?int $excludePrestitoId, ?int $excludeUserId): array
    {
        $sql = "SELECT GREATEST(p.data_prestito, ?) AS s, LEAST(p.data_scadenza, ?) AS e
                FROM prestiti p
                WHERE p.libro_id = ?
                  AND p.data_prestito <= ? AND p.data_scadenza >= ?
                  AND ( (p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                        OR (p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL) )";
        $types = 'ssiss';
        $params = [$start, $end, $libroId, $end, $start];
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
    private function activeReservationIntervals(int $libroId, string $start, string $end, ?int $excludeReservationId, ?int $excludeUserId): array
    {
        // Canonical 3-step coalesce chain for the reservation end (no 2-step variants).
        $rEnd = 'COALESCE(r.data_fine_richiesta, DATE(r.data_scadenza_prenotazione), r.data_inizio_richiesta)';
        $sql = "SELECT GREATEST(r.data_inizio_richiesta, ?) AS s, LEAST($rEnd, ?) AS e
                FROM prenotazioni r
                WHERE r.libro_id = ?
                  AND r.stato = 'attiva'
                  AND r.data_inizio_richiesta IS NOT NULL
                  AND r.data_inizio_richiesta <= ?
                  AND $rEnd >= ?";
        $types = 'ssiss';
        $params = [$start, $end, $libroId, $end, $start];
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
        return $this->fetchIntervals($sql, $types, $params);
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
