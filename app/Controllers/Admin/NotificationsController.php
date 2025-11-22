<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\NotificationService;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NotificationsController
{
    private mysqli $db;
    private NotificationService $notificationService;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->notificationService = new NotificationService($db);
    }

    /**
     * Get notifications (for dropdown)
     */
    public function getRecent(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $limit = (int)($queryParams['limit'] ?? 10);
        $unreadOnly = isset($queryParams['unread_only']) && $queryParams['unread_only'] === 'true';

        $notifications = $this->notificationService->getRecentNotifications($limit, $unreadOnly);
        $unreadCount = $this->notificationService->getUnreadCount();

        $data = [
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get unread count only
     */
    public function getUnreadCount(Request $request, Response $response): Response
    {
        $count = $this->notificationService->getUnreadCount();

        $response->getBody()->write(json_encode(['count' => $count]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, Response $response, array $args): Response
    {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['error' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $id = (int)($args['id'] ?? 0);

        $success = $this->notificationService->markNotificationAsRead($id);

        if ($success) {
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => __('Impossibile segnare come letta la notifica.')]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Mark all as read
     */
    public function markAllAsRead(Request $request, Response $response): Response
    {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['error' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $success = $this->notificationService->markAllNotificationsAsRead();

        if ($success) {
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => __('Impossibile segnare tutte le notifiche come lette.')]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Delete notification
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $csrfToken = $request->getHeaderLine('X-CSRF-Token');
        if (!\App\Support\Csrf::validate($csrfToken)) {
            $response->getBody()->write(json_encode(['error' => __('Token CSRF non valido')]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $id = (int)($args['id'] ?? 0);

        $success = $this->notificationService->deleteNotification($id);

        if ($success) {
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['error' => __('Impossibile eliminare la notifica.')]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Show notifications page
     */
    public function index(Request $request, Response $response): Response
    {
        $notifications = $this->notificationService->getRecentNotifications(50, false);
        $unreadCount = $this->notificationService->getUnreadCount();

        $title = __('Notifiche');

        ob_start();
        include __DIR__ . '/../../Views/admin/notifications.php';
        $content = ob_get_clean();

        ob_start();
        include __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }
}
