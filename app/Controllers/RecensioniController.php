<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Repositories\RecensioniRepository;
use App\Support\NotificationService;

class RecensioniController
{
    /**
     * Verifica se un utente può recensire un libro
     */
    public function canReview(Request $request, Response $response, mysqli $db, array $args): Response
    {
        // Verifica autenticazione
        if (empty($_SESSION['user']['id'])) {
            $response->getBody()->write(json_encode(['can_review' => false, 'reason' => 'not_authenticated']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }

        $userId = (int)$_SESSION['user']['id'];
        $libroId = (int)($args['libro_id'] ?? 0);

        if ($libroId <= 0) {
            $response->getBody()->write(json_encode(['can_review' => false, 'reason' => 'invalid_book_id']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        $repository = new RecensioniRepository($db);
        $canReview = $repository->canUserReview($userId, $libroId);

        $response->getBody()->write(json_encode([
            'can_review' => $canReview,
            'reason' => $canReview ? 'ok' : 'no_completed_loan_or_already_reviewed'
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Crea una nuova recensione
     */
    public function create(Request $request, Response $response, mysqli $db): Response
    {
        // Verifica autenticazione
        if (empty($_SESSION['user']['id'])) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Non autenticato')]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }

        $userId = (int)$_SESSION['user']['id'];

        // Parse JSON body
        $contentType = $request->getHeaderLine('Content-Type');

        if (strpos($contentType, 'application/json') !== false) {
            $bodyRaw = (string)$request->getBody();
            $body = json_decode($bodyRaw, true) ?? [];
        } else {
            $body = $request->getParsedBody() ?? [];
        }

        // Verifica CSRF token
        $csrfToken = $body['csrf_token'] ?? '';

        if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Token CSRF non valido')]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        // Validazione input
        $libroId = (int)($body['libro_id'] ?? 0);
        $stelle = (int)($body['stelle'] ?? 0);
        $titolo = trim($body['titolo'] ?? '');
        $descrizione = trim($body['descrizione'] ?? '');

        if ($libroId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID libro non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if ($stelle < 1 || $stelle > 5) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Valutazione non valida (1-5 stelle)')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if (strlen($titolo) > 255) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Titolo troppo lungo (max 255 caratteri)')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if (strlen($descrizione) > 2000) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Descrizione troppo lunga (max 2000 caratteri)')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $repository = new RecensioniRepository($db);

        // Verifica che l'utente possa recensire questo libro
        if (!$repository->canUserReview($userId, $libroId)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Non puoi recensire questo libro (devi averlo preso in prestito e non averlo già recensito)')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Crea la recensione
        try {
            $reviewId = $repository->createReview([
                'libro_id' => $libroId,
                'utente_id' => $userId,
                'stelle' => $stelle,
                'titolo' => $titolo,
                'descrizione' => $descrizione
            ]);

            if (!$reviewId) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => __('Errore nella creazione della recensione')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }

            // Invia notifica agli admin
            try {
                $notificationService = new NotificationService($db);
                $notificationService->notifyNewReview($reviewId);
            } catch (\Exception $e) {
                error_log("Error sending review notification: " . $e->getMessage());
                // Non blocca la risposta se la notifica fallisce
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Recensione inviata con successo! Sarà pubblicata dopo l\'approvazione di un amministratore.'),
                'review_id' => $reviewId
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            error_log("Error creating review: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore del server') . ': ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
