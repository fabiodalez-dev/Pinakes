<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserDashboardController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $stats = [
            'libri' => 0,
            'prestiti_in_corso' => 0,
            'preferiti' => 0,
            'storico_prestiti' => 0
        ];
        
        $ultimiArrivi = [];
        $prestitiAttivi = [];

        try {
            // Get user stats
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            
            // Count total books
            $res = $db->query("SELECT COUNT(*) AS c FROM libri WHERE deleted_at IS NULL");
            $stats['libri'] = (int)($res->fetch_assoc()['c'] ?? 0);
            
            // Count user active loans
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM prestiti WHERE utente_id = ? AND attivo = 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $stats['prestiti_in_corso'] = (int)($res->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            
            // Count user favorites
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM wishlist WHERE utente_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $stats['preferiti'] = (int)($res->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            
            // Count user loan history
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM prestiti WHERE utente_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $stats['storico_prestiti'] = (int)($res->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            
            // Get recently added books (last 5)
            $stmt = $db->prepare("
                SELECT l.id, l.titolo, l.copertina_url,
                       (SELECT GROUP_CONCAT(a.nome SEPARATOR ', ')
                        FROM libri_autori la
                        JOIN autori a ON la.autore_id = a.id
                        WHERE la.libro_id = l.id
                        ORDER BY la.ruolo = 'principale' DESC, a.nome
                        LIMIT 3) AS autore,
                       l.copie_disponibili
                FROM libri l
                WHERE l.deleted_at IS NULL
                ORDER BY l.created_at DESC
                LIMIT 5
            ");
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ultimiArrivi[] = $row;
            }
            $stmt->close();
            
            // Get user active loans with book titles
            $stmt = $db->prepare("
                SELECT p.id, p.libro_id, p.data_scadenza,
                       l.titolo AS titolo_libro, l.copertina_url
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                WHERE p.utente_id = ? AND p.attivo = 1 AND l.deleted_at IS NULL
                ORDER BY p.data_scadenza ASC
                LIMIT 5
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $prestitiAttivi[] = $row;
            }
            $stmt->close();
            
        } catch (\Exception $e) {
            error_log('UserDashboard error: ' . $e->getMessage());
        }

        // Render view
        ob_start();
        require __DIR__ . '/../Views/user_dashboard/index.php';
        $content = ob_get_clean();

        // Use frontend layout
        ob_start();
        $title = 'Dashboard - Biblioteca';
        require __DIR__ . '/../Views/frontend/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function prenotazioni(Request $request, Response $response, mysqli $db, mixed $container = null): Response
    {
        // Verifica autenticazione
        if (empty($_SESSION['user']['id'])) {
            return $response
                ->withHeader('Location', \App\Support\RouteTranslator::route('login'))
                ->withStatus(302);
        }

        $userId = (int)$_SESSION['user']['id'];
        $pendingRequests = [];
        $activePrestiti = [];
        $items = [];
        $pastPrestiti = [];
        $myReviews = [];

        try {
            // Loan requests waiting for approval
            $stmt = $db->prepare("
                SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_scadenza, pr.stato, pr.created_at,
                       l.titolo, l.copertina_url
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.stato = 'pendente' AND l.deleted_at IS NULL
                ORDER BY pr.created_at DESC
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $pendingRequests[] = $row;
            }
            $stmt->close();

            // Active loans
            $stmt = $db->prepare("
                SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_scadenza, pr.stato,
                       l.titolo, l.copertina_url,
                       EXISTS(SELECT 1 FROM recensioni r WHERE r.libro_id = pr.libro_id AND r.utente_id = ?) AS has_review
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.attivo = 1 AND pr.stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato') AND l.deleted_at IS NULL
                ORDER BY pr.data_scadenza ASC
            ");
            $stmt->bind_param('ii', $userId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $activePrestiti[] = $row;
            }
            $stmt->close();

            // Active reservations
            $stmt = $db->prepare("
                SELECT p.id, p.libro_id, p.data_prenotazione, p.data_scadenza_prenotazione, p.queue_position, p.stato,
                       l.titolo, l.copertina_url
                FROM prenotazioni p
                JOIN libri l ON l.id = p.libro_id
                WHERE p.utente_id = ? AND p.stato = 'attiva' AND l.deleted_at IS NULL
                ORDER BY p.data_prenotazione DESC
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
            $stmt->close();

            // Past loans (completed)
            $stmt = $db->prepare("
                SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_restituzione, pr.stato,
                       l.titolo, l.copertina_url,
                       EXISTS(SELECT 1 FROM recensioni r WHERE r.libro_id = pr.libro_id AND r.utente_id = ?) AS has_review
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.attivo = 0 AND pr.stato != 'prestato' AND l.deleted_at IS NULL
                ORDER BY pr.data_restituzione DESC, pr.data_prestito DESC
                LIMIT 50
            ");
            $stmt->bind_param('ii', $userId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $pastPrestiti[] = $row;
            }
            $stmt->close();

            // User reviews
            $stmt = $db->prepare("
                SELECT r.*, l.titolo AS libro_titolo, l.copertina_url AS libro_copertina
                FROM recensioni r
                JOIN libri l ON l.id = r.libro_id
                WHERE r.utente_id = ? AND l.deleted_at IS NULL
                ORDER BY r.created_at DESC
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $myReviews[] = $row;
            }
            $stmt->close();

        } catch (\Exception $e) {
            error_log('Prenotazioni error: ' . $e->getMessage());
        }

        // Render view
        ob_start();
        $title = 'Le Mie Prenotazioni - Biblioteca';
        require __DIR__ . '/../Views/user_dashboard/prenotazioni.php';
        $content = ob_get_clean();

        // Use frontend layout
        ob_start();
        require __DIR__ . '/../Views/frontend/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
