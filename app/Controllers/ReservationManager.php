<?php

namespace App\Controllers;

use mysqli;

class ReservationManager {
    private $db;

    public function __construct(mysqli $db) {
        $this->db = $db;
    }

    /**
     * Process reservations when a book becomes available
     */
    public function processBookAvailability($bookId) {
        // Get the next reservation in queue
        $stmt = $this->db->prepare("
            SELECT r.*, u.email, u.nome, u.cognome
            FROM prenotazioni r
            JOIN utenti u ON r.utente_id = u.id
            WHERE r.libro_id = ? AND r.stato = 'attiva'
            ORDER BY r.queue_position ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $nextReservation = $result->fetch_assoc();

        if ($nextReservation) {
            // Check if the desired date range is available
            $startDate = $nextReservation['data_inizio_richiesta'];
            $endDate = $nextReservation['data_fine_richiesta'];

            if ($this->isDateRangeAvailable($bookId, $startDate, $endDate)) {
                // Create the loan - check return value to handle race conditions
                $loanCreated = $this->createLoanFromReservation($nextReservation);

                if ($loanCreated === false) {
                    // Race condition detected - loan creation failed
                    return false;
                }

                // Mark reservation as completed
                $stmt = $this->db->prepare("UPDATE prenotazioni SET stato = 'completata' WHERE id = ?");
                $stmt->bind_param('i', $nextReservation['id']);
                $stmt->execute();

                // Update queue positions for remaining reservations
                $this->updateQueuePositions($bookId);

                // Send notification to user
                $this->sendReservationNotification($nextReservation);

                return true;
            }
        }

        return false;
    }

    private function isDateRangeAvailable($bookId, $startDate, $endDate) {
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
        $totalCopies = (int)($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalStmt->close();

        if ($totalCopies === 0) {
            return false;
        }

        // Count overlapping loans (include pendente, prenotato, in_corso, in_ritardo)
        // Overlap check: existing_start <= our_end AND existing_end >= our_start
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as conflicts
            FROM prestiti
            WHERE libro_id = ?
            AND attivo = 1
            AND stato IN ('in_corso', 'in_ritardo', 'prenotato', 'pendente')
            AND data_prestito <= ? AND data_scadenza >= ?
        ");
        $stmt->bind_param('iss', $bookId, $endDate, $startDate);
        $stmt->execute();
        $loanConflicts = (int)($stmt->get_result()->fetch_assoc()['conflicts'] ?? 0);
        $stmt->close();

        // Count overlapping active reservations
        // Use COALESCE to handle NULL data_inizio_richiesta and data_fine_richiesta
        // Fall back to data_scadenza_prenotazione if specific dates are not set
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as conflicts
            FROM prenotazioni
            WHERE libro_id = ?
            AND stato = 'attiva'
            AND COALESCE(data_inizio_richiesta, DATE(data_scadenza_prenotazione)) <= ?
            AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione)) >= ?
        ");
        $stmt->bind_param('iss', $bookId, $endDate, $startDate);
        $stmt->execute();
        $reservationConflicts = (int)($stmt->get_result()->fetch_assoc()['conflicts'] ?? 0);
        $stmt->close();

        // Multi-copy: available if total occupied < total copies
        $totalOccupied = $loanConflicts + $reservationConflicts;
        return $totalOccupied < $totalCopies;
    }

    private function createLoanFromReservation($reservation) {
        $bookId = (int)$reservation['libro_id'];
        $startDate = $reservation['data_inizio_richiesta'];
        $endDate = $reservation['data_fine_richiesta'];
        $today = date('Y-m-d');

        // Determine state: 'prenotato' if future loan, 'in_corso' if immediate
        $isFutureLoan = ($startDate > $today);
        $newState = $isFutureLoan ? 'prenotato' : 'in_corso';

        // Find an available copy for this date range (no overlapping loans)
        // Consider 'disponibile' and 'prenotato' copies (exclude perso/danneggiato/manutenzione)
        // The NOT EXISTS clause ensures no overlapping loans for the requested dates
        $copyStmt = $this->db->prepare("
            SELECT c.id FROM copie c
            WHERE c.libro_id = ?
            AND c.stato IN ('disponibile', 'prenotato')
            AND NOT EXISTS (
                SELECT 1 FROM prestiti p
                WHERE p.copia_id = c.id
                AND p.attivo = 1
                AND p.stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
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

        $copyId = $copy ? (int)$copy['id'] : null;

        if (!$copyId) {
            // No copy available for the requested range – treat as failed allocation
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
            AND stato IN ('in_corso','prenotato','in_ritardo','pendente')
            AND data_prestito <= ? AND data_scadenza >= ?
            LIMIT 1
        ");
        $overlapCopyStmt->bind_param('iss', $copyId, $endDate, $startDate);
        $overlapCopyStmt->execute();
        $overlapCopy = $overlapCopyStmt->get_result()->fetch_assoc();
        $overlapCopyStmt->close();

        if ($overlapCopy) {
            // Abort if race detected
            return false;
        }

        // Create loan with copia_id
        $stmt = $this->db->prepare("
            INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param('iiisss',
            $reservation['libro_id'],
            $reservation['utente_id'],
            $copyId,
            $startDate,
            $endDate,
            $newState
        );
        $stmt->execute();
        $stmt->close();

        // Update copy status: 'prenotato' for future loans, 'prestato' for immediate
        $copyRepo = new \App\Models\CopyRepository($this->db);
        $copyStatus = $isFutureLoan ? 'prenotato' : 'prestato';
        $copyRepo->updateStatus($copyId, $copyStatus);

        // Update book availability
        $integrity = new \App\Support\DataIntegrity($this->db);
        $integrity->recalculateBookAvailability($bookId);

        return true;
    }

    private function updateQueuePositions($bookId) {
        $stmt = $this->db->prepare("
            UPDATE prenotazioni
            SET queue_position = queue_position - 1
            WHERE libro_id = ? AND stato = 'attiva' AND queue_position > 1
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
    }

    private function sendReservationNotification($reservation) {
        try {
            // Get book details
            $stmt = $this->db->prepare("
                SELECT l.titolo, COALESCE(l.isbn13, l.isbn10, '') as isbn,
                       GROUP_CONCAT(a.nome ORDER BY la.ruolo='principale' DESC, a.nome SEPARATOR ', ') AS autore
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.id = ?
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

            $variables = [
                'utente_nome' => $reservation['nome'],
                'libro_titolo' => $book['titolo'],
                'libro_autore' => $book['autore'] ?: 'Autore non specificato',
                'libro_isbn' => $book['isbn'] ?: 'N/A',
                'data_inizio' => date('d-m-Y', strtotime($reservation['data_inizio_richiesta'])),
                'data_fine' => date('d-m-Y', strtotime($reservation['data_fine_richiesta'])),
                'book_url' => rtrim($this->getBaseUrl(), '/') . $bookLink,
                'profile_url' => $this->getBaseUrl() . '/profile/prestiti'
            ];

            // Use NotificationService for consistent email handling
            $notificationService = new \App\Support\NotificationService($this->db);
            $success = $notificationService->sendReservationBookAvailable(
                $reservation['email'],
                $variables
            );

            // Mark as notified
            $stmt = $this->db->prepare("UPDATE prenotazioni SET notifica_inviata = 1 WHERE id = ?");
            $stmt->bind_param('i', $reservation['id']);
            $stmt->execute();
            $stmt->close();

            return $success;

        } catch (\Exception $e) {
            error_log("Failed to send reservation notification: " . $e->getMessage());
            return false;
        }
    }

    private function getBaseUrl(): string {
        // PRIORITY 1: Use APP_CANONICAL_URL from .env if configured
        // This ensures emails always use the production URL even when sent from CLI/localhost
        $canonicalUrl = $_ENV['APP_CANONICAL_URL'] ?? getenv('APP_CANONICAL_URL') ?: false;
        if ($canonicalUrl !== false) {
            $canonicalUrl = trim((string)$canonicalUrl);
            if ($canonicalUrl !== '' && filter_var($canonicalUrl, FILTER_VALIDATE_URL)) {
                return rtrim($canonicalUrl, '/');
            }
        }

        // PRIORITY 2: Fallback to HTTP_HOST with security validation
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Validate hostname format to prevent Host Header Injection attacks
        // Accepts: domain.com, subdomain.domain.com, localhost, localhost:8000, IP:port
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*(:[0-9]{1,5})?$/', $host)) {
            return $protocol . '://' . $host;
        }

        // Invalid hostname format - fallback to localhost
        return $protocol . '://localhost';
    }

    /**
     * Check if book is available for immediate loan (multi-copy aware)
     */
    public function isBookAvailableForImmediateLoan($bookId) {
        $today = date('Y-m-d');

        // Count total lendable copies for this book (exclude perso, danneggiato, manutenzione)
        $totalStmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM copie
            WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')
        ");
        $totalStmt->bind_param('i', $bookId);
        $totalStmt->execute();
        $totalCopies = (int)($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalStmt->close();

        if ($totalCopies === 0) {
            return false;
        }

        // Count active loans that overlap with today (include prenotato)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as active_loans
            FROM prestiti
            WHERE libro_id = ?
            AND attivo = 1
            AND stato IN ('in_corso', 'in_ritardo', 'prenotato', 'pendente')
            AND data_prestito <= ? AND data_scadenza >= ?
        ");
        $stmt->bind_param('iss', $bookId, $today, $today);
        $stmt->execute();
        $activeLoans = (int)($stmt->get_result()->fetch_assoc()['active_loans'] ?? 0);
        $stmt->close();

        // Count active reservations that overlap with today
        // Use COALESCE to handle NULL data_inizio_richiesta and data_fine_richiesta
        // Fall back to data_scadenza_prenotazione if specific dates are not set
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as active_reservations
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            AND COALESCE(data_inizio_richiesta, DATE(data_scadenza_prenotazione)) <= ?
            AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione)) >= ?
        ");
        $stmt->bind_param('iss', $bookId, $today, $today);
        $stmt->execute();
        $activeReservations = (int)($stmt->get_result()->fetch_assoc()['active_reservations'] ?? 0);
        $stmt->close();

        // Multi-copy: available if total occupied < total copies
        $totalOccupied = $activeLoans + $activeReservations;
        return $totalOccupied < $totalCopies;
    }

    /**
     * Cancel expired reservations
     */
    public function cancelExpiredReservations() {
        $stmt = $this->db->prepare("
            UPDATE prenotazioni
            SET stato = 'annullata'
            WHERE stato = 'attiva'
            AND data_scadenza_prenotazione IS NOT NULL
            AND data_scadenza_prenotazione < NOW()
        ");
        $stmt->execute();

        // Update queue positions for affected books
        $stmt = $this->db->prepare("
            SELECT DISTINCT libro_id
            FROM prenotazioni
            WHERE stato = 'annullata'
            AND data_scadenza_prenotazione < NOW()
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $this->reorderQueuePositions($row['libro_id']);
        }
    }

    private function reorderQueuePositions($bookId) {
        // Get all active reservations ordered by original queue position
        $stmt = $this->db->prepare("
            SELECT id FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            ORDER BY queue_position ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $position = 1;
        while ($row = $result->fetch_assoc()) {
            $updateStmt = $this->db->prepare("UPDATE prenotazioni SET queue_position = ? WHERE id = ?");
            $updateStmt->bind_param('ii', $position, $row['id']);
            $updateStmt->execute();
            $position++;
        }
    }
}
