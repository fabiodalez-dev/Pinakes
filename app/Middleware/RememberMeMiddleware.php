<?php
declare(strict_types=1);

namespace App\Middleware;

use mysqli;
use App\Support\RememberMeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware to auto-login users via "Remember Me" token.
 *
 * This middleware should run early in the stack, before AuthMiddleware.
 * It checks for a valid remember token cookie and populates $_SESSION['user']
 * if the token is valid.
 */
class RememberMeMiddleware implements MiddlewareInterface
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Only attempt auto-login if user is not already logged in
        if (!isset($_SESSION['user'])) {
            $this->attemptAutoLogin();
        }

        return $handler->handle($request);
    }

    /**
     * Attempt to auto-login user via remember token.
     */
    private function attemptAutoLogin(): void
    {
        $rememberMeService = new RememberMeService($this->db);
        $userId = $rememberMeService->validateToken();

        if ($userId === null) {
            return;
        }

        // Fetch user data from database
        $stmt = $this->db->prepare("
            SELECT id, email, tipo_utente, email_verificata, stato, nome, cognome
            FROM utenti
            WHERE id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return;
        }

        // Verify user is still active and verified
        if (((int) ($row['email_verificata'] ?? 0)) !== 1) {
            return;
        }
        if (($row['stato'] ?? '') !== 'attivo') {
            return;
        }

        // Regenerate session ID for security
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Populate session with user data
        $_SESSION['user'] = [
            'id' => $row['id'],
            'email' => $row['email'],
            'tipo_utente' => $row['tipo_utente'],
            'name' => trim(\App\Support\HtmlHelper::decode((string) ($row['nome'] ?? '')) . ' ' . \App\Support\HtmlHelper::decode((string) ($row['cognome'] ?? ''))),
        ];

        // Log auto-login for security auditing
        \App\Support\Log::security('login.remember_me', [
            'user_id' => $row['id'],
            'email' => $row['email'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }
}
