<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Updater;
use App\Support\Csrf;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UpdateController
{
    /**
     * Display the update management page
     */
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        // Admin-only access check removed - relying on Middleware


        $updater = new Updater($db);

        // Check for updates
        $updateInfo = $updater->checkForUpdates();
        $requirements = $updater->checkRequirements();
        $history = $updater->getUpdateHistory();
        $changelog = [];

        if ($updateInfo['available'] && $updateInfo['release']) {
            $changelog = $updater->getChangelog($updateInfo['current']);
        }

        ob_start();
        $data = compact('updateInfo', 'requirements', 'history', 'changelog');
        require __DIR__ . '/../Views/admin/updates.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * API: Check for updates
     */
    public function checkUpdates(Request $request, Response $response, mysqli $db): Response
    {
        // Admin-only access check removed


        $updater = new Updater($db);
        $updateInfo = $updater->checkForUpdates();

        return $this->jsonResponse($response, $updateInfo);
    }

    /**
     * API: Perform the update
     */
    public function performUpdate(Request $request, Response $response, mysqli $db): Response
    {
        // Admin-only access check removed


        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $targetVersion = $data['version'] ?? '';

        if (empty($targetVersion)) {
            return $this->jsonResponse($response, ['error' => __('Versione non specificata')], 400);
        }

        $updater = new Updater($db);

        // Check requirements first
        $requirements = $updater->checkRequirements();
        if (!$requirements['met']) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Requisiti di sistema non soddisfatti'),
                'requirements' => $requirements['requirements']
            ], 400);
        }

        // Perform the update
        $result = $updater->performUpdate($targetVersion);

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => sprintf(__('Aggiornamento alla versione %s completato'), $targetVersion),
                'backup_path' => $result['backup_path']
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error']
        ], 500);
    }

    /**
     * API: Create backup only
     */
    public function createBackup(Request $request, Response $response, mysqli $db): Response
    {
        // Admin-only access check removed


        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $updater = new Updater($db);
        $result = $updater->createBackup();

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => __('Backup creato con successo'),
                'path' => $result['path']
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error']
        ], 500);
    }

    /**
     * API: Get update history
     */
    public function getHistory(Request $request, Response $response, mysqli $db): Response
    {
        // Admin-only access check removed


        $updater = new Updater($db);
        $history = $updater->getUpdateHistory();

        return $this->jsonResponse($response, ['history' => $history]);
    }

    /**
     * API: Check if update is available (for header notification)
     */
    public function checkAvailable(Request $request, Response $response, mysqli $db): Response
    {
        // Any logged-in admin/staff can check
        $userType = $_SESSION['user']['tipo_utente'] ?? '';
        if (!in_array($userType, ['admin', 'staff'], true)) {
            return $this->jsonResponse($response, ['available' => false]);
        }

        $updater = new Updater($db);
        $updateInfo = $updater->checkForUpdates();

        return $this->jsonResponse($response, [
            'available' => $updateInfo['available'],
            'current' => $updateInfo['current'],
            'latest' => $updateInfo['latest']
        ]);
    }

    /**
     * API: Get backup list
     */
    public function getBackups(Request $request, Response $response, mysqli $db): Response
    {


        $updater = new Updater($db);
        $backups = $updater->getBackupList();

        return $this->jsonResponse($response, ['backups' => $backups]);
    }

    /**
     * API: Delete a backup
     */
    public function deleteBackup(Request $request, Response $response, mysqli $db): Response
    {


        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!\App\Support\Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $backupName = $data['backup'] ?? '';
        if (empty($backupName)) {
            return $this->jsonResponse($response, ['error' => __('Nome backup non specificato')], 400);
        }

        $updater = new Updater($db);
        $result = $updater->deleteBackup($backupName);

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => __('Backup eliminato con successo')
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error']
        ], 500);
    }

    /**
     * Download a backup file
     */
    public function downloadBackup(Request $request, Response $response, mysqli $db): Response
    {


        $backupName = $request->getQueryParams()['backup'] ?? '';
        if (empty($backupName)) {
            return $this->jsonResponse($response, ['error' => __('Nome backup non specificato')], 400);
        }

        $updater = new Updater($db);
        $result = $updater->getBackupDownloadPath($backupName);

        if (!$result['success']) {
            return $this->jsonResponse($response, ['error' => $result['error']], 404);
        }

        $content = file_get_contents($result['path']);
        if ($content === false) {
            return $this->jsonResponse($response, ['error' => __('Impossibile leggere il file di backup')], 500);
        }

        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'application/sql')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($content));
    }

    /**
     * API: Clear maintenance mode (emergency recovery)
     */
    public function clearMaintenance(Request $request, Response $response): Response
    {
        // Admin-only access check removed


        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $maintenanceFile = dirname(__DIR__, 2) . '/storage/.maintenance';

        if (file_exists($maintenanceFile)) {
            if (@unlink($maintenanceFile)) {
                error_log("[Updater] Maintenance mode cleared manually by admin user " . ($_SESSION['user']['id'] ?? 'unknown'));
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => __('Modalità manutenzione disattivata')
                ]);
            } else {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => __('Impossibile eliminare il file di manutenzione')
                ], 500);
            }
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => __('La modalità manutenzione non era attiva')
        ]);
    }

    /**
     * API: Get recent updater logs
     */
    public function getLogs(Request $request, Response $response): Response
    {
        $logFile = dirname(__DIR__, 2) . '/storage/logs/app.log';
        $lines = (int)($request->getQueryParams()['lines'] ?? 200);
        $filter = $request->getQueryParams()['filter'] ?? 'Updater';

        if (!file_exists($logFile)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('File di log non trovato'),
                'path' => $logFile
            ], 404);
        }

        // Read last N lines
        $allLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($allLines === false) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Impossibile leggere il file di log')
            ], 500);
        }

        // Get last lines and filter for Updater entries
        $lastLines = array_slice($allLines, -$lines);
        $filtered = [];

        foreach ($lastLines as $line) {
            if ($filter === '' || stripos($line, $filter) !== false) {
                $filtered[] = $line;
            }
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'total_lines' => count($allLines),
            'filtered_count' => count($filtered),
            'filter' => $filter,
            'logs' => $filtered
        ]);
    }

    /**
     * API: Upload manual update package
     */
    public function uploadUpdate(Request $request, Response $response, mysqli $db): Response
    {
        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($uploadedFiles['update_package'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Nessun file caricato')
            ], 400);
        }

        $uploadedFile = $uploadedFiles['update_package'];

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Errore durante il caricamento del file')
            ], 400);
        }

        // Validate file type (PSR-7: getClientFilename() can return null)
        $filename = $uploadedFile->getClientFilename();
        if ($filename === null || !str_ends_with(strtolower($filename), '.zip')) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Il file deve essere un archivio ZIP')
            ], 400);
        }

        // Validate file size (max 50MB) (PSR-7: getSize() can return null)
        $size = $uploadedFile->getSize();
        if ($size === null || $size > 50 * 1024 * 1024) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Il file è troppo grande (max 50MB)')
            ], 400);
        }

        try {
            $updater = new Updater($db);
            $result = $updater->saveUploadedPackage($uploadedFile);

            if ($result['success']) {
                // Store path in session to avoid leaking filesystem paths to client
                $_SESSION['manual_update_path'] = $result['path'];
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => __('Pacchetto caricato con successo')
                ]);
            }

            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $result['error']
            ], 500);

        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * API: Install manually uploaded update package
     */
    public function installManualUpdate(Request $request, Response $response, mysqli $db): Response
    {
        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        // Retrieve path from session (not from client) to prevent path manipulation
        $tempPath = $_SESSION['manual_update_path'] ?? '';
        unset($_SESSION['manual_update_path']);

        if (empty($tempPath)) {
            return $this->jsonResponse($response, ['error' => __('Path pacchetto non specificato')], 400);
        }

        // Security: validate that temp_path is actually in our temp directory
        $rootPath = dirname(__DIR__, 2);
        $storageTmp = $rootPath . '/storage/tmp';
        $realTempPath = realpath($tempPath);
        $realStorageTmp = realpath($storageTmp);

        if (!$realTempPath || !$realStorageTmp || !str_starts_with($realTempPath, $realStorageTmp)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Path pacchetto non valido')
            ], 400);
        }

        $updater = new Updater($db);

        // Check requirements first
        $requirements = $updater->checkRequirements();
        if (!$requirements['met']) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Requisiti di sistema non soddisfatti'),
                'requirements' => $requirements['requirements']
            ], 400);
        }

        // Perform the update from uploaded file (use resolved path to prevent TOCTOU)
        $result = $updater->performUpdateFromFile($realTempPath);

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => __('Aggiornamento completato con successo'),
                'backup_path' => $result['backup_path']
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error']
        ], 500);
    }

    /**
     * Helper: Send JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
