<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Repositories\RecensioniRepository;

class RecensioniAdminController
{
    /**
     * Pagina principale gestione recensioni
     */
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        // Verifica autenticazione admin
        if (empty($_SESSION['user']) || !in_array($_SESSION['user']['tipo_utente'], ['admin', 'staff'])) {
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        $repository = new RecensioniRepository($db);

        // Ottieni recensioni per stato
        $pendingReviews = $repository->getAllReviews('pendente');
        $approvedReviews = $repository->getAllReviews('approvata');
        $rejectedReviews = $repository->getAllReviews('rifiutata');
        $pendingCount = count($pendingReviews);

        // Render view
        $title = 'Recensioni';

        ob_start();
        include __DIR__ . '/../../Views/admin/recensioni/index.php';
        $content = ob_get_clean();

        ob_start();
        include __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Approva una recensione
     */
    public function approve(Request $request, Response $response, mysqli $db, array $args): Response
    {
        // Verifica autenticazione admin
        if (empty($_SESSION['user']) || !in_array($_SESSION['user']['tipo_utente'], ['admin', 'staff'])) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Non autorizzato']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        $reviewId = (int)($args['id'] ?? 0);
        $adminId = (int)$_SESSION['user']['id'];

        if ($reviewId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'ID recensione non valido']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $repository = new RecensioniRepository($db);
        $success = $repository->approveReview($reviewId, $adminId);

        if ($success) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Recensione approvata con successo'
            ]));
        } else {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Errore nell\'approvazione della recensione'
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Rifiuta una recensione
     */
    public function reject(Request $request, Response $response, mysqli $db, array $args): Response
    {
        // Verifica autenticazione admin
        if (empty($_SESSION['user']) || !in_array($_SESSION['user']['tipo_utente'], ['admin', 'staff'])) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Non autorizzato']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        $reviewId = (int)($args['id'] ?? 0);
        $adminId = (int)$_SESSION['user']['id'];

        if ($reviewId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'ID recensione non valido']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $repository = new RecensioniRepository($db);
        $success = $repository->rejectReview($reviewId, $adminId);

        if ($success) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Recensione rifiutata'
            ]));
        } else {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Errore nel rifiutare la recensione'
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Elimina una recensione
     */
    public function delete(Request $request, Response $response, mysqli $db, array $args): Response
    {
        // Verifica autenticazione admin
        if (empty($_SESSION['user']) || !in_array($_SESSION['user']['tipo_utente'], ['admin', 'staff'])) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Non autorizzato']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        $reviewId = (int)($args['id'] ?? 0);

        if ($reviewId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'ID recensione non valido']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $repository = new RecensioniRepository($db);
        $success = $repository->deleteReview($reviewId);

        if ($success) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Recensione eliminata con successo'
            ]));
        } else {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Errore nell\'eliminazione della recensione'
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }
}
