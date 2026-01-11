<?php

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StatsController
{
    public function index(Request $request, Response $response, \mysqli $db): Response
    {
        // Top 10 most borrowed books
        // IMPORTANT: Use consistent definition of "active loan": attivo = 1 AND stato IN ('in_corso', 'in_ritardo')
        $topBooksQuery = "
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
        ";
        $topBooksResult = $db->query($topBooksQuery);
        $topBooks = [];
        while ($row = $topBooksResult->fetch_assoc()) {
            $topBooks[] = $row;
        }

        // Top 10 most active readers
        // IMPORTANT: Use consistent definition of "active loan": attivo = 1 AND stato IN ('in_corso', 'in_ritardo')
        $topReadersQuery = "
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
        ";
        $topReadersResult = $db->query($topReadersQuery);
        $topReaders = [];
        while ($row = $topReadersResult->fetch_assoc()) {
            $topReaders[] = $row;
        }

        // Loans by month (last 12 months)
        $loansByMonthQuery = "
            SELECT
                DATE_FORMAT(data_prestito, '%Y-%m') as mese,
                DATE_FORMAT(data_prestito, '%M %Y') as mese_label,
                COUNT(*) as totale_prestiti
            FROM prestiti
            WHERE data_prestito >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(data_prestito, '%Y-%m'), DATE_FORMAT(data_prestito, '%M %Y')
            ORDER BY mese ASC
        ";
        $loansByMonthResult = $db->query($loansByMonthQuery);
        $loansByMonth = [];
        while ($row = $loansByMonthResult->fetch_assoc()) {
            $loansByMonth[] = $row;
        }

        // Loans by status (only active loans)
        // Shows breakdown of current active loans by their status
        $loansByStatusQuery = "
            SELECT
                stato,
                COUNT(*) as totale
            FROM prestiti
            WHERE attivo = 1 AND stato IN ('in_corso', 'in_ritardo', 'pendente')
            GROUP BY stato
        ";
        $loansByStatusResult = $db->query($loansByStatusQuery);
        $loansByStatus = [];
        while ($row = $loansByStatusResult->fetch_assoc()) {
            $loansByStatus[] = $row;
        }

        // Overall stats
        // IMPORTANT: Use consistent definition of "active loan" across all queries:
        // - attivo = 1
        // - stato IN ('in_corso', 'in_ritardo')
        $statsQuery = "
            SELECT
                (SELECT COUNT(*) FROM libri WHERE stato = 'disponibile' AND deleted_at IS NULL) as libri_disponibili,
                (SELECT COUNT(*) FROM libri WHERE stato = 'prestato' AND deleted_at IS NULL) as libri_prestati,
                (SELECT COUNT(*) FROM prestiti WHERE attivo = 1 AND stato IN ('in_corso', 'in_ritardo')) as prestiti_attivi,
                (SELECT COUNT(*) FROM prestiti WHERE attivo = 1 AND stato = 'in_ritardo') as prestiti_in_ritardo,
                (SELECT COUNT(*) FROM prestiti WHERE stato = 'restituito') as prestiti_completati,
                (SELECT COUNT(DISTINCT utente_id) FROM prestiti) as utenti_con_prestiti
        ";
        $statsResult = $db->query($statsQuery);
        $stats = $statsResult->fetch_assoc();

        $title = 'Statistiche Prestiti';

        ob_start();
        include __DIR__ . '/../../Views/admin/stats.php';
        $content = ob_get_clean();

        ob_start();
        include __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
