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

        // Get current and future loans for this book. Approved states always
        // hold a copy; a reservation-conversion pending (attivo=0 with a
        // copia_id) also holds its copy until pickup confirmation (#157, model
        // A-refined). Bare 'pendente' requests with no copy are excluded.
        $stmt = $this->db->prepare("
            SELECT data_prestito, data_scadenza, data_restituzione, pickup_deadline, stato, copia_id
            FROM prestiti
            WHERE libro_id = ? AND (
                stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')
                OR (stato = 'pendente' AND copia_id IS NOT NULL)
            )
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

        // Normalize intervals (#157, model A-refined):
        // approved loans (prenotato, da_ritirare, in_corso, in_ritardo) hold a
        // copy, and so does a reservation-conversion 'pendente' that already
        // carries a copia_id. A bare 'pendente' request with no copy assigned
        // does NOT block a slot.
        $loanIntervals = [];
        foreach ($currentLoans as $loan) {
            $startDateLoan = $loan['data_prestito'] ?? null;
            $loanStatus = $loan['stato'] ?? '';

            if (!$startDateLoan) {
                continue;
            }

            // A 'pendente' loan blocks a slot only when it already holds a copy.
            if ($loanStatus === 'pendente' && empty($loan['copia_id'])) {
                continue;
            }

            // For approved states, use the actual loan period
            // 'prenotato': future loan - block from data_prestito to data_scadenza
            // 'da_ritirare': ready for pickup - block until data_scadenza (copy is committed)
            // 'in_corso'/'in_ritardo': active loan - block until data_scadenza or data_restituzione
            if ($loanStatus === 'da_ritirare') {
                // Block the full loan period: the copy is committed to this user
                // even though they haven't picked it up yet
                $endDateLoan = $loan['data_scadenza']
                    ?? (new DateTime($startDateLoan))->add(new DateInterval('P7D'))->format('Y-m-d');
            } else {
                // For other states: data_restituzione > data_scadenza > null
                $endDateLoan = $loan['data_restituzione'] ?? $loan['data_scadenza'] ?? null;
            }

            // Fallback: if still no end date, use start date (single day block)
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

        // CSRF validated by CsrfMiddleware

        // Validate user session. Canonical session key is $_SESSION['user']['id'];
        // the legacy $_SESSION['user_id'] fallback is not set anywhere and only
        // risked cross-controller auth inconsistency, so it is dropped.
        $sessionUser = $_SESSION['user'] ?? null;
        $sessionUserId = $sessionUser['id'] ?? null;

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

        // If no end date specified, set it using the configured loan duration (fallback: 30 days)
        try {
            if (!$endDate) {
                $loanDays = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'loan_duration_days', '30') ?? 30);
                if ($loanDays < 1) {
                    $loanDays = 30;
                }
                $endDateTime = new DateTime($startDate);
                $endDateTime->modify("+{$loanDays} days");
                $endDate = $endDateTime->format('Y-m-d');
            }

            // Check if dates are available
            $requestedDates = $this->getDateRange($startDate, $endDate);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Formato data non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
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

        // Start transaction for concurrency control
        $this->db->begin_transaction();

        try {
            // Lock book row for update to prevent race conditions
            $stmt = $this->db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $result = $stmt->get_result();
            if (!$result->fetch_assoc()) {
                $this->db->rollback();
                $response->getBody()->write(json_encode(['success' => false, 'message' => __('Libro non trovato')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            $stmt->close();

            // Check for existing active loan from this user for this book (any active state)
            // Note: 'pendente' has attivo=0, other active states have attivo=1
            // This check is inside transaction after lock to prevent TOCTOU race condition
            $dupStmt = $this->db->prepare("SELECT id FROM prestiti WHERE libro_id = ? AND utente_id = ? AND (
                (attivo = 0 AND stato = 'pendente')
                OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
            ) FOR UPDATE");
            $dupStmt->bind_param('ii', $bookId, $userId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->fetch_assoc()) {
                $dupStmt->close();
                $this->db->rollback();
                $response->getBody()->write(json_encode(['success' => false, 'message' => __('Hai già un prestito attivo o in attesa per questo libro')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $dupStmt->close();

            $dupReservationStmt = $this->db->prepare("
                SELECT id
                FROM prenotazioni
                WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva'
                LIMIT 1
                FOR UPDATE
            ");
            $dupReservationStmt->bind_param('ii', $bookId, $userId);
            $dupReservationStmt->execute();
            if ($dupReservationStmt->get_result()->fetch_assoc()) {
                $dupReservationStmt->close();
                $this->db->rollback();
                $response->getBody()->write(json_encode(['success' => false, 'message' => __('Hai già una prenotazione attiva per questo libro')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $dupReservationStmt->close();

            // Enforce max active loans per user (admin setting; 0 = no limit)
            $maxLoans = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'max_active_loans_per_user', '0') ?? 0);
            if ($maxLoans > 0) {
                // Serialize concurrent same-user requests on different books: the
                // per-book libri lock taken earlier does not mutually-exclude them,
                // so without this both could pass the limit check and both commit.
                $userLockStmt = $this->db->prepare("SELECT id FROM utenti WHERE id = ? FOR UPDATE");
                $userLockStmt->bind_param('i', $userId);
                $userLockStmt->execute();
                $userLockStmt->close();

                $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM prestiti WHERE utente_id = ? AND attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')");
                $cntStmt->bind_param('i', $userId);
                $cntStmt->execute();
                $cntResult = $cntStmt->get_result();
                $activeCount = (int) ($cntResult ? $cntResult->fetch_row()[0] : 0);
                $cntStmt->close();
                if ($activeCount >= $maxLoans) {
                    $this->db->rollback();
                    $response->getBody()->write(json_encode(['success' => false, 'message' => __('Hai raggiunto il numero massimo di prestiti attivi consentiti')]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
                }
            }

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
                } catch (\Throwable $notifError) {
                    \App\Support\SecureLogger::error('Error sending notification for loan request', ['error' => $notifError->getMessage()]);
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
        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log("Error creating reservation: " . $e->getMessage());
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Errore del server')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function getBookAvailabilityData($bookId, ?string $startDate = null, int $days = 730, ?int $excludeUserId = null)
    {
        $totalCopies = $this->getBookTotalCopies($bookId);

        // Get current and future loans for this book. Approved states always
        // hold a copy; a reservation-conversion pending (attivo=0 with a
        // copia_id) also holds its copy until pickup confirmation (#157, model
        // A-refined). Bare 'pendente' requests with no copy are excluded.
        $stmt = $this->db->prepare("
            SELECT data_prestito, data_scadenza, data_restituzione, pickup_deadline, stato, copia_id
            FROM prestiti
            WHERE libro_id = ? AND (
                stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')
                OR (stato = 'pendente' AND copia_id IS NOT NULL)
            )
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
        // First check if ANY copies exist in the copie table for this book
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ?");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $totalCopiesExist = (int) ($row['total'] ?? 0);

        // If copies exist in copie table, count only loanable ones
        // Exclude non-lendable copies.
        // Include 'disponibile' and 'prestato' (currently on loan but will return)
        if ($totalCopiesExist > 0) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM copie
                WHERE libro_id = ?
                AND stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
            ");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            // Return actual loanable copies (can be 0 if all are lost/damaged)
            return (int) ($row['total'] ?? 0);
        }

        // Fallback: if NO copies exist in copie table at all, use libri.copie_totali.
        // Distinguish two cases of "no copie rows":
        //   - copie_totali IS NULL (legacy rows never migrated to per-copy tracking):
        //     default to 1 loanable copy, so a legacy catalogue entry stays lendable.
        //   - copie_totali = 0 (explicitly declared zero, AVAIL-007): keep 0 — not
        //     lendable, only reservable via the queue.
        // IFNULL replaces only NULL, so an explicit 0 is preserved.
        $stmt = $this->db->prepare("SELECT IFNULL(copie_totali, 1) AS copie_totali FROM libri WHERE id = ? AND deleted_at IS NULL");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        // If book doesn't exist or is soft-deleted, return 0
        if ($row === null) {
            return 0;
        }

        return (int) $row['copie_totali'];
    }
}
