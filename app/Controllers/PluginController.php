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
        $pluginSettings = [];
        foreach ($plugins as $plugin) {
            $settings = $this->pluginManager->getSettings((int) $plugin['id']);

            // Handle Google Books API key
            if (array_key_exists('google_books_api_key', $settings)) {
                $settings['google_books_api_key_exists'] = $settings['google_books_api_key'] !== '';
                unset($settings['google_books_api_key']);
            }

            // Handle API Book Scraper settings - never expose the actual API key
            if ($plugin['name'] === 'api-book-scraper' && array_key_exists('api_key', $settings)) {
                $settings['api_key_exists'] = $settings['api_key'] !== '' && $settings['api_key'] !== '••••••••';
                $settings['api_key'] = $settings['api_key_exists'] ? '••••••••' : '';
            }

            $pluginSettings[$plugin['id']] = $settings;
        }

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
                    'message' => __('Non autorizzato.')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Verify CSRF token (handle multipart/form-data)
            $body = $request->getParsedBody();
            $csrfToken = $body['csrf_token'] ?? $_POST['csrf_token'] ?? '';

            if (!Csrf::validate($csrfToken)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Token CSRF non valido.')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            $uploadedFiles = $request->getUploadedFiles();

            if (!isset($uploadedFiles['plugin_file'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('File non trovato nell\'upload.')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $uploadError = $uploadedFiles['plugin_file']->getError();
            if ($uploadError !== UPLOAD_ERR_OK) {
                error_log("[Plugin Upload] Upload error code: $uploadError");
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Errore durante il caricamento del file (code: %s).', $uploadError)
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
                    'message' => __('Solo file ZIP sono accettati.')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Save uploaded file temporarily
            $uploadsDir = __DIR__ . '/../../storage/uploads/plugins';
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
                'message' => __('Errore interno: %s', $e->getMessage())
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
                'message' => __('Non autorizzato.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Verify CSRF token
        $body = $request->getParsedBody();
        if (!Csrf::validate($body['csrf_token'] ?? '')) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Token CSRF non valido.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int) $args['id'];
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
                'message' => __('Non autorizzato.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Verify CSRF token
        $body = $request->getParsedBody();
        if (!Csrf::validate($body['csrf_token'] ?? '')) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Token CSRF non valido.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int) $args['id'];
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
                'message' => __('Non autorizzato.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        // Verify CSRF token
        $body = $request->getParsedBody();
        if (!Csrf::validate($body['csrf_token'] ?? '')) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Token CSRF non valido.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int) $args['id'];
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
                'message' => __('Non autorizzato.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int) $args['id'];
        $plugin = $this->pluginManager->getPlugin($pluginId);

        if (!$plugin) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Plugin non trovato.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'plugin' => $plugin
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Update plugin settings (limited to supported plugins)
     */
    public function updateSettings(Request $request, Response $response, array $args): Response
    {
        error_log('[PluginController] updateSettings called');

        if (!isset($_SESSION['user']) || $_SESSION['user']['tipo_utente'] !== 'admin') {
            error_log('[PluginController] Unauthorized access attempt');
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Non autorizzato.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $body = $request->getParsedBody();
        error_log('[PluginController] Request body: ' . json_encode($body));

        if (!Csrf::validate($body['csrf_token'] ?? '')) {
            error_log('[PluginController] Invalid CSRF token');
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Token CSRF non valido.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $pluginId = (int) $args['id'];
        error_log('[PluginController] Plugin ID: ' . $pluginId);

        $plugin = $this->pluginManager->getPlugin($pluginId);

        if (!$plugin) {
            error_log('[PluginController] Plugin not found: ' . $pluginId);
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Plugin non trovato.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        error_log('[PluginController] Plugin name: ' . $plugin['name']);

        $settings = $body['settings'] ?? [];
        if (!is_array($settings)) {
            error_log('[PluginController] Invalid settings format');
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Formato impostazioni non valido.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Handle settings based on plugin type
        if ($plugin['name'] === 'open-library') {
            // Open Library: Google Books API key
            $apiKey = trim((string) ($settings['google_books_api_key'] ?? ''));
            $apiKeyLength = strlen($apiKey);
            error_log('[PluginController] Google Books API key length: ' . $apiKeyLength);

            $saveResult = $this->pluginManager->setSetting($pluginId, 'google_books_api_key', $apiKey, false);
            error_log('[PluginController] Save result: ' . ($saveResult ? 'true' : 'false'));

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $apiKey !== ''
                    ? __('Chiave Google Books salvata correttamente.')
                    : __('Chiave Google Books rimossa.'),
                'data' => [
                    'google_books_api_key' => $apiKey !== '' ? 'saved' : 'removed',
                    'key_length' => $apiKeyLength
                ]
            ]));
        } elseif ($plugin['name'] === 'api-book-scraper') {
            // API Book Scraper: endpoint, api_key, timeout, enabled
            $apiEndpoint = trim((string) ($settings['api_endpoint'] ?? ''));
            $apiKey = trim((string) ($settings['api_key'] ?? ''));
            $timeout = max(5, min(60, (int) ($settings['timeout'] ?? 10)));
            $enabled = isset($settings['enabled']) && $settings['enabled'] === '1';

            error_log('[PluginController] API Book Scraper settings - endpoint: ' . $apiEndpoint . ', timeout: ' . $timeout . ', enabled: ' . ($enabled ? 'yes' : 'no'));

            // Save all settings
            $this->pluginManager->setSetting($pluginId, 'api_endpoint', $apiEndpoint, true);
            $this->pluginManager->setSetting($pluginId, 'api_key', $apiKey, true);
            $this->pluginManager->setSetting($pluginId, 'timeout', (string) $timeout, true);
            $this->pluginManager->setSetting($pluginId, 'enabled', $enabled ? '1' : '0', true);

            // Load the plugin instance to re-register hooks
            $pluginPath = $this->pluginManager->getPluginPath($plugin['name']);
            $wrapperFile = $pluginPath . '/wrapper.php';

            if (file_exists($wrapperFile)) {
                $db = $this->db;
                $pluginInstance = require $wrapperFile;

                if ($pluginInstance && method_exists($pluginInstance, 'setPluginId')) {
                    $pluginInstance->setPluginId($pluginId);
                }
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Impostazioni API Book Scraper salvate correttamente.'),
                'data' => [
                    'api_endpoint' => $apiEndpoint !== '' ? 'saved' : 'empty',
                    'api_key' => $apiKey !== '' ? 'saved' : 'empty',
                    'timeout' => $timeout,
                    'enabled' => $enabled
                ]
            ]));
        } elseif ($plugin['name'] === 'z39-server') {
            // Z39.50/SRU Integration settings
            $enableServer = isset($settings['enable_server']) && $settings['enable_server'] === '1';
            $enableClient = isset($settings['enable_client']) && $settings['enable_client'] === '1';
            $servers = $settings['servers'] ?? '[]';

            // Validate JSON
            if (is_string($servers)) {
                $decoded = json_decode($servers, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $servers = '[]';
                }
            } elseif (is_array($servers)) {
                $servers = json_encode($servers);
            } else {
                $servers = '[]';
            }

            $this->pluginManager->setSetting($pluginId, 'enable_server', $enableServer ? '1' : '0', true);
            $this->pluginManager->setSetting($pluginId, 'enable_client', $enableClient ? '1' : '0', true);
            $this->pluginManager->setSetting($pluginId, 'servers', $servers, true);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Impostazioni Z39.50 salvate correttamente.'),
                'data' => [
                    'enable_server' => $enableServer,
                    'enable_client' => $enableClient,
                    'servers_count' => count(json_decode($servers, true))
                ]
            ]));
        } else {
            // Plugin not supported
            error_log('[PluginController] Plugin does not support settings: ' . $plugin['name']);
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Questo plugin non supporta impostazioni personalizzate.')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        error_log('[PluginController] Settings saved successfully');
        return $response->withHeader('Content-Type', 'application/json');
    }
}
