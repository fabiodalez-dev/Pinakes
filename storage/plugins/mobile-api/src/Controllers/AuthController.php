<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\TokenService;
use App\Support\ConfigStore;
use App\Support\EmailService;
use App\Support\Mailer;
use App\Support\NotificationService;
use App\Support\RateLimiter;
use App\Support\RouteTranslator;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Auth endpoints for the Mobile API.
 *
 * Public:
 *   POST /auth/login            — validate against utenti (SAME hashing as web),
 *                                 issue a 256-bit token (store only sha256 hash),
 *                                 return plaintext once + user payload. Throttled.
 *   POST /auth/register         — only if instance registration enabled; reuses
 *                                 the exact web INSERT + email-verification flow.
 *   POST /auth/forgot-password  — reuses the web reset-token + email flow.
 *
 * Authenticated (AppAuthMiddleware):
 *   POST   /auth/logout         — revoke the current device token.
 *   GET    /me/devices          — list this user's active devices.
 *   DELETE /me/devices/{id}     — revoke one of THIS user's devices (own only).
 *
 * Reuses RateLimitMiddleware/RateLimiter for the login throttle; identity is
 * only ever derived from the bearer token, never from client-supplied ids.
 */
final class AuthController
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ─── POST /auth/login ───────────────────────────────────────────────────

    public function login(Request $request, ResponseInterface $response): ResponseInterface
    {
        // App-access gate FIRST, before touching any credential: with
        // mobile_api.enabled off, login must not mint tokens that every
        // authenticated route would then reject via AppAuthMiddleware.
        // Same error code literal as the middleware (app_access_disabled).
        if (!$this->appAccessEnabled()) {
            return ResponseEnvelope::error($response, 'app_access_disabled', __('L\'accesso da app non è abilitato su questa istanza.'), 403);
        }

        $body     = JsonBody::parse($request);
        $email    = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        // Constant-time password verification (mirrors web AuthController): always
        // run password_verify() even for unknown emails to prevent enumeration.
        // The "dummy" hash is generated per-request from random bytes — it is NOT
        // a credential, just a non-matching bcrypt input that keeps the timing of
        // the no-such-user path indistinguishable from the wrong-password path.
        $dummyHash = self::dummyHash();

        if ($email === '' || $password === '') {
            password_verify($password, $dummyHash);
            return ResponseEnvelope::error($response, 'invalid_credentials', __('Email o password non validi.'), 401);
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, email, password, tipo_utente, email_verificata, stato, nome, cognome, locale
                   FROM utenti
                  WHERE LOWER(email) = LOWER(?)
                  LIMIT 1'
            );
            if ($stmt === false) {
                return ResponseEnvelope::error($response, 'internal_error', __('Errore del server.'), 500);
            }
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            $hashToCheck = (string) ($row['password'] ?? $dummyHash);
            $passwordOk  = password_verify($password, $hashToCheck);

            if (!$passwordOk || $row === null) {
                SecureLogger::warning('[MobileApi] login.invalid_credentials');
                return ResponseEnvelope::error($response, 'invalid_credentials', __('Email o password non validi.'), 401);
            }

            if (((int) ($row['email_verificata'] ?? 0)) !== 1) {
                return ResponseEnvelope::error($response, 'email_not_verified', __('Devi confermare la tua email prima di accedere.'), 403);
            }
            if (($row['stato'] ?? '') !== 'attivo') {
                return ResponseEnvelope::error($response, 'account_not_active', __('Il tuo account non è ancora attivo.'), 403);
            }

            $deviceName = isset($body['device_name']) ? (string) $body['device_name'] : null;
            $deviceId   = isset($body['device_id']) ? (string) $body['device_id'] : null;
            $platform   = isset($body['platform']) ? (string) $body['platform'] : null;

            $service = new TokenService($this->db);
            $issued  = $service->issue((int) $row['id'], $deviceName, $deviceId, $platform);
            if ($issued === null) {
                return ResponseEnvelope::error($response, 'internal_error', __('Errore del server.'), 500);
            }

            SecureLogger::info('[MobileApi] login.success user_id=' . (int) $row['id']);

            $data = [
                'token' => $issued['token'], // plaintext, returned exactly once
                'user'  => [
                    'id'          => (int) $row['id'],
                    'email'       => (string) $row['email'],
                    'nome'        => (string) ($row['nome'] ?? ''),
                    'cognome'     => (string) ($row['cognome'] ?? ''),
                    'tipo_utente' => (string) $row['tipo_utente'],
                    'locale'      => $row['locale'] !== null ? (string) $row['locale'] : null,
                ],
            ];

            // No internal token_id in the envelope — the opaque token in $data is
            // all the client needs; exposing the DB id leaks issuance volume.
            return ResponseEnvelope::success($response, $data, [], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] login failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore del server.'), 500);
        }
    }

    // ─── POST /auth/register ────────────────────────────────────────────────

    public function register(Request $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->appAccessEnabled()) {
            return ResponseEnvelope::error($response, 'app_access_disabled', __('L\'accesso da app non è abilitato su questa istanza.'), 403);
        }
        if (!$this->registrationEnabled()) {
            return ResponseEnvelope::error($response, 'registration_disabled', __('La registrazione non è abilitata su questa istanza.'), 403);
        }

        $body = JsonBody::parse($request);

        $nome      = \App\Support\HtmlHelper::decode(trim((string) ($body['nome'] ?? '')));
        $cognome   = \App\Support\HtmlHelper::decode(trim((string) ($body['cognome'] ?? '')));
        $email     = trim((string) ($body['email'] ?? ''));
        $telefono  = trim((string) ($body['telefono'] ?? ''));
        $indirizzo = \App\Support\HtmlHelper::decode(trim((string) ($body['indirizzo'] ?? '')));
        $password  = (string) ($body['password'] ?? '');
        $password2 = (string) ($body['password_confirm'] ?? '');
        $privacy   = !empty($body['privacy_acceptance']);

        // Same validation rules as the web RegistrationController.
        if (!$privacy) {
            return ResponseEnvelope::error($response, 'privacy_required', __('Devi accettare la privacy policy.'), 422);
        }
        if ($nome === '' || $cognome === '' || $email === '' || $telefono === '' || $indirizzo === '' || $password === '' || $password !== $password2) {
            return ResponseEnvelope::error($response, 'missing_fields', __('Compila tutti i campi obbligatori.'), 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return ResponseEnvelope::error($response, 'invalid_email', __('Indirizzo email non valido.'), 422);
        }
        if (strlen($nome) > 100 || strlen($cognome) > 100) {
            return ResponseEnvelope::error($response, 'name_too_long', __('Nome o cognome troppo lunghi.'), 422);
        }
        if (strlen($password) > 72 || strlen($password) < 8) {
            return ResponseEnvelope::error($response, 'invalid_password', __('La password deve avere tra 8 e 72 caratteri.'), 422);
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return ResponseEnvelope::error($response, 'weak_password', __('La password deve contenere maiuscole, minuscole e numeri.'), 422);
        }

        try {
            $stmt = $this->db->prepare('SELECT id FROM utenti WHERE email = ? LIMIT 1');
            if ($stmt === false) {
                return ResponseEnvelope::error($response, 'internal_error', __('Errore del server.'), 500);
            }
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res    = $stmt->get_result();
            $exists = $res instanceof \mysqli_result && $res->num_rows > 0;
            $stmt->close();
            if ($exists) {
                return ResponseEnvelope::error($response, 'email_exists', __('Esiste già un account con questa email.'), 409);
            }

            $codiceTessera = $this->generateTessera();
            $hash          = password_hash($password, PASSWORD_DEFAULT);
            $token         = bin2hex(random_bytes(24));

            $this->db->query("SET SESSION time_zone = '+00:00'");
            $scadenzaTessera = gmdate('Y-m-d', (int) strtotime('+5 years'));
            $scadenzaToken   = gmdate('Y-m-d H:i:s', time() + 24 * 60 * 60);
            $accettazione    = gmdate('Y-m-d H:i:s');
            $privacyVersion  = '1.0';
            $stato           = 'sospeso';   // requires admin approval (same as web)
            $ruolo           = 'standard';

            $columns = 'nome, cognome, email, password, telefono, indirizzo, codice_tessera, stato, tipo_utente, email_verificata, token_verifica_email, data_token_verifica, data_scadenza_tessera, privacy_accettata, data_accettazione_privacy, privacy_policy_version';
            $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 1, ?, ?';
            $types  = 'ssssssssssssss';
            $values = [
                $nome, $cognome, $email, $hash, $telefono, $indirizzo, $codiceTessera,
                $stato, $ruolo, $token, $scadenzaToken, $scadenzaTessera, $accettazione, $privacyVersion,
            ];

            $stmt = $this->db->prepare("INSERT INTO utenti ({$columns}) VALUES ({$placeholders})");
            if ($stmt === false) {
                return ResponseEnvelope::error($response, 'internal_error', __('Errore del server.'), 500);
            }
            $stmt->bind_param($types, ...$values);
            if (!$stmt->execute()) {
                $stmt->close();
                return ResponseEnvelope::error($response, 'internal_error', __('Errore durante la registrazione.'), 500);
            }
            $userId = (int) $stmt->insert_id;
            $stmt->close();

            // Reuse the web email-verification flow verbatim.
            $notifications = new NotificationService($this->db);
            $notifications->sendUserRegistrationPending($userId);
            $notifications->notifyNewUserRegistration($userId);
            $notifications->notifyNewUserInApp($userId, $nome . ' ' . $cognome, $email);

            $data = [
                'user_id'             => $userId,
                'email_verification'  => true,
                'requires_approval'   => true,
            ];

            return ResponseEnvelope::success(
                $response,
                $data,
                ['message' => __('Registrazione completata. Controlla la tua email per confermare l\'account.')],
                201
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] register failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore durante la registrazione.'), 500);
        }
    }

    // ─── POST /auth/forgot-password ─────────────────────────────────────────

    public function forgotPassword(Request $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->appAccessEnabled()) {
            return ResponseEnvelope::error($response, 'app_access_disabled', __('L\'accesso da app non è abilitato su questa istanza.'), 403);
        }

        $body  = JsonBody::parse($request);
        $email = trim((string) ($body['email'] ?? ''));

        // Always answer the same way (no account enumeration). Validation failures
        // and unknown emails alike produce the generic "if it exists, we sent it".
        $generic = ResponseEnvelope::success(
            $response,
            null,
            ['message' => __('Se l\'indirizzo è registrato, riceverai un\'email con le istruzioni.')],
            200
        );

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $generic;
        }

        // Reuse the web reset throttle key so app + web share the same budget.
        if (RateLimiter::isLimited('forgot_password:' . strtolower($email))) {
            return ResponseEnvelope::error($response, 'rate_limited', __('Troppe richieste. Riprova più tardi.'), 429);
        }

        try {
            $this->db->query("SET SESSION time_zone = '+00:00'");

            $stmt = $this->db->prepare('SELECT id, nome, cognome FROM utenti WHERE LOWER(email) = LOWER(?) LIMIT 1');
            if ($stmt === false) {
                return $generic;
            }
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($row !== null) {
                $resetToken = bin2hex(random_bytes(32));
                $tokenHash  = hash('sha256', $resetToken);
                $expiresAt  = gmdate('Y-m-d H:i:s', time() + 2 * 60 * 60); // 2h, same as web

                $upd = $this->db->prepare('UPDATE utenti SET token_reset_password = ?, data_token_reset = ? WHERE id = ?');
                if ($upd !== false) {
                    $userId = (int) $row['id'];
                    $upd->bind_param('ssi', $tokenHash, $expiresAt, $userId);
                    $upd->execute();
                    $upd->close();

                    $this->sendResetEmail($email, trim((string) ($row['nome'] ?? '') . ' ' . (string) ($row['cognome'] ?? '')), $resetToken);
                }
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] forgot-password failed: ' . $e->getMessage());
            // Still answer generically — never leak whether the email existed.
        }

        return $generic;
    }

    // ─── POST /auth/logout ──────────────────────────────────────────────────

    public function logout(Request $request, ResponseInterface $response): ResponseInterface
    {
        $user    = $this->currentUser($request);
        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
        if ($user === null || !is_int($tokenId)) {
            return ResponseEnvelope::error($response, 'unauthorized', __('Non autenticato.'), 401);
        }

        (new TokenService($this->db))->revokeOwn($tokenId, $user['id']);

        return ResponseEnvelope::success($response, null, ['message' => __('Disconnesso.')], 200);
    }

    // ─── GET /me/devices ────────────────────────────────────────────────────

    public function listDevices(Request $request, ResponseInterface $response): ResponseInterface
    {
        $user    = $this->currentUser($request);
        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
        if ($user === null) {
            return ResponseEnvelope::error($response, 'unauthorized', __('Non autenticato.'), 401);
        }

        $devices = (new TokenService($this->db))->listDevices(
            $user['id'],
            is_int($tokenId) ? $tokenId : null
        );

        return ResponseEnvelope::success($response, $devices, ['count' => count($devices)], 200);
    }

    // ─── DELETE /me/devices/{id} ────────────────────────────────────────────

    public function revokeDevice(Request $request, ResponseInterface $response, int $deviceId): ResponseInterface
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return ResponseEnvelope::error($response, 'unauthorized', __('Non autenticato.'), 401);
        }

        // revokeOwn scopes by user_id → a user can only ever revoke their OWN
        // device (data isolation). A foreign / unknown id yields false → 404.
        $ok = (new TokenService($this->db))->revokeOwn($deviceId, $user['id']);
        if (!$ok) {
            return ResponseEnvelope::error($response, 'not_found', __('Dispositivo non trovato.'), 404);
        }

        return ResponseEnvelope::success($response, null, ['message' => __('Dispositivo revocato.')], 200);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * @return array{id:int, email:string, tipo_utente:string, nome:string, cognome:string, locale:?string, stato:string}|null
     */
    private function currentUser(Request $request): ?array
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);

        return is_array($user) && isset($user['id']) ? $user : null;
    }

    /**
     * Registration discovery flag — mirrors HealthController: a dedicated
     * `registration.enabled` setting if present, else "open unless private mode".
     */
    /**
     * Same gate AppAuthMiddleware enforces on the authenticated surface
     * (system_settings mobile_api.enabled), re-read per request here because
     * the public auth endpoints run without that middleware.
     */
    private function appAccessEnabled(): bool
    {
        // Read system_settings directly: ConfigStore only surfaces a hardcoded
        // whitelist of core categories, so ConfigStore::get('mobile_api.enabled')
        // would ALWAYS return the default '0' and 403 every login (the same trap
        // MobileApiPlugin::isAppAccessEnabled() documents — same query as
        // book-club's MobileModule::appAccessEnabled()).
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM system_settings WHERE category = 'mobile_api' AND setting_key = 'enabled' LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            return $row !== null && (string) $row['setting_value'] === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function registrationEnabled(): bool
    {
        $setting = ConfigStore::get('registration.enabled', null);
        if ($setting !== null) {
            return (string) $setting === '1';
        }
        $privateMode = (string) ConfigStore::get('advanced.private_mode', '0') === '1';

        return !$privateMode;
    }

    private function sendResetEmail(string $email, string $name, string $resetToken): void
    {
        $envUrl = getenv('APP_CANONICAL_URL') ?: ($_ENV['APP_CANONICAL_URL'] ?? '');
        if (is_string($envUrl) && $envUrl !== '') {
            $resetUrl = rtrim($envUrl, '/') . RouteTranslator::route('reset_password') . '?token=' . urlencode($resetToken);
        } else {
            $resetUrl = absoluteUrl(RouteTranslator::route('reset_password')) . '?token=' . urlencode($resetToken);
        }

        $safeName = htmlspecialchars($name !== '' ? $name : $email, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        $subject = __('Recupera la tua password');
        $html = '<h2>' . __('Recupera la tua password') . '</h2>' .
            '<p>' . __('Ciao') . ' ' . $safeName . ',</p>' .
            '<p>' . __('Abbiamo ricevuto una richiesta di reset della password per il tuo account.') . '</p>' .
            '<p style="margin: 20px 0;"><a href="' . $safeUrl . '" style="background-color: #111827; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;">' . __('Resetta Password') . '</a></p>' .
            '<p><code style="background-color: #f3f4f6; padding: 10px; display: block; word-break: break-all;">' . $safeUrl . '</code></p>' .
            '<p><strong>' . __('Nota:') . '</strong> ' . __('Questo link scadrà tra 2 ore.') . '</p>' .
            '<p>' . __('Se non hai richiesto il reset della password, puoi ignorare questa email. Il tuo account rimane sicuro.') . '</p>';

        try {
            (new EmailService($this->db))->sendEmail($email, $subject, $html, $name);
        } catch (\Throwable $e) {
            SecureLogger::warning('[MobileApi] reset email via EmailService failed, falling back: ' . $e->getMessage());
            Mailer::send($email, $subject, $html);
        }
    }

    /**
     * Produce a throwaway bcrypt hash of random bytes for constant-time
     * verification on the no-such-user path. Never persisted, never a credential.
     */
    /** Process-cached dummy bcrypt hash (computed once, see dummyHash()). */
    private static ?string $dummyHash = null;

    private static function dummyHash(): string
    {
        // A valid, non-matching bcrypt hash used to keep the no-such-user path's
        // timing close to the wrong-password path. Computed ONCE per process and
        // cached — not a fresh password_hash() per call — so a flood of
        // unknown-email logins can't force a bcrypt computation on every request
        // (CPU-exhaustion vector). It is still a real bcrypt hash, so
        // password_verify() does the full work (constant-time) and always fails.
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    }

    private function generateTessera(): string
    {
        // Unique card code, retrying on the (unique) collision — same shape as the
        // web generator (BIB + year + random) but self-contained for the plugin.
        for ($i = 0; $i < 10; $i++) {
            $candidate = 'BIB' . date('Y') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $stmt = $this->db->prepare('SELECT 1 FROM utenti WHERE codice_tessera = ? LIMIT 1');
            if ($stmt === false) {
                return $candidate;
            }
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            $res   = $stmt->get_result();
            $taken = $res instanceof \mysqli_result && $res->num_rows > 0;
            $stmt->close();
            if (!$taken) {
                return $candidate;
            }
        }

        return 'BIB' . date('Y') . bin2hex(random_bytes(4));
    }
}
