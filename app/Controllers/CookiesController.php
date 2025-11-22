<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\ConfigStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CookiesController
{
    public function showPage(Request $request, Response $response, mixed $container = null): Response
    {
        $config = ConfigStore::get('privacy', []);

        $pageContent = $config['cookie_policy_content'] ?? '<p>Questa pagina descrive come utilizziamo i cookie sul nostro sito.</p>';

        $appName = ConfigStore::get('app.name', 'Biblioteca');
        $title = 'Cookie Policy - ' . $appName;

        // cookies-page.php will include layout.php which needs $container for theme colors
        ob_start();
        include __DIR__ . '/../Views/frontend/cookies-page.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
