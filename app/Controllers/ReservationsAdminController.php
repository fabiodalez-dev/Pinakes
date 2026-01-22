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
                JOIN libri l ON l.id=p.libro_id 
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
                               FROM prenotazioni p JOIN libri l ON l.id=p.libro_id JOIN utenti u ON u.id=p.utente_id
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

        // Get libro_id before update for availability recalculation
        $libroStmt = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ?");
        $libroStmt->bind_param('i', $id);
        $libroStmt->execute();
        $libroResult = $libroStmt->get_result()->fetch_assoc();
        $libroStmt->close();
        $libroId = $libroResult ? (int) $libroResult['libro_id'] : 0;

        $stmt = $db->prepare("UPDATE prenotazioni SET stato=?, data_prenotazione=?, data_scadenza_prenotazione=?, data_inizio_richiesta=?, data_fine_richiesta=? WHERE id=?");
        $stmt->bind_param('sssssi', $stato, $startDt, $endDt, $dataInizioRichiesta, $dataFineRichiesta, $id);
        $stmt->execute();
        $stmt->close();

        // Recalculate book availability after reservation update
        if ($libroId > 0) {
            $integrity = new \App\Support\DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId);
        }

        return $response->withHeader('Location', '/admin/prenotazioni?updated=1')->withStatus(302);
    }

    public function createForm(Request $request, Response $response, mysqli $db): Response
    {
        // Get all books for dropdown
        $libri = [];
        $result = $db->query("SELECT id, titolo FROM libri ORDER BY titolo");
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
            return $response->withHeader('Location', '/admin/prenotazioni/crea?error=missing_data')->withStatus(302);
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
            $dataScadenza = date('Y-m-d H:i:s', strtotime('+30 days'));
            $dataScadenzaDate = date('Y-m-d', strtotime('+30 days'));
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

        // Calculate queue position
        $queuePosition = 1;
        $stmt = $db->prepare("SELECT COUNT(*) + 1 as position FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $queuePosition = (int) $row['position'];
        }
        $stmt->close();

        // Insert reservation with date range for availability calculations
        $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta, queue_position, stato) VALUES (?, ?, ?, ?, ?, ?, ?, 'attiva')");
        $stmt->bind_param('iissssi', $libroId, $utenteId, $dataPrenotazione, $dataScadenza, $dataInizioRichiesta, $dataFineRichiesta, $queuePosition);

        if ($stmt->execute()) {
            $stmt->close();

            // Recalculate book availability after new reservation
            $integrity = new \App\Support\DataIntegrity($db);
            $integrity->recalculateBookAvailability($libroId);

            return $response->withHeader('Location', '/admin/prenotazioni?created=1')->withStatus(302);
        } else {
            $stmt->close();
            return $response->withHeader('Location', '/admin/prenotazioni/crea?error=save_failed')->withStatus(302);
        }
    }
}
