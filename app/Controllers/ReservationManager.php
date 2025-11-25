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
                // Create the loan
                $this->createLoanFromReservation($nextReservation);

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

        // Check for conflicting loans
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as conflicts
            FROM prestiti
            WHERE libro_id = ?
            AND stato IN ('in_corso', 'in_ritardo')
            AND (
                (data_prestito <= ? AND data_scadenza >= ?) OR
                (data_prestito <= ? AND data_scadenza >= ?) OR
                (data_prestito >= ? AND data_prestito <= ?)
            )
        ");
        $stmt->bind_param('issssss', $bookId, $startDate, $startDate, $endDate, $endDate, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $conflicts = (int)$result->fetch_assoc()['conflicts'];

        // Check for conflicting active reservations with earlier queue positions
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as conflicts
            FROM prenotazioni
            WHERE libro_id = ?
            AND stato = 'attiva'
            AND data_inizio_richiesta IS NOT NULL
            AND data_fine_richiesta IS NOT NULL
            AND (
                (data_inizio_richiesta <= ? AND data_fine_richiesta >= ?) OR
                (data_inizio_richiesta <= ? AND data_fine_richiesta >= ?) OR
                (data_inizio_richiesta >= ? AND data_inizio_richiesta <= ?)
            )
        ");
        $stmt->bind_param('issssss', $bookId, $startDate, $startDate, $endDate, $endDate, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $reservationConflicts = (int)$result->fetch_assoc()['conflicts'];

        return $conflicts === 0 && $reservationConflicts === 0;
    }

    private function createLoanFromReservation($reservation) {
        $stmt = $this->db->prepare("
            INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo)
            VALUES (?, ?, ?, ?, 'in_corso', 1)
        ");
        $stmt->bind_param('iiss',
            $reservation['libro_id'],
            $reservation['utente_id'],
            $reservation['data_inizio_richiesta'],
            $reservation['data_fine_richiesta']
        );
        $stmt->execute();
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
     * Check if book is available for immediate loan (no active reservations)
     */
    public function isBookAvailableForImmediateLoan($bookId) {
        // Check for active loans
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as active_loans
            FROM prestiti
            WHERE libro_id = ? AND stato IN ('in_corso', 'in_ritardo')
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $activeLoans = (int)$result->fetch_assoc()['active_loans'];

        if ($activeLoans > 0) {
            return false;
        }

        // Check for active reservations
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as active_reservations
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $activeReservations = (int)$result->fetch_assoc()['active_reservations'];

        return $activeReservations === 0;
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
