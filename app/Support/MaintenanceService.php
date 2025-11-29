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
            'reservations_converted' => 0,
            'overdue_loans_updated' => 0,
            'expiration_warnings' => 0,
            'overdue_notifications' => 0,
            'wishlist_notifications' => 0,
            'ics_generated' => false,
            'errors' => []
        ];

        try {
            $results['scheduled_loans_activated'] = $this->activateScheduledLoans();
        } catch (\Throwable $e) {
            $results['errors'][] = 'activateScheduledLoans: ' . $e->getMessage();
            error_log('MaintenanceService error (activateScheduledLoans): ' . $e->getMessage());
        }

        try {
            $results['reservations_converted'] = $this->processScheduledReservations();
        } catch (\Throwable $e) {
            $results['errors'][] = 'processScheduledReservations: ' . $e->getMessage();
            error_log('MaintenanceService error (processScheduledReservations): ' . $e->getMessage());
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

        // Generate ICS calendar file
        try {
            $results['ics_generated'] = $this->generateIcsCalendar();
        } catch (\Throwable $e) {
            $results['errors'][] = 'generateIcsCalendar: ' . $e->getMessage();
            error_log('MaintenanceService error (generateIcsCalendar): ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Generate ICS calendar file for loans and reservations
     */
    public function generateIcsCalendar(): bool
    {
        $icsGenerator = new IcsGenerator($this->db);
        $storagePath = __DIR__ . '/../../storage/calendar';

        // Ensure storage directory exists
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        return $icsGenerator->saveToFile($storagePath . '/library-calendar.ics');
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

                // Recalculate book availability using DataIntegrity for consistency
                $integrity = new DataIntegrity($this->db);
                $integrity->recalculateBookAvailability((int)$loan['libro_id']);

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
     * Process scheduled reservations - convert reservations to loans when:
     * 1. Their start date (data_inizio_richiesta) is today or in the past
     * 2. A copy is actually available for that book
     *
     * This handles the case where a user creates a reservation for a future date
     * and the book is already available - without this, the reservation would
     * sit in queue forever waiting for a "book returned" event that never comes.
     */
    public function processScheduledReservations(): int
    {
        $today = date('Y-m-d');

        // Find all active reservations where the requested start date has arrived
        // Process them in queue order (queue_position ASC)
        $stmt = $this->db->prepare("
            SELECT p.id, p.libro_id, p.utente_id, p.data_inizio_richiesta, p.data_fine_richiesta,
                   u.email, u.nome, u.cognome
            FROM prenotazioni p
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'attiva'
            AND p.data_inizio_richiesta <= ?
            ORDER BY p.libro_id, p.queue_position ASC
        ");

        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare scheduled reservations query');
        }

        $stmt->bind_param('s', $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $reservations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $convertedCount = 0;
        $processedBooks = []; // Track which books we've already processed a reservation for

        foreach ($reservations as $reservation) {
            $bookId = (int)$reservation['libro_id'];

            // Only process one reservation per book per run (the first in queue)
            // This prevents converting multiple reservations when only one copy is available
            if (isset($processedBooks[$bookId])) {
                continue;
            }

            $this->db->begin_transaction();

            try {
                // Lock the book row
                $lockStmt = $this->db->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
                $lockStmt->bind_param('i', $bookId);
                $lockStmt->execute();
                $lockStmt->close();

                // Use ReservationManager to process the reservation
                // processBookAvailability() will find the first date-eligible reservation in queue
                // and convert it to a loan if a copy is available
                $reservationManager = new \App\Controllers\ReservationManager($this->db);
                $success = $reservationManager->processBookAvailability($bookId);

                if ($success) {
                    $this->db->commit();
                    $convertedCount++;
                    $processedBooks[$bookId] = true;

                    error_log("MaintenanceService: Converted reservation {$reservation['id']} to loan for book {$bookId}");
                } else {
                    // No copy available yet, rollback and continue
                    $this->db->rollback();
                }

            } catch (\Throwable $e) {
                $this->db->rollback();
                error_log("MaintenanceService: Failed to process reservation {$reservation['id']}: " . $e->getMessage());
            }
        }

        return $convertedCount;
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
     * @param int $_userId User ID (unused, kept for hook signature compatibility)
     * @param array $userData User data array containing tipo_utente
     * @param mixed $_request Request object (unused, kept for hook signature compatibility)
     */
    public static function onAdminLogin(int $_userId, array $userData, $_request): void
    {
        // Only run for admin/staff users
        if (!in_array($userData['tipo_utente'] ?? '', ['admin', 'staff'], true)) {
            return;
        }

        $createdConnection = false;

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
                $createdConnection = true;
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

            // Close connection if we created it
            if ($createdConnection) {
                $db->close();
            }

            if (!($result['skipped'] ?? false)) {
                error_log(sprintf(
                    'MaintenanceService: Activated %d scheduled loans, updated %d overdue loans (triggered by admin login)',
                    $result['scheduled_loans_activated'],
                    $result['overdue_loans_updated']
                ));
            }

        } catch (\Throwable $e) {
            // Close connection on error if we created it
            if ($createdConnection && isset($db) && $db instanceof mysqli) {
                $db->close();
            }
            error_log('MaintenanceService: Error during admin login hook: ' . $e->getMessage());
        }
    }
}
