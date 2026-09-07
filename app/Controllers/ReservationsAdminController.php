<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReservationsAdminController
{
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $q = $request->getQueryParams();
        $qLibro = trim((string) ($q['q_libro'] ?? ''));
        $qUtente = trim((string) ($q['q_utente'] ?? ''));
        $libroId = (int) ($q['libro_id'] ?? 0);
        $utenteId = (int) ($q['utente_id'] ?? 0);

        $sql = "SELECT p.id, p.libro_id, p.utente_id, p.data_prenotazione, p.data_scadenza_prenotazione, p.queue_position, p.stato,
                       l.titolo AS libro_titolo, CONCAT(u.nome,' ',u.cognome) AS utente_nome
                FROM prenotazioni p 
                JOIN libri l ON l.id=p.libro_id AND l.deleted_at IS NULL
                JOIN utenti u ON u.id=p.utente_id";
        $conds = [];
        $types = '';
        $params = [];
        if ($libroId > 0) {
            $conds[] = 'l.id = ?';
            $types .= 'i';
            $params[] = $libroId;
        }
        if ($utenteId > 0) {
            $conds[] = 'u.id = ?';
            $types .= 'i';
            $params[] = $utenteId;
        }
        if ($libroId <= 0 && $qLibro !== '') {
            $conds[] = 'l.titolo LIKE ?';
            $types .= 's';
            $params[] = '%' . $qLibro . '%';
        }
        if ($utenteId <= 0 && $qUtente !== '') {
            $conds[] = "CONCAT(u.nome,' ',u.cognome) LIKE ?";
            $types .= 's';
            $params[] = '%' . $qUtente . '%';
        }
        if ($conds) {
            $sql .= ' WHERE ' . implode(' AND ', $conds);
        }
        $sql .= ' ORDER BY p.created_at DESC LIMIT 200';

        $rows = [];
        if ($types !== '') {
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $stmt->close();
        } else {
            if ($res = $db->query($sql)) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = $r;
                }
            }
        }

        ob_start();
        require __DIR__ . '/../Views/prenotazioni/index.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $stmt = $db->prepare("SELECT p.*, l.titolo AS libro_titolo, CONCAT(u.nome,' ',u.cognome) AS utente_nome 
                               FROM prenotazioni p JOIN libri l ON l.id=p.libro_id AND l.deleted_at IS NULL JOIN utenti u ON u.id=p.utente_id
                               WHERE p.id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item)
            return $response->withStatus(404);
        ob_start();
        require __DIR__ . '/../Views/prenotazioni/modifica_prenotazione.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function update(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);
        // CSRF validated by CsrfMiddleware
        $stato = (string) ($data['stato'] ?? 'attiva');
        $start = trim((string) ($data['data_prenotazione'] ?? ''));
        $end = trim((string) ($data['data_scadenza_prenotazione'] ?? ''));

        // Date range for the requested loan period
        $dataInizioRichiesta = trim((string) ($data['data_inizio_richiesta'] ?? ''));
        $dataFineRichiesta = trim((string) ($data['data_fine_richiesta'] ?? ''));

        // Empty optional dates get the documented defaults below; a supplied but
        // impossible date (for example 2026-02-30) is an input error and must not
        // be silently normalized by DateTime/MySQL.
        foreach ([$start, $end, $dataInizioRichiesta, $dataFineRichiesta] as $postedDate) {
            if ($postedDate !== '' && !\App\Support\DateHelper::isISODateFormat($postedDate)) {
                return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=invalid_date')->withStatus(302);
            }
        }

        $today = \App\Support\DateHelper::today();
        if ($start === '') {
            $start = $today;
        }
        if ($end === '') {
            $loanDays = $this->loanDurationDays($db);
            $end = (new \DateTimeImmutable($start))->modify("+{$loanDays} days")->format('Y-m-d');
        }
        // Normalize an inverted range on the reservation dates too (form can post
        // end < start), mirroring the data_*_richiesta clamp below (#252).
        if ($end < $start) {
            $end = $start;
        }

        // Derive data_inizio_richiesta from data_prenotazione (start) if not explicitly provided
        // This ensures the loan period matches the reservation dates from the form
        if ($dataInizioRichiesta === '') {
            $dataInizioRichiesta = $start;
        }
        // Derive data_fine_richiesta from data_scadenza_prenotazione (end) if not explicitly provided
        if ($dataFineRichiesta === '') {
            $dataFineRichiesta = $end;
        }

        // Normalize inverted date range (defensive check)
        if ($dataFineRichiesta < $dataInizioRichiesta) {
            $dataFineRichiesta = $dataInizioRichiesta;
        }

        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';

        // Rifiuta (non coercire) uno stato fuori whitelist: la vecchia
        // coercizione ad 'attiva' faceva sì che un POST malformato su una
        // prenotazione annullata/completata la riattivasse silenziosamente.
        if (!in_array($stato, ['attiva', 'completata', 'annullata'], true)) {
            return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=invalid_status')->withStatus(302);
        }

        $lookupStmt = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ?");
        $lookupStmt->bind_param('i', $id);
        $lookupStmt->execute();
        $lookupResult = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();
        if (!$lookupResult) {
            return $response->withHeader('Location', url('/admin/reservations') . '?error=not_found')->withStatus(302);
        }

        $libroId = (int) $lookupResult['libro_id'];

        $cancelNotification = null;
        $db->begin_transaction();
        try {
            $lockStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libroId);
            $lockStmt->execute();
            $bookExists = (bool) $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();
            if (!$bookExists) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/reservations') . '?error=book_not_found')->withStatus(302);
            }

            $libroStmt = $db->prepare("SELECT libro_id, utente_id, stato FROM prenotazioni WHERE id = ? FOR UPDATE");
            $libroStmt->bind_param('i', $id);
            $libroStmt->execute();
            $libroResult = $libroStmt->get_result()->fetch_assoc();
            $libroStmt->close();
            if (!$libroResult || (int) $libroResult['libro_id'] !== $libroId) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/reservations') . '?error=not_found')->withStatus(302);
            }

            $utenteId = (int) $libroResult['utente_id'];
            $oldStato = (string) $libroResult['stato'];

            // Snapshot AFTER the FOR UPDATE lock: taken earlier, a concurrent
            // request could change the row between the read and the lock and
            // the audit diff would attribute someone else's change to this edit.
            $activityBefore = \App\Support\ActivityLog::loadReservationSnapshot($db, $id);

            // Una prenotazione 'completata' è stata promossa: il prestito
            // collegato vive solo tramite origine='prenotazione' (nessuna FK) e
            // questo edit non lo toccherebbe. Finché quel prestito è aperto,
            // cambiare qui lo stato produrrebbe solo coppie incoerenti
            // (prenotazione annullata + prestito promosso vivo): va gestito il
            // prestito, non la prenotazione.
            if ($oldStato === 'completata' && $stato !== 'completata') {
                $promotedStmt = $db->prepare("
                    SELECT id FROM prestiti
                    WHERE libro_id = ? AND utente_id = ? AND origine = 'prenotazione' AND (
                        (attivo = 0 AND stato = 'pendente')
                        OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
                    )
                    LIMIT 1
                    FOR UPDATE
                ");
                $promotedStmt->bind_param('ii', $libroId, $utenteId);
                $promotedStmt->execute();
                $hasPromotedLoan = $promotedStmt->get_result()->num_rows > 0;
                $promotedStmt->close();
                if ($hasPromotedLoan) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=promoted_loan_active')->withStatus(302);
                }
            }

            if ($stato === 'attiva') {
                $dupReservationStmt = $db->prepare("
                    SELECT id
                    FROM prenotazioni
                    WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva' AND id != ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $dupReservationStmt->bind_param('iii', $libroId, $utenteId, $id);
                $dupReservationStmt->execute();
                if ($dupReservationStmt->get_result()->fetch_assoc()) {
                    $dupReservationStmt->close();
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=duplicate')->withStatus(302);
                }
                $dupReservationStmt->close();

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
                if ($dupLoanStmt->get_result()->fetch_assoc()) {
                    $dupLoanStmt->close();
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=duplicate')->withStatus(302);
                }
                $dupLoanStmt->close();

                // Keep the canonical book -> commitments -> user lock order used
                // by the approval paths, then apply the shared patron gate.
                $userLockStmt = $db->prepare('SELECT id FROM utenti WHERE id = ? FOR UPDATE');
                $userLockStmt->bind_param('i', $utenteId);
                $userLockStmt->execute();
                $userLockStmt->close();
                $eligibilityError = \App\Support\LoanEligibility::checkUser($db, $utenteId);
                if ($eligibilityError !== null) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=' . rawurlencode($eligibilityError))->withStatus(302);
                }
            }

            // Capacity gate on edit (the decision): only an 'attiva' reservation
            // occupies. Reject a change that would push the period over capacity,
            // excluding this reservation itself (and the user) from the count.
            if ($stato === 'attiva') {
                $capacity = new \App\Services\CapacityService($db);
                // Riattivazione (non-attiva -> attiva): stesso gate di store() —
                // senza righe in `copie` la promozione non può mai convertire la
                // coda (seleziona solo copie fisiche), quindi la prenotazione
                // riattivata non convertirebbe mai. Rifiuta con lo stesso errore.
                if ($oldStato !== 'attiva' && !$capacity->hasPhysicalCopies($libroId)) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=capacity_full')->withStatus(302);
                }
                if (!$capacity->hasFreeCapacity($libroId, $dataInizioRichiesta, $dataFineRichiesta, excludeReservationId: $id, excludeUserId: $utenteId)) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=capacity_full')->withStatus(302);
                }
            }

            // #13: reactivating a cancelled/completed reservation must NOT keep its old
            // (small) queue_position — that would jump it ahead of everyone who waited
            // continuously. Append it to the tail, exactly like store() does.
            $reactivating = ($oldStato !== 'attiva' && $stato === 'attiva');
            if ($reactivating) {
                $posStmt = $db->prepare("SELECT COALESCE(MAX(queue_position), 0) + 1 AS position FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
                $posStmt->bind_param('i', $libroId);
                $posStmt->execute();
                $newPosition = (int) ($posStmt->get_result()->fetch_assoc()['position'] ?? 1);
                $posStmt->close();

                $stmt = $db->prepare("UPDATE prenotazioni SET stato=?, data_prenotazione=?, data_scadenza_prenotazione=?, data_inizio_richiesta=?, data_fine_richiesta=?, queue_position=? WHERE id=?");
                $stmt->bind_param('sssssii', $stato, $startDt, $endDt, $dataInizioRichiesta, $dataFineRichiesta, $newPosition, $id);
            } else {
                $stmt = $db->prepare("UPDATE prenotazioni SET stato=?, data_prenotazione=?, data_scadenza_prenotazione=?, data_inizio_richiesta=?, data_fine_richiesta=? WHERE id=?");
                $stmt->bind_param('sssssi', $stato, $startDt, $endDt, $dataInizioRichiesta, $dataFineRichiesta, $id);
            }
            if (!$stmt->execute()) {
                throw new \RuntimeException('Reservation update failed');
            }
            $stmt->close();

            if ($oldStato === 'attiva' || $stato === 'attiva') {
                $this->reorderQueuePositions($db, $libroId);
            }

            $integrity = new \App\Support\DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, insideTransaction: true)) {
                throw new \RuntimeException('Failed to recalculate availability after reservation update.');
            }

            // Cancelling/completing an active reservation frees a slot: promote the next
            // queued reservation(s) right away, exactly like every other release path
            // (LoanApproval, Prestiti, …) — otherwise the next in line waits for the
            // periodic MaintenanceService sweep and the book looks free in the meantime.
            // Anche attiva -> attiva promuove: restringere/spostare la finestra
            // può liberare capacità nel periodo lasciato scoperto. E anche la
            // riattivazione (annullata/completata -> attiva): la prenotazione
            // rientra in coda e, se c'è capacità libera, deve poter convertire
            // subito come una appena creata — non aspettare lo sweep periodico.
            $reservationManager = null;
            if ($oldStato === 'attiva' || $reactivating) {
                $reservationManager = new \App\Controllers\ReservationManager($db);
                $reservationManager->setExternalTransaction(true); // already inside a transaction
                for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($libroId); $promoGuard++) {
                    // promote until the freed capacity is exhausted
                }
            }

            if ($oldStato === 'attiva' && $stato === 'annullata') {
                // The update path already rejects archived titles up front (the
                // book row is locked WITH deleted_at IS NULL and the request
                // fails with book_not_found before reaching this point), so this
                // fetch keeps the standard filter: it can only ever see a live
                // book, and the controller-wide soft-delete rule stays intact.
                $notificationStmt = $db->prepare(
                    "SELECT CONCAT(u.nome, ' ', u.cognome) AS utente_nome, u.email, l.titolo
                     FROM utenti u
                     JOIN libri l ON l.id = ? AND l.deleted_at IS NULL
                     WHERE u.id = ?"
                );
                $notificationStmt->bind_param('ii', $libroId, $utenteId);
                $notificationStmt->execute();
                $cancelNotification = $notificationStmt->get_result()->fetch_assoc() ?: null;
                $notificationStmt->close();
            }

            // Audit inside the transaction: after commit a concurrent request
            // could alter the row before the snapshot read, attributing the
            // next state to this operation. A failed audit write never aborts
            // the transaction (recordBookEvent swallows its own errors).
            \App\Support\ActivityLog::recordReservationEvent(
                $db,
                $id,
                $stato === 'annullata' ? 'reservation.cancelled' : 'reservation.updated',
                $activityBefore,
                source: 'admin'
            );
            $db->commit();

            // Post-commit: un flush fallito non deve trasformare un update già
            // durevole in error=update_failed né sopprimere l'email di
            // annullamento qui sotto — le email promo restano nell'outbox.
            if ($reservationManager !== null) {
                try {
                    $reservationManager->flushDeferredNotifications();
                } catch (\Throwable $flushError) {
                    \App\Support\SecureLogger::warning(
                        "ReservationsAdminController: deferred promotion notifications failed after update of reservation {$id}",
                        ['error' => $flushError->getMessage()]
                    );
                }
            }
            if ($cancelNotification !== null && !empty($cancelNotification['email'])) {
                try {
                    $notificationService = new \App\Support\NotificationService($db);
                    // Motivo nel locale del DESTINATARIO (#360 parity), non in
                    // quello della sessione admin che sta annullando.
                    $sent = $notificationService->sendReservationCancelledNotification(
                        (string) $cancelNotification['email'],
                        [
                            'utente_nome' => (string) $cancelNotification['utente_nome'],
                            'libro_titolo' => (string) $cancelNotification['titolo'],
                            'motivo' => $notificationService->translateInLocale(
                                'Annullata dalla biblioteca',
                                $notificationService->resolveRecipientLocale((string) $cancelNotification['email'])
                            ),
                        ]
                    );
                    if (!$sent) {
                        \App\Support\SecureLogger::warning("ReservationsAdminController: cancellation email failed for reservation {$id}");
                    }
                } catch (\Throwable $notificationError) {
                    \App\Support\SecureLogger::warning(
                        "ReservationsAdminController: cancellation notification failed for reservation {$id}",
                        ['error' => $notificationError->getMessage()]
                    );
                }
            }
            return $response->withHeader('Location', url('/admin/reservations') . '?updated=1')->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            return $response->withHeader('Location', url('/admin/reservations/edit/' . $id) . '?error=update_failed')->withStatus(302);
        }
    }

    public function createForm(Request $request, Response $response, mysqli $db): Response
    {
        // Get all books for dropdown
        $libri = [];
        $result = $db->query("SELECT id, titolo FROM libri WHERE deleted_at IS NULL ORDER BY titolo");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $libri[] = $row;
            }
        }

        // Get all users for dropdown
        $utenti = [];
        $result = $db->query("SELECT id, CONCAT(nome, ' ', cognome) as nome_completo, email FROM utenti WHERE stato = 'attivo' ORDER BY nome, cognome");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $utenti[] = $row;
            }
        }

        $defaultLoanDays = $this->loanDurationDays($db);

        ob_start();
        $title = "Crea Prenotazione - Admin";
        require __DIR__ . '/../Views/prenotazioni/crea_prenotazione.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);

        // CSRF validated by CsrfMiddleware

        $libroId = (int) ($data['libro_id'] ?? 0);
        $utenteId = (int) ($data['utente_id'] ?? 0);
        $dataPrenotazione = trim((string) ($data['data_prenotazione'] ?? ''));
        $dataScadenza = trim((string) ($data['data_scadenza'] ?? ''));

        // Date range for the requested loan period (critical for availability calculations)
        $dataInizioRichiesta = trim((string) ($data['data_inizio_richiesta'] ?? ''));
        $dataFineRichiesta = trim((string) ($data['data_fine_richiesta'] ?? ''));

        // Validation
        if ($libroId <= 0 || $utenteId <= 0) {
            return $response->withHeader('Location', url('/admin/reservations/create') . '?error=missing_data')->withStatus(302);
        }

        foreach ([$dataPrenotazione, $dataScadenza, $dataInizioRichiesta, $dataFineRichiesta] as $postedDate) {
            if ($postedDate !== '' && !\App\Support\DateHelper::isISODateFormat($postedDate)) {
                return $response->withHeader('Location', url('/admin/reservations/create') . '?error=invalid_date')->withStatus(302);
            }
        }

        // Set default dates in the application timezone.
        $today = \App\Support\DateHelper::today();

        // Parse form dates (date only, without time)
        $dataPrenotazioneDate = $dataPrenotazione;
        $dataScadenzaDate = $dataScadenza;

        if (empty($dataPrenotazione)) {
            $dataPrenotazione = $today . ' 00:00:00';
            $dataPrenotazioneDate = $today;
        } else {
            $dataPrenotazioneDate = $dataPrenotazione; // Keep date only for loan period
            $dataPrenotazione = $dataPrenotazione . ' 00:00:00';
        }

        if (empty($dataScadenza)) {
            // Durata di default allineata all'impostazione admin loan_duration_days
            // (stesso valore mostrato dalla view), non più 30 giorni hardcoded.
            $loanDays = $this->loanDurationDays($db);
            // The default duration starts from the requested start date, not
            // from today (critical for future reservations).
            $dataScadenzaDate = (new \DateTimeImmutable($dataPrenotazioneDate))
                ->modify("+{$loanDays} days")
                ->format('Y-m-d');
            $dataScadenza = $dataScadenzaDate . ' 23:59:59';
        } else {
            $dataScadenzaDate = $dataScadenza; // Keep date only for loan period
            // Normalize an inverted range when both dates are posted (#252), mirroring
            // the data_*_richiesta clamp below.
            if ($dataScadenzaDate < $dataPrenotazioneDate) {
                $dataScadenzaDate = $dataPrenotazioneDate;
            }
            $dataScadenza = $dataScadenzaDate . ' 23:59:59';
        }

        // Derive data_inizio_richiesta from data_prenotazione if not explicitly provided
        // This ensures the loan period matches the reservation dates from the form
        if (empty($dataInizioRichiesta)) {
            $dataInizioRichiesta = $dataPrenotazioneDate;
        }

        // Derive data_fine_richiesta from data_scadenza if not explicitly provided
        if (empty($dataFineRichiesta)) {
            $dataFineRichiesta = $dataScadenzaDate;
        }

        // Normalize inverted date range (defensive check)
        if ($dataFineRichiesta < $dataInizioRichiesta) {
            $dataFineRichiesta = $dataInizioRichiesta;
        }

        $db->begin_transaction();
        try {
            $lockStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libroId);
            $lockStmt->execute();
            $bookExists = (bool) $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();
            if (!$bookExists) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/reservations/create') . '?error=book_not_found')->withStatus(302);
            }

            $dupReservationStmt = $db->prepare("
                SELECT id
                FROM prenotazioni
                WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva'
                LIMIT 1
                FOR UPDATE
            ");
            $dupReservationStmt->bind_param('ii', $libroId, $utenteId);
            $dupReservationStmt->execute();
            if ($dupReservationStmt->get_result()->fetch_assoc()) {
                $dupReservationStmt->close();
                $db->rollback();
                return $response->withHeader('Location', url('/admin/reservations/create') . '?error=duplicate')->withStatus(302);
            }
            $dupReservationStmt->close();

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
            if ($dupLoanStmt->get_result()->fetch_assoc()) {
                $dupLoanStmt->close();
                $db->rollback();
                return $response->withHeader('Location', url('/admin/reservations/create') . '?error=duplicate')->withStatus(302);
            }
            $dupLoanStmt->close();

            $userLockStmt = $db->prepare('SELECT id FROM utenti WHERE id = ? FOR UPDATE');
            $userLockStmt->bind_param('i', $utenteId);
            $userLockStmt->execute();
            $userLockStmt->close();
            $eligibilityError = \App\Support\LoanEligibility::checkUser($db, $utenteId);
            if ($eligibilityError !== null) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/reservations/create') . '?error=' . rawurlencode($eligibilityError))->withStatus(302);
            }

            // Capacity gate (the decision): a waitlist reservation occupies one
            // capacity unit for its promised period, so reject it when the book is
            // already at capacity for that window (counting other commitments).
            // Same CapacityService predicate as the promotion gate and the auditor.
            $capacity = new \App\Services\CapacityService($db);
            // Senza righe in `copie` la promozione non può mai convertire la
            // coda (seleziona solo copie fisiche): rifiuta a monte.
            if (!$capacity->hasPhysicalCopies($libroId)) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/reservations/create') . '?error=capacity_full')->withStatus(302);
            }
            if (!$capacity->hasFreeCapacity($libroId, $dataInizioRichiesta, $dataFineRichiesta, excludeUserId: $utenteId)) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/reservations/create') . '?error=capacity_full')->withStatus(302);
            }

            $stmt = $db->prepare("SELECT COALESCE(MAX(queue_position), 0) + 1 as position FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
            $stmt->bind_param('i', $libroId);
            $stmt->execute();
            $result = $stmt->get_result();
            $queuePosition = (int) (($result->fetch_assoc()['position'] ?? 1));
            $stmt->close();

            $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta, queue_position, stato) VALUES (?, ?, ?, ?, ?, ?, ?, 'attiva')");
            $stmt->bind_param('iissssi', $libroId, $utenteId, $dataPrenotazione, $dataScadenza, $dataInizioRichiesta, $dataFineRichiesta, $queuePosition);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Reservation insert failed');
            }
            $reservationId = (int) $db->insert_id;
            $stmt->close();

            $integrity = new \App\Support\DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, insideTransaction: true)) {
                throw new \RuntimeException('Failed to recalculate availability after reservation creation.');
            }

            // Audit inside the transaction (see update() for the rationale).
            \App\Support\ActivityLog::recordReservationEvent(
                $db,
                $reservationId,
                'reservation.created',
                action: 'inserimento',
                source: 'admin'
            );
            $db->commit();
            return $response->withHeader('Location', url('/admin/reservations') . '?created=1')->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            return $response->withHeader('Location', url('/admin/reservations/create') . '?error=save_failed')->withStatus(302);
        }
    }

    /**
     * The configured loan duration in days (loans.loan_duration_days), with the
     * canonical fallback to 30 for a missing or non-positive value. Centralizes
     * the lookup previously duplicated in update(), store() and createForm().
     */
    private function loanDurationDays(mysqli $db): int
    {
        $days = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'loan_duration_days', '30') ?? 30);
        return $days > 0 ? $days : 30;
    }

    private function reorderQueuePositions(mysqli $db, int $libroId): void
    {
        $stmt = $db->prepare("
            SELECT id
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            ORDER BY queue_position ASC, id ASC
        ");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $result = $stmt->get_result();

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $stmt->close();

        // Two-phase move (stesso pattern di ReservationManager): prima le
        // righe attive vanno su posizioni temporanee fuori intervallo, poi
        // si assegnano le posizioni finali — il trigger BEFORE UPDATE
        // rifiuta i duplicati riga per riga durante il riordino.
        // Offset DINAMICO: un offset fisso può collidere con posizioni
        // residue già oltre l'offset; deve superare sia il MAX attivo sia
        // l'ultima posizione finale assegnata (righe NULL incluse in N).
        $maxStmt = $db->prepare("SELECT COALESCE(MAX(queue_position), 0) FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
        $maxStmt->bind_param('i', $libroId);
        $maxStmt->execute();
        $offset = max((int) $maxStmt->get_result()->fetch_row()[0], count($ids));
        $maxStmt->close();
        if ($offset > 0) {
            $shiftStmt = $db->prepare("
                UPDATE prenotazioni
                SET queue_position = queue_position + ?
                WHERE libro_id = ? AND stato = 'attiva' AND queue_position IS NOT NULL
            ");
            $shiftStmt->bind_param('ii', $offset, $libroId);
            $shiftStmt->execute();
            $shiftStmt->close();
        }

        $position = 1;
        $updateStmt = $db->prepare("UPDATE prenotazioni SET queue_position = ? WHERE id = ?");
        foreach ($ids as $reservationId) {
            $updateStmt->bind_param('ii', $position, $reservationId);
            $updateStmt->execute();
            $position++;
        }
        $updateStmt->close();
    }
}
