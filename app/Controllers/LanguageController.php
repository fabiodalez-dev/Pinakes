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
        $locale = $args['locale'] ?? 'it_IT';

        // Validate locale
        $availableLocales = I18n::getAvailableLocales();
        if (!isset($availableLocales[$locale])) {
            $locale = 'it_IT';
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

        // Get referrer to redirect back
        $referrer = $request->getServerParams()['HTTP_REFERER'] ?? '/';

        // Redirect back
        return $response
            ->withHeader('Location', $referrer)
            ->withStatus(302);
    }
}
