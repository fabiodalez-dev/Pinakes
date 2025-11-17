<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Exception;
use DateTime;
use DateInterval;
use App\Support\NotificationService;

class ReservationsController {
    private $db;

    public function __construct(mysqli $db = null) {
        // Accept DB connection from dependency injection (preferred)
        if ($db !== null) {
            $this->db = $db;
            return;
        }

        // Fallback: create own connection if not provided (legacy compatibility)
        $settings = require __DIR__ . '/../../config/settings.php';
        $cfg = $settings['db'];

        $socket = $cfg['socket'] ?? null;
        $this->db = new mysqli(
            $cfg['hostname'],
            $cfg['username'],
            $cfg['password'],
            $cfg['database'],
            $cfg['port'],
            $socket
        );
        if ($this->db->connect_error) {
            throw new Exception("Connection failed: " . $this->db->connect_error);
        }
        $this->db->set_charset($cfg['charset']);
    }

    public function getBookAvailability($request, $response, $args) {
        $bookId = (int)$args['id'];

        // Get current and future loans for this book (including pending)
        $stmt = $this->db->prepare("
            SELECT data_prestito, data_scadenza, data_restituzione, stato
            FROM prestiti
            WHERE libro_id = ? AND stato IN ('in_corso', 'in_ritardo', 'pendente')
            ORDER BY data_prestito
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $loansResult = $stmt->get_result();
        $currentLoans = $loansResult->fetch_all(MYSQLI_ASSOC);

        // Get existing reservations
        $stmt = $this->db->prepare("
            SELECT data_inizio_richiesta, data_fine_richiesta, stato, queue_position
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            ORDER BY queue_position ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $reservationsResult = $stmt->get_result();
        $existingReservations = $reservationsResult->fetch_all(MYSQLI_ASSOC);

        // Calculate availability
        $availability = $this->calculateAvailability($currentLoans, $existingReservations);

        $response->getBody()->write(json_encode([
            'success' => true,
            'availability' => $availability,
            'current_loans' => $currentLoans,
            'existing_reservations' => $existingReservations
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function calculateAvailability($currentLoans, $existingReservations) {
        $unavailableDates = [];
        $today = date('Y-m-d');

        // Mark dates as unavailable for current and pending loans
        foreach ($currentLoans as $loan) {
            $startDate = $loan['data_prestito'];
            $endDate = $loan['data_scadenza'];

            // Skip if dates are invalid
            if (!$startDate || !$endDate) continue;

            $current = new DateTime($startDate);
            $end = new DateTime($endDate);

            while ($current <= $end) {
                $unavailableDates[] = $current->format('Y-m-d');
                $current->add(new DateInterval('P1D'));
            }
        }

        // Mark dates as unavailable for existing reservations
        foreach ($existingReservations as $reservation) {
            if ($reservation['data_inizio_richiesta'] && $reservation['data_fine_richiesta']) {
                $startDate = $reservation['data_inizio_richiesta'];
                $endDate = $reservation['data_fine_richiesta'];

                $current = new DateTime($startDate);
                $end = new DateTime($endDate);

                while ($current <= $end) {
                    $unavailableDates[] = $current->format('Y-m-d');
                    $current->add(new DateInterval('P1D'));
                }
            }
        }

        return [
            'unavailable_dates' => array_unique($unavailableDates),
            'earliest_available' => $this->getEarliestAvailableDate($unavailableDates, $today)
        ];
    }

    private function getEarliestAvailableDate($unavailableDates, $today) {
        $current = new DateTime($today);

        while (true) {
            $dateStr = $current->format('Y-m-d');
            if (!in_array($dateStr, $unavailableDates)) {
                return $dateStr;
            }
            $current->add(new DateInterval('P1D'));
        }
    }

    public function createReservation($request, $response, $args) {
        $bookId = (int)$args['id'];

        // Try to get JSON data properly
        $contentType = $request->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $request->getBody()->getContents();
            $data = json_decode($rawBody, true) ?: [];
        } else {
            $data = $request->getParsedBody() ?: [];
        }

        // Validate CSRF token
        $token = $data['csrf_token'] ?? '';
        if (!\App\Support\Csrf::validate($token)) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Token CSRF non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validate user session
        $sessionUser = $_SESSION['user'] ?? null;
        $sessionUserId = $sessionUser['id'] ?? ($_SESSION['user_id'] ?? null);

        if ($sessionUserId === null) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Accesso non autorizzato')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $userId = (int)$sessionUserId;
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        if (!$startDate) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Data inizio richiesta mancante')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // If no end date specified, set it to 1 month from start date
        if (!$endDate) {
            $endDateTime = new DateTime($startDate);
            $endDateTime->add(new DateInterval('P1M'));
            $endDate = $endDateTime->format('Y-m-d');
        }

        // Check if dates are available
        $availability = $this->getBookAvailabilityData($bookId);
        $requestedDates = $this->getDateRange($startDate, $endDate);
        $unavailableDates = $availability['unavailable_dates'];

        $conflictDates = array_intersect($requestedDates, $unavailableDates);
        if (!empty($conflictDates)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Alcune date richieste non sono disponibili'),
                'conflict_dates' => $conflictDates
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Get next queue position
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(queue_position), 0) + 1 as next_position FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $nextPosition = $result->fetch_assoc()['next_position'];

        // Start transaction for concurrency control
        $this->db->begin_transaction();

        try {
            // Lock book row for update to prevent race conditions
            $stmt = $this->db->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $stmt->close();

            // Create pending loan request
            $stmt = $this->db->prepare("
                INSERT INTO prestiti
                (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo)
                VALUES (?, ?, ?, ?, 'pendente', 0)
            ");
            $stmt->bind_param('iiss', $bookId, $userId, $startDate, $endDate);

            if ($stmt->execute()) {
                $loanRequestId = $this->db->insert_id;
                $this->db->commit();

                // Send notification to admins
                try {
                    $notificationService = new NotificationService($this->db);
                    $notificationService->notifyLoanRequest($loanRequestId);
                } catch (Exception $notifError) {
                    error_log("Error sending notification for loan request: " . $notifError->getMessage());
                    // Don't fail the loan request creation if notification fails
                }

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => __('Richiesta di prestito inviata con successo'),
                    'loan_request_id' => $loanRequestId,
                    'status' => 'pending_approval'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            } else {
                $this->db->rollback();
                $response->getBody()->write(json_encode(['success' => false, 'message' => __('Errore nella creazione della richiesta di prestito')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error creating reservation: " . $e->getMessage());
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Errore del server')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function getBookAvailabilityData($bookId) {
        // Get current and future loans for this book (including pending)
        $stmt = $this->db->prepare("
            SELECT data_prestito, data_scadenza, data_restituzione, stato
            FROM prestiti
            WHERE libro_id = ? AND stato IN ('in_corso', 'in_ritardo', 'pendente')
            ORDER BY data_prestito
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $loansResult = $stmt->get_result();
        $currentLoans = $loansResult->fetch_all(MYSQLI_ASSOC);

        // Get existing reservations
        $stmt = $this->db->prepare("
            SELECT data_inizio_richiesta, data_fine_richiesta, stato, queue_position
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            ORDER BY queue_position ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $reservationsResult = $stmt->get_result();
        $existingReservations = $reservationsResult->fetch_all(MYSQLI_ASSOC);

        return $this->calculateAvailability($currentLoans, $existingReservations);
    }

    private function getDateRange($startDate, $endDate) {
        $dates = [];
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);

        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current->add(new DateInterval('P1D'));
        }

        return $dates;
    }
}
