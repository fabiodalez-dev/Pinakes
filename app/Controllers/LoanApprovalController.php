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

            // Verifica che il prestito sia ancora pendente
            $stmt = $db->prepare("SELECT libro_id, utente_id FROM prestiti WHERE id = ? AND stato = 'pendente'");
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

            // Seleziona una copia disponibile del libro
            $copyRepo = new \App\Models\CopyRepository($db);
            $availableCopies = $copyRepo->getAvailableByBookId($loan['libro_id']);

            if (empty($availableCopies)) {
                // Ricalcola disponibilità per sicurezza
                $integrity = new DataIntegrity($db);
                $integrity->recalculateBookAvailability($loan['libro_id']);

                // Riprova a trovare copie disponibili
                $availableCopies = $copyRepo->getAvailableByBookId($loan['libro_id']);

                if (empty($availableCopies)) {
                    $db->rollback();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => __('Nessuna copia disponibile per questo libro')
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            }

            // Prendi la prima copia disponibile
            $selectedCopy = $availableCopies[0];

            // Assegna la copia al prestito e approva
            $stmt = $db->prepare("
                UPDATE prestiti
                SET stato = 'in_corso', attivo = 1, copia_id = ?
                WHERE id = ? AND stato = 'pendente'
            ");
            $stmt->bind_param('ii', $selectedCopy['id'], $loanId);
            $stmt->execute();
            $stmt->close();

            // Aggiorna stato della copia a 'prestato'
            $copyRepo->updateStatus($selectedCopy['id'], 'prestato');

            // Update book availability with integrity check
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($loan['libro_id']);

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
                'message' => __('Prestito approvato con successo')
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

    private function updateBookAvailability(mysqli $db, int $loanId): void {
        // Get book ID from loan
        $stmt = $db->prepare("SELECT libro_id FROM prestiti WHERE id = ?");
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        $bookId = $result->fetch_assoc()['libro_id'] ?? null;

        if ($bookId) {
            // Update book availability
            $stmt = $db->prepare("
                UPDATE libri SET copie_disponibili = copie_totali - (
                    SELECT COUNT(*) FROM prestiti
                    WHERE libro_id = ? AND stato IN ('in_corso', 'in_ritardo')
                )
                WHERE id = ?
            ");
            $stmt->bind_param('ii', $bookId, $bookId);
            $stmt->execute();
        }
    }
}
