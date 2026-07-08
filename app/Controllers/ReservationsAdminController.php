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

        // Validate date formats
        if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start))
            $start = '';
        if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))
            $end = '';
        if ($dataInizioRichiesta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInizioRichiesta))
            $dataInizioRichiesta = '';
        if ($dataFineRichiesta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFineRichiesta))
            $dataFineRichiesta = '';

        $today = date('Y-m-d');
        if ($start === '') {
            $start = $today;
        }
        if ($end === '') {
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
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

        if (!in_array($stato, ['attiva', 'completata', 'annullata'], true)) {
            $stato = 'attiva';
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
            }

            // Capacity gate on edit (the decision): only an 'attiva' reservation
            // occupies. Reject a change that would push the period over capacity,
            // excluding this reservation itself (and the user) from the count.
            if ($stato === 'attiva') {
                $capacity = new \App\Services\CapacityService($db);
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
            $integrity->recalculateBookAvailability($libroId, insideTransaction: true);

            $db->commit();
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

        $defaultLoanDays = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'loan_duration_days', '30') ?? 30);
        if ($defaultLoanDays < 1) {
            $defaultLoanDays = 30;
        }

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

        // Validate date formats (reset to empty if invalid format)
        if ($dataPrenotazione !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPrenotazione)) {
            $dataPrenotazione = '';
        }
        if ($dataScadenza !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataScadenza)) {
            $dataScadenza = '';
        }

        // Set default dates if not provided (use date() for server timezone consistency)
        $today = date('Y-m-d');

        // Parse form dates (date only, without time)
        $dataPrenotazioneDate = $dataPrenotazione;
        $dataScadenzaDate = $dataScadenza;

        if (empty($dataPrenotazione)) {
            $dataPrenotazione = date('Y-m-d H:i:s');
            $dataPrenotazioneDate = $today;
        } else {
            $dataPrenotazioneDate = $dataPrenotazione; // Keep date only for loan period
            $dataPrenotazione = $dataPrenotazione . ' 00:00:00';
        }

        if (empty($dataScadenza)) {
            // Durata di default allineata all'impostazione admin loan_duration_days
            // (stesso valore mostrato dalla view), non più 30 giorni hardcoded.
            $loanDays = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'loan_duration_days', '30') ?? 30);
            if ($loanDays < 1) {
                $loanDays = 30;
            }
            $dataScadenza = date('Y-m-d H:i:s', strtotime("+{$loanDays} days"));
            $dataScadenzaDate = date('Y-m-d', strtotime("+{$loanDays} days"));
        } else {
            $dataScadenzaDate = $dataScadenza; // Keep date only for loan period
            $dataScadenza = $dataScadenza . ' 23:59:59';
        }

        // Derive data_inizio_richiesta from data_prenotazione if not explicitly provided
        // This ensures the loan period matches the reservation dates from the form
        if (empty($dataInizioRichiesta) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInizioRichiesta)) {
            $dataInizioRichiesta = $dataPrenotazioneDate;
        }

        // Derive data_fine_richiesta from data_scadenza if not explicitly provided
        if (empty($dataFineRichiesta) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFineRichiesta)) {
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

            // Capacity gate (the decision): a waitlist reservation occupies one
            // capacity unit for its promised period, so reject it when the book is
            // already at capacity for that window (counting other commitments).
            // Same CapacityService predicate as the promotion gate and the auditor.
            $capacity = new \App\Services\CapacityService($db);
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
            $stmt->close();

            $integrity = new \App\Support\DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId, insideTransaction: true);

            $db->commit();
            return $response->withHeader('Location', url('/admin/reservations') . '?created=1')->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            return $response->withHeader('Location', url('/admin/reservations/create') . '?error=save_failed')->withStatus(302);
        }
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
