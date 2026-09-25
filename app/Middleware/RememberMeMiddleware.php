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

        // Verify user is still active and verified. This runs before anything
        // else this method can do, joining a sibling's session included: the
        // token row says the device is still trusted, it says nothing about
        // whether the account still is, and a suspended one must not be served
        // by any path through here.
        if (((int) ($row['email_verificata'] ?? 0)) !== 1) {
            return;
        }
        if (($row['stato'] ?? '') !== 'attivo') {
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
        // that session_start() inside blocks until that sibling releases the
        // session lock, which is the point: what comes back is a session that
        // has already been populated, not a half-written one.
        $tokenPlain = (string) ($_COOKIE['remember_token'] ?? '');
        $boundRow = isset($_SESSION[RememberMeService::SESSION_ROW_KEY])
            ? (int) $_SESSION[RememberMeService::SESSION_ROW_KEY]
            : 0;

        $joined = $this->joinSession(RememberMeSessionRegistry::lookup($tokenPlain), $userId, $boundRow);
        if ($joined === self::JOIN_READY) {
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
        if (session_status() === PHP_SESSION_ACTIVE && $joined === self::JOIN_NOTHING) {
            session_regenerate_id(false);
        }

        // Whatever session this request ends up in, that is the one a sibling
        // arriving in the next few milliseconds should join. Written after the
        // regeneration above, so the id recorded is the one the browser will
        // actually be given — and answered by whoever published first, which
        // may be a sibling that beat us between the lookup above and here. In
        // that case we join it now instead of publishing a second id.
        if ($tokenPlain !== '' && session_status() === PHP_SESSION_ACTIVE) {
            $published = RememberMeSessionRegistry::claim($tokenPlain, (string) session_id());
            if ($published !== null
                && $published !== session_id()
                && $this->joinSession($published, $userId, $boundRow) === self::JOIN_READY) {
                return;
            }
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

    /** Still in this request's own session: there was nothing to join. */
    private const JOIN_NOTHING = 0;

    /** Now inside the sibling's session, which nobody has filled in yet. */
    private const JOIN_SHARED = 1;

    /** Now inside the sibling's session, and it already holds this user. */
    private const JOIN_READY = 2;

    /**
     * Move into the session a concurrent request created for this same token.
     *
     * Answers JOIN_READY only when this request is now inside a session that
     * already holds this very user and is fit to serve; JOIN_SHARED when the
     * move succeeded but the sibling had not filled it in, so the caller fills
     * it in without regenerating the id out from under the sibling; and
     * JOIN_NOTHING when there was nothing to join, the note pointed at a
     * session that no longer exists, or what came back belongs to somebody
     * else — in which case this request is back in its own session and carries
     * on exactly as it did before any of this existed.
     */
    private function joinSession(?string $sibling, int $userId, int $boundRow): int
    {
        if ($sibling === null || session_status() !== PHP_SESSION_ACTIVE) {
            return self::JOIN_NOTHING;
        }
        if ($sibling === session_id()) {
            return self::JOIN_NOTHING;
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
            return self::JOIN_NOTHING;
        }

        // use_strict_mode refuses an id with no session behind it, leaving us
        // with a fresh empty one under a different id. That is not the sibling
        // we meant to join, so treat it as "nothing to join".
        if ((string) session_id() !== $sibling) {
            return self::JOIN_NOTHING;
        }

        // The note is named after a hash of the token, so it can only ever
        // point at a session this same cookie opened — but this request is
        // about to serve whatever identity it finds in there, so the identity
        // is checked rather than assumed.
        $occupant = $_SESSION['user']['id'] ?? null;
        if ($occupant !== null && (int) $occupant !== $userId) {
            // Somebody else's. Leave it untouched and go back to our own
            // rather than writing this user over it.
            session_write_close();
            session_id($ours);
            @session_start();

            return self::JOIN_NOTHING;
        }

        // validateToken() bound this request's session to its user_sessions
        // row, and that binding stayed behind in the session just abandoned.
        // Without it here, revoking the device would have nothing to notice.
        if ($boundRow > 0 && empty($_SESSION[RememberMeService::SESSION_ROW_KEY])) {
            $_SESSION[RememberMeService::SESSION_ROW_KEY] = $boundRow;
        }

        return $occupant === null ? self::JOIN_SHARED : self::JOIN_READY;
    }
}
