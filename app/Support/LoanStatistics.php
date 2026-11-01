<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * The loan figures of /admin/statistics (and of the app's GET
 * /api/v1/staff/statistics): overview counters, top books and readers, loans
 * per month and active loans per state.
 *
 * One definition of "active loan" everywhere: attivo = 1 AND stato IN
 * ('in_corso', 'in_ritardo'). Rows come back exactly as the queries return
 * them (strings from mysqli), so the web view renders what it always did.
 */
final class LoanStatistics
{
    private function __construct()
    {
    }

    /**
     * Top 10 most borrowed books (soft-deleted records excluded).
     *
     * @return list<array<string, mixed>>
     */
    public static function topBooks(mysqli $db): array
    {
        return self::rows($db, "
            SELECT
                l.id,
                l.titolo,
                l.copertina_url,
                COUNT(p.id) as prestiti_totali,
                COUNT(CASE WHEN p.stato = 'restituito' THEN 1 END) as prestiti_completati,
                COUNT(CASE WHEN p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo') THEN 1 END) as prestiti_attivi,
                COUNT(CASE WHEN p.attivo = 1 AND p.stato = 'in_ritardo' THEN 1 END) as prestiti_in_ritardo
            FROM libri l
            LEFT JOIN prestiti p ON l.id = p.libro_id
            WHERE l.deleted_at IS NULL
            GROUP BY l.id
            HAVING prestiti_totali > 0
            ORDER BY prestiti_totali DESC
            LIMIT 10
        ");
    }

    /**
     * Top 10 most active readers.
     *
     * @return list<array<string, mixed>>
     */
    public static function topReaders(mysqli $db): array
    {
        return self::rows($db, "
            SELECT
                u.id,
                u.nome,
                u.cognome,
                u.email,
                u.tipo_utente,
                COUNT(p.id) as prestiti_totali,
                COUNT(CASE WHEN p.stato = 'restituito' THEN 1 END) as prestiti_completati,
                COUNT(CASE WHEN p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo') THEN 1 END) as prestiti_attivi,
                COUNT(CASE WHEN p.attivo = 1 AND p.stato = 'in_ritardo' THEN 1 END) as prestiti_in_ritardo
            FROM utenti u
            LEFT JOIN prestiti p ON u.id = p.utente_id
            GROUP BY u.id
            HAVING prestiti_totali > 0
            ORDER BY prestiti_totali DESC
            LIMIT 10
        ");
    }

    /**
     * Loans per month over the last 12 months (mese = Y-m), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function loansByMonth(mysqli $db): array
    {
        return self::rows($db, "
            SELECT
                DATE_FORMAT(data_prestito, '%Y-%m') as mese,
                DATE_FORMAT(data_prestito, '%M %Y') as mese_label,
                COUNT(*) as totale_prestiti
            FROM prestiti
            WHERE data_prestito >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(data_prestito, '%Y-%m'), DATE_FORMAT(data_prestito, '%M %Y')
            ORDER BY mese ASC
        ");
    }

    /**
     * Current active loans by state (in_corso / in_ritardo).
     *
     * @return list<array<string, mixed>>
     */
    public static function loansByStatus(mysqli $db): array
    {
        return self::rows($db, "
            SELECT
                stato,
                COUNT(*) as totale
            FROM prestiti
            WHERE attivo = 1 AND stato IN ('in_corso', 'in_ritardo')
            GROUP BY stato
        ");
    }

    /**
     * The overview counters.
     *
     * @return array<string, mixed>|null
     */
    public static function overview(mysqli $db): ?array
    {
        $result = $db->query("
            SELECT
                (SELECT COUNT(*) FROM libri WHERE stato = 'disponibile' AND deleted_at IS NULL) as libri_disponibili,
                (SELECT COUNT(*) FROM libri WHERE stato = 'prestato' AND deleted_at IS NULL) as libri_prestati,
                (SELECT COUNT(*) FROM prestiti WHERE attivo = 1 AND stato IN ('in_corso', 'in_ritardo')) as prestiti_attivi,
                (SELECT COUNT(*) FROM prestiti WHERE attivo = 1 AND stato = 'in_ritardo') as prestiti_in_ritardo,
                (SELECT COUNT(*) FROM prestiti WHERE stato = 'restituito') as prestiti_completati,
                (SELECT COUNT(DISTINCT utente_id) FROM prestiti) as utenti_con_prestiti
        ");
        if (!$result instanceof \mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    /**
     * Fines report period from ?fines_from / ?fines_to (Y-m-d); default: the
     * last twelve months up to today, in the application timezone. A value
     * that is not a date falls back to its default; a reversed range is
     * swapped.
     *
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string}
     */
    public static function finesPeriod(array $query): array
    {
        $today = DateHelper::today();
        $to = is_string($query['fines_to'] ?? null) && DateHelper::isISODateFormat($query['fines_to']) ? $query['fines_to'] : $today;
        $defaultFrom = (new \DateTimeImmutable($to))->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
        $from = is_string($query['fines_from'] ?? null) && DateHelper::isISODateFormat($query['fines_from']) ? $query['fines_from'] : $defaultFrom;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mysqli $db, string $sql): array
    {
        $result = $db->query($sql);
        $rows = [];
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
