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
            SELECT id, email, tipo_utente, email_verificata, stato, nome, cognome, locale
            FROM utenti
            WHERE id = ? LIMIT 1
        ");
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return;
        }
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

        // Regenerate session ID for security. delete_old_session = FALSE on
        // purpose: with TRUE the old session file is destroyed immediately, so a
        // concurrent in-flight AJAX request or a sibling browser tab still
        // carrying the old ID is rejected by use_strict_mode and bounced to
        // login — the exact "logged out for no reason" failure the periodic
        // regeneration in public/index.php:259 already avoids the same way. The
        // fixation-critical regeneration with TRUE still happens at real login
        // (AuthController); auto-login is not a privilege-elevation boundary.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(false);
        }

        // Populate session with user data
        $_SESSION['user'] = [
            'id' => $row['id'],
            'email' => $row['email'],
            'tipo_utente' => $row['tipo_utente'],
            'name' => trim(\App\Support\HtmlHelper::decode((string) ($row['nome'] ?? '')) . ' ' . \App\Support\HtmlHelper::decode((string) ($row['cognome'] ?? ''))),
        ];

        // Seed a CSRF token for the freshly-authenticated session. Without this,
        // the new session created by this auto-login has no csrf_token, so the
        // first POST the admin makes is rejected and (before the #4 fix) shown
        // the misleading "session expired" screen even though they are logged in.
        \App\Support\Csrf::ensureToken();

        // Load and apply user's preferred locale (only persist if setLocale succeeds)
        if (!empty($row['locale'])) {
            $locale = (string) $row['locale'];
            // Ensure language cache is loaded (middleware may run before bootstrap i18n)
            \App\Support\I18n::loadFromDatabase($this->db);
            if (\App\Support\I18n::setLocale($locale)) {
                $_SESSION['locale'] = $locale;
            }
        }

        // Log auto-login for security auditing (no PII logged per GDPR)
        \App\Support\Log::security('login.remember_me', [
            'user_id' => $row['id'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }
}
