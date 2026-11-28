<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * "Is this title still in circulation?" — the guard that must pass before a
 * book is soft-deleted.
 *
 * The two queries state the same rule the web back office applies before a
 * single or a bulk deletion (App\Support\DataIntegrity::booksBlockingDeletion):
 * an approved loan that still holds (or will hold) a copy, a pending loan
 * with a copy set aside (a promoted reservation), or an active wait-list
 * entry. They are kept here, in the plugin, rather than calling the
 * core helper so the plugin keeps working against the core version it declares
 * in requires_app; the unit test tests/mobile-api-staff.unit.php pins them to
 * the core rule so the two cannot drift apart silently.
 */
final class BookCirculationCheck
{
    /** Loans that occupy (or are about to occupy) a copy: approved ones, and pending ones holding a copy. */
    public const ACTIVE_LOANS_SQL = "SELECT COUNT(*) AS count FROM prestiti WHERE libro_id = ?
        AND ((attivo = 1 AND stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato'))
             OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL))";

    /** Wait-list entries that are still waiting for a copy. */
    public const ACTIVE_RESERVATIONS_SQL = "SELECT COUNT(*) AS count FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'";

    private function __construct()
    {
    }

    /**
     * True when the title has an active loan or reservation. Fails closed: a
     * query that cannot run is treated as "in circulation" so a database hiccup
     * can never let a borrowed title be deleted.
     */
    public static function hasActiveCirculation(\mysqli $db, int $bookId): bool
    {
        foreach ([self::ACTIVE_LOANS_SQL, self::ACTIVE_RESERVATIONS_SQL] as $sql) {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return true;
            }
            $stmt->bind_param('i', $bookId);
            if (!$stmt->execute()) {
                $stmt->close();
                return true;
            }
            $result = $stmt->get_result();
            $row = $result !== false ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!is_array($row) || (int) ($row['count'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
