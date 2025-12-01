<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Csrf;
use App\Support\CsrfHelper;
use App\Support\Mailer;
use App\Support\ConfigStore;
use App\Support\NotificationService;
use App\Support\RouteTranslator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RegistrationController
{
    public function form(Request $request, Response $response): Response
    {
        Csrf::ensureToken();
        ob_start();
        require __DIR__ . '/../Views/auth/register.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    public function register(Request $request, Response $response, mysqli $db): Response
    {
        // Ensure data_token_verifica column exists (migration for existing installations)
        $this->ensureTokenVerificaColumn($db);

        $data = (array) ($request->getParsedBody() ?? []);

        // CSRF validated by CsrfMiddleware
        $nome = \App\Support\HtmlHelper::decode(trim((string) ($data['nome'] ?? '')));
        $cognome = \App\Support\HtmlHelper::decode(trim((string) ($data['cognome'] ?? '')));
        $email = trim((string) ($data['email'] ?? ''));
        $telefono = trim((string) ($data['telefono'] ?? ''));
        $indirizzo = \App\Support\HtmlHelper::decode(trim((string) ($data['indirizzo'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $password2 = (string) ($data['password_confirm'] ?? '');

        // Optional fields
        $dataNascita = trim((string) ($data['data_nascita'] ?? ''));
        $dataNascita = $dataNascita !== '' ? $dataNascita : null;

        $sesso = trim((string) ($data['sesso'] ?? ''));
        $sesso = $sesso !== '' ? $sesso : null;
        if ($sesso !== null && !in_array($sesso, ['M', 'F', 'Altro'], true)) {
            $sesso = null;
        }

        $cod_fiscale = strtoupper(trim((string) ($data['cod_fiscale'] ?? '')));
        $cod_fiscale = $cod_fiscale !== '' ? $cod_fiscale : null;

        // Validate required fields
        if ($nome === '' || $cognome === '' || $email === '' || $telefono === '' || $indirizzo === '' || $password === '' || $password !== $password2) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=missing_fields')->withStatus(302);
        }

        // Validate input lengths
        if (strlen($nome) > 100 || strlen($cognome) > 100) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=name_too_long')->withStatus(302);
        }

        if (strlen($email) > 255) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=email_too_long')->withStatus(302);
        }

        if (strlen($password) > 128) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=password_too_long')->withStatus(302);
        }

        // Validate password complexity
        if (strlen($password) < 8) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=password_too_short')->withStatus(302);
        }

        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=password_needs_upper_lower_number')->withStatus(302);
        }
        // Check existing email
        $stmt = $db->prepare("SELECT id FROM utenti WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=email_exists')->withStatus(302);
        }
        $stmt->close();

        $codice_tessera = $this->generateTessera($db);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(24));

        // Default stato: sospeso (richiede approvazione admin). Email da verificare
        $stato = 'sospeso';
        $ruolo = 'standard';

        // Ensure timezone consistency BEFORE generating dates
        $db->query("SET SESSION time_zone = '+00:00'");

        $data_scadenza_tessera = gmdate('Y-m-d', strtotime('+5 years')); // Scadenza tessera tra 5 anni in UTC
        $data_scadenza_token = gmdate('Y-m-d H:i:s', time() + 24 * 60 * 60); // Token scade tra 24 ore in UTC

        // Build dynamic INSERT to handle NULL values properly for ENUM fields
        $columns = 'nome, cognome, email, password, telefono, indirizzo, codice_tessera, stato, tipo_utente, email_verificata, token_verifica_email, data_token_verifica, data_scadenza_tessera';
        $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?';
        $types = 'ssssssssssss';
        $values = [
            $nome,
            $cognome,
            $email,
            $hash,
            $telefono,
            $indirizzo,
            $codice_tessera,
            $stato,
            $ruolo,
            $token,
            $data_scadenza_token,
            $data_scadenza_tessera
        ];

        // Add optional fields only if they have values (to avoid ENUM truncation errors)
        if ($dataNascita !== null) {
            $columns .= ', data_nascita';
            $placeholders .= ', ?';
            $types .= 's';
            $values[] = $dataNascita;
        }

        if ($sesso !== null) {
            $columns .= ', sesso';
            $placeholders .= ', ?';
            $types .= 's';
            $values[] = $sesso;
        }

        if ($cod_fiscale !== null) {
            $columns .= ', cod_fiscale';
            $placeholders .= ', ?';
            $types .= 's';
            $values[] = $cod_fiscale;
        }

        $stmt = $db->prepare("
            INSERT INTO utenti ({$columns}) VALUES ({$placeholders})
        ");
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            $stmt->close();
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=db')->withStatus(302);
        }
        $userId = (int) $stmt->insert_id;
        $stmt->close();

        // Send notification emails using new service
        $notificationService = new NotificationService($db);

        // Send welcome email to user
        $notificationService->sendUserRegistrationPending($userId);

        // Notify admins of new registration (email)
        $notificationService->notifyNewUserRegistration($userId);

        // Create in-app notification for admins
        $notificationService->notifyNewUserInApp($userId, $nome . ' ' . $cognome, $email);

        // Redirect to success page
        return $response->withHeader('Location', RouteTranslator::route('register_success'))->withStatus(302);
    }

    public function success(Request $request, Response $response): Response
    {
        ob_start();
        require __DIR__ . '/../Views/auth/register_success.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function verifyEmail(Request $request, Response $response, mysqli $db): Response
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        // Sanitize token to prevent HTTP response splitting
        $token = str_replace(["\r", "\n"], '', $token);
        if ($token === '') {
            return $response->withHeader('Location', RouteTranslator::route('login') . '?error=invalid_token')->withStatus(302);
        }

        // Ensure timezone consistency
        $db->query("SET SESSION time_zone = '+00:00'");

        // Check token: must exist, not be null, and not be expired
        $stmt = $db->prepare("SELECT id FROM utenti WHERE token_verifica_email = ? AND data_token_verifica IS NOT NULL AND data_token_verifica > NOW() LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $uid = (int) $row['id'];
            $stmt->close();
            $stmt = $db->prepare("UPDATE utenti SET email_verificata = 1, token_verifica_email = NULL, data_token_verifica = NULL WHERE id = ?");
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();
            // Show message instructing admin approval pending
            return $response->withHeader('Location', RouteTranslator::route('login') . '?verified=1')->withStatus(302);
        }
        $stmt->close();

        // Token is expired or invalid
        return $response->withHeader('Location', RouteTranslator::route('login') . '?error=token_expired')->withStatus(302);
    }

    private function generateTessera(mysqli $db): string
    {
        do {
            $random = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
            $tessera = 'T' . $random;
            $stmt = $db->prepare("SELECT id FROM utenti WHERE codice_tessera = ?");
            $stmt->bind_param("s", $tessera);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } while ($exists);
        return $tessera;
    }

    /**
     * Ensure data_token_verifica column exists (migration for existing installations)
     */
    private function ensureTokenVerificaColumn(mysqli $db): void
    {
        try {
            $result = $db->query("SHOW COLUMNS FROM utenti LIKE 'data_token_verifica'");
            if ($result && $result->num_rows === 0) {
                // Column doesn't exist, add it
                $db->query("ALTER TABLE utenti ADD COLUMN data_token_verifica datetime DEFAULT NULL AFTER token_verifica_email");
            }
        } catch (\Exception $e) {
            // Log but don't fail - column might already exist or this is a new installation
            error_log("Migration check for data_token_verifica: " . $e->getMessage());
        }
    }
}
