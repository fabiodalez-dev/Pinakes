<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * MaintenanceService
 * Handles background maintenance tasks like activating scheduled loans
 * Can be triggered by cron job or automatically on admin login
 */
class MaintenanceService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Run all maintenance tasks (if not run recently)
     * Returns early if already run within the cooldown period
     */
    public function runIfNeeded(int $cooldownMinutes = 60): array
    {
        $cacheKey = 'maintenance_last_run';
        $now = time();

        // Check if we ran recently (use session as simple cache)
        if (isset($_SESSION[$cacheKey]) && ($now - $_SESSION[$cacheKey]) < ($cooldownMinutes * 60)) {
            return ['skipped' => true, 'reason' => 'cooldown'];
        }

        // Mark as running
        $_SESSION[$cacheKey] = $now;

        return $this->runAll();
    }

    /**
     * Run all maintenance tasks immediately
     */
    public function runAll(): array
    {
        $results = [
            'scheduled_loans_activated' => 0,
            'overdue_loans_updated' => 0,
            'expiration_warnings' => 0,
            'overdue_notifications' => 0,
            'wishlist_notifications' => 0,
            'errors' => []
        ];

        try {
            $results['scheduled_loans_activated'] = $this->activateScheduledLoans();
        } catch (\Throwable $e) {
            $results['errors'][] = 'activateScheduledLoans: ' . $e->getMessage();
            error_log('MaintenanceService error (activateScheduledLoans): ' . $e->getMessage());
        }

        try {
            $results['overdue_loans_updated'] = $this->updateOverdueLoans();
        } catch (\Throwable $e) {
            $results['errors'][] = 'updateOverdueLoans: ' . $e->getMessage();
            error_log('MaintenanceService error (updateOverdueLoans): ' . $e->getMessage());
        }

        // Run automatic notifications
        try {
            $notificationResults = $this->runNotifications();
            $results['expiration_warnings'] = $notificationResults['expiration_warnings'] ?? 0;
            $results['overdue_notifications'] = $notificationResults['overdue_notifications'] ?? 0;
            $results['wishlist_notifications'] = $notificationResults['wishlist_notifications'] ?? 0;
        } catch (\Throwable $e) {
            $results['errors'][] = 'runNotifications: ' . $e->getMessage();
            error_log('MaintenanceService error (runNotifications): ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Run automatic notifications (expiration warnings, overdue, wishlist)
     */
    public function runNotifications(): array
    {
        $results = [
            'expiration_warnings' => 0,
            'overdue_notifications' => 0,
            'wishlist_notifications' => 0,
            'errors' => []
        ];

        try {
            $notificationService = new NotificationService($this->db);
            $notifResults = $notificationService->runAutomaticNotifications();

            $results['expiration_warnings'] = $notifResults['expiration_warnings'] ?? 0;
            $results['overdue_notifications'] = $notifResults['overdue_notifications'] ?? 0;
            $results['wishlist_notifications'] = $notifResults['wishlist_notifications'] ?? 0;

            if (!empty($notifResults['errors'])) {
                $results['errors'] = $notifResults['errors'];
            }
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Activate scheduled loans (prenotato -> in_corso) when their start date arrives
     */
    public function activateScheduledLoans(): int
    {
        // Find all scheduled loans that should be activated
        $stmt = $this->db->prepare("
            SELECT id, copia_id, libro_id FROM prestiti
            WHERE stato = 'prenotato'
            AND data_prestito <= CURDATE()
            AND attivo = 1
        ");

        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare scheduled loans query');
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $scheduledLoans = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $activatedCount = 0;

        foreach ($scheduledLoans as $loan) {
            $this->db->begin_transaction();

            try {
                // Update loan status to in_corso
                $updateStmt = $this->db->prepare("UPDATE prestiti SET stato = 'in_corso' WHERE id = ?");
                $updateStmt->bind_param('i', $loan['id']);
                $updateStmt->execute();
                $updateStmt->close();

                // Mark the copy as 'prestato'
                $copyStmt = $this->db->prepare("UPDATE copie SET stato = 'prestato' WHERE id = ?");
                $copyStmt->bind_param('i', $loan['copia_id']);
                $copyStmt->execute();
                $copyStmt->close();

                // Recalculate book availability
                $availStmt = $this->db->prepare("
                    UPDATE libri l
                    SET copie_disponibili = (
                        SELECT COUNT(*) FROM copie c WHERE c.libro_id = l.id AND c.stato = 'disponibile'
                    )
                    WHERE l.id = ?
                ");
                $availStmt->bind_param('i', $loan['libro_id']);
                $availStmt->execute();
                $availStmt->close();

                $this->db->commit();
                $activatedCount++;

            } catch (\Throwable $e) {
                $this->db->rollback();
                error_log("MaintenanceService: Failed to activate loan {$loan['id']}: " . $e->getMessage());
            }
        }

        return $activatedCount;
    }

    /**
     * Update overdue loans status (in_corso -> in_ritardo)
     */
    public function updateOverdueLoans(): int
    {
        $stmt = $this->db->prepare("
            UPDATE prestiti
            SET stato = 'in_ritardo'
            WHERE stato = 'in_corso'
            AND data_scadenza < CURDATE()
            AND attivo = 1
        ");

        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare overdue loans query');
        }

        $stmt->execute();
        $affected = $this->db->affected_rows;
        $stmt->close();

        return $affected;
    }

    /**
     * Static method to run maintenance on admin login via hook
     */
    public static function onAdminLogin(int $userId, array $userData, $request): void
    {
        // Only run for admin/staff users
        if (!in_array($userData['tipo_utente'] ?? '', ['admin', 'staff'], true)) {
            return;
        }

        try {
            // Get database connection from global container or settings
            global $app;
            $db = null;

            if (isset($app) && method_exists($app, 'getContainer')) {
                $container = $app->getContainer();
                if ($container && $container->has('db')) {
                    $db = $container->get('db');
                }
            }

            if (!$db) {
                // Fallback: create new connection from settings
                $settings = require __DIR__ . '/../../config/settings.php';
                $cfg = $settings['db'];
                $db = new \mysqli(
                    $cfg['hostname'],
                    $cfg['username'],
                    $cfg['password'],
                    $cfg['database'],
                    $cfg['port'],
                    $cfg['socket'] ?? null
                );

                if ($db->connect_error) {
                    error_log('MaintenanceService: Database connection failed: ' . $db->connect_error);
                    return;
                }

                $db->set_charset($cfg['charset']);
            }

            $service = new self($db);
            $result = $service->runIfNeeded(60); // Run if not run in last 60 minutes

            if (!($result['skipped'] ?? false)) {
                error_log(sprintf(
                    'MaintenanceService: Activated %d scheduled loans, updated %d overdue loans (triggered by admin login)',
                    $result['scheduled_loans_activated'],
                    $result['overdue_loans_updated']
                ));
            }

        } catch (\Throwable $e) {
            error_log('MaintenanceService: Error during admin login hook: ' . $e->getMessage());
        }
    }
}
