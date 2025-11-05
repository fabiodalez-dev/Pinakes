<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Csrf;
use App\Support\PluginManager;
use App\Support\HtmlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Plugin Controller
 *
 * Handles plugin management: listing, installation, activation, deactivation, and uninstallation
 */
class PluginController
{
    private PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Show plugins list page
     */
    public function index(Request $request, Response $response): Response
    {
        // Check authorization
        if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
            return $response->withStatus(403)->withHeader('Location', '/admin/dashboard');
        }

        $plugins = $this->pluginManager->getAllPlugins();

        // Render view
        ob_start();
        require __DIR__ . '/../Views/admin/plugins.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Handle plugin upload and installation
     */
    public function upload(Request $request, Response $response): Response
    {
        try {
            // Check authorization
            if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Non autorizzato.'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Verify CSRF token (handle multipart/form-data)
            $body = $request->getParsedBody();
            $csrfToken = $body['csrf_token'] ?? $_POST['csrf_token'] ?? '';

            if (!Csrf::validate($csrfToken)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Token CSRF non valido.'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            $uploadedFiles = $request->getUploadedFiles();

            if (!isset($uploadedFiles['plugin_file'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'File non trovato nell\'upload.'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $uploadError = $uploadedFiles['plugin_file']->getError();
            if ($uploadError !== UPLOAD_ERR_OK) {
                error_log("[Plugin Upload] Upload error code: $uploadError");
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Errore durante il caricamento del file (code: ' . $uploadError . ').'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $uploadedFile = $uploadedFiles['plugin_file'];

            // Validate file type
            $filename = $uploadedFile->getClientFilename();
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if ($extension !== 'zip') {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Solo file ZIP sono accettati.'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Save uploaded file temporarily
            $uploadsDir = __DIR__ . '/../../uploads/plugins';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }

            $tempPath = $uploadsDir . '/' . uniqid('plugin_', true) . '.zip';
            $uploadedFile->moveTo($tempPath);

            // Install plugin
            $result = $this->pluginManager->installFromZip($tempPath);

            // Delete temporary file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            error_log("[Plugin Upload] Exception: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Errore interno: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Activate a plugin
     */
    public function activate(Request $request, Response $response, array $args): Response
    {
        // Check authorization
        if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Non autorizzato.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Verify CSRF token
        $body = $request->getParsedBody();
        if (!Csrf::validate($body['csrf_token'] ?? '')) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Token CSRF non valido.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int)$args['id'];
        $result = $this->pluginManager->activatePlugin($pluginId);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Deactivate a plugin
     */
    public function deactivate(Request $request, Response $response, array $args): Response
    {
        // Check authorization
        if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Non autorizzato.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Verify CSRF token
        $body = $request->getParsedBody();
        if (!Csrf::validate($body['csrf_token'] ?? '')) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Token CSRF non valido.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int)$args['id'];
        $result = $this->pluginManager->deactivatePlugin($pluginId);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Uninstall a plugin
     */
    public function uninstall(Request $request, Response $response, array $args): Response
    {
        // Check authorization
        if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Non autorizzato.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Verify CSRF token
        $body = $request->getParsedBody();
        if (!Csrf::validate($body['csrf_token'] ?? '')) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Token CSRF non valido.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int)$args['id'];
        $result = $this->pluginManager->uninstallPlugin($pluginId);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get plugin details
     */
    public function details(Request $request, Response $response, array $args): Response
    {
        // Check authorization
        if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Non autorizzato.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int)$args['id'];
        $plugin = $this->pluginManager->getPlugin($pluginId);

        if (!$plugin) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Plugin non trovato.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'plugin' => $plugin
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
