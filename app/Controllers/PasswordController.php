<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Csrf;
use App\Support\CsrfHelper;
use App\Support\Mailer;
use App\Support\EmailService;
use App\Support\RouteTranslator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PasswordController
{
    public function forgotForm(Request $request, Response $response): Response
    {
        $csrf_token = Csrf::ensureToken();
        ob_start();
        require __DIR__ . '/../Views/auth/forgot-password.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function forgot(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)($request->getParsedBody() ?? []);
        // Validate CSRF using helper

        if ($error = CsrfHelper::validateRequest($request, $response, RouteTranslator::route('forgot_password') . '?error=csrf')) {

            return $error;

        }
        $email = trim((string)($data['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $response->withHeader('Location', RouteTranslator::route('forgot_password') . '?error=invalid')->withStatus(302);
        }

        // Ensure MySQL and PHP use the same timezone (UTC)
        $db->query("SET SESSION time_zone = '+00:00'");

        // Rate limiting: max 3 requests per 15 minutes per email
        if (!$this->checkRateLimit($db, $email, 'forgot_password', 3, 15)) {
            return $response->withHeader('Location', RouteTranslator::route('forgot_password') . '?error=rate_limit')->withStatus(302);
        }

        $stmt = $db->prepare("SELECT id, nome, cognome, email_verificata FROM utenti WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            // Create secure token: 32 random bytes = 64 char hex string
            $resetToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $resetToken);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 2 * 60 * 60); // 2 hours in UTC (was 24 for better UX)
            $stmt->close();

            $stmt = $db->prepare("UPDATE utenti SET token_reset_password = ?, data_token_reset = ? WHERE id = ?");
            $stmt->bind_param('ssi', $tokenHash, $expiresAt, $row['id']);
            $stmt->execute();
            $stmt->close();

            // Use validated base URL to prevent Host Header Injection
            $baseUrl = $this->getValidatedBaseUrl();
            $resetUrl = $baseUrl . RouteTranslator::route('reset_password') . '?token=' . urlencode($resetToken);
            $name = trim((string)($row['nome'] ?? '') . ' ' . (string)($row['cognome'] ?? ''));
            $subject = 'Recupera la tua password';
            $html = '<h2>Recupera la tua password</h2>' .
                    '<p>Ciao ' . htmlspecialchars($name !== '' ? $name : $email, ENT_QUOTES, 'UTF-8') . ',</p>' .
                    '<p>Abbiamo ricevuto una richiesta di reset della password per il tuo account.</p>' .
                    '<p>Clicca sul pulsante qui sotto per resettare la tua password:</p>' .
                    '<p style="margin: 20px 0;"><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '" style="background-color: #111827; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;">Resetta Password</a></p>' .
                    '<p>Oppure copia e incolla questo link nel tuo browser:</p>' .
                    '<p><code style="background-color: #f3f4f6; padding: 10px; display: block; word-break: break-all;">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</code></p>' .
                    '<p><strong>Nota:</strong> Questo link scadrà tra 2 ore.</p>' .
                    '<p>Se non hai richiesto il reset della password, puoi ignorare questa email. Il tuo account rimane sicuro.</p>';

            // Use EmailService if available, fall back to Mailer
            try {
                $emailService = new EmailService($db);
                $emailService->sendEmail($email, $subject, $html, $name);
            } catch (\Exception $e) {
                error_log('EmailService failed, falling back to Mailer: ' . $e->getMessage());
                Mailer::send($email, $subject, $html);
            }
        } else {
            $stmt->close();
        }
        return $response->withHeader('Location', RouteTranslator::route('forgot_password') . '?sent=1')->withStatus(302);
    }

    public function resetForm(Request $request, Response $response, mysqli $db): Response
    {
        $params = $request->getQueryParams();
        $token = (string)($params['token'] ?? '');

        if (empty($token)) {
            return $response->withHeader('Location', RouteTranslator::route('forgot_password') . '?error=invalid_token')->withStatus(302);
        }

        // Ensure MySQL and PHP use the same timezone (UTC)
        $db->query("SET SESSION time_zone = '+00:00'");

        // Verify token exists and is not expired
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare("SELECT id FROM utenti WHERE token_reset_password = ? AND data_token_reset IS NOT NULL AND data_token_reset > NOW() LIMIT 1");
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return $response->withHeader('Location', RouteTranslator::route('forgot_password') . '?error=token_expired')->withStatus(302);
        }

        $csrf_token = Csrf::ensureToken();
        ob_start();
        require __DIR__ . '/../Views/auth/reset-password.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function reset(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)($request->getParsedBody() ?? []);
        // Validate CSRF using helper

        if ($error = CsrfHelper::validateRequest($request, $response, RouteTranslator::route('reset_password') . '?error=csrf')) {

            return $error;

        }
        $token = (string)($data['token'] ?? '');
        // Sanitize token to prevent HTTP response splitting
        $token = str_replace(["\r", "\n"], '', $token);
        $pwd1 = (string)($data['password'] ?? '');
        $pwd2 = (string)($data['password_confirm'] ?? '');
        if ($token === '' || $pwd1 === '' || $pwd1 !== $pwd2) {
            return $response->withHeader('Location', RouteTranslator::route('reset_password') . '?token='.urlencode($token).'&error=invalid')->withStatus(302);
        }

        // Validate password complexity before checking token
        if (strlen($pwd1) < 8) {
            return $response->withHeader('Location', RouteTranslator::route('reset_password') . '?token='.urlencode($token).'&error=password_too_short')->withStatus(302);
        }

        if (!preg_match('/[A-Z]/', $pwd1) || !preg_match('/[a-z]/', $pwd1) || !preg_match('/[0-9]/', $pwd1)) {
            return $response->withHeader('Location', RouteTranslator::route('reset_password') . '?token='.urlencode($token).'&error=password_needs_upper_lower_number')->withStatus(302);
        }

        // Ensure MySQL and PHP use the same timezone (UTC)
        $db->query("SET SESSION time_zone = '+00:00'");

        // Hash the token to look up in database (secure comparison)
        $tokenHash = hash('sha256', $token);

        // Check token: must exist, not be null, and not be expired
        $stmt = $db->prepare("SELECT id, data_token_reset FROM utenti WHERE token_reset_password = ? AND data_token_reset IS NOT NULL AND data_token_reset > NOW() LIMIT 1");
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $uid = (int)$row['id'];
            $stmt->close();

            // Hash password with bcrypt cost 12
            $hash = password_hash($pwd1, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare("UPDATE utenti SET password = ?, token_reset_password = NULL, data_token_reset = NULL WHERE id = ?");
            $stmt->bind_param('si', $hash, $uid);
            $stmt->execute();
            $stmt->close();
            return $response->withHeader('Location', RouteTranslator::route('login') . '?reset=1')->withStatus(302);
        }
        $stmt->close();
        return $response->withHeader('Location', RouteTranslator::route('reset_password') . '?error=invalid_token')->withStatus(302);
    }

    /**
     * Get validated base URL to prevent Host Header Injection attacks
     */
    private function getValidatedBaseUrl(): string
    {
        // PRIORITY 1: Use APP_CANONICAL_URL from .env if configured
        // This ensures emails always use the production URL even when sent from CLI/localhost
        $canonicalUrl = $_ENV['APP_CANONICAL_URL'] ?? getenv('APP_CANONICAL_URL') ?: false;
        if ($canonicalUrl !== false) {
            $canonicalUrl = trim((string)$canonicalUrl);
            if ($canonicalUrl !== '' && filter_var($canonicalUrl, FILTER_VALIDATE_URL)) {
                return rtrim($canonicalUrl, '/');
            }
        }

        // PRIORITY 2: Fallback to HTTP_HOST with security validation
        $protocol = (($_SERVER['HTTPS'] ?? 'off') === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Validate hostname format to prevent Host Header Injection attacks
        // Accepts: domain.com, subdomain.domain.com, localhost, localhost:8000, IP:port
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*(:[0-9]{1,5})?$/', $host)) {
            return $protocol . '://' . $host;
        }

        // Invalid hostname format - fallback to localhost
        return $protocol . '://localhost';
    }

    /**
     * Check rate limit for an action
     * Returns false if limit exceeded, true otherwise
     */
    private function checkRateLimit(mysqli $db, string $identifier, string $action, int $maxAttempts, int $windowMinutes): bool
    {
        $windowSeconds = $windowMinutes * 60;
        $timeLimit = gmdate('Y-m-d H:i:s', time() - $windowSeconds);

        // Ensure rate_limit_log table exists
        $db->query("
            CREATE TABLE IF NOT EXISTS rate_limit_log (
                id INT PRIMARY KEY AUTO_INCREMENT,
                identifier VARCHAR(255) NOT NULL,
                action VARCHAR(100) NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_identifier_action (identifier, action),
                INDEX idx_timestamp (timestamp)
            )
        ");

        // Count recent attempts
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM rate_limit_log WHERE identifier = ? AND action = ? AND timestamp > ?");
        $stmt->bind_param('sss', $identifier, $action, $timeLimit);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $recentAttempts = (int)($row['count'] ?? 0);

        if ($recentAttempts >= $maxAttempts) {
            return false;
        }

        // Log this attempt
        $stmt = $db->prepare("INSERT INTO rate_limit_log (identifier, action) VALUES (?, ?)");
        $stmt->bind_param('ss', $identifier, $action);
        $stmt->execute();
        $stmt->close();

        return true;
    }
}
