<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\ThemeManager;
use App\Support\ThemeColorizer;
use App\Support\Csrf;
use App\Support\HtmlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ThemeController
{
    private ThemeManager $themeManager;
    private ThemeColorizer $themeColorizer;

    public function __construct(
        ThemeManager $themeManager,
        ThemeColorizer $themeColorizer
    ) {
        $this->themeManager = $themeManager;
        $this->themeColorizer = $themeColorizer;
    }

    /**
     * Display list of all themes
     */
    public function index(Request $request, Response $response): Response
    {
        // Check authorization
        if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
            return $response->withStatus(403)->withHeader('Location', '/admin/dashboard');
        }

        $themes = $this->themeManager->getAllThemes();
        $activeTheme = $this->themeManager->getActiveTheme();
        $pageTitle = __('Gestione Temi');

        // Render view
        ob_start();
        require __DIR__ . '/../Views/admin/themes.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Display theme customization page
     */
    public function customize(Request $request, Response $response, array $args): Response
    {
        // Check authorization
        if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
            return $response->withStatus(403)->withHeader('Location', '/admin/dashboard');
        }

        $themeId = (int)($args['id'] ?? 0);
        $theme = $this->themeManager->getThemeById($themeId);

        if (!$theme) {
            $_SESSION['error'] = __('Tema non trovato');
            return $response
                ->withHeader('Location', '/admin/themes')
                ->withStatus(302);
        }

        $settings = json_decode($theme['settings'], true) ?? [];
        $colors = $settings['colors'] ?? [];
        $advanced = $settings['advanced'] ?? [];
        $pageTitle = __('Personalizza Tema') . ': ' . $theme['name'];

        // Render view
        ob_start();
        require __DIR__ . '/../Views/admin/theme-customize.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Save theme colors and settings
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        $parsedBody = $request->getParsedBody();
        $token = $parsedBody['csrf_token'] ?? '';

        if (!Csrf::validateToken($token)) {
            $_SESSION['error'] = __('Token CSRF non valido');
            return $response
                ->withHeader('Location', '/admin/themes')
                ->withStatus(302);
        }

        $themeId = (int)($args['id'] ?? 0);
        $theme = $this->themeManager->getThemeById($themeId);

        if (!$theme) {
            $_SESSION['error'] = __('Tema non trovato');
            return $response
                ->withHeader('Location', '/admin/themes')
                ->withStatus(302);
        }

        // Get submitted colors
        $colors = $parsedBody['colors'] ?? [];

        // Validate colors
        foreach ($colors as $key => $color) {
            if (!$this->themeColorizer->isValidHex($color)) {
                $_SESSION['error'] = __('Colore non valido') . ': ' . $key;
                return $response
                    ->withHeader('Location', '/admin/themes/' . $themeId . '/customize')
                    ->withStatus(302);
            }

            // Normalize colors
            $colors[$key] = $this->themeColorizer->normalizeHex($color);
        }

        // Check button contrast (minimum WCAG AA Large Text: 3:1)
        if (isset($colors['button']) && isset($colors['button_text'])) {
            $contrastRatio = $this->themeColorizer->getContrastRatio($colors['button_text'], $colors['button']);

            if ($contrastRatio < 3.0) {
                $_SESSION['error'] = __('Contrasto insufficiente tra bottone e testo (minimo 3:1). Attuale') . ': ' . number_format($contrastRatio, 2) . ':1';
                return $response
                    ->withHeader('Location', '/admin/themes/' . $themeId . '/customize')
                    ->withStatus(302);
            }
        }

        // Update theme colors
        $success = $this->themeManager->updateThemeColors($themeId, $colors);

        // Update advanced settings if provided
        if (isset($parsedBody['advanced'])) {
            $advanced = [
                'custom_css' => $parsedBody['advanced']['custom_css'] ?? '',
                'custom_js' => $parsedBody['advanced']['custom_js'] ?? ''
            ];

            // Sanitize custom CSS/JS (basic sanitization - remove <script> tags from CSS)
            $advanced['custom_css'] = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $advanced['custom_css']);

            $this->themeManager->updateAdvancedSettings($themeId, $advanced);
        }

        if ($success) {
            $_SESSION['success'] = __('Tema salvato con successo');
        } else {
            $_SESSION['error'] = __('Errore nel salvataggio del tema');
        }

        return $response
            ->withHeader('Location', '/admin/themes/' . $themeId . '/customize')
            ->withStatus(302);
    }

    /**
     * Activate a theme
     */
    public function activate(Request $request, Response $response, array $args): Response
    {
        $themeId = (int)($args['id'] ?? 0);

        $success = $this->themeManager->activateTheme($themeId);

        $response->getBody()->write(json_encode([
            'success' => $success,
            'message' => $success ? __('Tema attivato con successo') : __('Errore durante l\'attivazione del tema')
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Reset theme colors to defaults
     */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $themeId = (int)($args['id'] ?? 0);

        $success = $this->themeManager->resetThemeColors($themeId);

        $response->getBody()->write(json_encode([
            'success' => $success,
            'message' => $success ? __('Colori ripristinati ai valori predefiniti') : __('Errore nel ripristino dei colori')
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Check contrast ratio between two colors (AJAX endpoint)
     */
    public function checkContrast(Request $request, Response $response): Response
    {
        $parsedBody = $request->getParsedBody();
        $foreground = $parsedBody['fg'] ?? '#000000';
        $background = $parsedBody['bg'] ?? '#ffffff';

        if (!$this->themeColorizer->isValidHex($foreground) || !$this->themeColorizer->isValidHex($background)) {
            $response->getBody()->write(json_encode([
                'error' => 'Invalid color format'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $ratio = $this->themeColorizer->getContrastRatio($foreground, $background);
        $passAA = $this->themeColorizer->isAccessibleAA($foreground, $background);
        $passAAA = $this->themeColorizer->isAccessibleAAA($foreground, $background);

        $response->getBody()->write(json_encode([
            'ratio' => $ratio,
            'passAA' => $passAA,
            'passAAA' => $passAAA
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
