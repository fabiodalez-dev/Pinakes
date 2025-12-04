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
        // Admin-only access
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            $_SESSION['error_message'] = __('Accesso negato');
            return $this->redirect($response, '/admin/dashboard');
        }

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
        // Admin-only access
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Accesso negato')], 403);
        }

        $updater = new Updater($db);
        $updateInfo = $updater->checkForUpdates();

        return $this->jsonResponse($response, $updateInfo);
    }

    /**
     * API: Perform the update
     */
    public function performUpdate(Request $request, Response $response, mysqli $db): Response
    {
        // Admin-only access
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Accesso negato')], 403);
        }

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
        // Admin-only access
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Accesso negato')], 403);
        }

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
        // Admin-only access
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => __('Accesso negato')], 403);
        }

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
     * Helper: Send JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Helper: Redirect
     */
    private function redirect(Response $response, string $url): Response
    {
        return $response
            ->withHeader('Location', $url)
            ->withStatus(302);
    }
}
