<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\RememberMeService;
use App\Support\RouteTranslator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProfileController
{
    public function show(Request $request, Response $response, mysqli $db, mixed $container = null): Response
    {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if ($uid <= 0)
            return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302);
        $stmt = $db->prepare("SELECT id, nome, cognome, email, codice_tessera, stato, tipo_utente, data_ultimo_accesso, data_nascita, telefono, sesso, indirizzo, cod_fiscale, data_scadenza_tessera FROM utenti WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        ob_start();
        require __DIR__ . '/../Views/profile/index.php';
        $content = ob_get_clean();

        // Use frontend layout for normal users, admin layout for admin/staff
        $isAdminOrStaff = isset($_SESSION['user']['tipo_utente']) &&
            ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff');

        ob_start();
        $title = __('Profilo') . ' - ' . __('Biblioteca');
        if ($isAdminOrStaff) {
            require __DIR__ . '/../Views/layout.php';
        } else {
            require __DIR__ . '/../Views/frontend/layout.php';
        }
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function changePassword(Request $request, Response $response, mysqli $db): Response
    {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if ($uid <= 0)
            return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302);
        $data = (array) ($request->getParsedBody() ?? []);

        // CSRF validated by CsrfMiddleware
        $p1 = (string) ($data['password'] ?? '');
        $p2 = (string) ($data['password_confirm'] ?? '');
        if ($p1 === '' || $p1 !== $p2) {
            $profileUrl = RouteTranslator::route('profile');
            return $response->withHeader('Location', $profileUrl . '?error=invalid')->withStatus(302);
        }

        // Validate password complexity
        if (strlen($p1) < 8) {
            $profileUrl = RouteTranslator::route('profile');
            return $response->withHeader('Location', $profileUrl . '?error=password_too_short')->withStatus(302);
        }

        if (!preg_match('/[A-Z]/', $p1) || !preg_match('/[a-z]/', $p1) || !preg_match('/[0-9]/', $p1)) {
            $profileUrl = RouteTranslator::route('profile');
            return $response->withHeader('Location', $profileUrl . '?error=password_needs_upper_lower_number')->withStatus(302);
        }

        $hash = password_hash($p1, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE utenti SET password = ? WHERE id = ?");
        $stmt->bind_param('si', $hash, $uid);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success_message'] = __('Password aggiornata con successo.');
        return $response->withHeader('Location', RouteTranslator::route('profile'))->withStatus(302);
    }

    public function update(Request $request, Response $response, mysqli $db): Response
    {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if ($uid <= 0)
            return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302);

        $data = (array) ($request->getParsedBody() ?? []);

        // CSRF validated by CsrfMiddleware

        // Extract and sanitize input
        $nome = trim(strip_tags((string) ($data['nome'] ?? '')));
        $cognome = trim(strip_tags((string) ($data['cognome'] ?? '')));
        $telefono = trim(strip_tags((string) ($data['telefono'] ?? '')));
        $data_nascita = trim(strip_tags((string) ($data['data_nascita'] ?? '')));
        $cod_fiscale = trim(strip_tags((string) ($data['cod_fiscale'] ?? '')));
        $sesso = trim(strip_tags((string) ($data['sesso'] ?? '')));
        $indirizzo = trim(strip_tags((string) ($data['indirizzo'] ?? '')));

        // Convert empty strings to null for optional fields
        $telefono = empty($telefono) ? null : $telefono;
        $data_nascita = empty($data_nascita) ? null : $data_nascita;
        $cod_fiscale = empty($cod_fiscale) ? null : $cod_fiscale;
        $indirizzo = empty($indirizzo) ? null : $indirizzo;

        // Validate sesso - only allow M, F, A (for Altro), or empty
        if ($sesso) {
            $sesso = strtoupper(substr($sesso, 0, 1)); // Take first character and uppercase
            if (!in_array($sesso, ['M', 'F', 'A'], true)) {
                $sesso = null; // Invalid value, set to null
            }
        } else {
            $sesso = null; // Empty value becomes null
        }

        // Validate required fields
        if (empty($nome) || empty($cognome)) {
            $profileUrl = RouteTranslator::route('profile');
            return $response->withHeader('Location', $profileUrl . '?error=required_fields')->withStatus(302);
        }

        // Update user (sesso can be NULL)
        $stmt = $db->prepare("UPDATE utenti SET nome = ?, cognome = ?, telefono = ?, data_nascita = ?, cod_fiscale = ?, sesso = ?, indirizzo = ? WHERE id = ?");
        $stmt->bind_param('sssssssi', $nome, $cognome, $telefono, $data_nascita, $cod_fiscale, $sesso, $indirizzo, $uid);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = __('Profilo aggiornato con successo.');
            // Update session data
            $_SESSION['user']['name'] = $nome . ' ' . $cognome;
        } else {
            error_log("Profile update error for user $uid: " . $stmt->error);
            $_SESSION['error_message'] = 'Errore durante l\'aggiornamento del profilo.';
        }

        $stmt->close();
        return $response->withHeader('Location', RouteTranslator::route('profile'))->withStatus(302);
    }

    /**
     * Get active sessions for the current user (API endpoint).
     */
    public function getSessions(Request $request, Response $response, mysqli $db): Response
    {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if ($uid <= 0) {
            $response->getBody()->write(json_encode(['error' => __('Non autorizzato')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        try {
            $rememberMeService = new RememberMeService($db);
            $sessions = $rememberMeService->getActiveSessions($uid);

            $json = json_encode(['sessions' => $sessions]);
            if ($json === false) {
                throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
            }
            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            error_log("getSessions error for user $uid: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore durante il recupero delle sessioni')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Revoke a specific session (API endpoint).
     */
    public function revokeSession(Request $request, Response $response, mysqli $db): Response
    {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if ($uid <= 0) {
            $response->getBody()->write(json_encode(['error' => __('Non autorizzato')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $data = (array) ($request->getParsedBody() ?? []);

        // CSRF validated by CsrfMiddleware

        $sessionId = (int) ($data['session_id'] ?? 0);

        if ($sessionId <= 0) {
            $response->getBody()->write(json_encode(['error' => __('ID sessione non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $rememberMeService = new RememberMeService($db);
            $success = $rememberMeService->revokeSession($sessionId, $uid);

            if ($success) {
                $json = json_encode(['success' => true, 'message' => __('Sessione revocata')]);
                if ($json === false) {
                    throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
                }
                $response->getBody()->write($json);
            } else {
                $response->getBody()->write(json_encode(['error' => __('Impossibile revocare la sessione')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            error_log("revokeSession error for user $uid: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore durante la revoca della sessione')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Revoke all sessions except the current one (API endpoint).
     */
    public function revokeAllSessions(Request $request, Response $response, mysqli $db): Response
    {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if ($uid <= 0) {
            $response->getBody()->write(json_encode(['error' => __('Non autorizzato')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // CSRF validated by CsrfMiddleware

        try {
            $rememberMeService = new RememberMeService($db);
            // Note: revokeAllTokens() preserves the current session if possible
            $revoked = $rememberMeService->revokeAllTokens($uid);

            $json = json_encode([
                'success' => true,
                'message' => \sprintf(__('Revocate %d sessioni'), $revoked),
                'revoked' => $revoked
            ]);
            if ($json === false) {
                throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
            }
            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            error_log("revokeAllSessions error for user $uid: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore durante la revoca delle sessioni')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}

