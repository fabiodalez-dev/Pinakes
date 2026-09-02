<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\RouteTranslator;
use App\Support\SecureLogger;
use App\Controllers\ReservationManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

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
                WHERE pr.utente_id = ? AND pr.stato = 'pendente' AND l.deleted_at IS NULL
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
                WHERE pr.utente_id = ? AND pr.attivo = 1 AND pr.stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo') AND l.deleted_at IS NULL
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
                WHERE p.utente_id=? AND p.stato='attiva' AND l.deleted_at IS NULL ORDER BY p.data_prenotazione DESC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($r = $res->fetch_assoc()) {
            $items[] = $r;
        }
        $stmt->close();

        // Storico prestiti (ultimi 20) - tutti i prestiti conclusi, inclusi
        // annullati e scaduti (prima sparivano dallo storico). Questi non hanno
        // data_restituzione: ordina sul momento di chiusura (updated_at) così
        // un annullamento recente non finisce in fondo alla lista.
        $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_restituzione, pr.stato,
                       l.titolo, l.copertina_url,
                       EXISTS(SELECT 1 FROM recensioni r WHERE r.libro_id = pr.libro_id AND r.utente_id = ?) as has_review
                FROM prestiti pr
                JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                WHERE pr.utente_id = ? AND pr.attivo = 0 AND pr.stato IN ('restituito','perso','danneggiato','annullato','scaduto')
                ORDER BY COALESCE(pr.data_restituzione, pr.updated_at) DESC, pr.data_prestito DESC
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
                JOIN libri l ON l.id = r.libro_id AND l.deleted_at IS NULL
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

        // ORDINE DI LOCK CANONICO (P3, M2): risolvi libro_id con una lettura
        // NON bloccante PRIMA di begin_transaction() (lock-first, MVCC: la read
        // view REPEATABLE READ nasce alla prima consistent read in transazione —
        // anticiparla al pre-lock renderebbe le SELECT non bloccanti successive,
        // promozione coda inclusa, cieche ai commit concorrenti avvenuti durante
        // l'attesa del lock), blocca la riga `libri` PRIMA e solo dopo il prestito
        // — stesso pattern di cancelReservation qui sotto. Lockare prima la
        // riga prestiti incrocerebbe i lock con i percorsi di creazione/
        // approvazione (che vanno libri -> prestiti) causando deadlock.
        // Note: 'pendente' has attivo=0, 'prenotato'/'da_ritirare' have attivo=1.
        // 'da_ritirare' (approved, waiting for pickup) must be user-cancellable too
        // (#381): its copy is held in copie.stato='prenotato' exactly like a
        // 'prenotato' loan, so the same release path below applies.
        $lookupStmt = $db->prepare("
            SELECT libro_id
            FROM prestiti
            WHERE id = ? AND utente_id = ? AND (
                (attivo = 0 AND stato = 'pendente')
                OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare'))
            )
        ");
        $lookupStmt->bind_param('ii', $loanId, $uid);
        $lookupStmt->execute();
        $lookupRow = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();

        if (!$lookupRow) {
            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_found')->withStatus(302);
        }

        $libroId = (int) $lookupRow['libro_id'];

        $db->begin_transaction();

        try {
            // Lock della riga libri per serializzare rilascio copia, promozione
            // coda e ricalcolo disponibilità con gli altri percorsi sullo stesso libro.
            // CI-SOFT-DELETE-EXEMPT: user cancellation must release existing circulation state for a deleted book.
            $lockBookStmt = $db->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
            $lockBookStmt->bind_param('i', $libroId);
            $lockBookStmt->execute();
            $lockBookStmt->close();

            // Ora blocca e ri-verifica il prestito: la lettura iniziale era non
            // bloccante, quindi un'approvazione/annullamento concorrente può
            // averne cambiato lo stato (o, TOCTOU, il libro) nel frattempo.
            $stmt = $db->prepare("
                SELECT id, copia_id, stato, libro_id
                FROM prestiti
                WHERE id = ? AND utente_id = ? AND (
                    (attivo = 0 AND stato = 'pendente')
                    OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare'))
                )
                FOR UPDATE
            ");
            $stmt->bind_param('ii', $loanId, $uid);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan || (int) $loan['libro_id'] !== $libroId) {
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_found')->withStatus(302);
            }

            // Snapshot AFTER the FOR UPDATE lock: taken earlier it could
            // capture a concurrent transition and misattribute the diff.
            $activityBefore = \App\Support\ActivityLog::loadLoanSnapshot($db, $loanId);

            // Mark as cancelled
            $cancelNote = "\n[User] Annullato dall'utente";
            // pickup_deadline = NULL: a 'da_ritirare' loan carries a deadline;
            // clear it on cancel so the expiry cron / PickupDeadlineBackfill (#366)
            // cannot act on a dead deadline.
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET stato = 'annullato', attivo = 0, pickup_deadline = NULL, updated_at = NOW(), note = CONCAT(COALESCE(note, ''), ?)
                WHERE id = ?
            ");
            $updateStmt->bind_param('si', $cancelNote, $loanId);
            $updateStmt->execute();
            $updateStmt->close();

            // If it had a reserved copy, free it and reassign. Vale anche per un
            // 'pendente' promosso dalla coda (attivo=0 con copia_id): prima solo
            // il ramo 'prenotato' riassegnava subito e la copia del pendente
            // annullato restava in attesa dello sweep di manutenzione.
            if ($loan['copia_id'] && in_array((string) $loan['stato'], ['prenotato', 'pendente', 'da_ritirare'], true)) {
                $copiaId = (int) $loan['copia_id'];

                // Update copy status to available. Both 'prenotato' and 'da_ritirare'
                // hold the copy in copie.stato='prenotato', so this guarded UPDATE
                // frees it; a promoted 'pendente' keeps its copy 'disponibile' and
                // the UPDATE is a no-op.
                $copyStmt = $db->prepare("UPDATE copie SET stato = 'disponibile' WHERE id = ? AND stato = 'prenotato'");
                $copyStmt->bind_param('i', $copiaId);
                $copyStmt->execute();
                $copyStmt->close();

                // Trigger reassignment
                $reassignmentService = new \App\Services\ReservationReassignmentService($db);
                $reassignmentService->setExternalTransaction(true);
                $reassignmentService->reassignOnReturn($copiaId);
            }

            // Promote the waitlist (Layer 2): a cancellation frees capacity for
            // queued reservations. Loop until none convert. Both queues (D5/BUG10).
            $reservationManager = new \App\Controllers\ReservationManager($db);
            $reservationManager->setExternalTransaction(true);
            // Reassignment and every queue promotion share this outer transaction:
            // any exception reaches the catch below and rolls all mutations back.
            for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability((int) $loan['libro_id']); $promoGuard++) {
                // keep promoting while freed capacity converts the next queued reservation
            }

            // Recalculate book availability (insideTransaction: true — TXN-002:
            // siamo dentro la transazione aperta in questo metodo, evita il
            // commit implicito di una begin_transaction() annidata)
            $integrity = new \App\Support\DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability((int) $loan['libro_id'], insideTransaction: true)) {
                throw new \RuntimeException('Failed to recalculate availability after loan cancellation.');
            }

            \App\Support\ActivityLog::recordLoanEvent(
                $db,
                $loanId,
                'loan.cancelled',
                $activityBefore,
                source: 'user',
                operatorId: $uid
            );
            $db->commit();

            // Send deferred notifications after commit
            if (isset($reassignmentService)) {
                try {
                    $reassignmentService->flushDeferredNotifications();
                } catch (\Throwable $e) {
                    SecureLogger::warning('Failed to flush deferred notifications after loan cancellation', ['error' => $e->getMessage()]);
                }
            }
            try {
                $reservationManager->flushDeferredNotifications();
            } catch (\Throwable $e) {
                SecureLogger::warning('Failed to flush reservation notifications after loan cancellation', ['error' => $e->getMessage()]);
            }

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

        // CANONICAL LOCK ORDER (P3, L7): resolve libro_id with a NON-blocking
        // read BEFORE begin_transaction() (lock-first, MVCC: the REPEATABLE READ
        // view is created at the transaction's first consistent read — creating
        // it pre-lock would blind every later non-locking SELECT, queue
        // promotion included, to commits that landed while waiting for the book
        // lock), then lock the `libri` row FIRST and only then the reservation —
        // same order as LoanRepository::close, so this path never crosses
        // locks with the create/approve paths (which go libri -> rows).
        $lookupStmt = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ? AND utente_id = ? AND stato = 'attiva'");
        $lookupStmt->bind_param('ii', $rid, $uid);
        $lookupStmt->execute();
        $lookupRow = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();

        if (!$lookupRow) {
            // Check if it's actually a loan/active reservation (prestiti table) request instead?
            // Sometimes frontend might send reservation_id for prestiti items if confusingly named
            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_found')->withStatus(302);
        }

        $libroId = (int) $lookupRow['libro_id'];

        $db->begin_transaction();

        try {
            // Lock the book row to serialize the queue reorder + availability
            // recalculation with other paths working on the same book's queue.
            // CI-SOFT-DELETE-EXEMPT: reservation cancellation must unblock a deleted book's existing queue.
            $lockBookStmt = $db->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
            $lockBookStmt->bind_param('i', $libroId);
            $lockBookStmt->execute();
            $lockBookStmt->close();

            // Now lock and re-verify the reservation: the initial read was
            // non-blocking, so a concurrent promotion/cancellation may have
            // changed its state (or, TOCTOU, its book) in the meantime.
            $getStmt = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ? AND utente_id = ? AND stato = 'attiva' FOR UPDATE");
            $getStmt->bind_param('ii', $rid, $uid);
            $getStmt->execute();
            $result = $getStmt->get_result();
            $reservation = $result->fetch_assoc();
            $getStmt->close();

            if (!$reservation || (int) $reservation['libro_id'] !== $libroId) {
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_found')->withStatus(302);
            }

            // Snapshot AFTER the FOR UPDATE lock: taken earlier it could
            // capture a concurrent transition and misattribute the diff.
            $activityBefore = \App\Support\ActivityLog::loadReservationSnapshot($db, $rid);

            // Cancel the reservation
            $stmt = $db->prepare("UPDATE prenotazioni SET stato='annullata' WHERE id=? AND utente_id=?");
            $stmt->bind_param('ii', $rid, $uid);
            $stmt->execute();
            $stmt->close();

            // Reorder queue positions for remaining active reservations.
            // FOR UPDATE like the admin twin (LoanApprovalController): without
            // row locks two concurrent cancel/reserve leave gaps or duplicates.
            $reorderStmt = $db->prepare("
                SELECT id FROM prenotazioni
                WHERE libro_id = ? AND stato = 'attiva'
                ORDER BY queue_position ASC
                FOR UPDATE
            ");
            $reorderStmt->bind_param('i', $libroId);
            $reorderStmt->execute();
            $reorderResult = $reorderStmt->get_result();

            $position = 1;
            $updatePos = $db->prepare("UPDATE prenotazioni SET queue_position = ? WHERE id = ?");
            while ($row = $reorderResult->fetch_assoc()) {
                $updatePos->bind_param('ii', $position, $row['id']);
                $updatePos->execute();
                $position++;
            }
            $updatePos->close();
            $reorderStmt->close();

            // Cancelling a queue reservation frees a promised capacity unit.
            // Promote every now-eligible row in the same transaction, matching
            // the admin cancellation and physical-copy release paths.
            $reservationManager = new \App\Controllers\ReservationManager($db);
            $reservationManager->setExternalTransaction(true);
            for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($libroId); $promoGuard++) {
                // promote until the newly-freed capacity is exhausted
            }

            $integrity = new \App\Support\DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, insideTransaction: true)) {
                throw new \RuntimeException('Failed to recalculate availability after reservation cancellation.');
            }

            \App\Support\ActivityLog::recordReservationEvent(
                $db,
                $rid,
                'reservation.cancelled',
                $activityBefore,
                source: 'user',
                operatorId: $uid
            );
            $db->commit();

            try {
                $reservationManager->flushDeferredNotifications();
            } catch (\Throwable $e) {
                SecureLogger::warning('Failed to flush reservation notifications after user cancellation', ['error' => $e->getMessage()]);
            }

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
        if ($rid <= 0 || !\App\Support\DateHelper::isISODateFormat($date)) {
            return $response->withStatus(422);
        }
        // "Today" via DateHelper (M9): see loan() — avoids the midnight
        // day-boundary mismatch between process tz and app tz.
        if (strtotime($date) < strtotime(\App\Support\DateHelper::today())) {
            return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=past_date')->withStatus(302);
        }

        $uid = (int) $user['id'];
        $startDate = $date;
        $loanDays = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'loan_duration_days', '30') ?? 30);
        $loanDays = $loanDays > 0 ? $loanDays : 30;
        $endDate = (new \DateTimeImmutable($date))->modify("+{$loanDays} days")->format('Y-m-d');

        $db->begin_transaction();

        try {
            // Resolve without locking, then follow the canonical book->reservation
            // lock order used by create/cancel/promotion.
            $getStmt = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ? AND utente_id = ? AND stato = 'attiva'");
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

            // Lock book row first to serialize every capacity decision.
            $lockStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libroId);
            $lockStmt->execute();
            if (!$lockStmt->get_result()->fetch_assoc()) {
                $lockStmt->close();
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=book_not_found')->withStatus(302);
            }
            $lockStmt->close();

            // Re-lock and revalidate the reservation after acquiring the book.
            $getStmt = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ? AND utente_id = ? AND stato = 'attiva' FOR UPDATE");
            $getStmt->bind_param('ii', $rid, $uid);
            $getStmt->execute();
            $lockedReservation = $getStmt->get_result()->fetch_assoc();
            $getStmt->close();
            if (!$lockedReservation || (int) $lockedReservation['libro_id'] !== $libroId) {
                $db->rollback();
                return $response->withHeader('Location', RouteTranslator::route('reservations') . '?error=not_found')->withStatus(302);
            }

            // Snapshot AFTER the FOR UPDATE lock: taken earlier it could
            // capture a concurrent transition and misattribute the diff.
            $activityBefore = \App\Support\ActivityLog::loadReservationSnapshot($db, $rid);

            // Canonical peak-capacity check, excluding the row being moved.
            $capacity = new \App\Services\CapacityService($db);
            if (!$capacity->hasFreeCapacity($libroId, $startDate, $endDate, excludeReservationId: $rid, excludeUserId: $uid)) {
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
            if (!$integrity->recalculateBookAvailability($libroId, insideTransaction: true)) {
                throw new \RuntimeException('Failed to recalculate availability after reservation date change.');
            }

            \App\Support\ActivityLog::recordReservationEvent(
                $db,
                $rid,
                'reservation.updated',
                $activityBefore,
                source: 'user',
                operatorId: $uid
            );
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

        // User eligibility gate (M7): stato/tessera are only verified at login,
        // so a user suspended (or with an expired card) mid-session could
        // otherwise keep submitting loan requests.
        if (\App\Support\LoanEligibility::checkUser($db, $utenteId) !== null) {
            return $this->back($response, ['loan_error' => 'not_eligible']);
        }

        // "Today" via DateHelper (M9): PHP process tz is often UTC while the
        // library runs in a local zone, so a raw date('Y-m-d') near midnight
        // would date the request to the wrong day.
        $data_prestito = \App\Support\DateHelper::today();
        // Read configured loan duration; fallback to 30 days
        $loanDays = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'loan_duration_days', '30') ?? 30);
        if ($loanDays < 1) {
            $loanDays = 30;
        }
        $data_scadenza = date('Y-m-d', strtotime($data_prestito . " +{$loanDays} days"));

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

            $dupReservationStmt = $db->prepare("
                SELECT id
                FROM prenotazioni
                WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva'
                LIMIT 1
                FOR UPDATE
            ");
            $dupReservationStmt->bind_param('ii', $libroId, $utenteId);
            $dupReservationStmt->execute();
            $dupReservationResult = $dupReservationStmt->get_result();
            if ($dupReservationResult->fetch_assoc()) {
                $dupReservationStmt->close();
                $db->rollback();
                return $this->back($response, ['loan_error' => 'duplicate']);
            }
            $dupReservationStmt->close();

            // Revalidate eligibility while holding the user row. The fast check
            // before the transaction improves feedback, but an administrator can
            // suspend the patron between that check and this INSERT.
            $userLockStmt = $db->prepare("SELECT id FROM utenti WHERE id = ? FOR UPDATE");
            $userLockStmt->bind_param('i', $utenteId);
            $userLockStmt->execute();
            $userLockStmt->get_result();
            $userLockStmt->close();
            if (\App\Support\LoanEligibility::checkUser($db, $utenteId) !== null) {
                $db->rollback();
                return $this->back($response, ['loan_error' => 'not_eligible']);
            }

            // #384 coherence: the reservation-vs-loan decision is the SAME
            // shared gate used by the book-detail modal endpoint
            // (ReservationsController::createReservation). When the title has
            // physical copies but no in-library copy can serve the requested
            // window without depending on a preceding borrower returning on
            // time (or an earlier FIFO reservation consumes the only capacity
            // slot), the gate creates a REAL prenotazioni.attiva waitlist row
            // instead of letting this route commit a bare prestiti.pendente
            // that admin approval would then reject (HTTP 400). Legacy books
            // with no `copie` rows keep the bare-pending fallback (I6). The
            // gate runs inside THIS transaction (inCallerTransaction: true)
            // under the canonical book lock taken above and never commits it.
            // Checked BEFORE the max-active-loans cap, mirroring
            // createReservation: a waitlist reservation does not consume an
            // active-loan slot until promotion.
            $routing = (new \App\Services\LoanRequestGate($db))
                ->route($libroId, $utenteId, $data_prestito, $data_scadenza, inCallerTransaction: true);
            if ($routing->isReservation()) {
                $db->commit();
                // Reuse the reserve() success contract: the frontend already
                // renders reserve_success as "queued in the waitlist".
                return $this->back($response, [
                    'reserve_success' => 1,
                    'reserve_date' => $data_prestito,
                ]);
            }

            // Enforce max active loans per user (admin setting; 0 = no limit)
            $maxLoans = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'max_active_loans_per_user', '0') ?? 0);
            if ($maxLoans > 0) {
                $cntStmt = $db->prepare("SELECT COUNT(*) FROM prestiti WHERE utente_id = ? AND attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')");
                $cntStmt->bind_param('i', $utenteId);
                $cntStmt->execute();
                $cntResult = $cntStmt->get_result();
                $activeCount = (int) ($cntResult ? $cntResult->fetch_row()[0] : 0);
                $cntStmt->close();
                if ($activeCount >= $maxLoans) {
                    $db->rollback();
                    return $this->back($response, ['loan_error' => 'max_loans_reached']);
                }
            }

            // Capture the setting while the canonical book/copy locks are still
            // held. With automatic approval enabled, persist the exact copy
            // selected by LoanRequestGate in the pending row BEFORE commit: the
            // copy-bound pending is canonical capacity, so a concurrent request
            // cannot pass the gate in the post-commit approval window. This is
            // the same claim protocol used by ReservationsController.
            $autoApproveEnabled = false;
            try {
                $autoApproveEnabled = (new \App\Models\SettingsRepository($db))->autoApproveLoanRequests();
            } catch (\Throwable $settingError) {
                SecureLogger::warning('Automatic-loan setting unavailable; request left for manual approval', [
                    'book_id' => $libroId,
                    'user_id' => $utenteId,
                    'error' => $settingError->getMessage(),
                ]);
            }

            $preassignedCopyId = $autoApproveEnabled ? $routing->assignableCopyId : null;
            if ($preassignedCopyId !== null) {
                $stmt = $db->prepare("
                    INSERT INTO prestiti
                        (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo)
                    VALUES (?, ?, ?, ?, ?, 'pendente', 0)
                ");
                $stmt->bind_param('iiiss', $libroId, $preassignedCopyId, $utenteId, $data_prestito, $data_scadenza);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO prestiti
                        (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo)
                    VALUES (?, ?, ?, ?, 'pendente', 0)
                ");
                $stmt->bind_param('iiss', $libroId, $utenteId, $data_prestito, $data_scadenza);
            }

            if (!$stmt->execute()) {
                $stmt->close();
                $db->rollback();
                return $this->back($response, ['loan_error' => 'db']);
            }

            $newLoanId = (int) $db->insert_id;
            $stmt->close();

            if ($preassignedCopyId !== null) {
                $integrity = new \App\Support\DataIntegrity($db);
                if (!$integrity->recalculateBookAvailability($libroId, insideTransaction: true)) {
                    throw new \RuntimeException('Failed to recalculate availability after automatic copy claim.');
                }
            }
            \App\Support\ActivityLog::recordLoanEvent(
                $db,
                $newLoanId,
                'loan.created',
                action: 'inserimento',
                source: 'user',
                operatorId: $utenteId
            );
            $db->commit();

            // Promote immediately before performing slower email I/O, minimizing
            // the post-commit window in which another request could claim the
            // same copy. The canonical approval path re-checks every constraint.
            // ?string: the persisted state ('prenotato'/'da_ritirare') on
            // success, null when the request stays pending.
            $autoApproved = $this->autoApproveLoanRequest($request, $db, $newLoanId, $autoApproveEnabled);

            // A successfully auto-approved request no longer needs admin action.
            // Emitting the old "new request" notification here left a stale
            // approval link pointing at a loan that was already da_ritirare.
            if (!$autoApproved) {
                try {
                    $notificationService = $this->createNotificationService($db);
                    $notificationService->notifyLoanRequest($newLoanId);
                } catch (\Throwable $e) {
                    SecureLogger::warning(__('Notifica richiesta prestito fallita'), ['error' => $e->getMessage()]);
                }
            }

            return $this->back($response, [
                'loan_request_success' => 1,
                'loan_id' => $newLoanId,
                'auto_approved' => $autoApproved ? 1 : 0,
                // Thread the persisted state through the redirect so the alert
                // can distinguish a scheduled ('prenotato') loan from one
                // awaiting pickup ('da_ritirare').
                'loan_state' => $autoApproved ?? '',
            ]);

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
        if ($desired !== '' && !\App\Support\DateHelper::isISODateFormat($desired)) {
            return $this->back($response, ['reserve_error' => 'invalid_date']);
        }
        // "Today" via DateHelper (M9): see loan() — avoids the midnight
        // day-boundary mismatch between process tz and app tz.
        $today = \App\Support\DateHelper::today();
        if ($desired !== '' && strtotime($desired) < strtotime($today)) {
            return $this->back($response, ['reserve_error' => 'past_date']);
        }
        $utenteId = (int) $user['id'];

        // User eligibility gate (M7): stato/tessera are only verified at login,
        // so a user suspended (or with an expired card) mid-session could
        // otherwise keep queueing reservations.
        if (\App\Support\LoanEligibility::checkUser($db, $utenteId) !== null) {
            return $this->back($response, ['reserve_error' => 'not_eligible']);
        }

        // Calculate date range for availability check
        $start = ($desired !== '') ? $desired : $today;
        $loanDays = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'loan_duration_days', '30') ?? 30);
        $loanDays = $loanDays > 0 ? $loanDays : 30;
        $end = (new \DateTimeImmutable($start))->modify("+{$loanDays} days")->format('Y-m-d');

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

            $dupLoanStmt = $db->prepare("
                SELECT id
                FROM prestiti
                WHERE libro_id = ? AND utente_id = ? AND (
                    (attivo = 0 AND stato = 'pendente')
                    OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
                )
                LIMIT 1
                FOR UPDATE
            ");
            $dupLoanStmt->bind_param('ii', $libroId, $utenteId);
            $dupLoanStmt->execute();
            $dupLoanResult = $dupLoanStmt->get_result();
            if ($dupLoanResult->fetch_assoc()) {
                $dupLoanStmt->close();
                $db->rollback();
                return $this->back($response, ['reserve_error' => 'duplicate']);
            }
            $dupLoanStmt->close();

            // Revalidate under the user lock so a concurrent suspension/card
            // expiry cannot race the pre-transaction eligibility check.
            $userLockStmt = $db->prepare("SELECT id FROM utenti WHERE id = ? FOR UPDATE");
            $userLockStmt->bind_param('i', $utenteId);
            $userLockStmt->execute();
            $userLockStmt->get_result();
            $userLockStmt->close();
            if (\App\Support\LoanEligibility::checkUser($db, $utenteId) !== null) {
                $db->rollback();
                return $this->back($response, ['reserve_error' => 'not_eligible']);
            }

            // Canonical peak-capacity decision (same service as admin create,
            // approval, renew and audit), excluding this user defensively.
            $capacity = new \App\Services\CapacityService($db);
            // Senza righe in `copie` la coda non può mai convertire (la
            // promozione seleziona solo copie fisiche): rifiuta a monte invece
            // di accettare una prenotazione che fallirebbe in silenzio per sempre.
            if (!$capacity->hasPhysicalCopies($libroId)) {
                $db->rollback();
                return $this->back($response, ['reserve_error' => 'not_available']);
            }
            if (!$capacity->hasFreeCapacity($libroId, $start, $end, excludeUserId: $utenteId)) {
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
                // insert_id right after the INSERT: any later query resets it.
                $reservationId = (int) $db->insert_id;
                $stmt->close();

                // Recalculate book availability after reservation
                $integrity = new \App\Support\DataIntegrity($db);
                if (!$integrity->recalculateBookAvailability($libroId, insideTransaction: true)) {
                    throw new \RuntimeException('Failed to recalculate availability after reservation creation.');
                }

                // Same audit trail as cancelReservation()/changeReservationDate();
                // recorded inside the transaction, and the helper swallows its
                // own failures so it cannot abort the reservation.
                \App\Support\ActivityLog::recordReservationEvent(
                    $db,
                    $reservationId,
                    'reservation.created',
                    action: 'inserimento',
                    source: 'user',
                    operatorId: $utenteId
                );

                $db->commit();
                $params = ['reserve_success' => 1];
                if ($desired !== '') {
                    $params['reserve_date'] = $desired;
                }
                return $this->back($response, $params);
            }
            $stmt->close();
            $db->rollback();
        } catch (\Throwable $e) {
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
     * Promote a newly-created request through the canonical approval pipeline.
     * A failure deliberately leaves the request pending, so an administrator can
     * still process it instead of losing an otherwise valid request.
     */
    private function autoApproveLoanRequest(
        Request $request,
        mysqli $db,
        int $loanId,
        ?bool $knownEnabled = null
    ): ?string
    {
        // The normal request path captures the setting under the book lock and
        // passes it in $knownEnabled, keeping its pre-commit copy claim and its
        // post-commit approval decision identical. Legacy/reflection callers may
        // omit it; their settings read remains inside this try so a DB hiccup
        // degrades to "left pending" instead of escaping after the durable INSERT.
        try {
            $enabled = $knownEnabled
                ?? (new \App\Models\SettingsRepository($db))->autoApproveLoanRequests();
            if (!$enabled) {
                // A disabled setting is not a failure: leave the request pending
                // for an admin without logging any warning noise.
                return null;
            }

            $approvalRequest = $request
                ->withParsedBody(['loan_id' => $loanId])
                ->withAttribute('automatic_loan_approval', true);
            $result = (new LoanApprovalController())->approveLoan(
                $approvalRequest,
                new SlimResponse(),
                $db
            );

            if ($result->getStatusCode() >= 200 && $result->getStatusCode() < 300) {
                // Return the state approveLoan actually persisted ('prenotato'
                // for a future-dated loan, 'da_ritirare' for an immediate one)
                // so the caller can describe the real outcome.
                $body = json_decode((string) $result->getBody(), true);
                return is_array($body) && isset($body['loan_state']) && is_string($body['loan_state'])
                    ? $body['loan_state']
                    : 'da_ritirare';
            }

            SecureLogger::warning('Automatic loan approval left request pending', [
                'loan_id' => $loanId,
                'status' => $result->getStatusCode(),
            ]);
        } catch (\Throwable $e) {
            SecureLogger::warning('Automatic loan approval failed; request left pending', [
                'loan_id' => $loanId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Factory seam for request notifications. Production receives the normal
     * service; DB-backed controller tests override it with a no-op so exercising
     * the manual queue never contacts an external mail transport.
     */
    protected function createNotificationService(mysqli $db): \App\Support\NotificationService
    {
        return new \App\Support\NotificationService($db);
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
