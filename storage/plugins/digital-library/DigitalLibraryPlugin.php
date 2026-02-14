<?php
declare(strict_types=1);

/**
 * Digital Library Plugin
 *
 * Enables eBook (PDF/ePub) and audiobook management with integrated
 * Green Audio Player for audiobook playback.
 *
 * Features:
 * - File upload via Uppy (reusing existing infrastructure)
 * - eBook download buttons (PDF/ePub)
 * - Audiobook player with Green Audio Player
 * - Status badge icons showing digital content availability
 * - Optional and fully disableable
 *
 * @package Pinakes\Plugins\DigitalLibrary
 * @version 1.0.0
 */
class DigitalLibraryPlugin
{
    private ?\mysqli $db = null;
    private ?object $hookManager = null;
    private int $pluginId = 0;
    private array $settings = [];
    private static bool $routesRegistered = false;

    /**
     * Constructor
     *
     * @param \mysqli|null $db Database connection
     * @param object|null $hookManager Hook manager instance
     */
    public function __construct(?\mysqli $db = null, ?object $hookManager = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * Set plugin ID (called by PluginManager after installation)
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
        $this->loadSettings();
        $this->registerHooks();
    }

    /**
     * Load plugin settings from database
     */
    private function loadSettings(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $stmt = $this->db->prepare("
            SELECT setting_key, setting_value
            FROM plugin_settings
            WHERE plugin_id = ?
        ");
        $stmt->bind_param("i", $this->pluginId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }

        $stmt->close();
    }

    /**
     * Plugin activation hook
     */
    public function onActivate(): void
    {
        // Create digital uploads directory if it doesn't exist
        $uploadsDir = __DIR__ . '/../../../public/uploads/digital';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        // Create .htaccess for security
        $htaccess = $uploadsDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "# Protect directory listing\nOptions -Indexes\n");
        }

        // Register hooks in database
        $this->registerHooks();
    }

    /**
     * Plugin deactivation hook
     */
    public function onDeactivate(): void
    {
        // Remove registered hooks from database
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
        $stmt->bind_param("i", $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Plugin installation hook
     */
    public function onInstall(): void
    {
        // Verify database columns exist
        if (!$this->db) {
            return;
        }

        $result = $this->db->query("SHOW COLUMNS FROM libri LIKE 'file_url'");
        if ($result->num_rows === 0) {
            // Add file_url column if missing
            $this->db->query("ALTER TABLE libri ADD COLUMN file_url VARCHAR(500) DEFAULT NULL COMMENT 'eBook file URL' AFTER note_varie");
        }

        $result = $this->db->query("SHOW COLUMNS FROM libri LIKE 'audio_url'");
        if ($result->num_rows === 0) {
            // Add audio_url column if missing
            $this->db->query("ALTER TABLE libri ADD COLUMN audio_url VARCHAR(500) DEFAULT NULL COMMENT 'Audiobook file URL' AFTER file_url");
        }
    }

    /**
     * Plugin uninstallation hook
     */
    public function onUninstall(): void
    {
        // Clean up hooks
        $this->onDeactivate();

        // Note: We don't drop the columns as they might contain data
        // Administrator can manually remove them if needed
    }

    /**
     * Register plugin hooks in database
     */
    private function registerHooks(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $hooks = [
            [
                'hook_name' => 'book.form.digital_fields',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderAdminFormFields',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.detail.digital_buttons',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderFrontendButtons',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.detail.digital_player',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderAudioPlayer',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.badge.digital_icons',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderBadgeIcons',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'app.routes.register',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'registerRoutes',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'assets.head',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'enqueueAssets',
                'priority' => 10,
                'is_active' => 1
            ]
        ];

        foreach ($hooks as $hook) {
            // Check if hook already exists
            $stmt = $this->db->prepare("
                SELECT id FROM plugin_hooks
                WHERE plugin_id = ? AND hook_name = ?
            ");
            $stmt->bind_param("is", $this->pluginId, $hook['hook_name']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                // Insert new hook
                $stmt = $this->db->prepare("
                    INSERT INTO plugin_hooks
                    (plugin_id, hook_name, callback_class, callback_method, priority, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "isssii",
                    $this->pluginId,
                    $hook['hook_name'],
                    $hook['callback_class'],
                    $hook['callback_method'],
                    $hook['priority'],
                    $hook['is_active']
                );
                $stmt->execute();
            }

            $stmt->close();
        }
    }

    /**
     * Register plugin routes (file upload endpoint)
     * Hook: app.routes.register
     *
     * @param \Slim\App $app
     * @return void
     */
    public function registerRoutes($app): void
    {
        if (!$app) {
            return;
        }

        // Prevent duplicate registration
        if (self::$routesRegistered) {
            return;
        }
        self::$routesRegistered = true;

        // Capture plugin instance for use in closures
        $plugin = $this;

        // Register upload endpoint
        $app->post('/admin/plugins/digital-library/upload', function ($request, $response) use ($plugin) {
            return $plugin->handleUploadRequest($request, $response, []);
        });

        // Serve plugin-specific assets without exposing storage/ directly
        $app->get('/plugins/digital-library/assets/{type}/{filename}', function ($request, $response, array $args) use ($plugin) {
            return $plugin->serveAsset($request, $response, $args);
        });

        \App\Support\SecureLogger::debug('[Digital Library Plugin] Routes registered', [
            'routes' => ['/admin/plugins/digital-library/upload', '/plugins/digital-library/assets/{type}/{filename}']
        ]);
    }

    // ========================================================================
    // Hook Callback Methods
    // ========================================================================

    /**
     * Render admin form fields for digital content upload
     * Hook: book.form.digital_fields
     */
    public function renderAdminFormFields(array $book): void
    {
        include __DIR__ . '/views/admin-form-fields.php';
    }

    /**
     * Render frontend download buttons
     * Hook: book.detail.digital_buttons
     */
    public function renderFrontendButtons(array $book): void
    {
        include __DIR__ . '/views/frontend-buttons.php';
    }

    /**
     * Render audio player
     * Hook: book.detail.digital_player
     */
    public function renderAudioPlayer(array $book): void
    {
        if ($this->hasAudiobook($book)) {
            include __DIR__ . '/views/frontend-player.php';
        }
    }

    /**
     * Render badge icons
     * Hook: book.badge.digital_icons
     */
    public function renderBadgeIcons(array $book): void
    {
        include __DIR__ . '/views/badge-icons.php';
    }

    /**
     * Enqueue CSS assets
     * Hook: assets.head
     */
    public function enqueueAssets(): void
    {
        // Green Audio Player CSS (hosted locally to satisfy CSP)
        $cssPath = url('/assets/vendor/green-audio-player/css/green-audio-player.min.css');
        echo '<link rel="stylesheet" href="' . htmlspecialchars($cssPath, ENT_QUOTES, 'UTF-8') . '">' . "\n";

        // Digital Library CSS - only load if file exists in plugin directory
        $pluginCssPath = __DIR__ . '/assets/css/digital-library.css';
        if (file_exists($pluginCssPath)) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars(url('/plugins/digital-library/assets/css/digital-library.css'), ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        // Green Audio Player JS (hosted locally to satisfy CSP)
        $jsPath = url('/assets/vendor/green-audio-player/js/green-audio-player.min.js');
        echo '<script src="' . htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Check if book has eBook file
     */
    private function hasEbook(array $book): bool
    {
        return !empty($book['file_url'] ?? '');
    }

    /**
     * Check if book has audiobook file
     */
    private function hasAudiobook(array $book): bool
    {
        return !empty($book['audio_url'] ?? '');
    }

    /**
     * Get safe file URL
     */
    private function getSafeUrl(?string $url): string
    {
        if (empty($url)) {
            return '';
        }
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Handle AJAX upload request
     */
    public function handleUploadRequest($request, $response, array $args = [])
    {
        // Require admin/staff session
        $user = $_SESSION['user'] ?? null;
        $role = $user['tipo_utente'] ?? '';
        if (!$user || !in_array($role, ['admin', 'staff'], true)) {
            error_log('[Digital Library] Upload rejected: No valid admin/staff session');
            return $this->json($response, ['success' => false, 'message' => __('Accesso negato.')], 403);
        }

        // CSRF validation (using standard App\Support\Csrf class)
        // Read from both parsed body and POST superglobal (for multipart/form-data compatibility)
        $params = (array)$request->getParsedBody();
        $postParams = $_POST;
        $csrfToken = $request->getHeaderLine('X-CSRF-Token') ?: ($params['csrf_token'] ?? $postParams['csrf_token'] ?? '');

        \App\Support\SecureLogger::debug('[Digital Library] Upload request', [
            'user' => $user['username'] ?? 'unknown',
            'type' => $params['digital_type'] ?? $postParams['digital_type'] ?? 'unspecified'
        ]);

        if (!\App\Support\Csrf::validate($csrfToken)) {
            error_log('[Digital Library] Upload rejected: Invalid CSRF token');
            return $this->json($response, ['success' => false, 'message' => __('Token CSRF non valido.')], 400);
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            error_log('[Digital Library] Upload rejected: No file in request');
            return $this->json($response, ['success' => false, 'message' => __('Nessun file caricato.')], 400);
        }

        $type = $params['digital_type']
            ?? $postParams['digital_type']
            ?? $params['type']
            ?? $postParams['type']
            ?? 'ebook';

        if (!in_array($type, ['audio', 'ebook'], true)) {
            $type = 'ebook';
        }
        $file = $uploadedFiles['file'];
        \App\Support\SecureLogger::debug('[Digital Library] Upload processing', [
            'type' => $type,
            'filename' => $file->getClientFilename()
        ]);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['success' => false, 'message' => __('Errore durante il caricamento del file.')], 400);
        }

        // Validate size / mime
        $maxSize = ($type === 'audio') ? (500 * 1024 * 1024) : (50 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            return $this->json($response, ['success' => false, 'message' => __('File troppo grande.')], 400);
        }

        $allowedMime = ($type === 'audio')
            ? ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/x-m4a', 'audio/wav']
            : ['application/pdf', 'application/epub+zip', 'application/octet-stream'];

        $clientMediaType = $file->getClientMediaType();
        if (!in_array($clientMediaType, $allowedMime, true)) {
            // Allow by extension as fallback
            $filename = strtolower($file->getClientFilename());
            $validExt = ($type === 'audio')
                ? ['mp3', 'm4a', 'ogg', 'wav']
                : ['pdf', 'epub'];
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (!in_array($ext, $validExt, true)) {
                return $this->json($response, ['success' => false, 'message' => __('Formato file non supportato.')], 400);
            }
        }

        $uploadsDir = realpath(__DIR__ . '/../../../public/uploads/digital');
        if ($uploadsDir === false) {
            $uploadsDir = __DIR__ . '/../../../public/uploads/digital';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }
        }

        $safeName = $this->generateSafeFilename($file->getClientFilename(), $type);
        $targetPath = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

        try {
            $file->moveTo($targetPath);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => __('Impossibile salvare il file.')], 500);
        }

        $publicUrl = '/uploads/digital/' . $safeName;
        return $this->json($response, [
            'success' => true,
            'uploadURL' => $publicUrl,
            'filename' => $safeName,
            'type' => $type
        ]);
    }

    /**
     * Serve plugin assets (CSS/JS) from storage safely
     */
    public function serveAsset($request, $response, array $args = [])
    {
        $type = $args['type'] ?? '';
        $filename = $args['filename'] ?? '';

        if (!in_array($type, ['css', 'js'], true)) {
            return $response->withStatus(404);
        }

        if (!preg_match('/^[A-Za-z0-9._-]+$/', (string)$filename)) {
            return $response->withStatus(404);
        }

        $baseDir = __DIR__ . '/assets/' . $type;
        $baseRealPath = realpath($baseDir);
        if ($baseRealPath === false) {
            return $response->withStatus(404);
        }

        $filePath = realpath($baseRealPath . DIRECTORY_SEPARATOR . $filename);
        if ($filePath === false || strpos($filePath, $baseRealPath . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
            return $response->withStatus(404);
        }

        $mime = $type === 'css' ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8';
        $response->getBody()->write((string)file_get_contents($filePath));

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=31536000');
    }

    /**
     * Generate safe filename
     */
    private function generateSafeFilename(string $original, string $type): string
    {
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = ($type === 'audio') ? 'mp3' : 'pdf';
        }

        $base = bin2hex(random_bytes(8));
        return date('YmdHis') . '_' . $base . '.' . $ext;
    }

    /**
     * Helper to output JSON response
     */
    private function json($response, array $data, int $status = 200)
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
