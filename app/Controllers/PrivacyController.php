<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\ConfigStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PrivacyController
{
    public function showPage(Request $request, Response $response, mixed $container = null): Response
    {
        $config = ConfigStore::get('privacy', []);

        $pageTitle = $config['page_title'] ?? 'Privacy Policy';
        $pageContent = $config['page_content'] ?? '<p>La tua privacy è importante per noi.</p>';

        $cookieBannerEnabled = $config['cookie_banner_enabled'] ?? true;

        $appName = ConfigStore::get('app.name', 'Biblioteca');
        $title = $pageTitle . ' - ' . $appName;

        // privacy-page.php will include layout.php which needs $container for theme colors
        ob_start();
        include __DIR__ . '/../Views/frontend/privacy-page.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
