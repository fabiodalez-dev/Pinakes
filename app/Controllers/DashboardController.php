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
        $stats = ['libri'=>0,'utenti'=>0,'prestiti_in_corso'=>0,'autori'=>0,'prestiti_pendenti'=>0,'pickup_pronti'=>0];
        $lastBooks = $active = $overdue = $pending = $pickupLoans = $scheduledLoans = $reservations = $calendarEvents = [];
        $activityFeed = ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        $activityOperators = [];
        $activityFilters = ['activity_type' => '', 'activity_operator' => 0, 'activity_q' => ''];

        try {
            $repo = new \App\Models\DashboardStats($db);
            $stats = $repo->counts();
            $lastBooks = $repo->lastBooks();
            $active = $repo->activeLoans();
            $overdue = $repo->overdueLoans();
            $pending = $repo->pendingLoans(6);
            $pickupLoans = $repo->pickupReadyLoans(6);
            $scheduledLoans = $repo->scheduledLoans(6);
            $reservations = $repo->activeReservations(6);
            $calendarEvents = $repo->calendarEvents();
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('Dashboard data loading failed: ' . $e->getMessage());
            $_SESSION['error_message'] = __('Alcuni dati della dashboard non sono disponibili. Verifica la connessione al database.');
        }

        // The dashboard route is shared with patrons, but issue #374 defines
        // the activity feed as administrative data. Keep both the query and the
        // rendered section restricted to admin/staff.
        $currentRole = (string) ($_SESSION['user']['tipo_utente'] ?? '');
        $isAdminOrStaff = in_array($currentRole, ['admin', 'staff'], true);
        if ($isAdminOrStaff) {
            $query = $request->getQueryParams();
            $rawType = $query['activity_type'] ?? '';
            $activityType = is_string($rawType) && in_array($rawType, \App\Support\ActivityLog::TYPES, true)
                ? $rawType
                : '';

            $rawOperator = $query['activity_operator'] ?? '';
            $activityOperator = is_scalar($rawOperator)
                && preg_match('/^[1-9]\d*$/D', (string) $rawOperator) === 1
                ? (int) $rawOperator
                : 0;

            $rawPage = $query['activity_page'] ?? 1;
            $activityPage = is_scalar($rawPage)
                && preg_match('/^[1-9]\d*$/D', (string) $rawPage) === 1
                ? (int) $rawPage
                : 1;

            $rawQ = $query['activity_q'] ?? '';
            $activityQ = is_string($rawQ) ? mb_substr(trim($rawQ), 0, 100) : '';

            $activityFilters = [
                'activity_type' => $activityType,
                'activity_operator' => $activityOperator,
                'activity_q' => $activityQ,
            ];
            $activityFeed = \App\Support\ActivityLog::recent(
                $db,
                $activityPage,
                12,
                $activityType !== '' ? $activityType : null,
                $activityOperator > 0 ? $activityOperator : null,
                $activityQ !== '' ? $activityQ : null
            );
            $activityOperators = \App\Support\ActivityLog::operators($db);
        }

        // ICS file URL (dynamic generation)
        $icsUrl = url('/calendar/events.ics');

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
