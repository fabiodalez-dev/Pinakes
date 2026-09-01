<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\I18n;

class LanguageController
{
    /**
     * Switch language
     */
    public function switchLanguage(Request $request, Response $response, mysqli $db, array $args): Response
    {
        $locale = I18n::normalizeLocaleCode((string)($args['locale'] ?? 'it_IT'));

        // Validate locale
        $availableLocales = I18n::getAvailableLocales();
        if (!isset($availableLocales[$locale])) {
            $locale = I18n::getLocale();
        }

        // Set locale in I18n
        I18n::setLocale($locale);

        // Save in session — but only when one is already open (logged-in
        // users and cookie-bearing visitors). A sessionless anonymous visitor
        // (issue #387 step 6) must NOT be handed a session just to remember
        // the language: the choice is persisted in the pinakes_locale cookie
        // below, which public/index.php reads on the no-session path.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['locale'] = $locale;
        }

        // Save to database if user is logged in
        if (isset($_SESSION['user']['id'])) {
            $userId = (int)$_SESSION['user']['id'];
            $stmt = $db->prepare("UPDATE utenti SET locale = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $locale, $userId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['user']['locale'] = $locale;
            }
        }

        // Persist the choice in a cookie so the anonymous no-session path
        // (issue #387 step 6) resolves the locale deterministically without a
        // session. $locale is normalized and validated against the available
        // locales above, so the value is always a safe xx_XX code. HTTPS
        // detection mirrors AuthController::loginForm() (honours
        // X-Forwarded-Proto/-Ssl behind TLS-terminating proxies).
        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $forwardedSsl = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || $forwardedProto === 'https'
            || $forwardedSsl === 'on';
        $basePath = rtrim(\App\Support\HtmlHelper::getBasePath(), '/');
        // No trailing slash: a cookie Path of "/subfolder/" fails RFC 6265
        // path-matching for the base URL itself ("/subfolder"), so the locale
        // cookie would not be sent on the app root of a subdirectory install.
        // "/subfolder" matches "/subfolder", "/subfolder/" and "/subfolder/*"
        // without leaking to a sibling like "/subfolderX".
        $cookiePath = $basePath === '' ? '/' : $basePath;
        $cookie = 'pinakes_locale=' . rawurlencode($locale)
            . '; Path=' . $cookiePath
            . '; Max-Age=31536000; SameSite=Lax; HttpOnly' . ($secure ? '; Secure' : '');

        // Determine safe redirect target
        $queryParams = $request->getQueryParams();
        $redirect = $this->sanitizeRedirect($queryParams['redirect'] ?? '/');

        return $response
            ->withAddedHeader('Set-Cookie', $cookie)
            ->withHeader('Location', $redirect)
            ->withStatus(302);
    }

    /**
     * Ensure redirect targets stay within the application
     */
    private function sanitizeRedirect($redirect): string
    {
        if (!is_string($redirect) || $redirect === '') {
            return '/';
        }

        if (str_contains($redirect, "\n") || str_contains($redirect, "\r")) {
            return '/';
        }

        if (preg_match('#^(?:[a-z]+:)?//#i', $redirect)) {
            return '/';
        }

        if ($redirect[0] !== '/') {
            return '/';
        }

        // Reject backslash- or double-slash-prefixed paths (e.g. "/\evil.com")
        // that browsers normalise into a cross-origin redirect.
        if (preg_match('#^/[\\\\/]#', $redirect)) {
            return '/';
        }

        return $redirect;
    }
}
