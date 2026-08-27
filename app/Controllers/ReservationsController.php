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
        \App\Support\DateHelper::synchronizeDatabaseSession($this->db);
    }

    // Rimosso getBookAvailability(): non era instradato da nessuna rotta e il
    // suo payload esponeva gli utente_id dei prenotanti (le rotte reali passano
    // da getBookAvailabilityData(), che filtra i campi sensibili).

    private function calculateAvailability($currentLoans, $existingReservations, int $totalCopies, ?string $startDate = null, int $days = 730, ?int $excludeUserId = null)
    {
        // Default start = "today" in the APP timezone (DateHelper), not the PHP
        // process TZ (usually UTC): a bare `new DateTime()` made the mobile
        // calendar (the only null-start caller) begin on "yesterday" between
        // midnight and 2am Rome time, diverging from every web surface.
        $start = new DateTime($startDate ?: \App\Support\DateHelper::today());
        $start->setTime(0, 0, 0);
        $today = \App\Support\DateHelper::today();

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
            } elseif (
                empty($loan['data_restituzione'])
                && (
                    $loanStatus === 'in_ritardo'
                    || ($loanStatus === 'in_corso'
                        && !empty($loan['data_scadenza'])
                        && $loan['data_scadenza'] < $today)
                )
            ) {
                // Overdue and not yet returned: the copy is physically still out and its
                // original data_scadenza is in the PAST — using it would free the copy on
                // the availability calendar and let a new request slip in (double-booking).
                // Keep it blocked open-ended until it is actually returned.
                $endDateLoan = '9999-12-31';
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

        // F040: does the excluded user already hold an active reservation on this
        // book? Same predicate as the date-less duplicate guard in
        // createReservation (prenotazioni WHERE libro_id AND utente_id AND
        // stato='attiva'). Surfaced so the picker can warn instead of showing an
        // all-green calendar that the guard would reject for every date.
        $hasActiveReservation = false;

        $reservationIntervals = [];
        foreach ($existingReservations as $reservation) {
            // Skip reservation if it belongs to the excluded user (e.g. the user making the request)
            if ($excludeUserId !== null && isset($reservation['utente_id']) && (int) $reservation['utente_id'] === $excludeUserId) {
                $hasActiveReservation = true;
                continue;
            }

            // Occupancy start mirrors CapacityService's COALESCE fallback: a legacy
            // 'attiva' row whose data_inizio_richiesta is NULL still occupies its
            // copy, using the reservation deadline (data_scadenza_prenotazione) as
            // the start. It must NOT be skipped — otherwise the calendars would
            // free a slot the canonical capacity gate still counts. Only drop the
            // row if neither a start nor a deadline is known.
            $resStart = $reservation['data_inizio_richiesta'] ?? null;
            if (!$resStart && !empty($reservation['data_scadenza_prenotazione'])) {
                $resStart = substr((string) $reservation['data_scadenza_prenotazione'], 0, 10);
            }
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

        return [
            'total_copies' => $totalCopies,
            'unavailable_dates' => array_values(array_unique($unavailableDates)),
            'earliest_available' => $earliestAvailable,
            'days' => $daysData,
            'by_date' => array_column($daysData, null, 'date'),
            'has_active_reservation' => $hasActiveReservation,
        ];
    }

    /**
     * Handle the unified loan/reservation calendar submission.
     *
     * If at least one physical copy is in the library and can serve the requested
     * window without depending on a preceding return, create the normal loan
     * request (`prestiti.pendente`) and optionally auto-approve it. The automatic
     * path pins that exact safe copy before releasing the book lock; manual requests
     * remain copy-less until staff approval. If the title
     * has physical copies but every copy is currently out or already committed,
     * create a real period-bearing waitlist reservation
     * (`prenotazioni.attiva`) instead (#384). A contractual due date is not proof
     * that an out copy will actually be back, so such a request must not bypass
     * the reservation queue merely because its future dates are disjoint.
     *
     * Legacy aggregate-only books intentionally keep the pending-loan fallback:
     * without a `copie` row the reservation promotion pipeline can never bind a
     * physical copy.
     */
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

        // User eligibility gate (M7): stato/tessera are only verified at login,
        // so a user suspended by the admin (or whose card expired) mid-session
        // could otherwise keep submitting loan requests.
        $eligibilityError = \App\Support\LoanEligibility::checkUser($this->db, $userId);
        if ($eligibilityError !== null) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => \App\Support\LoanEligibility::errorMessage($eligibilityError)]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        if (!$startDate) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Data inizio richiesta mancante')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Date validation BEFORE any computation (H2). Without these guards the
        // route accepted past start dates (approved loans born already expired),
        // inverted ranges (getDateRange() returns [] -> zero conflict checks and
        // a row with data_scadenza < data_prestito) and unbounded durations (a
        // far end_date makes calculateAvailability iterate per-day for millions
        // of days: memory-exhaustion DoS on an authenticated request).
        $isValidDate = static function ($value): bool {
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return false;
            }
            $parsed = DateTime::createFromFormat('Y-m-d', $value);
            return $parsed !== false && $parsed->format('Y-m-d') === $value;
        };

        if (!$isValidDate($startDate) || ($endDate && !$isValidDate($endDate))) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'invalid_date', 'message' => __('Formato data non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if ($startDate < \App\Support\DateHelper::today()) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'past_date', 'message' => __('La data di inizio non può essere nel passato')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if ($endDate && $endDate < $startDate) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'invalid_range', 'message' => __('La data di fine non può precedere la data di inizio')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Configurable duration cap (anti-DoS): applies both to an explicit
        // end_date and to the default end computed from loan_duration_days.
        $maxDurationDays = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'max_loan_duration_days', '90') ?? 90);
        if ($maxDurationDays < 1) {
            $maxDurationDays = 90;
        }

        // If no end date specified, set it using the configured loan duration (fallback: 30 days)
        if (!$endDate) {
            $loanDays = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'loan_duration_days', '30') ?? 30);
            if ($loanDays < 1) {
                $loanDays = 30;
            }
            $loanDays = min($loanDays, $maxDurationDays);
            $endDateTime = new DateTime($startDate);
            $endDateTime->modify("+{$loanDays} days");
            $endDate = $endDateTime->format('Y-m-d');
        }

        $requestedDurationDays = (int) (new DateTime($startDate))->diff(new DateTime($endDate))->days;
        if ($requestedDurationDays > $maxDurationDays) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'max_duration_exceeded', 'message' => __('Il periodo richiesto supera la durata massima consentita di %d giorni', $maxDurationDays)]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Availability decision routes through CapacityService — the single
        // capacity authority — so this web loan-request gate matches the mobile
        // gate exactly (same COALESCE occupancy semantics). excludeUserId mirrors
        // the previous hand-rolled behaviour of not letting the user's own
        // commitments block them.
        $capacity = new \App\Services\CapacityService($this->db);
        $requestedDates = $this->getDateRange($startDate, $endDate);

        if (!$capacity->hasFreeCapacity($bookId, $startDate, $endDate, excludeUserId: $userId)) {
            $conflictDates = $this->capacityConflictDates($capacity, $bookId, $requestedDates, $userId);
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

            // The pre-transaction eligibility check is only a fast fail. Lock
            // and re-check the patron before creating the durable request so a
            // concurrent suspension/card expiry cannot slip through the gap.
            $userLockStmt = $this->db->prepare("SELECT id FROM utenti WHERE id = ? FOR UPDATE");
            $userLockStmt->bind_param('i', $userId);
            $userLockStmt->execute();
            $userLockStmt->get_result();
            $userLockStmt->close();
            $eligibilityError = \App\Support\LoanEligibility::checkUser($this->db, $userId);
            if ($eligibilityError !== null) {
                $this->db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => \App\Support\LoanEligibility::errorMessage($eligibilityError),
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Re-check availability after acquiring the canonical book lock.
            // The pre-lock check is only a fast fail; this one closes the race
            // with returns, approvals, cancellations and other reservations.
            if (!$capacity->hasFreeCapacity($bookId, $startDate, $endDate, excludeUserId: $userId)) {
                $postLockConflicts = $this->capacityConflictDates($capacity, $bookId, $requestedDates, $userId);
                $this->db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Nessuna copia disponibile nelle date richieste'),
                    'conflict_dates' => $postLockConflicts
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // #384: the public button is deliberately unified (loan when a copy
            // is in hand, reservation otherwise). The reservation-vs-loan
            // decision lives in the shared LoanRequestGate so THIS endpoint and
            // POST /user/loan (UserActionsController::loan) can never diverge:
            // with physical rows but no in-library copy that can serve the
            // window without a preceding return, the gate occupies the period
            // through the real FIFO waitlist instead of letting a bare,
            // capacity-free pending loan be created. The gate runs inside THIS
            // transaction (inCallerTransaction: true) under the canonical book
            // lock taken above.
            $routing = (new \App\Services\LoanRequestGate($this->db))
                ->route($bookId, $userId, $startDate, $endDate, inCallerTransaction: true);
            $assignableCopyId = $routing->assignableCopyId;

            if ($routing->isReservation()) {
                $reservationId = (int) $routing->reservationId;
                $this->db->commit();

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => __('Prenotazione effettuata con successo'),
                    'reservation_id' => $reservationId,
                    'auto_approved' => false,
                    'status' => 'reserved',
                    'loan_state' => null,
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            // Enforce max active loans per user (admin setting; 0 = no limit).
            // A real waitlist reservation is intentionally handled above: like
            // UserActionsController::reserve(), it does not consume an active-
            // loan slot until promotion.
            $maxLoans = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'max_active_loans_per_user', '0') ?? 0);
            if ($maxLoans > 0) {
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

            // Read the setting while the canonical book lock is still held. With
            // automatic approval enabled, persist the exact safe copia_id in the
            // pending row: copy-bound pending rows occupy canonical capacity, so a
            // second request cannot slip through after this transaction commits and
            // before approveLoan() reacquires the lock. Manual requests intentionally
            // remain copy-less and keep their existing review-queue semantics.
            $autoApproveEnabled = false;
            try {
                $autoApproveEnabled = (new \App\Models\SettingsRepository($this->db))->autoApproveLoanRequests();
            } catch (\Throwable $settingError) {
                // A settings lookup failure must not discard an otherwise valid
                // request. Leave it copy-less for staff review and report the fault.
                \App\Support\SecureLogger::warning('Automatic-loan setting unavailable; request left for manual approval', [
                    'book_id' => $bookId,
                    'user_id' => $userId,
                    'error' => $settingError->getMessage(),
                ]);
            }

            $preassignedCopyId = $autoApproveEnabled ? $assignableCopyId : null;
            if ($preassignedCopyId !== null) {
                $stmt = $this->db->prepare("
                    INSERT INTO prestiti
                    (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
                    VALUES (?, ?, ?, ?, ?, 'pendente', 'richiesta', 0)
                ");
                $stmt->bind_param('iiiss', $bookId, $preassignedCopyId, $userId, $startDate, $endDate);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO prestiti
                    (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
                    VALUES (?, ?, ?, ?, 'pendente', 'richiesta', 0)
                ");
                $stmt->bind_param('iiss', $bookId, $userId, $startDate, $endDate);
            }

            if ($stmt->execute()) {
                $loanRequestId = (int) $this->db->insert_id;
                if ($preassignedCopyId !== null) {
                    // A copy-bound pending row is a real canonical hold. Keep the
                    // denormalized book counters coherent even if the subsequent
                    // post-commit automatic approval fails and staff must finish it.
                    $integrity = new \App\Support\DataIntegrity($this->db);
                    if (!$integrity->recalculateBookAvailability($bookId, insideTransaction: true)) {
                        throw new \RuntimeException('Failed to recalculate availability after automatic copy claim.');
                    }
                }
                $this->db->commit();

                // #301: honour the automatic-approval setting on THIS entry point
                // too. The book-detail modal posts here, but the auto-approve
                // lived only in UserActionsController::loan() — so real users'
                // requests always landed in the admin approval queue even with
                // the option enabled. Same race-safe canonical pipeline: a
                // failure deliberately leaves the request pending for an admin.
                // ?string: the persisted state ('prenotato' scheduled loan /
                // 'da_ritirare' immediate pickup) on success, null when the
                // request stays pending (setting off / approval failed).
                $loanState = $autoApproveEnabled
                    ? $this->autoApproveLoanRequest($request, $loanRequestId, true)
                    : null;

                if ($loanState === null) {
                    // Send notification to admins (an auto-approved request no
                    // longer needs admin action — the old "new request" email
                    // would carry a stale approval link).
                    try {
                        $notificationService = new NotificationService($this->db);
                        $notificationService->notifyLoanRequest($loanRequestId);
                    } catch (\Throwable $notifError) {
                        \App\Support\SecureLogger::error('Error sending notification for loan request', ['error' => $notifError->getMessage()]);
                        // Don't fail the loan request creation if notification fails
                    }
                }

                // The message/status must describe the state actually persisted:
                // a future-dated auto-approved loan is SCHEDULED ('prenotato'),
                // not awaiting pickup.
                if ($loanState === 'prenotato') {
                    $message = __('Prestito prenotato con successo');
                    $status = 'scheduled';
                } elseif ($loanState === 'da_ritirare') {
                    $message = __('Prestito approvato - in attesa di ritiro');
                    $status = 'approved';
                } else {
                    $message = __('Richiesta di prestito inviata con successo');
                    $status = 'pending_approval';
                }

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => $message,
                    'loan_request_id' => $loanRequestId,
                    // Keep auto_approved a real boolean: book-detail.php compares
                    // it with === true.
                    'auto_approved' => $loanState !== null,
                    'status' => $status,
                    'loan_state' => $loanState,
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            } else {
                $this->db->rollback();
                $response->getBody()->write(json_encode(['success' => false, 'message' => __('Errore nella creazione della richiesta di prestito')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        } catch (\Throwable $e) {
            $this->db->rollback();
            \App\Support\SecureLogger::error('ReservationsController: error creating reservation', [
                'error' => $e->getMessage(),
            ]);
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('Errore del server')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    // findAssignableInLibraryCopyThrough() and createActiveReservation() moved
    // verbatim into App\Services\LoanRequestGate: the #384 routing decision and
    // the waitlist write it may perform are shared with POST /user/loan
    // (UserActionsController::loan) so the two public entry points can never
    // diverge again.

    /**
     * Promote a newly-created request through the canonical approval pipeline
     * when the automatic-approval setting is on (#301). Mirrors
     * UserActionsController::autoApproveLoanRequest — a failure deliberately
     * leaves the request pending so an administrator can still process it.
     */
    private function autoApproveLoanRequest($request, int $loanId, ?bool $knownEnabled = null): ?string
    {
        // When the caller already captured the setting under the book lock it
        // passes that decision in $knownEnabled. Legacy/internal callers may omit
        // it; their post-commit settings read stays inside this try so a DB hiccup
        // degrades to "left pending" instead of escaping after the durable INSERT.
        try {
            $enabled = $knownEnabled
                ?? (new \App\Models\SettingsRepository($this->db))->autoApproveLoanRequests();
            if (!$enabled) {
                // A disabled setting is not a failure: leave the request pending
                // for an admin without logging any warning noise.
                return null;
            }

            $approvalRequest = $request
                ->withParsedBody(['loan_id' => $loanId])
                ->withAttribute('automatic_loan_approval', true);
            $result = (new \App\Controllers\LoanApprovalController())->approveLoan(
                $approvalRequest,
                new \Slim\Psr7\Response(),
                $this->db
            );

            if ($result->getStatusCode() >= 200 && $result->getStatusCode() < 300) {
                // Return the state approveLoan actually persisted ('prenotato'
                // for a future-dated loan, 'da_ritirare' for an immediate one)
                // so the response can describe the real outcome instead of
                // assuming "awaiting pickup".
                $body = json_decode((string) $result->getBody(), true);
                return is_array($body) && isset($body['loan_state']) && is_string($body['loan_state'])
                    ? $body['loan_state']
                    : 'da_ritirare';
            }

            \App\Support\SecureLogger::warning('Automatic loan approval left request pending (createReservation)', [
                'loan_id' => $loanId,
                'status' => $result->getStatusCode(),
            ]);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('Automatic loan approval failed; request left pending (createReservation)', [
                'loan_id' => $loanId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Per-day availability payload for a book, or NULL when the book does not
     * exist or is soft-deleted. Every caller must 404 on null: without this
     * guard the method served real per-day occupancy for soft-deleted books
     * (libri queries MUST honour deleted_at IS NULL).
     */
    public function getBookAvailabilityData($bookId, ?string $startDate = null, int $days = 730, ?int $excludeUserId = null): ?array
    {
        $bookStmt = $this->db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL");
        $bookStmt->bind_param('i', $bookId);
        $bookStmt->execute();
        $bookExists = $bookStmt->get_result()->fetch_assoc() !== null;
        $bookStmt->close();
        if (!$bookExists) {
            return null;
        }

        $totalCopies = $this->getBookTotalCopies($bookId);

        // Get current and future loans for this book. Approved states always
        // hold a copy; a reservation-conversion pending (attivo=0 with a
        // copia_id) also holds its copy until pickup confirmation (#157, model
        // A-refined). Bare 'pendente' requests with no copy are excluded.
        $stmt = $this->db->prepare("
            SELECT data_prestito, data_scadenza, data_restituzione, pickup_deadline, stato, copia_id
            FROM prestiti
            WHERE libro_id = ? AND (
                (attivo = 1 AND stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato'))
                OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL)
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

    /**
     * Days within $requestedDates for which the book has no free capacity, per the
     * canonical CapacityService gate. Used only to enrich the client error payload
     * after the whole-range hasFreeCapacity() decision has already failed, so the
     * response keeps its previous conflict_dates shape.
     *
     * @param list<string> $requestedDates
     * @return list<string>
     */
    private function capacityConflictDates(\App\Services\CapacityService $capacity, int $bookId, array $requestedDates, int $userId): array
    {
        if ($requestedDates === []) {
            return [];
        }
        // One interval load for the whole span instead of a per-day query storm.
        $unavailable = $capacity->unavailableDatesInRange($bookId, min($requestedDates), max($requestedDates), $userId);
        // Restrict to the actually-requested days (the request set may be sparse).
        $requestedSet = array_flip($requestedDates);
        return array_values(array_filter(
            $unavailable,
            static fn (string $date): bool => isset($requestedSet[$date])
        ));
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
