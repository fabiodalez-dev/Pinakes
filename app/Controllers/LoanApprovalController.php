<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\DataIntegrity;
use Exception;
use function __;

class LoanApprovalController {

    public function pendingLoans(Request $request, Response $response, mysqli $db): Response {
        // Get all pending loan requests
        $stmt = $db->prepare("
            SELECT p.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   p.data_prestito as data_richiesta_inizio,
                   p.data_scadenza as data_richiesta_fine
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'pendente'
            ORDER BY p.created_at ASC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $pendingLoans = $result->fetch_all(MYSQLI_ASSOC);

        ob_start();
        $title = "Approvazione Prestiti - Amministrazione";
        $pendingLoans = $pendingLoans;
        require __DIR__ . '/../Views/admin/pending_loans.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function approveLoan(Request $request, Response $response, mysqli $db): Response {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string)$request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int)($data['loan_id'] ?? 0);

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();

            // Verifica che il prestito sia ancora pendente e ottieni le date richieste
            $stmt = $db->prepare("SELECT libro_id, utente_id, data_prestito, data_scadenza FROM prestiti WHERE id = ? AND stato = 'pendente'");
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

            $libroId = (int)$loan['libro_id'];
            $dataPrestito = $loan['data_prestito'];
            $dataScadenza = $loan['data_scadenza'];
            $today = date('Y-m-d');

            // Determine state: 'prenotato' if future loan, 'in_corso' if immediate
            $isFutureLoan = ($dataPrestito > $today);
            $newState = $isFutureLoan ? 'prenotato' : 'in_corso';

            // Step 1: Count total copies for this book
            $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ?");
            $totalCopiesStmt->bind_param('i', $libroId);
            $totalCopiesStmt->execute();
            $totalCopies = (int)($totalCopiesStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $totalCopiesStmt->close();

            // Step 2: Count overlapping loans (excluding the current pending one)
            $loanCountStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prestiti
                WHERE libro_id = ? AND attivo = 1 AND id != ?
                AND stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
                AND data_prestito <= ? AND data_scadenza >= ?
            ");
            $loanCountStmt->bind_param('iiss', $libroId, $loanId, $dataScadenza, $dataPrestito);
            $loanCountStmt->execute();
            $overlappingLoans = (int)($loanCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
            $loanCountStmt->close();

            // Step 3: Count overlapping prenotazioni
            // Use COALESCE to handle NULL data_fine_richiesta (open-ended reservations)
            $resCountStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prenotazioni
                WHERE libro_id = ? AND stato = 'attiva'
                AND data_inizio_richiesta IS NOT NULL
                AND data_inizio_richiesta <= ?
                AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione), data_inizio_richiesta) >= ?
            ");
            $resCountStmt->bind_param('iss', $libroId, $dataScadenza, $dataPrestito);
            $resCountStmt->execute();
            $overlappingReservations = (int)($resCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
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

            // Step 4: Find a specific copy without overlapping assigned loans for this period
            // Include 'pendente' to match Step 2's counting logic
            $overlapStmt = $db->prepare("
                SELECT c.id FROM copie c
                WHERE c.libro_id = ?
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
            $overlapStmt->bind_param('iss', $libroId, $dataScadenza, $dataPrestito);
            $overlapStmt->execute();
            $overlapResult = $overlapStmt->get_result();
            $selectedCopy = $overlapResult ? $overlapResult->fetch_assoc() : null;
            $overlapStmt->close();

            if (!$selectedCopy) {
                // Fallback: try getAvailableByBookId (for immediate loans)
                $copyRepo = new \App\Models\CopyRepository($db);
                $availableCopies = $copyRepo->getAvailableByBookId($libroId);

                if (empty($availableCopies)) {
                    $db->rollback();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => __('Nessuna copia disponibile per questo libro')
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
                AND stato IN ('in_corso','prenotato','in_ritardo','pendente')
                AND data_prestito <= ? AND data_scadenza >= ?
                LIMIT 1
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

            // Assegna la copia al prestito con lo stato corretto
            $stmt = $db->prepare("
                UPDATE prestiti
                SET stato = ?, attivo = 1, copia_id = ?
                WHERE id = ? AND stato = 'pendente'
            ");
            $stmt->bind_param('sii', $newState, $selectedCopy['id'], $loanId);
            $stmt->execute();
            $stmt->close();

            // Aggiorna stato della copia a 'prestato' SOLO se il prestito inizia oggi
            // Per i prestiti futuri (prenotato), la copia rimane disponibile
            // MaintenanceService::activateScheduledLoans() la segnerà come 'prestato' quando il prestito inizia
            $copyRepo = new \App\Models\CopyRepository($db);
            if (!$isFutureLoan) {
                $copyRepo->updateStatus($selectedCopy['id'], 'prestato');
            }

            // Update book availability with integrity check
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId);

            $db->commit();

            // Send approval notification to user
            try {
                $notificationService = new \App\Support\NotificationService($db);
                $notificationService->sendLoanApprovedNotification($loanId);
            } catch (Exception $notifError) {
                error_log("Error sending approval notification for loan {$loanId}: " . $notifError->getMessage());
                // Don't fail the approval if notification fails
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $isFutureLoan
                    ? __('Prestito prenotato con successo')
                    : __('Prestito approvato con successo')
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

    public function rejectLoan(Request $request, Response $response, mysqli $db): Response {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string)$request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int)($data['loan_id'] ?? 0);
        $reason = $data['reason'] ?? '';

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Get book ID before deleting the loan
        $stmt = $db->prepare("SELECT libro_id FROM prestiti WHERE id = ? AND stato = 'pendente'");
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        $loan = $result->fetch_assoc();
        $stmt->close();

        if (!$loan) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Prestito non trovato o già processato')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $bookId = $loan['libro_id'];

        // Send rejection notification BEFORE deleting (need loan data)
        try {
            $notificationService = new \App\Support\NotificationService($db);
            $notificationService->sendLoanRejectedNotification($loanId, $reason);
        } catch (Exception $notifError) {
            error_log("Error sending rejection notification for loan {$loanId}: " . $notifError->getMessage());
            // Don't fail the rejection if notification fails
        }

        // Reject the loan (delete it)
        $stmt = $db->prepare("DELETE FROM prestiti WHERE id = ? AND stato = 'pendente'");
        $stmt->bind_param('i', $loanId);

        if ($stmt->execute() && $db->affected_rows > 0) {
            // Update book availability after loan rejection
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($bookId);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Richiesta rifiutata')
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } else {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore nel rifiuto della richiesta')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

}
