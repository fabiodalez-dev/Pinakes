<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $stats = ['libri'=>0,'utenti'=>0,'prestiti_in_corso'=>0,'autori'=>0,'prestiti_pendenti'=>0];
        $lastBooks = $active = $overdue = $pending = $reservations = $calendarEvents = [];

        try {
            $repo = new \App\Models\DashboardStats($db);
            $stats = $repo->counts();
            $lastBooks = $repo->lastBooks();
            $active = $repo->activeLoans();
            $overdue = $repo->overdueLoans();
            $pending = $repo->pendingLoans(6);
            $reservations = $repo->activeReservations(6);
            $calendarEvents = $repo->calendarEvents();
        } catch (\Exception $e) {
            // Handle error gracefully - use empty data
            error_log('Dashboard error: ' . $e->getMessage());
        }

        // ICS file URL (dynamic generation)
        $icsUrl = '/calendar/events.ics';

        ob_start();
        require __DIR__ . '/../Views/dashboard/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
