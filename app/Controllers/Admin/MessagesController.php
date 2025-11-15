<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MessagesController
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get all contact messages
     */
    public function getAll(Request $request, Response $response): Response
    {
        $messages = [];

        $result = $this->db->query("
            SELECT id, nome, cognome, email, telefono, indirizzo, messaggio,
                   privacy_accepted, ip_address, is_read, is_archived, created_at, read_at
            FROM contact_messages
            ORDER BY is_read ASC, created_at DESC
        ");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $messages[] = $row;
            }
            $result->free();
        }

        $response->getBody()->write(json_encode($messages));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get single message
     */
    public function getOne(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        $stmt = $this->db->prepare("
            SELECT id, nome, cognome, email, telefono, indirizzo, messaggio,
                   privacy_accepted, ip_address, user_agent, is_read, is_archived,
                   created_at, read_at
            FROM contact_messages
            WHERE id = ?
        ");

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $message = $result->fetch_assoc();
        $stmt->close();

        if (!$message) {
            $response->getBody()->write(json_encode(['error' => __('Messaggio non trovato.')]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Mark as read
        $updateStmt = $this->db->prepare("UPDATE contact_messages SET is_read = 1, read_at = NOW() WHERE id = ? AND is_read = 0");
        $updateStmt->bind_param('i', $id);
        $updateStmt->execute();
        $updateStmt->close();

        $response->getBody()->write(json_encode($message));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Delete message
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['error' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $id = (int)($args['id'] ?? 0);

        $stmt = $this->db->prepare("DELETE FROM contact_messages WHERE id = ?");
        $stmt->bind_param('i', $id);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => __('Impossibile eliminare il messaggio.')]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Archive message
     */
    public function archive(Request $request, Response $response, array $args): Response
    {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['error' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $id = (int)($args['id'] ?? 0);

        $stmt = $this->db->prepare("UPDATE contact_messages SET is_archived = 1 WHERE id = ?");
        $stmt->bind_param('i', $id);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => __('Impossibile archiviare il messaggio.')]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Mark all as read
     */
    public function markAllRead(Request $request, Response $response): Response
    {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['error' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $success = $this->db->query("UPDATE contact_messages SET is_read = 1, read_at = NOW() WHERE is_read = 0");

        if ($success) {
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => __('Impossibile segnare tutti i messaggi come letti.')]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}
