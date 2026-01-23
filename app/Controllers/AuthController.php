<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Csrf;
use App\Support\Log;
use App\Support\RememberMeService;
use App\Support\RouteTranslator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function loginForm(Request $request, Response $response): Response
    {
        // Simple session-based CSRF token
        $token = Csrf::ensureToken();
        $params = $request->getQueryParams();
        $returnUrl = $this->sanitizeReturnUrl($params['return_url'] ?? null);

        // Plugin hook: Before login form render
        \App\Support\Hooks::do('login.form.render.before', [$request]);

        // Render login page standalone (without admin layout)
        ob_start();
        $csrf_token = $token;
        $return_url = $returnUrl;
        require __DIR__ . '/../Views/auth/login.php';
        $html = ob_get_clean();

        // Plugin hook: Modify login form HTML
        $html = \App\Support\Hooks::apply('login.form.html', $html, [$request]);

        $response->getBody()->write($html);
        return $response;
    }

    public function login(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $remember = !empty($data['remember']);
        $returnUrl = $this->sanitizeReturnUrl($data['return_url'] ?? null);

        // CSRF validated by CsrfMiddleware

        if ($email !== '' && $password !== '') {
            $stmt = $db->prepare("SELECT id, email, password, tipo_utente, email_verificata, stato, nome, cognome FROM utenti WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            // Constant-time password verification to prevent timing attacks.
            // SECURITY NOTE: This dummy hash is NOT a leaked credential - it's an intentional
            // security measure. By always running password_verify() even for non-existent users,
            // attackers cannot enumerate valid emails by measuring response times.
            // See OWASP: https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
            $dummyHash = '$2y$12$PXZb520pM93TmNGnoJy2TuhssLxu4XversvqtKZ4B7xrm0sAldZE6'; // @codingStandardsIgnoreLine
            $hashToCheck = (string) ($row['password'] ?? $dummyHash);

            // Plugin hook: Custom login validation (e.g., reCAPTCHA, 2FA)
            $customValidation = \App\Support\Hooks::apply('login.validate', true, [$email, $request]);

            if (password_verify($password, $hashToCheck) && $row && $customValidation) {
                // Allow login only if email verified and stato attivo
                if (((int) ($row['email_verificata'] ?? 0)) !== 1) {
                    Log::security('login.email_not_verified', [
                        'email' => $email,
                        'user_id' => $row['id'] ?? null,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    return $response->withHeader('Location', RouteTranslator::route('login') . '?error=email_not_verified')->withStatus(302);
                }
                if (($row['stato'] ?? 'sospeso') === 'sospeso') {
                    Log::security('login.account_suspended', [
                        'email' => $email,
                        'user_id' => $row['id'] ?? null,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    return $response->withHeader('Location', RouteTranslator::route('login') . '?error=account_suspended')->withStatus(302);
                }
                if (($row['stato'] ?? '') !== 'attivo') {
                    Log::security('login.account_pending', [
                        'email' => $email,
                        'user_id' => $row['id'] ?? null,
                        'stato' => $row['stato'] ?? 'unknown',
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    return $response->withHeader('Location', RouteTranslator::route('login') . '?error=account_pending')->withStatus(302);
                }
                // Regenerate session ID to prevent session fixation attacks
                session_regenerate_id(true);

                // Regenerate CSRF token after login
                Csrf::regenerate();

                $_SESSION['user'] = [
                    'id' => $row['id'],
                    'email' => $row['email'],
                    'tipo_utente' => $row['tipo_utente'],
                    'name' => trim(\App\Support\HtmlHelper::decode((string) ($row['nome'] ?? '')) . ' ' . \App\Support\HtmlHelper::decode((string) ($row['cognome'] ?? ''))),
                ];

                // Handle "Remember Me" functionality with database-backed tokens
                if ($remember) {
                    $rememberMeService = new RememberMeService($db);
                    $rememberMeService->createToken((int) $row['id']);
                }

                // Log successful login
                Log::security('login.success', [
                    'email' => $email,
                    'user_id' => $row['id'],
                    'tipo_utente' => $row['tipo_utente'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);

                // Plugin hook: After successful login
                \App\Support\Hooks::do('login.success', [$row['id'], $_SESSION['user'], $request]);

                // Redirect based on user role (respect safe return URL if provided)
                if ($returnUrl !== null) {
                    $redirectUrl = $returnUrl;
                } elseif (in_array($row['tipo_utente'], ['admin', 'staff'], true)) {
                    $redirectUrl = '/admin/dashboard';
                } else {
                    $redirectUrl = '/user/dashboard';
                }

                return $response->withHeader('Location', $redirectUrl)->withStatus(302);
            }

            // Ensure hash verification still happens when credentials invalid
            password_verify($password, $dummyHash);
        }

        // Failed: redirect back with invalid credentials error
        Log::security('login.invalid_credentials', [
            'email' => $email,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        // Plugin hook: After failed login
        \App\Support\Hooks::do('login.failed', [$email, $request]);

        return $response->withHeader('Location', RouteTranslator::route('login') . '?error=invalid_credentials')->withStatus(302);
    }

    public function logout(Request $request, Response $response, mysqli $db): Response
    {
        // Revoke database-backed remember token if present
        $rememberMeService = new RememberMeService($db);
        $rememberMeService->revokeCurrentToken();

        // Regenerate CSRF before destroying session
        Csrf::regenerate();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302);
    }

    private function sanitizeReturnUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $clean = trim(str_replace(["\r", "\n"], '', $url));
        if ($clean === '' || !str_starts_with($clean, '/')) {
            return null;
        }
        if (str_starts_with($clean, '//')) {
            return null;
        }
        return $clean;
    }
}
