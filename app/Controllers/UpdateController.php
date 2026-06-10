<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Updater;
use App\Support\BackupManager;
use App\Support\Csrf;
use App\Support\SecureLogger;
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

        $githubTokenMasked = $updater->getGitHubTokenMasked();
        $hasGithubToken = $updater->hasGitHubToken();

        $backupIncludeFiles = (new \App\Models\SettingsRepository($db))
            ->get('backup', 'pre_update_include_files', '0') === '1';

        ob_start();
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
                'backup_path' => basename($result['backup_path'] ?? '')
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
        // Destructive/resource-heavy → admin-only (AdminAuthMiddleware also lets
        // staff through, so re-check here, like restoreBackup/uploadRestore).
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Operazione riservata agli amministratori')], 403);
        }

        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $scope = (($data['scope'] ?? 'full') === 'db') ? 'db' : 'full';
        $result = (new BackupManager($db, dirname(__DIR__, 2)))->createBackup($scope);

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => __('Backup creato con successo'),
                'name' => $result['name']
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


        $backups = (new BackupManager($db, dirname(__DIR__, 2)))->listBackups();

        return $this->jsonResponse($response, ['backups' => $backups]);
    }

    /**
     * API: Delete a backup
     */
    public function deleteBackup(Request $request, Response $response, mysqli $db): Response
    {
        // Destructive (removes a recovery point) → admin-only re-check.
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Operazione riservata agli amministratori')], 403);
        }

        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!\App\Support\Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $backupName = $data['backup'] ?? '';
        if (empty($backupName)) {
            return $this->jsonResponse($response, ['error' => __('Nome backup non specificato')], 400);
        }

        $result = (new BackupManager($db, dirname(__DIR__, 2)))->deleteBackup($backupName);

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
        // Exfiltrates a full DB+uploads archive → admin-only re-check. The other
        // error branches here already return JSON, so a 403 JSON body is consistent.
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Operazione riservata agli amministratori')], 403);
        }

        $backupName = $request->getQueryParams()['backup'] ?? '';
        if (empty($backupName)) {
            return $this->jsonResponse($response, ['error' => __('Nome backup non specificato')], 400);
        }

        $result = (new BackupManager($db, dirname(__DIR__, 2)))->getDownloadPath($backupName);
        if (!$result['success']) {
            return $this->jsonResponse($response, ['error' => $result['error']], 404);
        }

        // Stream the file instead of loading it into memory — backups can be large.
        $handle = fopen((string) $result['path'], 'rb');
        if ($handle === false) {
            return $this->jsonResponse($response, ['error' => __('Impossibile leggere il file di backup')], 500);
        }
        $size = filesize((string) $result['path']);

        return $response
            ->withBody(new \Slim\Psr7\Stream($handle))
            ->withHeader('Content-Type', (string) $result['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"')
            ->withHeader('Content-Length', (string) ($size === false ? 0 : $size));
    }

    /**
     * Restore a stored backup (database + uploaded files). Destructive →
     * admin-only (AdminAuthMiddleware also lets staff through, so re-check here).
     */
    public function restoreBackup(Request $request, Response $response, mysqli $db): Response
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Operazione riservata agli amministratori')], 403);
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? '')) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $backupName = $data['backup'] ?? '';
        if (empty($backupName)) {
            return $this->jsonResponse($response, ['error' => __('Nome backup non specificato')], 400);
        }

        $result = (new BackupManager($db, dirname(__DIR__, 2)))->restoreFromBackup((string) $backupName);

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => __('Ripristino completato'),
                'safety_backup' => $result['safety_backup'],
            ]);
        }
        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error'],
            'partial' => $result['partial'] ?? false,
            'restored_phase' => $result['restored_phase'] ?? null,
            'safety_backup' => $result['safety_backup'] ?? null,
        ], 500);
    }

    /**
     * Restore from an uploaded backup ZIP. Destructive → admin-only.
     */
    public function uploadRestore(Request $request, Response $response, mysqli $db): Response
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Operazione riservata agli amministratori')], 403);
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? '')) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $files = $request->getUploadedFiles();
        $upload = $files['backup_file'] ?? null;
        if (!$upload instanceof \Psr\Http\Message\UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonResponse($response, ['error' => __('Nessun file caricato')], 400);
        }

        // Reject an over-cap upload BEFORE moving it to disk, so a 2 GB+ file can't
        // exhaust storage just to be rejected. restoreFromUploadedZip keeps the
        // post-move check as a backstop (getSize() can be null/spoofed).
        $earlySize = $upload->getSize();
        if ($earlySize !== null && $earlySize > \App\Support\BackupManager::MAX_UPLOAD_BYTES) {
            return $this->jsonResponse($response, ['error' => __('File di backup troppo grande')], 400);
        }

        $tmpDir = dirname(__DIR__, 2) . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir . '/restore_' . bin2hex(random_bytes(6)) . '.zip';
        try {
            $upload->moveTo($tmpPath);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['error' => __('Impossibile salvare il file caricato')], 500);
        }

        $size = (int) ($upload->getSize() ?? (filesize($tmpPath) ?: 0));
        $result = (new BackupManager($db, dirname(__DIR__, 2)))->restoreFromUploadedZip($tmpPath, $size);
        if (is_file($tmpPath)) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- internally-generated storage/tmp path, not user input
            @unlink($tmpPath);
        }

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => __('Ripristino completato'),
                'safety_backup' => $result['safety_backup'],
            ]);
        }
        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error'],
            'partial' => $result['partial'] ?? false,
            'restored_phase' => $result['restored_phase'] ?? null,
            'safety_backup' => $result['safety_backup'] ?? null,
        ], 500);
    }

    /**
     * Persist the "include uploaded files in the pre-update backup" setting.
     */
    public function saveBackupSettings(Request $request, Response $response, mysqli $db): Response
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Operazione riservata agli amministratori')], 403);
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? '')) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $value = (($data['include_files'] ?? '0') === '1') ? '1' : '0';
        (new \App\Models\SettingsRepository($db))->set('backup', 'pre_update_include_files', $value);

        return $this->jsonResponse($response, ['success' => true]);
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
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- constant internal path (storage/.maintenance), not user input
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

        } catch (\Throwable $e) {
            error_log('[UpdateController] Upload failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Errore durante il caricamento del pacchetto')
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
                'backup_path' => basename($result['backup_path'] ?? '')
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error']
        ], 500);
    }

    /**
     * API: Save GitHub API token
     */
    public function saveToken(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf_token'] ?? '';

        if (!Csrf::validate($csrfToken)) {
            return $this->jsonResponse($response, ['error' => __('Token CSRF non valido')], 403);
        }

        $token = trim((string) ($data['github_token'] ?? ''));

        if ($token !== '' && preg_match('/[[:cntrl:]]/u', $token)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Token GitHub non valido')
            ], 400);
        }

        try {
            $updater = new Updater($db);
            $updater->saveGitHubToken($token);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => $token !== ''
                    ? __('Token GitHub salvato con successo')
                    : __('Token GitHub rimosso')
            ]);
        } catch (\Throwable $e) {
            SecureLogger::error('saveToken failed (' . get_class($e) . ')');
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => __('Errore nel salvataggio del token')
            ], 500);
        }
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
