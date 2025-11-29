<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Service for running background maintenance tasks
 *
 * Handles scheduled loan activation, overdue status updates,
 * automatic notifications, and ICS calendar generation.
 * Can be triggered by cron job or automatically on admin login
 * with a configurable cooldown period.
 *
 * @package App\Support
 */
class MaintenanceService
{
    /** @var string Path to ICS calendar file */
    private const ICS_PATH = __DIR__ . '/../../storage/calendar/library-calendar.ics';

    /** @var mysqli Database connection */
    private mysqli $db;

    /**
     * Create a new MaintenanceService instance
     *
     * @param mysqli $db Database connection
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Run all maintenance tasks (if not run recently)
     *
     * Returns early if already run within the cooldown period.
     * Uses session-based caching to prevent duplicate runs.
     *
     * @param int $cooldownMinutes Minimum minutes between runs (default: 60)
     * @return array{skipped?: bool, reason?: string, scheduled_loans_activated?: int, reservations_converted?: int, overdue_loans_updated?: int, expiration_warnings?: int, overdue_notifications?: int, wishlist_notifications?: int, ics_generated?: bool, errors?: array} Results or skip status
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
     *
     * Executes scheduled loan activation, reservation processing,
     * overdue loan updates, notifications, and ICS calendar generation.
     * Each task is wrapped in try-catch to prevent failures from blocking others.
     *
     * @return array{scheduled_loans_activated: int, reservations_converted: int, overdue_loans_updated: int, expiration_warnings: int, overdue_notifications: int, wishlist_notifications: int, ics_generated: bool, errors: array} Results for each maintenance task
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
            if ($results['ics_generated'] === false) {
                $results['errors'][] = 'generateIcsCalendar: ICS file not generated';
                error_log('MaintenanceService warning (generateIcsCalendar): ICS file could not be written');
            }
        } catch (\Throwable $e) {
            $results['errors'][] = 'generateIcsCalendar: ' . $e->getMessage();
            error_log('MaintenanceService error (generateIcsCalendar): ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Generate ICS calendar file for loans and reservations
     *
     * Creates an iCalendar (.ics) file in storage/calendar/ containing
     * all active loans, scheduled loans, and pending reservations.
     * Ensures the storage directory exists before writing.
     *
     * @return bool True if file was generated successfully, false otherwise
     */
    public function generateIcsCalendar(): bool
    {
        $icsGenerator = new IcsGenerator($this->db);
        // IcsGenerator::saveToFile() creates the directory if needed
        return $icsGenerator->saveToFile(self::ICS_PATH);
    }

    /**
     * Run automatic notifications (expiration warnings, overdue, wishlist)
     *
     * Delegates to NotificationService to send loan expiration warnings,
     * overdue loan notifications, and wishlist availability alerts.
     *
     * @return array{expiration_warnings: int, overdue_notifications: int, wishlist_notifications: int, errors: array} Notification counts and any errors
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
     *
     * Finds all active loans with status 'prenotato' where data_prestito <= today,
     * updates their status to 'in_corso', marks the copy as 'prestato',
     * and recalculates book availability. Uses transactions for data integrity.
     *
     * @return int Number of loans activated
     * @throws \RuntimeException If query preparation fails
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
     *
     * @return int Number of reservations converted to loans
     * @throws \RuntimeException If query preparation fails
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
     *
     * Bulk updates all active loans that have passed their due date,
     * changing status from 'in_corso' to 'in_ritardo'.
     *
     * @return int Number of loans marked as overdue
     * @throws \RuntimeException If query preparation fails
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
     *
     * Executes maintenance tasks with a 60-minute cooldown when an admin
     * or staff user logs in. Creates its own database connection if needed.
     *
     * @param int $_userId User ID (unused, kept for hook signature compatibility)
     * @param array $userData User data array containing tipo_utente
     * @param mixed $_request Request object (unused, kept for hook signature compatibility)
     * @return void
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
                    'MaintenanceService (admin login): loans=%d, overdue=%d, reservations=%d, ics=%s',
                    $result['scheduled_loans_activated'],
                    $result['overdue_loans_updated'],
                    $result['reservations_converted'] ?? 0,
                    $result['ics_generated'] ? 'ok' : 'failed'
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
