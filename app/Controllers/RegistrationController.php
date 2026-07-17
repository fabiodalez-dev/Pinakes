<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Csrf;
use App\Support\Mailer;
use App\Support\ConfigStore;
use App\Support\NotificationService;
use App\Support\RouteTranslator;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RegistrationController
{
    public function form(Request $request, Response $response, mysqli $db): Response
    {
        Csrf::ensureToken();
        // Admin-configurable field requirements + custom fields (issue #255),
        // consumed by the view for labels/required attributes and rendering.
        $registrationRequired = [
            'cognome'   => \App\Support\RegistrationFields::isRequired('cognome'),
            'telefono'  => \App\Support\RegistrationFields::isRequired('telefono'),
            'indirizzo' => \App\Support\RegistrationFields::isRequired('indirizzo'),
        ];
        $customFields = \App\Support\RegistrationFields::definitions($db);
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

        $privacyAccepted = !empty($data['privacy_acceptance']);

        $cod_fiscale = strtoupper(trim((string) ($data['cod_fiscale'] ?? '')));
        $cod_fiscale = $cod_fiscale !== '' ? $cod_fiscale : null;

        // Require privacy acceptance server-side (in addition to client-side required)
        if (!$privacyAccepted) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=privacy_required')->withStatus(302);
        }

        // Validate required fields. Surname/phone/address are admin-configurable
        // (issue #255, Settings → Registration); defaults keep them required so
        // existing installs behave exactly as before until the admin opts out.
        if ($nome === '' || $email === '' || $password === '' || $password !== $password2) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=missing_fields')->withStatus(302);
        }
        if (($cognome === '' && \App\Support\RegistrationFields::isRequired('cognome'))
            || ($telefono === '' && \App\Support\RegistrationFields::isRequired('telefono'))
            || ($indirizzo === '' && \App\Support\RegistrationFields::isRequired('indirizzo'))
        ) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=missing_fields')->withStatus(302);
        }

        // Admin-defined custom fields (issue #255): validate before any write.
        // Required IS enforced at signup (the field's contract); a format error
        // gets a distinct code so the user isn't told to "fill" a field they did.
        $customDefinitions = \App\Support\RegistrationFields::definitions($db);
        $customValidation = \App\Support\RegistrationFields::validate($customDefinitions, $data);
        if ($customValidation['error'] !== null) {
            $customError = $customValidation['error_reason'] === 'format' ? 'custom_field_invalid' : 'missing_fields';
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=' . $customError)->withStatus(302);
        }

        // Validate input lengths
        if (strlen($nome) > 100 || strlen($cognome) > 100) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=name_too_long')->withStatus(302);
        }

        if (strlen($email) > 255) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=email_too_long')->withStatus(302);
        }

        if (strlen($password) > 72) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=password_too_long')->withStatus(302);
        }

        // Validate password complexity
        if (strlen($password) < 8) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=password_too_short')->withStatus(302);
        }

        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=password_needs_upper_lower_number')->withStatus(302);
        }
        // Check existing email. The SELECT is wrapped too: mysqli is in exception mode,
        // so even a read failure here would otherwise throw and 500 the registration.
        try {
            $stmt = $db->prepare("SELECT id FROM utenti WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $emailTaken = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } catch (\Throwable $e) {
            // \Throwable, not \mysqli_sql_exception: if exceptions were ever off, prepare()
            // returns false and bind_param() raises a \Error. Log only the errno — never the
            // raw driver message, which for a duplicate carries the offending email/CF.
            SecureLogger::error('[Registration] email lookup failed (errno ' . (int) $e->getCode() . ')');
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=db')->withStatus(302);
        }
        if ($emailTaken) {
            // Public form: a GENERIC "already registered" message, never field-specific.
            // Saying which field (email / codice fiscale) is taken would let an anonymous
            // visitor enumerate members (privacy leak, esp. for the CF). Admin flows keep
            // the precise messages — see UsersController.
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=already_registered')->withStatus(302);
        }

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

        // GDPR: Record privacy acceptance timestamp
        $data_accettazione_privacy = gmdate('Y-m-d H:i:s');
        $privacy_policy_version = '1.0';

        // Build dynamic INSERT to handle NULL values properly for ENUM fields.
        // cognome stays in the base list: the column is NOT NULL by design (70+
        // display paths CONCAT nome+cognome and a NULL would blank the whole
        // name), so an optional surname is stored as an empty string.
        $columns = 'nome, cognome, email, password, codice_tessera, stato, tipo_utente, email_verificata, token_verifica_email, data_token_verifica, data_scadenza_tessera, privacy_accettata, data_accettazione_privacy, privacy_policy_version';
        $placeholders = '?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 1, ?, ?';
        $types = 'ssssssssssss';
        $values = [
            $nome,
            $cognome,
            $email,
            $hash,
            $codice_tessera,
            $stato,
            $ruolo,
            $token,
            $data_scadenza_token,
            $data_scadenza_tessera,
            $data_accettazione_privacy,
            $privacy_policy_version
        ];

        // Nullable contact fields: store NULL (not '') when the admin made them
        // optional and the user left them blank.
        if ($telefono !== '') {
            $columns .= ', telefono';
            $placeholders .= ', ?';
            $types .= 's';
            $values[] = $telefono;
        }
        if ($indirizzo !== '') {
            $columns .= ', indirizzo';
            $placeholders .= ', ?';
            $types .= 's';
            $values[] = $indirizzo;
        }

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

        // mysqli runs in exception mode (ConfigStore), so a failed INSERT throws instead of
        // returning false — an unguarded execute() surfaces as a 500. A UNIQUE violation
        // (1062) can be on email OR cod_fiscale (both user-entered) — map to the precise
        // field so the user is told which one is taken; anything else is a generic error.
        // prepare()/bind_param() are inside the try too: under MYSQLI_REPORT_STRICT they can
        // throw as well, and must route to ?error=db, not bubble up as a 500.
        $stmt = null;
        try {
            // Account row + custom field values land atomically: a failed custom
            // value write must not leave a half-registered account behind.
            $db->begin_transaction();
            $stmt = $db->prepare("
                INSERT INTO utenti ({$columns}) VALUES ({$placeholders})
            ");
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $userId = (int) $stmt->insert_id;
            \App\Support\RegistrationFields::saveValues($db, $userId, $customValidation['values']);
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // best-effort — never mask the original error below
            }
            // Public form: collapse ANY unique-key duplicate (email / cod_fiscale /
            // codice_tessera) into one GENERIC message so an anonymous visitor can't tell
            // which field is taken (member enumeration). Only a genuine non-duplicate DB
            // error falls through to the generic 'db' message + errno-only log.
            if ($e instanceof \mysqli_sql_exception && $e->getCode() === 1062) {
                $errCode = 'already_registered';
            } else {
                SecureLogger::error('[Registration] user INSERT failed (errno ' . (int) $e->getCode() . ')');
                $errCode = 'db';
            }
            return $response->withHeader('Location', RouteTranslator::route('register') . '?error=' . $errCode)->withStatus(302);
        } finally {
            // $stmt stays null if prepare() itself threw — guard the close().
            if ($stmt instanceof \mysqli_stmt) {
                $stmt->close();
            }
        }

        // The account is now created. Everything below is a best-effort side-effect
        // (audit log, welcome/admin emails, in-app notice): a failure here — an SMTP
        // outage, a missing consent_log table on an old install — must NOT turn a
        // successful registration into a 500. Log it and still land the user on success.
        try {
            // GDPR: Log consent in audit trail (Article 7 compliance)
            $this->logConsent($db, $userId, 'privacy_policy', true, $privacy_policy_version, $request);

            // Send notification emails using new service
            $notificationService = new NotificationService($db);
            // Send welcome email to user
            $notificationService->sendUserRegistrationPending($userId);
            // Notify admins of new registration (email)
            $notificationService->notifyNewUserRegistration($userId);
            // Create in-app notification for admins
            $notificationService->notifyNewUserInApp($userId, trim($nome . ' ' . $cognome), $email);
        } catch (\Throwable $e) {
            SecureLogger::error('[Registration] post-registration side-effect failed (account ' . $userId . ' was created): ' . $e->getMessage());
        }

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

        // mysqli runs in exception mode (ConfigStore), so prepare()/execute()
        // throw on failure. Wrap the whole verification so a DB error becomes a
        // handled redirect instead of a 500 on the verify link.
        try {
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

                // Whether new self-registrations still need an administrator to
                // approve them AFTER they verify their email. Default true (the
                // historical behaviour). When false, verifying the email is enough
                // to activate the account.
                $requireApproval = (bool) ConfigStore::get('registration.require_admin_approval', true);

                if ($requireApproval) {
                    $stmt = $db->prepare("UPDATE utenti SET email_verificata = 1, token_verifica_email = NULL, data_token_verifica = NULL WHERE id = ?");
                } else {
                    // No admin approval required: activate on email verification, but
                    // ONLY for a genuinely fresh, never-verified registration
                    // (email_verificata = 0). 'sospeso' is ALSO the state an admin
                    // sets to suspend an already-verified user, whose old
                    // verification token is not cleared on suspend — without the
                    // email_verificata = 0 gate a stale link would silently
                    // un-suspend them (auth bypass). The stato CASE is listed
                    // BEFORE email_verificata = 1 on purpose: MySQL evaluates SET
                    // assignments left-to-right, so the CASE reads the OLD (0)
                    // value; reversing the order would make it always see 1.
                    $stmt = $db->prepare("UPDATE utenti SET stato = CASE WHEN stato = 'sospeso' AND email_verificata = 0 THEN 'attivo' ELSE stato END, email_verificata = 1, token_verifica_email = NULL, data_token_verifica = NULL WHERE id = ?");
                }
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $stmt->close();

                // Pending-approval accounts get the "await approval" notice; auto-
                // activated ones are told they can sign in immediately.
                $verifiedQuery = $requireApproval ? '?verified=1' : '?verified=1&activated=1';
                return $response->withHeader('Location', RouteTranslator::route('login') . $verifiedQuery)->withStatus(302);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[registration] email verification failed', ['error' => $e->getMessage()]);
            return $response->withHeader('Location', RouteTranslator::route('login') . '?error=server')->withStatus(302);
        }

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
        } catch (\Throwable $e) {
            // Log but don't fail - column might already exist or this is a new installation
            error_log("Migration check for data_token_verifica: " . $e->getMessage());
        }
    }

    /**
     * Log consent to audit trail (GDPR Article 7 compliance)
     */
    private function logConsent(mysqli $db, int $userId, string $consentType, bool $consentGiven, ?string $version, Request $request): void
    {
        try {
            // Check if consent_log table exists (graceful degradation for upgrades)
            $result = $db->query("SHOW TABLES LIKE 'consent_log'");
            if (!$result || $result->num_rows === 0) {
                return;
            }

            $serverParams = $request->getServerParams();
            $ipAddress = $serverParams['REMOTE_ADDR'] ?? null;
            $userAgent = $serverParams['HTTP_USER_AGENT'] ?? null;
            $consentGivenInt = $consentGiven ? 1 : 0;

            $stmt = $db->prepare("
                INSERT INTO consent_log (utente_id, consent_type, consent_given, consent_version, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('isisss', $userId, $consentType, $consentGivenInt, $version, $ipAddress, $userAgent);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            // Log but don't fail registration if consent logging fails
            error_log("Failed to log consent: " . $e->getMessage());
        }
    }
}
