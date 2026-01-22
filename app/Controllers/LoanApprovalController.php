<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\DataIntegrity;
use Exception;
use function __;

class LoanApprovalController
{

    public function pendingLoans(Request $request, Response $response, mysqli $db): Response
    {
        // Get all pending loan requests with origin info
        $stmt = $db->prepare("
            SELECT p.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   p.data_prestito as data_richiesta_inizio,
                   p.data_scadenza as data_richiesta_fine,
                   COALESCE(p.origine, 'richiesta') as origine
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'pendente'
            ORDER BY p.created_at ASC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $pendingLoans = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get all loans ready for pickup (da_ritirare)
        $today = date('Y-m-d');
        $pickupStmt = $db->prepare("
            SELECT p.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   p.data_prestito as data_richiesta_inizio,
                   p.data_scadenza as data_richiesta_fine,
                   p.pickup_deadline,
                   COALESCE(p.origine, 'richiesta') as origine
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'da_ritirare'
              OR (p.stato = 'prenotato' AND p.data_prestito <= ?)
            ORDER BY
                CASE WHEN p.pickup_deadline IS NOT NULL THEN p.pickup_deadline ELSE p.data_prestito END ASC
        ");
        $pickupStmt->bind_param('s', $today);
        $pickupStmt->execute();
        $pickupResult = $pickupStmt->get_result();
        $pickupLoans = $pickupResult->fetch_all(MYSQLI_ASSOC);
        $pickupStmt->close();

        ob_start();
        $title = "Approvazione Prestiti - Amministrazione";
        // $pendingLoans and $pickupLoans are already set from queries above
        require __DIR__ . '/../Views/admin/pending_loans.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function approveLoan(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();

            // Lock and verify loan is still pending (FOR UPDATE prevents concurrent approval)
            // Include copia_id to check if a copy was already assigned (e.g., from reservation)
            $stmt = $db->prepare("SELECT libro_id, utente_id, data_prestito, data_scadenza, copia_id FROM prestiti WHERE id = ? AND stato = 'pendente' FOR UPDATE");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o già processato')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $loan = $result->fetch_assoc();
            $stmt->close();

            $libroId = (int) $loan['libro_id'];
            $existingCopiaId = $loan['copia_id'] ? (int) $loan['copia_id'] : null;
            $dataPrestito = $loan['data_prestito'];
            $dataScadenza = $loan['data_scadenza'];
            $today = date('Y-m-d');

            // Determine state: 'prenotato' if future loan, 'da_ritirare' if immediate
            // User must confirm pickup before loan becomes 'in_corso'
            $isFutureLoan = ($dataPrestito > $today);
            $newState = $isFutureLoan ? 'prenotato' : 'da_ritirare';

            // Calculate pickup deadline for immediate loans (da_ritirare state)
            $pickupDeadline = null;
            if (!$isFutureLoan) {
                $settingsRepo = new \App\Models\SettingsRepository($db);
                $pickupDays = (int) ($settingsRepo->get('loans', 'pickup_expiry_days', '3') ?? 3);
                $pickupDeadline = date('Y-m-d', strtotime("+{$pickupDays} days"));
            }

            // Step 1: Count total lendable copies for this book (exclude perso, danneggiato, manutenzione)
            $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')");
            $totalCopiesStmt->bind_param('i', $libroId);
            $totalCopiesStmt->execute();
            $totalCopies = (int) ($totalCopiesStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $totalCopiesStmt->close();

            // Step 2: Count overlapping loans (excluding the current pending one)
            $loanCountStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prestiti
                WHERE libro_id = ? AND attivo = 1 AND id != ?
                AND stato IN ('in_corso', 'prenotato', 'da_ritirare', 'in_ritardo', 'pendente')
                AND data_prestito <= ? AND data_scadenza >= ?
            ");
            $loanCountStmt->bind_param('iiss', $libroId, $loanId, $dataScadenza, $dataPrestito);
            $loanCountStmt->execute();
            $overlappingLoans = (int) ($loanCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
            $loanCountStmt->close();

            // Step 3: Count overlapping prenotazioni
            // Use COALESCE to handle NULL data_inizio_richiesta and data_fine_richiesta
            // Fall back to data_scadenza_prenotazione if specific dates are not set
            $resCountStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prenotazioni
                WHERE libro_id = ? AND stato = 'attiva'
                AND COALESCE(data_inizio_richiesta, DATE(data_scadenza_prenotazione)) <= ?
                AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione)) >= ?
                AND utente_id != ?
            ");
            $resCountStmt->bind_param('issi', $libroId, $dataScadenza, $dataPrestito, $loan['utente_id']);
            $resCountStmt->execute();
            $overlappingReservations = (int) ($resCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
            $resCountStmt->close();

            // Check if there's at least one slot available
            $totalOccupied = $overlappingLoans + $overlappingReservations;
            if ($totalOccupied >= $totalCopies) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Nessuna copia disponibile per il periodo richiesto')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Step 4: Find a copy. First check if loan already has an assigned copy.
            $selectedCopy = null;

            // Check if loan already has an assigned copy that's still valid for this period
            if ($existingCopiaId !== null) {
                $existingCopyStmt = $db->prepare("
                    SELECT c.id FROM copie c
                    WHERE c.id = ?
                    AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione')
                    AND NOT EXISTS (
                        SELECT 1 FROM prestiti p
                        WHERE p.copia_id = c.id
                        AND p.attivo = 1
                        AND p.id != ?
                        AND p.stato IN ('in_corso', 'prenotato', 'da_ritirare', 'in_ritardo', 'pendente')
                        AND p.data_prestito <= ?
                        AND p.data_scadenza >= ?
                    )
                ");
                $existingCopyStmt->bind_param('iiss', $existingCopiaId, $loanId, $dataScadenza, $dataPrestito);
                $existingCopyStmt->execute();
                $existingCopyResult = $existingCopyStmt->get_result();
                $selectedCopy = $existingCopyResult ? $existingCopyResult->fetch_assoc() : null;
                $existingCopyStmt->close();
            }

            // If no pre-assigned copy or it's no longer valid, find a new one
            if (!$selectedCopy) {
                // Find a specific lendable copy without overlapping assigned loans for this period
                // Include 'pendente' and 'da_ritirare' to match Step 2's counting logic
                // Exclude non-lendable copies (perso, danneggiato, manutenzione)
                $overlapStmt = $db->prepare("
                    SELECT c.id FROM copie c
                    WHERE c.libro_id = ?
                    AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione')
                    AND NOT EXISTS (
                        SELECT 1 FROM prestiti p
                        WHERE p.copia_id = c.id
                        AND p.attivo = 1
                        AND p.stato IN ('in_corso', 'prenotato', 'da_ritirare', 'in_ritardo', 'pendente')
                        AND p.data_prestito <= ?
                        AND p.data_scadenza >= ?
                    )
                    LIMIT 1
                ");
                $overlapStmt->bind_param('iss', $libroId, $dataScadenza, $dataPrestito);
                $overlapStmt->execute();
                $overlapResult = $overlapStmt->get_result();
                $selectedCopy = $overlapResult ? $overlapResult->fetch_assoc() : null;
                $overlapStmt->close();
            }

            if (!$selectedCopy) {
                // Fallback: try date-aware method to find available copy for the requested period
                $copyRepo = new \App\Models\CopyRepository($db);
                $availableCopies = $copyRepo->getAvailableByBookIdForDateRange($libroId, $dataPrestito, $dataScadenza);

                if (empty($availableCopies)) {
                    $db->rollback();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => __('Nessuna copia disponibile per il periodo richiesto')
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
                $selectedCopy = $availableCopies[0];
            }

            // Lock selected copy and re-check overlap to prevent race
            $lockCopyStmt = $db->prepare("SELECT id FROM copie WHERE id = ? FOR UPDATE");
            $lockCopyStmt->bind_param('i', $selectedCopy['id']);
            $lockCopyStmt->execute();
            $lockCopyStmt->close();

            $overlapCopyStmt = $db->prepare("
                SELECT 1 FROM prestiti
                WHERE copia_id = ? AND attivo = 1 AND id != ?
                AND stato IN ('in_corso','prenotato','da_ritirare','in_ritardo','pendente')
                AND data_prestito <= ? AND data_scadenza >= ?
                LIMIT 1
                FOR UPDATE
            ");
            $overlapCopyStmt->bind_param('iiss', $selectedCopy['id'], $loanId, $dataScadenza, $dataPrestito);
            $overlapCopyStmt->execute();
            $overlapCopy = $overlapCopyStmt->get_result()->fetch_assoc();
            $overlapCopyStmt->close();

            if ($overlapCopy) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Nessuna copia disponibile per il periodo richiesto')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Assegna la copia al prestito con lo stato corretto e pickup_deadline se applicabile
            if ($pickupDeadline !== null) {
                $stmt = $db->prepare("
                    UPDATE prestiti
                    SET stato = ?, attivo = 1, copia_id = ?, pickup_deadline = ?
                    WHERE id = ? AND stato = 'pendente'
                ");
                $stmt->bind_param('sisi', $newState, $selectedCopy['id'], $pickupDeadline, $loanId);
            } else {
                $stmt = $db->prepare("
                    UPDATE prestiti
                    SET stato = ?, attivo = 1, copia_id = ?, pickup_deadline = NULL
                    WHERE id = ? AND stato = 'pendente'
                ");
                $stmt->bind_param('sii', $newState, $selectedCopy['id'], $loanId);
            }
            $stmt->execute();
            $stmt->close();

            // Per 'da_ritirare', la copia resta 'disponibile' finché l'utente non ritira
            // Per 'prenotato', la copia resta 'disponibile' finché non inizia il prestito
            // Solo quando si conferma il ritiro (confirmPickup) o MaintenanceService transita
            // da prenotato a da_ritirare, la copia NON viene marcata come 'prestato'
            // La copia diventa 'prestato' SOLO quando si conferma il ritiro

            // Update book availability with integrity check
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId);

            $db->commit();

            // Send appropriate notification to user
            try {
                $notificationService = new \App\Support\NotificationService($db);
                if ($isFutureLoan) {
                    // Future loan: send general approval notification
                    $notificationService->sendLoanApprovedNotification($loanId);
                } else {
                    // Immediate loan (da_ritirare): send pickup ready notification with deadline
                    $notificationService->sendPickupReadyNotification($loanId);
                }
            } catch (Exception $notifError) {
                error_log("Error sending approval notification for loan {$loanId}: " . $notifError->getMessage());
                // Don't fail the approval if notification fails
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $isFutureLoan
                    ? __('Prestito prenotato con successo')
                    : __('Prestito approvato - in attesa di ritiro')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $db->rollback();
            error_log("Errore approvazione prestito {$loanId}: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore interno durante l\'approvazione')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function rejectLoan(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);
        $reason = $data['reason'] ?? '';

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Start transaction for atomic delete + availability update
        $db->begin_transaction();

        try {
            // Lock and verify loan is still pending
            $stmt = $db->prepare("SELECT libro_id, utente_id FROM prestiti WHERE id = ? AND stato = 'pendente' FOR UPDATE");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o già processato')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $bookId = (int) $loan['libro_id'];

            // Delete the loan
            $stmt = $db->prepare("DELETE FROM prestiti WHERE id = ? AND stato = 'pendente'");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();

            if ($db->affected_rows === 0) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito già processato da un altro utente')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }
            $stmt->close();

            // Update book availability (inside transaction)
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($bookId);

            $db->commit();

            // Send notification AFTER successful commit (outside transaction)
            try {
                $notificationService = new \App\Support\NotificationService($db);
                $notificationService->sendLoanRejectedNotification($loanId, $reason);
            } catch (\Exception $notifError) {
                error_log("[rejectLoan] Notification error for loan {$loanId}: " . $notifError->getMessage());
                // Don't fail - deletion already committed
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Richiesta rifiutata')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $db->rollback();
            error_log("[rejectLoan] Error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore nel rifiuto della richiesta')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Confirm pickup of a loan that is ready for pickup.
     * Accepts loans in 'da_ritirare' state or 'prenotato' state if data_prestito <= today.
     * This allows the system to work even without MaintenanceService.
     */
    public function confirmPickup(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();
            $today = date('Y-m-d');

            // Lock and verify loan is ready for pickup
            // Accept 'da_ritirare' OR 'prenotato' if data_prestito <= today (for systems without MaintenanceService)
            $stmt = $db->prepare("
                SELECT id, libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, pickup_deadline
                FROM prestiti
                WHERE id = ? AND attivo = 1
                AND (stato = 'da_ritirare' OR (stato = 'prenotato' AND data_prestito <= ?))
                FOR UPDATE
            ");
            $stmt->bind_param('is', $loanId, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non pronto per il ritiro')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Block if no copy assigned (data integrity issue - legacy/migration problem)
            if (empty($loan['copia_id'])) {
                $db->rollback();
                error_log("[confirmPickup] ERROR: Loan {$loanId} has no assigned copy - cannot confirm pickup");
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito senza copia assegnata - contattare l\'amministratore')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Check if pickup deadline has passed
            if (!empty($loan['pickup_deadline']) && $today > $loan['pickup_deadline']) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Il termine per il ritiro è scaduto')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $libroId = (int) $loan['libro_id'];
            $copiaId = $loan['copia_id'] ? (int) $loan['copia_id'] : null;

            // Update loan state to 'in_corso'
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET stato = 'in_corso', pickup_deadline = NULL
                WHERE id = ?
            ");
            $updateStmt->bind_param('i', $loanId);
            $updateStmt->execute();
            $updateStmt->close();

            // Update copy status to 'prestato' (only if copy is in a loanable state)
            if ($copiaId) {
                // Verify copy is in a valid state for lending (FOR UPDATE prevents TOCTOU race)
                $copyCheckStmt = $db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
                $copyCheckStmt->bind_param('i', $copiaId);
                $copyCheckStmt->execute();
                $copyResult = $copyCheckStmt->get_result()->fetch_assoc();
                $copyCheckStmt->close();

                $invalidStates = ['perso', 'danneggiato', 'manutenzione'];
                if ($copyResult && !in_array($copyResult['stato'], $invalidStates)) {
                    $copyRepo = new \App\Models\CopyRepository($db);
                    $copyRepo->updateStatus($copiaId, 'prestato');
                } elseif ($copyResult) {
                    // Log anomaly: loan confirmed but copy in invalid state - requires manual review
                    error_log("[confirmPickup] WARNING: Loan {$loanId} confirmed but copy {$copiaId} is in state '{$copyResult['stato']}' - requires manual review");
                }
            }

            // Recalculate book availability
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId);

            $db->commit();

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Ritiro confermato con successo')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $db->rollback();
            error_log("[confirmPickup] Error for loan {$loanId}: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore durante la conferma del ritiro')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Cancel a pickup that was not collected (e.g., expired or user didn't show up).
     * Accepts loans in 'da_ritirare' state or 'prenotato' state if data_prestito <= today.
     * Releases the copy and updates availability.
     */
    public function cancelPickup(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);
        $reason = $data['reason'] ?? __('Ritiro non effettuato');

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();
            $today = date('Y-m-d');

            // Lock and verify loan is in a cancellable pickup state
            // Accept 'da_ritirare' OR 'prenotato' if data_prestito <= today
            $stmt = $db->prepare("
                SELECT id, libro_id, copia_id, utente_id, stato
                FROM prestiti
                WHERE id = ? AND attivo = 1
                AND (stato = 'da_ritirare' OR (stato = 'prenotato' AND data_prestito <= ?))
                FOR UPDATE
            ");
            $stmt->bind_param('is', $loanId, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non cancellabile')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $libroId = (int) $loan['libro_id'];
            $copiaId = $loan['copia_id'] ? (int) $loan['copia_id'] : null;

            // Mark loan as cancelled/expired
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET stato = 'annullato', attivo = 0, pickup_deadline = NULL
                WHERE id = ?
            ");
            $updateStmt->bind_param('i', $loanId);
            $updateStmt->execute();
            $updateStmt->close();

            // Prepare reassignment service (for advancing reservation queue)
            // Critical: This is the ONLY reassignment opportunity if MaintenanceService doesn't run
            $reassignmentService = new \App\Services\ReservationReassignmentService($db);
            $reassignmentService->setExternalTransaction(true);

            // Release copy if assigned (set back to 'disponibile' only if valid state)
            if ($copiaId) {
                // FOR UPDATE prevents TOCTOU race - lock row before checking and updating
                $copyCheckStmt = $db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
                $copyCheckStmt->bind_param('i', $copiaId);
                $copyCheckStmt->execute();
                $copyResult = $copyCheckStmt->get_result()->fetch_assoc();
                $copyCheckStmt->close();

                $invalidStates = ['perso', 'danneggiato', 'manutenzione'];
                if ($copyResult && !in_array($copyResult['stato'], $invalidStates, true)) {
                    $copyRepo = new \App\Models\CopyRepository($db);
                    $copyRepo->updateStatus($copiaId, 'disponibile');

                    // Advance reservation queue: promote next waiting user for this copy
                    $reassignmentService->reassignOnReturn($copiaId);
                } elseif ($copyResult) {
                    error_log("[cancelPickup] WARNING: Copy {$copiaId} in state '{$copyResult['stato']}' not reset to disponibile");
                }
            }

            // Recalculate book availability
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId);

            $db->commit();

            // Send deferred reservation notifications AFTER commit (outside transaction)
            $reassignmentService->flushDeferredNotifications();

            // Send notification to user about cancelled pickup (outside transaction)
            try {
                $notificationService = new \App\Support\NotificationService($db);
                $notificationService->sendPickupCancelledNotification($loanId, $reason);
            } catch (\Exception $notifError) {
                error_log("[cancelPickup] Notification error for loan {$loanId}: " . $notifError->getMessage());
                // Don't fail - cancellation already committed
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Ritiro annullato con successo')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $db->rollback();
            error_log("[cancelPickup] Error for loan {$loanId}: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore durante l\'annullamento del ritiro')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

}
