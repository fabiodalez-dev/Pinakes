<?php
/**
 * Dewey Classification Editor Plugin
 *
 * Visual editor for managing Dewey Decimal Classification JSON files.
 * Allows adding, modifying, and deleting decimal codes with validation.
 *
 * @package DeweyEditorPlugin
 * @version 1.0.0
 */

declare(strict_types=1);

use App\Support\HookManager;
use App\Support\I18n;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/classes/DeweyValidator.php';

class DeweyEditorPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;
    private string $dataDir;
    private string $backupDir;

    private const MAX_BACKUPS = 5;

    /**
     * Get supported locales from installed languages
     * @return array List of locale codes (e.g., ['it_IT', 'en_US'])
     */
    private function getSupportedLocales(): array
    {
        $locales = array_keys(I18n::getAvailableLocales());
        // Fallback if no languages in DB
        return !empty($locales) ? $locales : ['it_IT', 'en_US'];
    }

    /**
     * Check if a locale is supported
     */
    private function isLocaleSupported(string $locale): bool
    {
        return in_array($locale, $this->getSupportedLocales(), true);
    }

    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
        $this->dataDir = dirname(__DIR__, 3) . '/data/dewey';
        $this->backupDir = dirname(__DIR__, 3) . '/storage/backups/dewey';

        $result = $db->query("SELECT id FROM plugins WHERE name = 'dewey-editor' LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $this->pluginId = (int) $row['id'];
        }
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function onInstall(): void
    {
        // Create backup directory
        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true)) {
                error_log("Dewey Editor: impossibile creare directory backup: {$this->backupDir}");
            }
        }
    }

    public function onUninstall(): void
    {
        // Keep backups on uninstall for safety
    }

    public function onActivate(): void
    {
        if ($this->pluginId === null) {
            error_log('[DeweyEditor] pluginId non impostato, impossibile registrare i hook.');
            return;
        }

        // Register hooks in database
        $hooks = [
            ['app.routes.register', 'registerRoutes', 10],
        ];

        // Delete existing hooks for this plugin
        $this->deleteHooks();

        foreach ($hooks as [$hookName, $method, $priority]) {
            $stmt = $this->db->prepare(
                "INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())"
            );

            if ($stmt === false) {
                error_log("[DeweyEditor] Failed to prepare statement: " . $this->db->error);
                continue;
            }

            $callbackClass = 'DeweyEditorPlugin';
            $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);

            if (!$stmt->execute()) {
                error_log("[DeweyEditor] Failed to register hook {$hookName}: " . $stmt->error);
            }

            $stmt->close();
        }
    }

    public function onDeactivate(): void
    {
        $this->deleteHooks();
    }

    private function deleteHooks(): void
    {
        if ($this->pluginId !== null) {
            $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $this->pluginId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    public function registerRoutes($app): void
    {
        $plugin = $this;
        $adminMiddleware = new \App\Middleware\AdminAuthMiddleware();
        $csrfMiddleware = new \App\Middleware\CsrfMiddleware();

        // Admin page
        $app->get('/admin/dewey-editor', function (Request $request, Response $response) use ($plugin) {
            return $plugin->renderEditor($request, $response);
        })->add($adminMiddleware);

        // API endpoints
        $app->get('/api/dewey-editor/data/{locale}', function (Request $request, Response $response, array $args) use ($plugin) {
            return $plugin->getData($request, $response, $args);
        })->add($adminMiddleware);

        $app->post('/api/dewey-editor/save/{locale}', function (Request $request, Response $response, array $args) use ($plugin) {
            return $plugin->saveData($request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->post('/api/dewey-editor/validate', function (Request $request, Response $response) use ($plugin) {
            return $plugin->validateData($request, $response);
        })->add($adminMiddleware);

        $app->get('/api/dewey-editor/export/{locale}', function (Request $request, Response $response, array $args) use ($plugin) {
            return $plugin->exportData($request, $response, $args);
        })->add($adminMiddleware);

        $app->post('/api/dewey-editor/import/{locale}', function (Request $request, Response $response, array $args) use ($plugin) {
            return $plugin->importData($request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->post('/api/dewey-editor/merge/{locale}', function (Request $request, Response $response, array $args) use ($plugin) {
            return $plugin->mergeImportData($request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->get('/api/dewey-editor/backups/{locale}', function (Request $request, Response $response, array $args) use ($plugin) {
            return $plugin->listBackups($request, $response, $args);
        })->add($adminMiddleware);

        $app->post('/api/dewey-editor/restore/{locale}', function (Request $request, Response $response, array $args) use ($plugin) {
            return $plugin->restoreBackup($request, $response, $args);
        })->add($csrfMiddleware)->add($adminMiddleware);
    }

    public function renderEditor(Request $_request, Response $response): Response
    {
        $csrfToken = $_SESSION['csrf_token'] ?? '';

        // Render the view content
        ob_start();
        include __DIR__ . '/views/editor.php';
        $content = ob_get_clean();

        // Wrap with standard layout (includes sidebar, header, CSS/JS)
        ob_start();
        require dirname(__DIR__, 3) . '/app/Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function getData(Request $_request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'it_IT';
        if (!$this->isLocaleSupported($locale)) {
            return $this->jsonError($response, __('Locale non supportato.'), 400);
        }

        $filePath = $this->getJsonPath($locale);
        if (!file_exists($filePath)) {
            return $this->jsonError($response, __('File Dewey non trovato.'), 404);
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        if ($data === null) {
            return $this->jsonError($response, __('Errore nel parsing del file JSON.'), 500);
        }

        return $this->jsonSuccess($response, ['data' => $data]);
    }

    public function saveData(Request $request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'it_IT';
        if (!$this->isLocaleSupported($locale)) {
            return $this->jsonError($response, __('Locale non supportato.'), 400);
        }

        $body = $this->getJsonBody($request);
        $data = $body['data'] ?? null;

        if (!$data) {
            return $this->jsonError($response, __('Dati mancanti.'), 400);
        }

        if (is_string($data)) {
            $data = json_decode($data, true);
            if ($data === null) {
                return $this->jsonError($response, __('JSON non valido.'), 400);
            }
        }

        if (!is_array($data)) {
            return $this->jsonError($response, __('Formato dati non valido.'), 400);
        }

        // Validate
        $validator = new DeweyValidator();
        $errors = $validator->validate($data);
        if (!empty($errors)) {
            return $this->jsonError($response, __('Errori di validazione.'), 400, $errors);
        }

        // Create backup
        $this->createBackup($locale);

        // Save
        $filePath = $this->getJsonPath($locale);
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            return $this->jsonError($response, __('Errore nella codifica JSON.'), 500);
        }
        if (file_put_contents($filePath, $jsonContent) === false) {
            return $this->jsonError($response, __('Errore nel salvataggio del file.'), 500);
        }

        return $this->jsonSuccess($response, ['message' => __('Salvato con successo.')]);
    }

    public function validateData(Request $request, Response $response): Response
    {
        $body = $this->getJsonBody($request);
        $data = $body['data'] ?? null;

        if (!$data) {
            return $this->jsonError($response, __('Dati mancanti.'), 400);
        }

        if (is_string($data)) {
            $data = json_decode($data, true);
            if ($data === null) {
                return $this->jsonError($response, __('JSON non valido.'), 400);
            }
        }

        if (!is_array($data)) {
            return $this->jsonError($response, __('Formato dati non valido.'), 400);
        }

        $validator = new DeweyValidator();
        $errors = $validator->validate($data);

        if (empty($errors)) {
            return $this->jsonSuccess($response, ['valid' => true, 'message' => __('Dati validi.')]);
        }

        return $this->jsonSuccess($response, ['valid' => false, 'errors' => $errors]);
    }

    public function exportData(Request $_request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'it_IT';
        if (!$this->isLocaleSupported($locale)) {
            return $this->jsonError($response, __('Locale non supportato.'), 400);
        }

        $filePath = $this->getJsonPath($locale);
        if (!file_exists($filePath)) {
            return $this->jsonError($response, __('File non trovato.'), 404);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return $this->jsonError($response, __('Errore nella lettura del file.'), 500);
        }

        $filename = "dewey_completo_{$locale}_" . date('Y-m-d_His') . '.json';

        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function importData(Request $request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'it_IT';
        if (!$this->isLocaleSupported($locale)) {
            return $this->jsonError($response, __('Locale non supportato.'), 400);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError($response, __('Errore nel caricamento del file.'), 400);
        }

        $stream = $file->getStream();
        $stream->rewind();
        $content = $stream->getContents();
        $data = json_decode($content, true);

        if ($data === null) {
            return $this->jsonError($response, __('JSON non valido.'), 400);
        }

        if (!is_array($data)) {
            return $this->jsonError($response, __('Formato dati non valido.'), 400);
        }

        // Validate
        $validator = new DeweyValidator();
        $errors = $validator->validate($data);
        if (!empty($errors)) {
            return $this->jsonError($response, __('Errori di validazione nel file importato.'), 400, $errors);
        }

        // Create backup before import
        $this->createBackup($locale);

        // Save imported data
        $filePath = $this->getJsonPath($locale);
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            return $this->jsonError($response, __('Errore nella codifica JSON.'), 500);
        }
        if (file_put_contents($filePath, $jsonContent) === false) {
            return $this->jsonError($response, __('Errore nel salvataggio del file.'), 500);
        }

        // Count entries
        $count = $this->countEntries($data);

        return $this->jsonSuccess($response, [
            'message' => sprintf(__('Importato con successo. %d voci totali.'), $count)
        ]);
    }

    public function mergeImportData(Request $request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'it_IT';
        if (!$this->isLocaleSupported($locale)) {
            return $this->jsonError($response, __('Locale non supportato.'), 400);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError($response, __('Errore nel caricamento del file.'), 400);
        }

        $stream = $file->getStream();
        $stream->rewind();
        $content = $stream->getContents();
        $importData = json_decode($content, true);

        if ($importData === null) {
            return $this->jsonError($response, __('JSON non valido.'), 400);
        }

        if (!is_array($importData)) {
            return $this->jsonError($response, __('Formato dati non valido.'), 400);
        }

        // Load existing data
        $filePath = $this->getJsonPath($locale);
        $existingData = [];
        if (file_exists($filePath)) {
            $existingContent = file_get_contents($filePath);
            if ($existingContent === false) {
                return $this->jsonError($response, __('Errore nella lettura del file Dewey esistente.'), 500);
            }

            $existingData = json_decode($existingContent, true);
            if ($existingData === null || !is_array($existingData)) {
                return $this->jsonError($response, __('File Dewey esistente non è un JSON valido o è corrotto.'), 500);
            }
        }

        // Merge data recursively by code
        $stats = ['added' => 0, 'updated' => 0, 'unchanged' => 0];
        $mergedData = $this->mergeByCode($existingData, $importData, $stats);

        // Validate merged result
        $validator = new DeweyValidator();
        $errors = $validator->validate($mergedData);
        if (!empty($errors)) {
            return $this->jsonError($response, __('Errori di validazione dopo il merge.'), 400, $errors);
        }

        // Create backup before saving
        $this->createBackup($locale);

        // Save merged data
        $jsonContent = json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            return $this->jsonError($response, __('Errore nella codifica JSON.'), 500);
        }
        if (file_put_contents($filePath, $jsonContent) === false) {
            return $this->jsonError($response, __('Errore nel salvataggio del file.'), 500);
        }

        $totalCount = $this->countEntries($mergedData);

        return $this->jsonSuccess($response, [
            'message' => sprintf(
                __('Merge completato: %d aggiunti, %d aggiornati, %d invariati. Totale: %d voci.'),
                $stats['added'],
                $stats['updated'],
                $stats['unchanged'],
                $totalCount
            ),
            'stats' => $stats,
            'total' => $totalCount
        ]);
    }

    /**
     * Recursively merge two Dewey data arrays by code
     *
     * @param array $existing Existing data
     * @param array $import Data to import/merge
     * @param array &$stats Statistics counter
     * @return array Merged data
     */
    private function mergeByCode(array $existing, array $import, array &$stats): array
    {
        // Index existing data by code for fast lookup
        $existingByCode = [];
        foreach ($existing as $index => $node) {
            if (isset($node['code'])) {
                $existingByCode[$node['code']] = $index;
            }
        }

        // Process import data
        foreach ($import as $importNode) {
            if (!isset($importNode['code'])) {
                continue;
            }

            $code = $importNode['code'];

            if (isset($existingByCode[$code])) {
                // Code exists - check if update needed
                $existingIndex = $existingByCode[$code];
                $existingNode = $existing[$existingIndex];

                // Check if name changed
                $nameChanged = ($existingNode['name'] ?? '') !== ($importNode['name'] ?? '');
                $levelChanged = ($existingNode['level'] ?? 0) !== ($importNode['level'] ?? 0);

                if ($nameChanged || $levelChanged) {
                    // Update name and level
                    $existing[$existingIndex]['name'] = $importNode['name'] ?? $existingNode['name'];
                    $existing[$existingIndex]['level'] = $importNode['level'] ?? $existingNode['level'];
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }

                // Recursively merge children
                $existingChildren = $existingNode['children'] ?? [];
                $importChildren = $importNode['children'] ?? [];

                if (!empty($importChildren)) {
                    $existing[$existingIndex]['children'] = $this->mergeByCode(
                        $existingChildren,
                        $importChildren,
                        $stats
                    );
                }
            } else {
                // New code - add it
                $existing[] = $importNode;
                $stats['added'] += $this->countEntries([$importNode]);
            }
        }

        // Sort by code
        usort($existing, fn($a, $b) => strcmp($a['code'] ?? '', $b['code'] ?? ''));

        return $existing;
    }

    public function listBackups(Request $_request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'it_IT';
        if (!$this->isLocaleSupported($locale)) {
            return $this->jsonError($response, __('Locale non supportato.'), 400);
        }

        $pattern = $this->backupDir . "/dewey_{$locale}_*.json";
        $files = glob($pattern) ?: [];
        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'size' => filesize($file)
            ];
        }

        // Sort by date descending
        usort($backups, fn($a, $b) => strcmp($b['date'], $a['date']));

        return $this->jsonSuccess($response, ['backups' => $backups]);
    }

    public function restoreBackup(Request $request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'it_IT';
        if (!$this->isLocaleSupported($locale)) {
            return $this->jsonError($response, __('Locale non supportato.'), 400);
        }

        $body = $this->getJsonBody($request);
        $filename = $body['filename'] ?? null;

        if (!$filename || !preg_match('/^dewey_' . preg_quote($locale) . '_\d{8}_\d{6}\.json$/', $filename)) {
            return $this->jsonError($response, __('Nome file non valido.'), 400);
        }

        $backupPath = $this->backupDir . '/' . $filename;
        if (!file_exists($backupPath)) {
            return $this->jsonError($response, __('Backup non trovato.'), 404);
        }

        // Create backup of current before restore
        $this->createBackup($locale);

        // Restore
        $content = file_get_contents($backupPath);
        if ($content === false) {
            return $this->jsonError($response, __('Errore nella lettura del backup.'), 500);
        }

        $filePath = $this->getJsonPath($locale);

        if (file_put_contents($filePath, $content) === false) {
            return $this->jsonError($response, __('Errore nel ripristino.'), 500);
        }

        return $this->jsonSuccess($response, ['message' => __('Backup ripristinato con successo.')]);
    }

    private function getJsonPath(string $locale): string
    {
        // Extract language code from locale (e.g., 'it_IT' -> 'it', 'en_US' -> 'en')
        $langCode = strtolower(substr($locale, 0, 2));
        $filename = "dewey_completo_{$langCode}.json";
        return $this->dataDir . '/' . $filename;
    }

    private function createBackup(string $locale): void
    {
        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true)) {
                error_log("Dewey Editor: impossibile creare directory backup: {$this->backupDir}");
                return;
            }
        }

        $sourcePath = $this->getJsonPath($locale);
        if (!file_exists($sourcePath)) {
            return;
        }

        $timestamp = date('Ymd_His');
        $backupPath = $this->backupDir . "/dewey_{$locale}_{$timestamp}.json";
        if (!copy($sourcePath, $backupPath)) {
            error_log("Dewey Editor: impossibile creare backup: {$backupPath}");
            return;
        }

        // Cleanup old backups
        $this->cleanupOldBackups($locale);
    }

    private function cleanupOldBackups(string $locale): void
    {
        $pattern = $this->backupDir . "/dewey_{$locale}_*.json";
        $files = glob($pattern) ?: [];

        if (count($files) <= self::MAX_BACKUPS) {
            return;
        }

        // Sort by modification time
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        // Delete oldest backups
        $toDelete = array_slice($files, self::MAX_BACKUPS);
        foreach ($toDelete as $file) {
            if (!unlink($file)) {
                error_log("Dewey Editor: impossibile eliminare vecchio backup: {$file}");
            }
        }
    }

    private function countEntries(array $data): int
    {
        $count = 0;
        foreach ($data as $item) {
            $count++;
            if (!empty($item['children'])) {
                $count += $this->countEntries($item['children']);
            }
        }
        return $count;
    }

    /**
     * Parse JSON body from request
     * Slim 4 doesn't automatically parse JSON - need to do it manually
     */
    private function getJsonBody(Request $request): array
    {
        $contentType = $request->getHeaderLine('Content-Type');

        // If JSON content type, parse manually
        if (stripos($contentType, 'application/json') !== false) {
            $body = $request->getBody();
            $body->rewind();
            $contents = $body->getContents();
            $parsed = json_decode($contents, true);
            return is_array($parsed) ? $parsed : [];
        }

        // Fallback to parsed body for form data
        $parsed = $request->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }

    private function jsonSuccess(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(Response $response, string $message, int $status, array $errors = []): Response
    {
        $data = ['success' => false, 'error' => $message];
        if (!empty($errors)) {
            $data['errors'] = $errors;
        }
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
