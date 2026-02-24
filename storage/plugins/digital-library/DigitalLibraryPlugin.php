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
 * @version 1.1.0
 */
class DigitalLibraryPlugin
{
    private ?\mysqli $db = null;
    private int $pluginId = 0;
    private static bool $routesRegistered = false;

    /**
     * Constructor
     *
     * @param \mysqli|null $db Database connection
     * @param object|null $hookManager Hook manager instance (part of plugin API contract)
     *
     * @phpstan-ignore constructor.unusedParameter
     */
    public function __construct(?\mysqli $db = null, ?object $hookManager = null)
    {
        $this->db = $db;
    }

    /**
     * Set plugin ID (called by PluginManager after installation)
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
        $this->registerHooks();
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
            file_put_contents($htaccess, implode("\n", [
                '# Protect directory listing',
                'Options -Indexes',
                '# Deny execution of any server-side scripts',
                '<FilesMatch "\.(php\d?|phtml|phar|pl|py|cgi|sh)$">',
                '    <IfModule mod_authz_core.c>',
                '        Require all denied',
                '    </IfModule>',
                '    <IfModule mod_access_compat.c>',
                '        Order Deny,Allow',
                '        Deny from all',
                '    </IfModule>',
                '</FilesMatch>',
                '',
            ]));
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
        if ($stmt === false) {
            return;
        }
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
        if ($result instanceof \mysqli_result && $result->num_rows === 0) {
            // Add file_url column if missing
            $this->db->query("ALTER TABLE libri ADD COLUMN file_url VARCHAR(500) DEFAULT NULL COMMENT 'eBook file URL' AFTER note_varie");
        }

        $result = $this->db->query("SHOW COLUMNS FROM libri LIKE 'audio_url'");
        if ($result instanceof \mysqli_result && $result->num_rows === 0) {
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
            if (!$stmt) {
                \App\Support\SecureLogger::error('DigitalLibraryPlugin: prepare() failed for hook check', ['hook' => $hook['hook_name']]);
                continue;
            }
            $stmt->bind_param("is", $this->pluginId, $hook['hook_name']);
            $stmt->execute();
            $result = $stmt->get_result();
            if (!$result instanceof \mysqli_result) {
                \App\Support\SecureLogger::error('DigitalLibraryPlugin: get_result() failed for hook check', ['hook' => $hook['hook_name']]);
                $stmt->close();
                continue;
            }

            if ($result->num_rows === 0) {
                $stmt->close(); // close SELECT stmt before reassignment
                // Insert new hook
                $stmt = $this->db->prepare("
                    INSERT INTO plugin_hooks
                    (plugin_id, hook_name, callback_class, callback_method, priority, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                if (!$stmt) {
                    \App\Support\SecureLogger::error('DigitalLibraryPlugin: prepare() failed for hook insert', ['hook' => $hook['hook_name']]);
                    continue;
                }
                $stmt->bind_param(
                    "isssii",
                    $this->pluginId,
                    $hook['hook_name'],
                    $hook['callback_class'],
                    $hook['callback_method'],
                    $hook['priority'],
                    $hook['is_active']
                );
                if (!$stmt->execute()) {
                    \App\Support\SecureLogger::error('[Digital Library] Hook insert failed', [
                        'hook' => $hook['hook_name'],
                        'error' => $stmt->error,
                    ]);
                }
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
        if (!empty($book['audio_url'])) {
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
    // Upload Handler
    // ========================================================================

    /**
     * Handle AJAX upload request
     */
    public function handleUploadRequest($request, $response, array $args = [])
    {
        // Require admin/staff session
        $user = $_SESSION['user'] ?? null;
        $role = $user['tipo_utente'] ?? '';
        if (!$user || !in_array($role, ['admin', 'staff'], true)) {
            \App\Support\SecureLogger::warning('[Digital Library] Upload rejected: No valid admin/staff session');
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
            \App\Support\SecureLogger::warning('[Digital Library] Upload rejected: Invalid CSRF token');
            return $this->json($response, ['success' => false, 'message' => __('Token CSRF non valido.')], 400);
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            \App\Support\SecureLogger::warning('[Digital Library] Upload rejected: No file in request');
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
            $errorCode = $file->getError();
            \App\Support\SecureLogger::warning('[Digital Library] Upload failed', [
                'error_code' => $errorCode,
                'filename' => $file->getClientFilename(),
                'php_upload_max' => ini_get('upload_max_filesize') ?: 'unknown',
                'php_post_max' => ini_get('post_max_size') ?: 'unknown'
            ]);

            $message = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                    __('Il file supera il limite di upload di PHP (%s). Aumenta upload_max_filesize e post_max_size nella configurazione PHP.'),
                    ini_get('upload_max_filesize') ?: '?'
                ),
                UPLOAD_ERR_PARTIAL => __('Il file è stato caricato solo parzialmente. Riprova.'),
                UPLOAD_ERR_NO_FILE => __('Nessun file caricato.'),
                UPLOAD_ERR_NO_TMP_DIR => __('Cartella temporanea mancante sul server. Contatta l\'amministratore di sistema.'),
                UPLOAD_ERR_CANT_WRITE => __('Impossibile scrivere il file su disco. Controlla i permessi della cartella temporanea.'),
                default => __('Errore durante il caricamento del file.') . ' (code: ' . $errorCode . ')',
            };

            $status = match ($errorCode) {
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 500,
                default => 400,
            };

            return $this->json($response, ['success' => false, 'message' => $message], $status);
        }

        // Validate size / mime
        $maxSize = ($type === 'audio') ? (500 * 1024 * 1024) : (100 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            return $this->json($response, ['success' => false, 'message' => __('File troppo grande.')], 400);
        }

        $allowedMime = ($type === 'audio')
            ? ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/wave']
            : ['application/pdf', 'application/epub+zip', 'application/zip'];

        // Always validate extension regardless of reported MIME type
        $clientFilename = $file->getClientFilename();
        if ($clientFilename === null || $clientFilename === '') {
            return $this->json($response, ['success' => false, 'message' => __('Nome file non valido.')], 400);
        }
        $filename = strtolower($clientFilename);
        $validExt = ($type === 'audio')
            ? ['mp3', 'm4a', 'ogg', 'wav']
            : ['pdf', 'epub'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array($ext, $validExt, true)) {
            return $this->json($response, ['success' => false, 'message' => __('Formato file non supportato.')], 400);
        }

        $uploadsDir = realpath(__DIR__ . '/../../../public/uploads/digital');
        if ($uploadsDir === false) {
            $uploadsDir = __DIR__ . '/../../../public/uploads/digital';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }
        }

        $safeName = $this->generateSafeFilename($clientFilename, $type);
        $targetPath = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

        try {
            $file->moveTo($targetPath);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => __('Impossibile salvare il file.')], 500);
        }

        // Server-side MIME validation using magic bytes (not client-reported type)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($targetPath);
        if ($detectedMime === false || !in_array($detectedMime, $allowedMime, true)) {
            // Remove the uploaded file — it failed MIME validation
            if (!unlink($targetPath)) {
                \App\Support\SecureLogger::error('[Digital Library] Failed to remove MIME-rejected upload', [
                    'path' => $targetPath,
                ]);
            }
            \App\Support\SecureLogger::warning('[Digital Library] Upload rejected: MIME mismatch', [
                'expected' => $allowedMime,
                'detected' => $detectedMime ?: 'unknown',
                'filename' => $clientFilename,
            ]);
            return $this->json($response, ['success' => false, 'message' => __('Formato file non supportato.')], 400);
        }

        $publicUrl = '/uploads/digital/' . $safeName;
        return $this->json($response, [
            'success' => true,
            'uploadURL' => $publicUrl,
            'filename' => $safeName,
            'type' => $type
        ]);
    }

    // ========================================================================
    // Asset Serving
    // ========================================================================

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
        if ($filePath === false || !str_starts_with($filePath, $baseRealPath . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
            return $response->withStatus(404);
        }

        $mime = $type === 'css' ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8';
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return $response->withStatus(500);
        }
        $response->getBody()->write($contents);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=31536000');
    }

    // ========================================================================
    // Private Helpers
    // ========================================================================

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
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            \App\Support\SecureLogger::error('[Digital Library] JSON encode failed', ['message' => $e->getMessage()]);
            $json = json_encode(['success' => false, 'message' => 'Internal error']) ?: '{"success":false,"message":"Internal error"}';
        }
        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
