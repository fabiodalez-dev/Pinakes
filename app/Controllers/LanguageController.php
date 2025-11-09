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

        // Save in session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['locale'] = $locale;

        // Save to database if user is logged in
        if (isset($_SESSION['user']['id'])) {
            $userId = (int)$_SESSION['user']['id'];
            $stmt = $db->prepare("UPDATE utenti SET locale = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $locale, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Determine safe redirect target
        $queryParams = $request->getQueryParams();
        $redirect = $this->sanitizeRedirect($queryParams['redirect'] ?? '/');

        return $response
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

        return $redirect[0] === '/' ? $redirect : '/';
    }
}
