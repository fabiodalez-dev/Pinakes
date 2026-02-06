<?php

namespace App\Controllers;

use mysqli;

/**
 * Manages book reservations and their conversion to loans
 *
 * Handles the reservation queue system, availability checking,
 * and automatic conversion of reservations to loans when books
 * become available and the requested date arrives.
 *
 * @package App\Controllers
 */
class ReservationManager
{
    /** @var mysqli Database connection */
    private $db;

    /**
     * Create a new ReservationManager instance
     *
     * @param mysqli $db Database connection
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Check if a transaction is currently active
     *
     * Uses mysqli's internal flag to detect active transactions.
     * This is needed because begin_transaction() inside an active transaction
     * would silently succeed in MySQLi but cause undefined behavior.
     *
     * @return bool True if a transaction is active
     */
    private function isInTransaction(): bool
    {
        // Check if autocommit is disabled (indicates active transaction)
        // Note: begin_transaction() internally disables autocommit
        $result = $this->db->query("SELECT @@autocommit as ac");
        if ($result) {
            $row = $result->fetch_assoc();
            return (int) ($row['ac'] ?? 1) === 0;
        }
        return false;
    }

    /**
     * Begin transaction only if not already in one
     *
     * @return bool True if we started a new transaction, false if already in one
     */
    private function beginTransactionIfNeeded(): bool
    {
        if ($this->isInTransaction()) {
            return false; // Already in transaction, don't start a new one
        }
        $this->db->begin_transaction();
        return true;
    }

    /**
     * Commit transaction only if we started it
     *
     * @param bool $ownTransaction Whether we started the transaction
     */
    private function commitIfOwned(bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $this->db->commit();
        }
    }

    /**
     * Rollback transaction only if we started it
     *
     * @param bool $ownTransaction Whether we started the transaction
     */
    private function rollbackIfOwned(bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $this->db->rollback();
        }
    }

    /**
     * Process reservations when a book becomes available
     *
     * Finds the next date-eligible reservation (where start date <= today)
     * and attempts to convert it to a pending loan. Only processes one
     * reservation at a time to maintain queue order.
     *
     * Uses row-level locking (SELECT FOR UPDATE) to prevent race conditions
     * where multiple processes try to convert the same reservation.
     *
     * @param int $bookId The book ID to process reservations for
     * @return bool True if a reservation was successfully converted to a loan
     */
    public function processBookAvailability($bookId)
    {
        $today = date('Y-m-d');
        $ownTransaction = $this->beginTransactionIfNeeded();

        try {
            // Lock the book row first to serialize all reservation processing for this book
            $lockBookStmt = $this->db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockBookStmt->bind_param('i', $bookId);
            $lockBookStmt->execute();
            $lockBookStmt->close();

            // Get the next date-eligible reservation in queue
            // Only process reservations where start date <= today (ready to convert to loan)
            // Note: Book-level lock above serializes all processing for this book,
            // so we don't need row-level lock on prenotazioni here
            $stmt = $this->db->prepare("
                SELECT r.*, u.email, u.nome, u.cognome
                FROM prenotazioni r
                JOIN utenti u ON r.utente_id = u.id
                WHERE r.libro_id = ? AND r.stato = 'attiva'
                AND r.data_inizio_richiesta <= ?
                ORDER BY r.queue_position ASC
                LIMIT 1
            ");
            $stmt->bind_param('is', $bookId, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            $nextReservation = $result->fetch_assoc();
            $stmt->close();

            if ($nextReservation) {
                // Check if the desired date range is available
                $startDate = $nextReservation['data_inizio_richiesta'];
                $endDate = $nextReservation['data_fine_richiesta'];

                if ($this->isDateRangeAvailable($bookId, $startDate, $endDate)) {
                    // Create the loan - check return value to handle race conditions
                    // Note: createLoanFromReservation() handles its own transaction internally
                    // when called standalone, but here we're already in a transaction
                    $loanCreated = $this->createLoanFromReservation($nextReservation);

                    if ($loanCreated === false) {
                        // Race condition detected - loan creation failed
                        $this->rollbackIfOwned($ownTransaction);
                        return false;
                    }

                    // Mark reservation as completed
                    $stmt = $this->db->prepare("UPDATE prenotazioni SET stato = 'completata' WHERE id = ?");
                    $stmt->bind_param('i', $nextReservation['id']);
                    $stmt->execute();
                    $stmt->close();

                    // Update queue positions for remaining reservations
                    $this->updateQueuePositions($bookId);

                    $this->commitIfOwned($ownTransaction);

                    // Send notification ONLY if we own the transaction (committed above)
                    // If external transaction, caller is responsible for notifications after their commit
                    if ($ownTransaction) {
                        $this->sendReservationNotification($nextReservation);
                    }

                    return true;
                }
            }

            $this->commitIfOwned($ownTransaction);
            return false;

        } catch (\Exception $e) {
            $this->rollbackIfOwned($ownTransaction);
            error_log("processBookAvailability error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a date range has available copies (multi-copy aware)
     *
     * Counts loanable copies and overlapping loans to determine
     * if at least one copy is available for the requested period.
     * Note: Does not count reservations, as they use a queue system.
     *
     * @param int $bookId The book ID
     * @param string|null $startDate Start date (Y-m-d format)
     * @param string|null $endDate End date (Y-m-d format)
     * @return bool True if at least one copy is available
     */
    private function isDateRangeAvailable($bookId, $startDate, $endDate)
    {
        if (!$startDate || !$endDate) {
            return false;
        }

        // Multi-copy aware: count only loanable copies (exclude lost/damaged/maintenance)
        $totalStmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM copie
            WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')
        ");
        $totalStmt->bind_param('i', $bookId);
        $totalStmt->execute();
        $totalCopies = (int) ($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalStmt->close();

        if ($totalCopies === 0) {
            return false;
        }

        // Count overlapping loans (approved states only)
        // Overlap check: existing_start <= our_end AND existing_end >= our_start
        // Note: We only count LOANS here, not reservations, because:
        // - This method is only used by processBookAvailability() to convert reservations to loans
        // - Reservations are in a queue system - they wait their turn, they don't block each other
        // - When converting reservation #1, other reservations shouldn't prevent the conversion
        // Note: 'da_ritirare' counts as occupied even if copy is physically available
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as conflicts
            FROM prestiti
            WHERE libro_id = ?
            AND attivo = 1
            AND stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')
            AND data_prestito <= ? AND data_scadenza >= ?
        ");
        $stmt->bind_param('iss', $bookId, $endDate, $startDate);
        $stmt->execute();
        $loanConflicts = (int) ($stmt->get_result()->fetch_assoc()['conflicts'] ?? 0);
        $stmt->close();

        // Multi-copy: available if there's at least one free slot
        return $loanConflicts < $totalCopies;
    }

    /**
     * Create a pending loan from an approved reservation
     *
     * Finds an available copy, locks it to prevent race conditions,
     * and creates a loan with state 'pendente' and origin 'prenotazione'.
     * The loan requires admin confirmation of physical book pickup.
     *
     * @param array{libro_id: int, utente_id: int, data_inizio_richiesta: string, data_fine_richiesta: string} $reservation Reservation data
     * @return int|false Loan ID on success, false on failure (no copies available or race condition)
     */
    private function createLoanFromReservation($reservation)
    {
        $bookId = (int) $reservation['libro_id'];
        $startDate = $reservation['data_inizio_richiesta'];
        $endDate = $reservation['data_fine_richiesta'];

        // Always create as 'pendente' (attivo=0) - requires admin confirmation of physical pickup
        // Origin is 'prenotazione' to distinguish from manual requests
        $newState = 'pendente';
        $origine = 'prenotazione';

        // Start transaction only if not already in one (e.g., called from MaintenanceService)
        // This prevents nested transaction issues with MySQLi
        $ownTransaction = $this->beginTransactionIfNeeded();

        try {
            // Find an available copy for this date range (no overlapping loans)
            // Consider 'disponibile' and 'prenotato' copies (exclude perso/danneggiato/manutenzione)
            // The NOT EXISTS clause ensures no overlapping loans for the requested dates
            // Note: 'da_ritirare' copies are still 'disponibile' but have a loan reservation
            $copyStmt = $this->db->prepare("
                SELECT c.id FROM copie c
                WHERE c.libro_id = ?
                AND c.stato IN ('disponibile', 'prenotato')
                AND NOT EXISTS (
                    SELECT 1 FROM prestiti p
                    WHERE p.copia_id = c.id
                    AND p.attivo = 1
                AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo')
                    AND p.data_prestito <= ?
                    AND p.data_scadenza >= ?
                )
                LIMIT 1
            ");
            $copyStmt->bind_param('iss', $bookId, $endDate, $startDate);
            $copyStmt->execute();
            $copyResult = $copyStmt->get_result();
            $copy = $copyResult->fetch_assoc();
            $copyStmt->close();

            $copyId = $copy ? (int) $copy['id'] : null;

            if (!$copyId) {
                // No copy available for the requested range – treat as failed allocation
                $this->rollbackIfOwned($ownTransaction);
                return false;
            }

            // Lock copy and re-check overlap to prevent race conditions
            $lockCopyStmt = $this->db->prepare("SELECT id FROM copie WHERE id = ? FOR UPDATE");
            $lockCopyStmt->bind_param('i', $copyId);
            $lockCopyStmt->execute();
            $lockCopyStmt->close();

            $overlapCopyStmt = $this->db->prepare("
                SELECT 1 FROM prestiti
                WHERE copia_id = ? AND attivo = 1
                AND stato IN ('in_corso','da_ritirare','prenotato','in_ritardo')
                AND data_prestito <= ? AND data_scadenza >= ?
                LIMIT 1
            ");
            $overlapCopyStmt->bind_param('iss', $copyId, $endDate, $startDate);
            $overlapCopyStmt->execute();
            $overlapCopy = $overlapCopyStmt->get_result()->fetch_assoc();
            $overlapCopyStmt->close();

            if ($overlapCopy) {
                // Abort if race detected
                $this->rollbackIfOwned($ownTransaction);
                return false;
            }

            // Create loan with copia_id and origine='prenotazione'
            // Copy stays available until admin confirms physical pickup
            $stmt = $this->db->prepare("
                INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, origine, attivo)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->bind_param(
                'iiissss',
                $reservation['libro_id'],
                $reservation['utente_id'],
                $copyId,
                $startDate,
                $endDate,
                $newState,
                $origine
            );
            $stmt->execute();
            $loanId = $this->db->insert_id;
            $stmt->close();

            // Verify INSERT succeeded (insert_id = 0 means failure)
            if ($loanId <= 0) {
                $this->rollbackIfOwned($ownTransaction);
                return false;
            }

            // Note: Copy status is NOT updated here - it remains 'disponibile'
            // The copy will be marked as 'prestato' when admin approves the pickup
            // via LoanApprovalController::approveLoan()

            // Update book availability
            $integrity = new \App\Support\DataIntegrity($this->db);
            $integrity->recalculateBookAvailability($bookId);

            $this->commitIfOwned($ownTransaction);
            return $loanId;

        } catch (\Exception $e) {
            $this->rollbackIfOwned($ownTransaction);
            error_log("Failed to create loan from reservation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Decrement queue positions after a reservation is completed
     *
     * @param int $bookId The book ID
     * @return void
     */
    private function updateQueuePositions($bookId)
    {
        $stmt = $this->db->prepare("
            UPDATE prenotazioni
            SET queue_position = queue_position - 1
            WHERE libro_id = ? AND stato = 'attiva' AND queue_position > 1
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
    }

    /**
     * Send notification email when reservation book becomes available
     *
     * Sends the 'reservation_book_available' email template to the user.
     * Updates the notifica_inviata flag only on successful send.
     *
     * @param array{id: int, libro_id: int, email: string, nome: string, cognome: string, data_inizio_richiesta: string, data_fine_richiesta: string} $reservation Reservation data with user info
     * @return bool True if email was sent successfully
     */
    private function sendReservationNotification(array $reservation): bool
    {
        try {
            // Get book details
            $stmt = $this->db->prepare("
                SELECT l.titolo, COALESCE(l.isbn13, l.isbn10, '') as isbn,
                       GROUP_CONCAT(a.nome ORDER BY la.ruolo='principale' DESC, a.nome SEPARATOR ', ') AS autore
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.id = ? AND l.deleted_at IS NULL
                GROUP BY l.id, l.titolo, l.isbn13, l.isbn10
            ");
            $stmt->bind_param('i', $reservation['libro_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $book = $result->fetch_assoc();
            $stmt->close();

            if (!$book) {
                return false;
            }

            $bookLink = book_url([
                'id' => $reservation['libro_id'],
                'titolo' => $book['titolo'] ?? '',
                'autore' => $book['autore'] ?? ''
            ]);

            // Format dates according to installation locale for email templates
            $locale = \App\Support\I18n::getInstallationLocale();
            $isItalian = str_starts_with($locale, 'it');
            $dateFormat = $isItalian ? 'd-m-Y' : 'Y-m-d';

            $variables = [
                'utente_nome' => $reservation['nome'],
                'libro_titolo' => $book['titolo'],
                'libro_autore' => $book['autore'] ?: 'Autore non specificato',
                'libro_isbn' => $book['isbn'] ?: 'N/A',
                'data_inizio' => date($dateFormat, strtotime($reservation['data_inizio_richiesta'])),
                'data_fine' => date($dateFormat, strtotime($reservation['data_fine_richiesta'])),
                'book_url' => rtrim($this->getBaseUrl(), '/') . $bookLink,
                'profile_url' => $this->getBaseUrl() . '/profile/prestiti'
            ];

            // Use NotificationService for consistent email handling
            $notificationService = new \App\Support\NotificationService($this->db);
            $success = $notificationService->sendReservationBookAvailable(
                $reservation['email'],
                $variables
            );

            // Only mark as notified if email was actually sent successfully
            // This allows retry on next cron/maintenance run if email fails
            if ($success) {
                $stmt = $this->db->prepare("UPDATE prenotazioni SET notifica_inviata = 1 WHERE id = ?");
                $stmt->bind_param('i', $reservation['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                error_log("ReservationManager: Email send failed for reservation ID {$reservation['id']}, will retry on next run");
            }

            return $success;

        } catch (\Exception $e) {
            error_log("Failed to send reservation notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get base URL for the application
     *
     * @throws \RuntimeException If unable to determine base URL
     */
    private function getBaseUrl(): string
    {
        // PRIORITY 1: Use APP_CANONICAL_URL from .env if configured
        // This ensures emails always use the production URL even when sent from CLI/localhost
        // This is the recommended approach for production environments
        $canonicalUrl = $_ENV['APP_CANONICAL_URL'] ?? getenv('APP_CANONICAL_URL') ?: false;
        if ($canonicalUrl !== false) {
            $canonicalUrl = trim((string) $canonicalUrl);
            if ($canonicalUrl !== '' && filter_var($canonicalUrl, FILTER_VALIDATE_URL)) {
                return rtrim($canonicalUrl, '/');
            }
        }

        // PRIORITY 2: Fallback to HTTP_HOST with security validation
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? null;

        // Validate hostname format to prevent Host Header Injection attacks
        // Accepts: domain.com, subdomain.domain.com, localhost, localhost:8000, IP:port
        if ($host !== null && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*(:[0-9]{1,5})?$/', $host)) {
            return $protocol . '://' . $host;
        }

        // CRITICAL: Cannot determine base URL - configuration required
        // This prevents the application from using hardcoded localhost on production
        throw new \RuntimeException(
            'Cannot determine application base URL. ' .
            'Please configure APP_CANONICAL_URL in your .env file. ' .
            'Example: APP_CANONICAL_URL=https://yourdomain.com'
        );
    }

    /**
     * Check if book is available for loan (multi-copy aware)
     *
     * @param int $bookId The book ID
     * @param string|null $startDate Start date of the requested period (Y-m-d format). Defaults to today.
     * @param string|null $endDate End date of the requested period (Y-m-d format). Defaults to startDate.
     * @return bool True if at least one copy is available for the entire requested period
     */
    public function isBookAvailableForImmediateLoan($bookId, ?string $startDate = null, ?string $endDate = null, ?int $excludeUserId = null): bool
    {
        // Default to today if no dates provided
        $startDate = $startDate ?: date('Y-m-d');
        $endDate = $endDate ?: $startDate;

        // Validate date format and ensure start <= end
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return false;
        }
        if ($startDate > $endDate) {
            return false;
        }

        // First check if ANY copies exist in the copie table for this book
        // This distinguishes between "no records" vs "all copies lost/damaged"
        $existStmt = $this->db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ?");
        $existStmt->bind_param('i', $bookId);
        $existStmt->execute();
        $totalCopiesExist = (int) ($existStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $existStmt->close();

        if ($totalCopiesExist > 0) {
            // Copies exist in copie table - count only loanable ones
            // Exclude permanently unavailable: 'perso', 'danneggiato', 'manutenzione'
            $totalStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM copie
                WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')
            ");
            $totalStmt->bind_param('i', $bookId);
            $totalStmt->execute();
            $totalCopies = (int) ($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $totalStmt->close();

            // If all copies are lost/damaged, book is unavailable
            if ($totalCopies === 0) {
                return false;
            }
        } else {
            // No copies in copie table - fallback to libri.copie_totali for legacy data
            $fallbackStmt = $this->db->prepare("SELECT IFNULL(copie_totali, 1) AS copie_totali FROM libri WHERE id = ? AND deleted_at IS NULL");
            $fallbackStmt->bind_param('i', $bookId);
            $fallbackStmt->execute();
            $fallbackRow = $fallbackStmt->get_result()?->fetch_assoc();
            $fallbackStmt->close();

            // If book doesn't exist or is soft-deleted, return false
            if ($fallbackRow === null) {
                return false;
            }
            $totalCopies = (int) $fallbackRow['copie_totali'];

            if ($totalCopies === 0) {
                return false;
            }
        }

        // Count active loans that overlap with the requested period (approved states only)
        // Overlap condition: existing_start <= our_end AND existing_end >= our_start
        // Note: We don't exclude user's own loans here - if they have a loan, they have the book.
        // Unless we want to allow them to borrow ANOTHER copy? Usually no.
        // Note: 'da_ritirare' counts as occupied even if copy is physically available
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as active_loans
            FROM prestiti
            WHERE libro_id = ?
            AND attivo = 1
            AND stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')
            AND data_prestito <= ? AND data_scadenza >= ?
        ");
        $stmt->bind_param('iss', $bookId, $endDate, $startDate);
        $stmt->execute();
        $activeLoans = (int) ($stmt->get_result()->fetch_assoc()['active_loans'] ?? 0);
        $stmt->close();

        // Count active reservations that overlap with the requested period
        // Use COALESCE for safety, though data_inizio/fine_richiesta are always set
        $sql = "
            SELECT COUNT(*) as active_reservations
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            AND COALESCE(data_inizio_richiesta, data_scadenza_prenotazione) <= ?
            AND COALESCE(data_fine_richiesta, data_scadenza_prenotazione) >= ?
        ";

        $params = [$bookId, $endDate, $startDate];
        $types = 'iss';

        if ($excludeUserId !== null) {
            $sql .= " AND utente_id != ?";
            $params[] = $excludeUserId;
            $types .= 'i';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $activeReservations = (int) ($stmt->get_result()->fetch_assoc()['active_reservations'] ?? 0);
        $stmt->close();

        // Multi-copy: available if total occupied < total copies
        $totalOccupied = $activeLoans + $activeReservations;
        return $totalOccupied < $totalCopies;
    }

    /**
     * Cancel reservations that have passed their expiration date
     *
     * Marks reservations as 'annullata' when data_scadenza_prenotazione
     * is in the past, and reorders queue positions for affected books.
     *
     * Uses transaction to ensure atomic update and queue reordering.
     *
     * @return int Number of reservations cancelled
     */
    public function cancelExpiredReservations(): int
    {
        $ownTransaction = $this->beginTransactionIfNeeded();

        try {
            // First, get affected book IDs BEFORE updating
            // Use FOR UPDATE to lock rows and prevent concurrent modifications
            $selectStmt = $this->db->prepare("
                SELECT DISTINCT libro_id
                FROM prenotazioni
                WHERE stato = 'attiva'
                AND data_scadenza_prenotazione IS NOT NULL
                AND data_scadenza_prenotazione < NOW()
                FOR UPDATE
            ");
            $selectStmt->execute();
            $result = $selectStmt->get_result();
            $affectedBooks = [];
            while ($row = $result->fetch_assoc()) {
                $affectedBooks[] = (int) $row['libro_id'];
            }
            $selectStmt->close();

            // Now update the reservations
            $stmt = $this->db->prepare("
                UPDATE prenotazioni
                SET stato = 'annullata'
                WHERE stato = 'attiva'
                AND data_scadenza_prenotazione IS NOT NULL
                AND data_scadenza_prenotazione < NOW()
            ");
            $stmt->execute();
            $cancelledCount = $this->db->affected_rows;
            $stmt->close();

            // Reorder queue positions for affected books
            foreach ($affectedBooks as $bookId) {
                $this->reorderQueuePositions($bookId);
            }

            $this->commitIfOwned($ownTransaction);
            return $cancelledCount;

        } catch (\Exception $e) {
            $this->rollbackIfOwned($ownTransaction);
            error_log("cancelExpiredReservations error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Reorder queue positions to be sequential starting from 1
     *
     * Called after cancellations to ensure no gaps in queue positions.
     *
     * @param int $bookId The book ID
     * @return void
     */
    private function reorderQueuePositions($bookId)
    {
        // Use MySQL user variables to update all positions in a single query
        // This avoids N+1 queries when there are many active reservations
        $this->db->query("SET @pos := 0");
        $stmt = $this->db->prepare("
            UPDATE prenotazioni
            SET queue_position = (@pos := @pos + 1)
            WHERE libro_id = ? AND stato = 'attiva'
            ORDER BY queue_position ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $stmt->close();
    }
}
