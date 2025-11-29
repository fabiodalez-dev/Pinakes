<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Exception;
use DateTime;
use DateInterval;
use App\Support\NotificationService;

class ReservationsController
{
    private $db;

    public function __construct(?mysqli $db = null)
    {
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

    public function getBookAvailability($request, $response, $args)
    {
        $bookId = (int) $args['id'];
        $totalCopies = $this->getBookTotalCopies($bookId);

        // Get current and future loans for this book (including pending and scheduled)
        $stmt = $this->db->prepare("
            SELECT data_prestito, data_scadenza, data_restituzione, stato
            FROM prestiti
            WHERE libro_id = ? AND stato IN ('in_corso', 'in_ritardo', 'pendente', 'prenotato')
            ORDER BY data_prestito
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $loansResult = $stmt->get_result();
        $currentLoans = $loansResult->fetch_all(MYSQLI_ASSOC);

        // Get existing reservations
        $stmt = $this->db->prepare("
            SELECT data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, stato, queue_position, utente_id
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            ORDER BY queue_position ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $reservationsResult = $stmt->get_result();
        $existingReservations = $reservationsResult->fetch_all(MYSQLI_ASSOC);

        // Calculate availability considering total copies
        // Note: For public API, we don't exclude any user
        $availability = $this->calculateAvailability($currentLoans, $existingReservations, $totalCopies);

        $response->getBody()->write(json_encode([
            'success' => true,
            'availability' => $availability,
            'current_loans' => $currentLoans,
            'existing_reservations' => $existingReservations
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function calculateAvailability($currentLoans, $existingReservations, int $totalCopies, ?string $startDate = null, int $days = 730, ?int $excludeUserId = null)
    {
        $start = $startDate ? new DateTime($startDate) : new DateTime(); // today by default
        $start->setTime(0, 0, 0);

        // Normalize intervals
        $loanIntervals = [];
        foreach ($currentLoans as $loan) {
            $startDateLoan = $loan['data_prestito'] ?? null;
            $endDateLoan = $loan['data_restituzione'] ?? $loan['data_scadenza'] ?? null;
            if (!$startDateLoan) {
                continue;
            }
            if (!$endDateLoan || $endDateLoan < $startDateLoan) {
                $endDateLoan = $startDateLoan;
            }
            $loanIntervals[] = [$startDateLoan, $endDateLoan];
        }

        $reservationIntervals = [];
        foreach ($existingReservations as $reservation) {
            // Skip reservation if it belongs to the excluded user (e.g. the user making the request)
            if ($excludeUserId !== null && isset($reservation['utente_id']) && (int) $reservation['utente_id'] === $excludeUserId) {
                continue;
            }

            $resStart = $reservation['data_inizio_richiesta'] ?? null;
            if (!$resStart) {
                continue;
            }
            $resEnd = $reservation['data_fine_richiesta'] ?? null;
            if (!$resEnd && !empty($reservation['data_scadenza_prenotazione'])) {
                $resEnd = substr((string) $reservation['data_scadenza_prenotazione'], 0, 10);
            }
            if (!$resEnd || $resEnd < $resStart) {
                $resEnd = $resStart;
            }
            $reservationIntervals[] = [$resStart, $resEnd];
        }

        $unavailableDates = [];
        $daysData = [];
        $earliestAvailable = null;

        for ($i = 0; $i < $days; $i++) {
            $current = clone $start;
            if ($i > 0) {
                $current->add(new DateInterval("P{$i}D"));
            }
            $d = $current->format('Y-m-d');

            $loaned = 0;
            foreach ($loanIntervals as [$s, $e]) {
                if ($s <= $d && $d <= $e) {
                    $loaned++;
                }
            }

            $reserved = 0;
            foreach ($reservationIntervals as [$s, $e]) {
                if ($s <= $d && $d <= $e) {
                    $reserved++;
                }
            }

            $available = max(0, $totalCopies - $loaned - $reserved);
            $state = 'free';
            if ($available <= 0) {
                $state = $loaned > 0 ? 'borrowed' : 'reserved';
                $unavailableDates[] = $d;
            } else {
                if ($earliestAvailable === null) {
                    $earliestAvailable = $d;
                }
            }

            $daysData[] = [
                'date' => $d,
                'available' => $available,
                'loaned' => $loaned,
                'reserved' => $reserved,
                'state' => $state,
            ];
        }

        if ($earliestAvailable === null) {
            // If all scanned days are busy, pick the first free day after the scanned window
            $fallback = (clone $start)->add(new DateInterval("P{$days}D"));
            $earliestAvailable = $fallback->format('Y-m-d');
        }

        return [
            'total_copies' => $totalCopies,
            'unavailable_dates' => array_values(array_unique($unavailableDates)),
            'earliest_available' => $earliestAvailable,
            'days' => $daysData,
            'by_date' => array_column($daysData, null, 'date'),
        ];
    }

    public function createReservation($request, $response, $args)
    {
        $bookId = (int) $args['id'];

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

        $userId = (int) $sessionUserId;
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
        $requestedDates = $this->getDateRange($startDate, $endDate);
        $rangeDays = max(count($requestedDates), 1);
        // Pass userId to exclude their own reservation from blocking them
        $availability = $this->getBookAvailabilityData($bookId, $startDate, $rangeDays + 30, $userId);
        $availabilityByDate = $availability['by_date'] ?? [];

        $conflictDates = [];
        foreach ($requestedDates as $date) {
            $dayData = $availabilityByDate[$date] ?? null;
            if ($dayData === null) {
                continue;
            }
            if (($dayData['available'] ?? 0) <= 0) {
                $conflictDates[] = $date;
            }
        }

        if (!empty($conflictDates)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Nessuna copia disponibile nelle date richieste'),
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

            // Re-check availability after acquiring lock to avoid races
            $postLockAvailability = $this->getBookAvailabilityData($bookId, $startDate, $rangeDays + 30, $userId);
            $postLockByDate = $postLockAvailability['by_date'] ?? [];
            $postLockConflicts = [];
            foreach ($requestedDates as $date) {
                $dayData = $postLockByDate[$date] ?? null;
                if ($dayData !== null && ($dayData['available'] ?? 0) <= 0) {
                    $postLockConflicts[] = $date;
                }
            }
            if (!empty($postLockConflicts)) {
                $this->db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Nessuna copia disponibile nelle date richieste'),
                    'conflict_dates' => $postLockConflicts
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Create pending loan request with origine='richiesta' (manual request from user)
            $stmt = $this->db->prepare("
                INSERT INTO prestiti
                (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
                VALUES (?, ?, ?, ?, 'pendente', 'richiesta', 0)
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

    public function getBookAvailabilityData($bookId, ?string $startDate = null, int $days = 730, ?int $excludeUserId = null)
    {
        $totalCopies = $this->getBookTotalCopies($bookId);

        // Get current and future loans for this book (including pending and scheduled)
        $stmt = $this->db->prepare("
            SELECT data_prestito, data_scadenza, data_restituzione, stato
            FROM prestiti
            WHERE libro_id = ? AND stato IN ('in_corso', 'in_ritardo', 'pendente', 'prenotato')
            ORDER BY data_prestito
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $loansResult = $stmt->get_result();
        $currentLoans = $loansResult->fetch_all(MYSQLI_ASSOC);

        // Get existing reservations
        $stmt = $this->db->prepare("
            SELECT data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, stato, queue_position, utente_id
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            ORDER BY queue_position ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $reservationsResult = $stmt->get_result();
        $existingReservations = $reservationsResult->fetch_all(MYSQLI_ASSOC);

        return $this->calculateAvailability($currentLoans, $existingReservations, $totalCopies, $startDate, $days, $excludeUserId);
    }

    private function getDateRange($startDate, $endDate)
    {
        $dates = [];
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);

        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current->add(new DateInterval('P1D'));
        }

        return $dates;
    }

    private function getBookTotalCopies(int $bookId): int
    {
        // Count only loanable copies from copie table
        // Exclude permanently unavailable copies: 'perso' (lost), 'danneggiato' (damaged), 'manutenzione' (maintenance)
        // Include 'disponibile' and 'prestato' (currently on loan but will return)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM copie
            WHERE libro_id = ?
            AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $total = (int) ($row['total'] ?? 0);

        // Fallback: if no copies exist in copie table, check libri.copie_totali as minimum
        if ($total === 0) {
            $stmt = $this->db->prepare("SELECT GREATEST(IFNULL(copie_totali, 1), 1) AS copie_totali FROM libri WHERE id = ?");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            $total = (int) ($row['copie_totali'] ?? 1);
        }

        return $total;
    }
}
