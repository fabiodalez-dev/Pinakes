<?php
declare(strict_types=1);

namespace App\Middleware;

use mysqli;
use App\Support\RememberMeService;
use App\Support\RememberMeSessionRegistry;
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

            return $handler->handle($request);
        }

        // Already signed in — and this is the half that was missing. Marking a
        // device revoked did nothing to the session that device already held,
        // because the only place the row was ever consulted was the branch
        // above, which runs exclusively for requests arriving WITHOUT one. The
        // operator saw "revoked" and the device carried on.
        $service = new RememberMeService($this->db);
        if ($service->boundSessionIsRevoked()) {
            $service->clearCookieForRevokedSession();
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            return $handler->handle($request);
        }

        // Still here, so the row is live: push an ordinary sign-in's row
        // forward while the person keeps working. Its stamp comes from
        // session.gc_maxlifetime, which PHP treats as inactivity and renews on
        // every request — without this the row would act as an absolute
        // deadline and sign an active session out mid-form.
        $service->keepBoundPlainSessionAlive();

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

        // A browser returning with only the remember-me cookie asks for
        // several things at once — the page, and whatever the page fetches for
        // itself. Each of those arrives without a session and reaches this
        // method, and each used to mint its own, with its own CSRF token. The
        // browser keeps the last Set-Cookie it receives, so the token printed
        // into the HTML could belong to a session that no longer has anything
        // to do with it, and the visitor's first form submission was refused.
        // Measured: roughly half of page loads, with the dashboard's own
        // /api/stats/active-loans-count request as the other creator.
        //
        // So a sibling that got here first is joined rather than raced. Note
        // that session_start() below blocks until that sibling releases the
        // session lock, which is the point: what comes back is a session that
        // has already been populated, not a half-written one.
        $tokenPlain = (string) ($_COOKIE['remember_token'] ?? '');
        $joined = $this->joinSiblingSession($tokenPlain);
        if ($joined && isset($_SESSION['user'])) {
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
        if (session_status() === PHP_SESSION_ACTIVE && !$joined) {
            session_regenerate_id(false);
        }

        // Whatever session this request ends up in, that is the one a sibling
        // arriving in the next few milliseconds should join. Written after the
        // regeneration above, so the id recorded is the one the browser will
        // actually be given.
        if ($tokenPlain !== '' && session_status() === PHP_SESSION_ACTIVE) {
            RememberMeSessionRegistry::remember($tokenPlain, (string) session_id());
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

        // Ensure language cache is loaded (middleware may run before bootstrap i18n),
        // then apply the per-user preference or the installation default.
        \App\Support\I18n::loadFromDatabase($this->db);
        $locale = \App\Support\I18n::resolveUserLocale($row['locale'] ?? null);
        \App\Support\I18n::setLocale($locale);
        $_SESSION['locale'] = $locale;
        $_SESSION['user']['locale'] = $locale;

        // Log auto-login for security auditing (no PII logged per GDPR)
        \App\Support\Log::security('login.remember_me', [
            'user_id' => $row['id'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }

    /**
     * Adopt the session a concurrent request already created for this token.
     *
     * Returns true when this request is now inside that session — whether or
     * not the sibling had finished populating it, which the caller checks.
     * Returns false when there was nothing to join, or the note pointed at a
     * session that no longer exists, in which case the caller carries on and
     * creates one as before.
     */
    private function joinSiblingSession(string $tokenPlain): bool
    {
        if ($tokenPlain === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $sibling = RememberMeSessionRegistry::lookup($tokenPlain);
        if ($sibling === null || $sibling === session_id()) {
            return false;
        }

        // Nothing has been written into this request's own session yet — it
        // was created empty moments ago — so discarding it loses nothing.
        $ours = (string) session_id();
        session_write_close();
        session_id($sibling);
        if (!@session_start()) {
            // Could not join; go back to our own session and carry on alone.
            session_id($ours);
            @session_start();
            return false;
        }

        // use_strict_mode refuses an id with no session behind it, leaving us
        // with a fresh empty one under a different id. That is not the sibling
        // we meant to join, so treat it as "nothing to join".
        if ((string) session_id() !== $sibling) {
            return false;
        }

        return true;
    }
}
