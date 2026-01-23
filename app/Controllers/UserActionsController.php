<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\RouteTranslator;
use App\Support\SecureLogger;
use App\Controllers\ReservationManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserActionsController
{
    public function reservationsPage(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302);
        }
        $uid = (int) $user['id'];

        // Richieste di prestito in sospeso
        $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_scadenza, pr.stato, pr.created_at,
                       l.titolo, l.copertina_url
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.stato = 'pendente'
                ORDER BY pr.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $pendingRequests = [];
        while ($r = $res->fetch_assoc()) {
            $pendingRequests[] = $r;
        }
        $stmt->close();

        // Prestiti in corso (include prenotato=scheduled, in_corso=active, in_ritardo=overdue)
        $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_scadenza, pr.stato,
                       l.titolo, l.copertina_url,
                       EXISTS(SELECT 1 FROM recensioni r WHERE r.libro_id = pr.libro_id AND r.utente_id = ?) as has_review
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.attivo = 1 AND pr.stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo')
                ORDER BY pr.data_prestito ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $uid, $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $activePrestiti = [];
        while ($r = $res->fetch_assoc()) {
            $activePrestiti[] = $r;
        }
        $stmt->close();

        // Prenotazioni attive
        $sql = "SELECT p.id, p.libro_id, p.data_prenotazione, p.data_scadenza_prenotazione, p.queue_position, p.stato,
                       l.titolo, l.copertina_url
                FROM prenotazioni p JOIN libri l ON l.id=p.libro_id
                WHERE p.utente_id=? AND p.stato='attiva' ORDER BY p.data_prenotazione DESC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) {
            $items[] = $r;
        }
        $stmt->close();

        // Storico prestiti (ultimi 20) - solo prestiti conclusi
        $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_restituzione, pr.stato,
                       l.titolo, l.copertina_url,
                       EXISTS(SELECT 1 FROM recensioni r WHERE r.libro_id = pr.libro_id AND r.utente_id = ?) as has_review
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id
                WHERE pr.utente_id = ? AND pr.attivo = 0 AND pr.stato != 'prestato'
                ORDER BY pr.data_restituzione DESC, pr.data_prestito DESC
                LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $uid, $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $pastPrestiti = [];
        while ($r = $res->fetch_assoc()) {
            $pastPrestiti[] = $r;
        }
        $stmt->close();

        // Le mie recensioni
        $sql = "SELECT r.*, l.titolo as libro_titolo, l.copertina_url as libro_copertina
                FROM recensioni r
                JOIN libri l ON l.id = r.libro_id
                WHERE r.utente_id = ?
                ORDER BY r.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $myReviews = [];
        while ($r = $res->fetch_assoc()) {
            $myReviews[] = $r;
        }
        $stmt->close();

        $title = 'I miei prestiti - Biblioteca';
        ob_start();
        require __DIR__ . '/../Views/profile/reservations.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/frontend/layout.php';
        return $response;
    }

    public function cancelLoan(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withStatus(401);
        }
        $data = (array) ($request->getParsedBody() ?? []);
        // CSRF validated by CsrfMiddleware
        $loanId = (int) ($data['loan_id'] ?? 0);
        if ($loanId <= 0) {
            return $response->withStatus(422);
        }
        $uid = (int) $user['id'];

        $db->begin_transaction();

        try {
            // Get loan details and lock
            // Note: 'pendente' has attivo=0, 'prenotato' has attivo=1
            $stmt = $db->prepare("
                SELECT id, copia_id, stato, libro_id
                FROM prestiti
                WHERE id = ? AND utente_id = ? AND (
                    (attivo = 0 AND stato = 'pendente')
                    OR (attivo = 1 AND stato = 'prenotato')
                )
                FOR UPDATE
            ");
            $stmt->bind_param('ii', $loanId, $uid);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_found')->withStatus(302);
            }

            // Mark as cancelled
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET stato = 'annullato', attivo = 0, updated_at = NOW(), note = CONCAT(COALESCE(note, ''), '\n[User] Annullato dall\'utente')
                WHERE id = ?
            ");
            $updateStmt->bind_param('i', $loanId);
            $updateStmt->execute();
            $updateStmt->close();

            // If it had a reserved copy, free it and reassign
            if ($loan['stato'] === 'prenotato' && $loan['copia_id']) {
                $copiaId = (int) $loan['copia_id'];

                // Update copy status to available (if it was 'prenotato')
                $copyStmt = $db->prepare("UPDATE copie SET stato = 'disponibile' WHERE id = ? AND stato = 'prenotato'");
                $copyStmt->bind_param('i', $copiaId);
                $copyStmt->execute();
                $copyStmt->close();

                // Trigger reassignment
                $reassignmentService = new \App\Services\ReservationReassignmentService($db);
                $reassignmentService->reassignOnReturn($copiaId);
            }

            // Recalculate book availability
            $integrity = new \App\Support\DataIntegrity($db);
            $integrity->recalculateBookAvailability((int) $loan['libro_id']);

            $db->commit();

            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?canceled=1')->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error(__('Errore annullamento prestito'), ['error' => $e->getMessage()]);
            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=db')->withStatus(302);
        }
    }

    public function cancelReservation(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withStatus(401);
        }
        $data = (array) ($request->getParsedBody() ?? []);
        // CSRF validated by CsrfMiddleware
        $rid = (int) ($data['reservation_id'] ?? 0);
        if ($rid <= 0) {
            return $response->withStatus(422);
        }
        $uid = (int) $user['id'];

        $db->begin_transaction();

        try {
            // Get libro_id before canceling (needed for queue reordering)
            $getStmt = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ? AND utente_id = ? AND stato = 'attiva' FOR UPDATE");
            $getStmt->bind_param('ii', $rid, $uid);
            $getStmt->execute();
            $result = $getStmt->get_result();
            $reservation = $result->fetch_assoc();
            $getStmt->close();

            if (!$reservation) {
                // Check if it's actually a loan/active reservation (prestiti table) request instead?
                // Sometimes frontend might send reservation_id for prestiti items if confusingly named
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_found')->withStatus(302);
            }

            $libroId = (int) $reservation['libro_id'];

            // Cancel the reservation
            $stmt = $db->prepare("UPDATE prenotazioni SET stato='annullata' WHERE id=? AND utente_id=?");
            $stmt->bind_param('ii', $rid, $uid);
            $stmt->execute();
            $stmt->close();

            // Reorder queue positions for remaining active reservations
            $reorderStmt = $db->prepare("
                SELECT id FROM prenotazioni
                WHERE libro_id = ? AND stato = 'attiva'
                ORDER BY queue_position ASC
            ");
            $reorderStmt->bind_param('i', $libroId);
            $reorderStmt->execute();
            $reorderResult = $reorderStmt->get_result();

            $position = 1;
            while ($row = $reorderResult->fetch_assoc()) {
                $updatePos = $db->prepare("UPDATE prenotazioni SET queue_position = ? WHERE id = ?");
                $updatePos->bind_param('ii', $position, $row['id']);
                $updatePos->execute();
                $updatePos->close();
                $position++;
            }
            $reorderStmt->close();

            // Recalculate book availability
            $integrity = new \App\Support\DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId);

            $db->commit();

            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?canceled=1')->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error(__('Errore annullamento prenotazione'), ['error' => $e->getMessage()]);
            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=db')->withStatus(302);
        }
    }

    public function changeReservationDate(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withStatus(401);
        }
        $data = (array) ($request->getParsedBody() ?? []);
        // CSRF validated by CsrfMiddleware
        $rid = (int) ($data['reservation_id'] ?? 0);
        $date = trim((string) ($data['desired_date'] ?? ''));
        if ($rid <= 0 || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $response->withStatus(422);
        }
        if (strtotime($date) < strtotime(date('Y-m-d'))) {
            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=past_date')->withStatus(302);
        }

        $uid = (int) $user['id'];
        $startDate = $date;
        $endDate = date('Y-m-d', strtotime($date . ' +1 month'));

        $db->begin_transaction();

        try {
            // Get reservation details and lock
            $getStmt = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ? AND utente_id = ? AND stato = 'attiva' FOR UPDATE");
            $getStmt->bind_param('ii', $rid, $uid);
            $getStmt->execute();
            $result = $getStmt->get_result();
            $reservation = $result->fetch_assoc();
            $getStmt->close();

            if (!$reservation) {
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_found')->withStatus(302);
            }

            $libroId = (int) $reservation['libro_id'];

            // Lock book row to prevent race conditions
            $lockStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libroId);
            $lockStmt->execute();
            $lockResult = $lockStmt->get_result();
            if (!$lockResult->fetch_assoc()) {
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=book_not_found')->withStatus(302);
            }
            $lockStmt->close();

            // Check availability for the new date range (excluding this user's reservation)
            $reservationsController = new \App\Controllers\ReservationsController($db);
            $availability = $reservationsController->getBookAvailabilityData($libroId, $startDate, 35, $uid);

            // Check each day in the new range
            $currentDate = new \DateTime($startDate);
            $endDateTime = new \DateTime($endDate);
            $hasConflict = false;

            while ($currentDate <= $endDateTime) {
                $dateStr = $currentDate->format('Y-m-d');
                $dayData = $availability['by_date'][$dateStr] ?? null;
                if ($dayData !== null && ($dayData['available'] ?? 0) <= 0) {
                    $hasConflict = true;
                    break;
                }
                $currentDate->add(new \DateInterval('P1D'));
            }

            if ($hasConflict) {
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_available')->withStatus(302);
            }

            // Update reservation with new dates
            // Update both date pairs for consistency
            $startDt = $startDate . ' 00:00:00';
            $endDt = $endDate . ' 23:59:59';
            $stmt = $db->prepare("
                UPDATE prenotazioni
                SET data_prenotazione = ?,
                    data_scadenza_prenotazione = ?,
                    data_inizio_richiesta = ?,
                    data_fine_richiesta = ?
                WHERE id = ? AND utente_id = ? AND stato = 'attiva'
            ");
            $stmt->bind_param('ssssii', $startDt, $endDt, $startDate, $endDate, $rid, $uid);
            $stmt->execute();
            $stmt->close();

            // Recalculate book availability
            $integrity = new \App\Support\DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId);

            $db->commit();

            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?updated=1')->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error(__('Errore modifica data prenotazione'), ['error' => $e->getMessage()]);
            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=db')->withStatus(302);
        }
    }
    public function reservationsCount(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        $count = 0;
        if ($user && !empty($user['id'])) {
            $uid = (int) $user['id'];
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE utente_id=? AND stato='attiva'");
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }
        $response->getBody()->write(json_encode(['count' => $count]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    public function loan(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302);
        }
        $data = (array) ($request->getParsedBody() ?? []);
        // CSRF validated by CsrfMiddleware
        $libroId = (int) ($data['libro_id'] ?? 0);
        if ($libroId <= 0) {
            return $this->back($response, ['loan_error' => 'invalid']);
        }

        $utenteId = (int) $user['id'];
        $data_prestito = date('Y-m-d');
        $data_scadenza = date('Y-m-d', strtotime('+14 days'));

        // Use transaction + lock to prevent race conditions
        $db->begin_transaction();

        try {
            // Lock the book row to prevent concurrent loan requests
            $lockStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libroId);
            $lockStmt->execute();
            $lockResult = $lockStmt->get_result();
            if (!$lockResult->fetch_assoc()) {
                $db->rollback();
                return $this->back($response, ['loan_error' => 'book_not_found']);
            }
            $lockStmt->close();

            // Re-check availability after acquiring lock - check full loan period
            $reservationManager = new ReservationManager($db);
            // Pass utenteId to exclude their own reservation from blocking the loan request
            if (!$reservationManager->isBookAvailableForImmediateLoan($libroId, $data_prestito, $data_scadenza, $utenteId)) {
                $db->rollback();
                return $this->back($response, ['loan_error' => 'not_available']);
            }

            // Check for existing active loan from this user for this book (any active state)
            // Note: 'pendente' has attivo=0, other active states have attivo=1
            $dupStmt = $db->prepare("SELECT id FROM prestiti WHERE libro_id = ? AND utente_id = ? AND (
                (attivo = 0 AND stato = 'pendente')
                OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
            ) LIMIT 1");
            $dupStmt->bind_param('ii', $libroId, $utenteId);
            $dupStmt->execute();
            $dupResult = $dupStmt->get_result();
            if ($dupResult->fetch_assoc()) {
                $dupStmt->close();
                $db->rollback();
                return $this->back($response, ['loan_error' => 'duplicate']);
            }
            $dupStmt->close();

            // Insert as 'pendente' - requires admin approval
            $stmt = $db->prepare("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, 'pendente', 0)");
            $stmt->bind_param('iiss', $libroId, $utenteId, $data_prestito, $data_scadenza);

            if (!$stmt->execute()) {
                $stmt->close();
                $db->rollback();
                return $this->back($response, ['loan_error' => 'db']);
            }

            $newLoanId = (int) $db->insert_id;
            $stmt->close();
            $db->commit();

            // Notify admins about new loan request (outside transaction)
            try {
                $notificationService = new \App\Support\NotificationService($db);
                $notificationService->notifyLoanRequest($newLoanId);
            } catch (\Exception $e) {
                SecureLogger::warning(__('Notifica richiesta prestito fallita'), ['error' => $e->getMessage()]);
            }

            return $this->back($response, ['loan_request_success' => 1]);

        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error(__('Errore richiesta prestito'), ['error' => $e->getMessage()]);
            return $this->back($response, ['loan_error' => 'db']);
        }
    }

    public function reserve(Request $request, Response $response, mysqli $db): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || empty($user['id'])) {
            return $response->withHeader('Location', RouteTranslator::route('login'))->withStatus(302);
        }
        $data = (array) ($request->getParsedBody() ?? []);
        // CSRF validated by CsrfMiddleware
        $libroId = (int) ($data['libro_id'] ?? 0);
        if ($libroId <= 0) {
            return $this->back($response, ['reserve_error' => 'invalid']);
        }
        $desired = trim((string) ($data['desired_date'] ?? ''));
        if ($desired !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desired)) {
            return $this->back($response, ['reserve_error' => 'invalid_date']);
        }
        if ($desired !== '' && strtotime($desired) < strtotime(date('Y-m-d'))) {
            return $this->back($response, ['reserve_error' => 'past_date']);
        }
        $utenteId = (int) $user['id'];

        // Calculate date range for availability check
        $start = ($desired !== '') ? $desired : date('Y-m-d');
        $end = date('Y-m-d', strtotime($start . ' +1 month'));

        // Start transaction for concurrency control
        $db->begin_transaction();

        try {
            // Lock the book row to serialize reservations for this book
            $lockStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libroId);
            $lockStmt->execute();
            $lockResult = $lockStmt->get_result();
            if (!$lockResult->fetch_assoc()) {
                $db->rollback();
                return $this->back($response, ['reserve_error' => 'book_not_found']);
            }
            $lockStmt->close();

            // Check if already has an active reservation for this book (inside transaction to prevent race condition)
            $dupStmt = $db->prepare("SELECT id FROM prenotazioni WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva' LIMIT 1");
            $dupStmt->bind_param('ii', $libroId, $utenteId);
            $dupStmt->execute();
            $dupResult = $dupStmt->get_result();
            if ($dupResult->fetch_assoc()) {
                $dupStmt->close();
                $db->rollback();
                return $this->back($response, ['reserve_error' => 'duplicate']);
            }
            $dupStmt->close();

            // Check availability for the requested date range (excluding this user's existing reservations)
            $reservationsController = new ReservationsController($db);
            $availability = $reservationsController->getBookAvailabilityData($libroId, $start, 35, $utenteId);

            // Check each day in the range for conflicts
            $currentDate = new \DateTime($start);
            $endDateTime = new \DateTime($end);
            $hasConflict = false;

            while ($currentDate <= $endDateTime) {
                $dateStr = $currentDate->format('Y-m-d');
                $dayData = $availability['by_date'][$dateStr] ?? null;
                if ($dayData !== null && ($dayData['available'] ?? 0) <= 0) {
                    $hasConflict = true;
                    break;
                }
                $currentDate->add(new \DateInterval('P1D'));
            }

            if ($hasConflict) {
                $db->rollback();
                return $this->back($response, ['reserve_error' => 'not_available']);
            }

            // Calculate queue position
            $stmt = $db->prepare("SELECT COALESCE(MAX(queue_position),0)+1 AS pos FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
            $stmt->bind_param('i', $libroId);
            $stmt->execute();
            $res = $stmt->get_result();
            $pos = (int) ($res->fetch_assoc()['pos'] ?? 1);
            $stmt->close();

            $startDt = $start . ' 00:00:00';
            $endDt = $end . ' 23:59:59';

            // Set both date pairs for consistency with availability checks:
            // - data_prenotazione / data_scadenza_prenotazione (datetime, legacy)
            // - data_inizio_richiesta / data_fine_richiesta (date, used by availability calculations)
            $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, queue_position, stato, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta) VALUES (?, ?, ?, 'attiva', ?, ?, ?, ?)");
            $stmt->bind_param('iiissss', $libroId, $utenteId, $pos, $startDt, $endDt, $start, $end);

            if ($stmt->execute()) {
                $stmt->close();

                // Recalculate book availability after reservation
                $integrity = new \App\Support\DataIntegrity($db);
                $integrity->recalculateBookAvailability($libroId);

                $db->commit();
                $params = ['reserve_success' => 1];
                if ($desired !== '') {
                    $params['reserve_date'] = $desired;
                }
                return $this->back($response, $params);
            }
            $stmt->close();
            $db->rollback();
        } catch (\Exception $e) {
            $db->rollback();
            SecureLogger::error(__('Errore prenotazione'), ['error' => $e->getMessage()]);
        }

        return $this->back($response, ['reserve_error' => 'db']);
    }

    private function back(Response $response, array $params): Response
    {
        $qs = http_build_query($params);
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';

        // Validate referer to prevent open redirect and header injection
        if (!$this->isValidReferer($referer)) {
            $referer = '/'; // Fallback to safe default
        }

        $sep = (str_contains($referer, '?') ? '&' : '?');
        return $response->withHeader('Location', $referer . $sep . $qs)->withStatus(302);
    }

    /**
     * Validate referer URL to prevent open redirect attacks
     */
    private function isValidReferer(string $referer): bool
    {
        // Check for CRLF injection
        if (strpos($referer, "\r") !== false || strpos($referer, "\n") !== false) {
            return false;
        }

        // Allow relative URLs starting with /
        if (str_starts_with($referer, '/') && !str_starts_with($referer, '//')) {
            return true;
        }

        // For absolute URLs, validate they're from the same host
        $parsedReferer = parse_url($referer);
        if (!$parsedReferer) {
            return false;
        }

        $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return isset($parsedReferer['host']) && $parsedReferer['host'] === $currentHost;
    }
}
